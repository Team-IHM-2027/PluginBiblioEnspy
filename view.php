<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// 1. Récupérer les paramètres de l'URL et les valider
$itemId = required_param('id', PARAM_ALPHANUMEXT);
$itemType = required_param('type', PARAM_ALPHA);
$collection = ($itemType === 'books') ? 'BiblioBooks' : 'BiblioThesis';

// 2. Setup de la page Moodle
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/biblio_enspy/view.php', ['id' => $itemId, 'type' => $itemType]));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/biblio_enspy/css/styles.css');


// 3. Configuration et appel à Firestore pour UN SEUL document
$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

$itemUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}/{$itemId}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $itemUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$itemData = json_decode($response, true);

if (!$itemData || !isset($itemData['fields'])) {
    throw new \moodle_exception('Document introuvable.', 'local_biblio_enspy');
}

$fields = $itemData['fields'];

// --- Logique pour extraire les données selon le type ---
if ($itemType === 'books') {
    $name = $fields['name']['stringValue'] ?? 'Titre non disponible';
    $category = $fields['cathegorie']['stringValue'] ?? 'Non disponible';
    $author = $fields['auteur']['stringValue'] ?? 'Non disponible';
    $description = $fields['desc']['stringValue'] ?? 'Aucune description.';
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-image.png';
    $exemplaire = $fields['exemplaire']['integerValue'] ?? $fields['exemplaire']['doubleValue'] ?? 0;
} else { // C'est un mémoire ('theses')
    $name = $fields['theme']['stringValue'] ?? 'Thème non disponible';
    $category = $fields['département']['stringValue'] ?? 'Non disponible';
    $author = $fields['name']['stringValue'] ?? 'Auteur non disponible';
    $superviseur = $fields['superviseur']['stringValue'] ?? 'Non spécifié';
    $matricule = $fields['matricule']['stringValue'] ?? 'Non spécifié';
    $pdfUrl = $fields['pdfUrl']['stringValue'] ?? '';
    
    // NOUVEAU : Gestion de la disponibilité physique
    // Vous devez ajouter un champ 'exemplaire' dans Firestore pour les mémoires
    // Si le champ n'existe pas encore, on suppose qu'il y a 1 exemplaire disponible
    $exemplaire = $fields['exemplaire']['integerValue'] ?? $fields['exemplaire']['doubleValue'] ?? 1;
    
    // NOUVEAU : On détermine deux types de disponibilité
    $hasPhysicalCopy = (int)$exemplaire > 0;
    $hasPdf = !empty($pdfUrl);
    
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-thesis.png'; 
}

// Pour les livres, la disponibilité est basée sur les exemplaires
// Pour les mémoires, on a maintenant deux critères séparés
if ($itemType === 'books') {
    $isAvailable = (int)$exemplaire > 0;
}

// Mettre à jour le titre de la page
$PAGE->set_title($name);
$PAGE->set_heading($name);

// 5. Récupération de l'ID utilisateur pour les réservations
global $USER;
$userDocId = null;
try {
    $userCollectionUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser";
    $chUser = curl_init();
    curl_setopt($chUser, CURLOPT_URL, $userCollectionUrl);
    curl_setopt($chUser, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chUser, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($chUser, CURLOPT_SSL_VERIFYPEER, false);
    $userResponse = curl_exec($chUser);
    curl_close($chUser);
    $userData = json_decode($userResponse, true);

    if (isset($userData['documents'])) {
        foreach ($userData['documents'] as $document) {
            if (isset($document['fields']['email']['stringValue']) && $document['fields']['email']['stringValue'] == $USER->email) {
                $pathParts = explode('/', $document['name']);
                $userDocId = end($pathParts);
                break;
            }
        }
    }
} catch (Exception $e) {
    $userDocId = null;
}

// 5. Affichage de la page
echo $OUTPUT->header();

$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

$html = '<div class="row">';

// Colonne de gauche pour l'image
$html .= '<div class="col-md-4 text-center">';
$html .= '<img src="' . $imageUrl . '" class="img-fluid rounded book-detail-image" alt="' . htmlspecialchars($name) . '">';
$html .= '</div>';

// Colonne de droite pour les informations
$html .= '<div class="col-md-8">';
$html .= '<h3>' . htmlspecialchars($name) . '</h3>';

// --- Affichage conditionnel des informations ---
if ($itemType === 'books') {
    $html .= '<p class="text-muted"><em>Par ' . htmlspecialchars($author) . '</em></p>';
    $html .= '<hr>';
    $html .= '<p><strong>Catégorie :</strong> ' . htmlspecialchars($category) . '</p>';
    if ($isAvailable) {
        $html .= '<p class="text-success"><strong>Disponibilité :</strong> En stock (' . $exemplaire . ' exemplaire(s) restant(s))</p>';
    } else {
        $html .= '<p class="text-danger"><strong>Disponibilité :</strong> Hors stock</p>';
    }
    $html .= '<div class="mt-4"><h4>Description</h4><p>' . nl2br(htmlspecialchars($description)) . '</p></div>';

} else { // Affichage pour les mémoires (AMÉLIORÉ)
    $html .= '<p class="text-muted"><em>Par ' . htmlspecialchars($author) . ' (Matricule: ' . htmlspecialchars($matricule) . ')</em></p>';
    $html .= '<hr>';
    $html .= '<p><strong>Département :</strong> ' . htmlspecialchars($category) . '</p>';
    $html .= '<p><strong>Superviseur :</strong> ' . htmlspecialchars($superviseur) . '</p>';
    
    // NOUVEAU : Affichage séparé des deux types de disponibilité
    $html .= '<div class="availability-section">';
    
    // Disponibilité physique
    if ($hasPhysicalCopy) {
        $html .= '<p class="text-success"><strong>Exemplaire physique :</strong> Disponible à la bibliothèque</p>';
    } else {
        $html .= '<p class="text-danger"><strong>Exemplaire physique :</strong> Non disponible</p>';
    }
    
    // Disponibilité PDF
    if ($hasPdf) {
        $html .= '<p class="text-success"><strong>Version numérique :</strong> Disponible en ligne</p>';
    } else {
        $html .= '<p class="text-danger"><strong>Version numérique :</strong> Non disponible</p>';
    }
    
    $html .= '</div>';
}

// NOUVELLE SECTION : Boutons d'action
$html .= '<div class="mt-4 text-center action-buttons">';

if ($itemType === 'books') {
    // Boutons pour les livres (inchangés)
    if ($isAvailable) {
        $html .= '<button id="reserveBtn" class="btn btn-primary btn-lg" data-id="' . $itemId . '" data-type="' . $itemType . '">Réserver cet ouvrage</button>';
    } else {
        $html .= '<button class="btn btn-secondary btn-lg" disabled>Réservation indisponible</button>';
    }
} else { 
    // NOUVEAU : Boutons pour les mémoires (logique améliorée)
    
    // Bouton Consulter le PDF (toujours affiché mais activé/désactivé)
    if ($hasPdf) {
        $html .= '<a href="' . $pdfUrl . '" class="btn btn-info btn-lg" target="_blank">Consulter le PDF</a>';
    } else {
        $html .= '<button class="btn btn-secondary btn-lg" disabled>PDF indisponible</button>';
    }
    
    // Espacement entre les boutons
    $html .= '&nbsp;&nbsp;';
    
    // Bouton Réserver (pour l'exemplaire physique)
    if ($hasPhysicalCopy) {
        $html .= '<button id="reserveBtn" class="btn btn-primary btn-lg" data-id="' . $itemId . '" data-type="' . $itemType . '">Réserver l\'exemplaire physique</button>';
    } else {
        $html .= '<button class="btn btn-secondary btn-lg" disabled>Exemplaire physique indisponible</button>';
    }
}

$html .= '</div>'; // Fin des boutons d'action

$html .= '</div>'; // Fin de la colonne de droite
$html .= '</div>'; // Fin de la row

echo $OUTPUT->box($html, 'p-3');

// --- JavaScript pour la réservation (adapté pour les mémoires) ---
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reserveBtn = document.getElementById('reserveBtn');
    if (reserveBtn) {
        reserveBtn.addEventListener('click', function() {
            const element = this;
            const itemId = element.getAttribute('data-id');
            const itemType = element.getAttribute('data-type');
            const userDocId = <?php echo json_encode($userDocId); ?>;
            
            // Message de confirmation adapté selon le type
            const itemName = "<?php echo addslashes($name); ?>";
            const confirmMessage = (itemType === 'books') 
                ? 'Confirmez-vous la réservation de ce livre ?'
                : 'Confirmez-vous la réservation de cet exemplaire physique du mémoire ?';

            if (!userDocId) {
                alert("Erreur : Impossible d'identifier l'utilisateur. Veuillez vous reconnecter.");
                return;
            }

            if (!confirm(confirmMessage)) { return; }

            element.disabled = true;
            element.textContent = 'Traitement en cours...';

            fetch('api_reserve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ itemId, itemType, userDocId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Réservation réussie !');
                    element.textContent = (itemType === 'books') 
                        ? 'Réservé avec succès' 
                        : 'Exemplaire réservé';
                    element.classList.remove('btn-primary');
                    element.classList.add('btn-success');
                    
                    // Pour les mémoires, on peut également désactiver le bouton PDF si on réserve le physique
                    if (itemType === 'theses') {
                        const pdfBtn = document.querySelector('.btn-info');
                        if (pdfBtn) {
                            pdfBtn.classList.remove('btn-info');
                            pdfBtn.classList.add('btn-secondary');
                            pdfBtn.textContent = 'PDF (exemplaire réservé)';
                        }
                    }
                    
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Échec de la réservation : ' + data.message);
                    element.disabled = false;
                    element.textContent = (itemType === 'books') 
                        ? 'Réserver cet ouvrage' 
                        : 'Réserver l\'exemplaire physique';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur réseau est survenue.');
                element.disabled = false;
                element.textContent = (itemType === 'books') 
                    ? 'Réserver cet ouvrage' 
                    : 'Réserver l\'exemplaire physique';
            });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>