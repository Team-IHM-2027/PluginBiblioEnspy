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

// --- CORRECTION : Logique pour extraire les données selon le type ---
if ($itemType === 'books') {
    $name = $fields['name']['stringValue'] ?? 'Titre non disponible';
    $category = $fields['cathegorie']['stringValue'] ?? 'Non disponible';
    $author = $fields['auteur']['stringValue'] ?? 'Non disponible';
    $description = $fields['desc']['stringValue'] ?? 'Aucune description.';
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-image.png';
    $exemplaire = $fields['exemplaire']['integerValue'] ?? $fields['exemplaire']['doubleValue'] ?? 0;
} else { // C'est un mémoire ('theses')
    $name = $fields['theme']['stringValue'] ?? 'Thème non disponible'; // Le titre est le thème
    $category = $fields['département']['stringValue'] ?? 'Non disponible'; // Le département est la catégorie
    $author = $fields['name']['stringValue'] ?? 'Auteur non disponible'; // L'auteur est dans le champ 'name'
    $superviseur = $fields['superviseur']['stringValue'] ?? 'Non spécifié';
    $matricule = $fields['matricule']['stringValue'] ?? 'Non spécifié';
    $pdfUrl = $fields['pdfUrl']['stringValue'] ?? '';
    // Pour les mémoires, on peut considérer qu'il y a toujours 1 "exemplaire" (le PDF)
    // ou utiliser le même champ 'exemplaire' si vous l'ajoutez aux mémoires.
    // Pour l'instant, on se base sur la présence d'une URL de PDF.
    $exemplaire = !empty($pdfUrl) ? 1 : 0; 
    // Image par défaut pour les mémoires, ou utilisez un champ 'image' si vous en avez un.
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-thesis.png'; 
}

$isAvailable = (int)$exemplaire > 0;

// Mettre à jour le titre de la page
$PAGE->set_title($name);
$PAGE->set_heading($name);


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

// --- CORRECTION : Affichage conditionnel des informations ---
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

} else { // Affichage pour les mémoires
    $html .= '<p class="text-muted"><em>Par ' . htmlspecialchars($author) . ' (Matricule: ' . htmlspecialchars($matricule) . ')</em></p>';
    $html .= '<hr>';
    $html .= '<p><strong>Département :</strong> ' . htmlspecialchars($category) . '</p>';
    $html .= '<p><strong>Superviseur :</strong> ' . htmlspecialchars($superviseur) . '</p>';
    if (!empty($pdfUrl)) {
        $html .= '<p class="text-success"><strong>Disponibilité :</strong> Accès en ligne</p>';
        // On pourrait ajouter un lien pour télécharger/voir le PDF
        $html .= '<a href="' . $pdfUrl . '" class="btn btn-info mt-2" target="_blank">Consulter le PDF</a>';
    } else {
        $html .= '<p class="text-danger"><strong>Disponibilité :</strong> PDF non disponible</p>';
    }
}

// Bouton de réservation (logique de réservation à adapter si besoin pour les mémoires)
$html .= '<div class="mt-4 text-center">';
if ($itemType === 'books' && $isAvailable) {
    // Le bouton de réservation n'a de sens que pour les livres physiques
    $html .= '<button id="reserveBtn" class="btn btn-primary btn-lg" data-id="' . $itemId . '" data-type="' . $itemType . '">Réserver cet ouvrage</button>';
} else if ($itemType === 'books' && !$isAvailable) {
     $html .= '<button class="btn btn-secondary btn-lg" disabled>Réservation indisponible</button>';
}
$html .= '</div>';

$html .= '</div>'; // Fin de la colonne de droite
$html .= '</div>'; // Fin de la row

echo $OUTPUT->box($html, 'p-3');


// --- JavaScript pour la réservation sur cette page (inchangé) ---
global $USER;
$userDocId = null;
// ... (le code pour récupérer userDocId est complexe et peut être simplifié)
// Simplifions en passant par une API si ce code échoue
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
    // Gérer l'échec de la récupération de l'ID utilisateur
    $userDocId = null;
}

?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reserveBtn = document.getElementById('reserveBtn');
    if (reserveBtn) {
        reserveBtn.addEventListener('click', function() {
            // ... (le reste du script JS est inchangé et fonctionne)
            const element = this;
            const itemId = element.getAttribute('data-id');
            const itemType = element.getAttribute('data-type');
            const userDocId = <?php echo json_encode($userDocId); ?>;

            if (!userDocId) {
                alert("Erreur : Impossible d'identifier l'utilisateur. Veuillez vous reconnecter.");
                return;
            }

            if (!confirm('Confirmez-vous la réservation de cet ouvrage ?')) { return; }

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
                    element.textContent = 'Réservé avec succès';
                    element.classList.remove('btn-primary');
                    element.classList.add('btn-success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Échec de la réservation : ' + data.message);
                    element.disabled = false;
                    element.textContent = 'Réserver cet ouvrage';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Une erreur réseau est survenue.');
                element.disabled = false;
                element.textContent = 'Réserver cet ouvrage';
            });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>
