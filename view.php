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
    // exemplaire peut être integerValue ou doubleValue ou absent
    if (isset($fields['exemplaire']['integerValue'])) {
        $exemplaire = (int)$fields['exemplaire']['integerValue'];
    } else if (isset($fields['exemplaire']['doubleValue'])) {
        $exemplaire = (int)$fields['exemplaire']['doubleValue'];
    } else {
        $exemplaire = 0;
    }
} else { // C'est un mémoire ('theses')
    $name = $fields['theme']['stringValue'] ?? 'Thème non disponible';
    $category = $fields['département']['stringValue'] ?? 'Non disponible';
    $author = $fields['name']['stringValue'] ?? 'Auteur non disponible';
    $superviseur = $fields['superviseur']['stringValue'] ?? 'Non spécifié';
    $matricule = $fields['matricule']['stringValue'] ?? 'Non spécifié';
    $pdfUrl = $fields['pdfUrl']['stringValue'] ?? '';
    if (isset($fields['exemplaire']['integerValue'])) {
        $exemplaire = (int)$fields['exemplaire']['integerValue'];
    } else if (isset($fields['exemplaire']['doubleValue'])) {
        $exemplaire = (int)$fields['exemplaire']['doubleValue'];
    } else {
        $exemplaire = 1;
    }
    $hasPhysicalCopy = (int)$exemplaire > 0;
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-thesis.png';
}

// Pour les livres, la disponibilité est basée sur les exemplaires
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

function extractReservationIdsFromUserData($userFields) {
    $reservationIds = [];
    $maxReservations = 3; 
    
    for ($i = 1; $i <= $maxReservations; $i++) { 
        $etatField = "etat{$i}";
        $tabEtatField = "tabEtat{$i}";
        
        if (isset($userFields[$etatField]['stringValue'])) {
            $currentStatus = $userFields[$etatField]['stringValue'];
            
            // On considère comme "occupé" si c'est réservé ou emprunté
            if (($currentStatus === 'reserv' || $currentStatus === 'emprunt') &&
                isset($userFields[$tabEtatField]['arrayValue']['values'])) {
                
                $tabEtatValues = $userFields[$tabEtatField]['arrayValue']['values'];
                
                // Indice 6 correspond au docId selon ta structure
                if (count($tabEtatValues) > 6 && isset($tabEtatValues[6]['stringValue'])) {
                    $reservationIds[] = $tabEtatValues[6]['stringValue'];
                }
            }
        }
    }
    return $reservationIds;
}

$userDocId = null;
$userReservationIds = []; 

if (isset($userData['documents'])) {
    foreach ($userData['documents'] as $document) {
        if (isset($document['fields']['email']['stringValue']) && $document['fields']['email']['stringValue'] == $USER->email) {
            $pathParts = explode('/', $document['name']);
            $userDocId = end($pathParts);
            $userReservationIds = extractReservationIdsFromUserData($document['fields']);
            break;
        }
    }
}

// Vérification cruciale pour le bouton :
$isAlreadyReserved = in_array($itemId, $userReservationIds);

// 6. Affichage de la page
echo $OUTPUT->header();

$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

$html = '<div class="row">';

// Colonne de gauche pour l'image
$html .= '<div class="col-md-4 text-center">';
$html .= '<img src="' . htmlspecialchars($imageUrl) . '" class="img-fluid rounded book-detail-image" alt="' . htmlspecialchars($name) . '">';
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
        $html .= '<p id="availabilityText" class="text-success"><strong>Disponibilité :</strong> En stock (' . (int)$exemplaire . ' exemplaire(s) restant(s))</p>';
    } else {
        $html .= '<p id="availabilityText" class="text-danger"><strong>Disponibilité :</strong> Hors stock</p>';
    }
    $html .= '<div class="mt-4"><h4>Description</h4><p>' . nl2br(htmlspecialchars($description)) . '</p></div>';

} else { // Affichage pour les mémoires (AMÉLIORÉ)
    $html .= '<p class="text-muted"><em>Par ' . htmlspecialchars($author) . ' (Matricule: ' . htmlspecialchars($matricule) . ')</em></p>';
    $html .= '<hr>';
    $html .= '<p><strong>Département :</strong> ' . htmlspecialchars($category) . '</p>';
    $html .= '<p><strong>Superviseur :</strong> ' . htmlspecialchars($superviseur) . '</p>';
    
    // NOUVEAU : Affichage séparé des deux types de disponibilité
    $html .= '<div class="availability-section">';
    if ($hasPhysicalCopy) {
        $html .= '<p id="availabilityText" class="text-success"><strong>Exemplaire physique :</strong> Disponible à la bibliothèque</p>';
    } else {
        $html .= '<p id="availabilityText" class="text-danger"><strong>Exemplaire physique :</strong> Non disponible</p>';
    }
    $html .= '</div>';
}

// NOUVELLE SECTION : Boutons d'action
$html .= '<div class="mt-4 text-center action-buttons">';

if ($isAlreadyReserved) {
    // Bouton grisé si déjà réservé par l'utilisateur (Logique switchBooks.js)
    $html .= '<button class="btn btn-secondary btn-lg" disabled style="cursor: not-allowed; opacity: 0.6;">';
    $html .= '<i class="fa fa-check-circle"></i> Déjà réservé';
    $html .= '</button>';
} else {
    // Bouton normal si disponible ou bouton désactivé si hors stock
    if ($itemType === 'books') {
        if ($isAvailable) {
            $html .= '<button id="reserveBtn" class="btn btn-primary btn-lg" data-id="' . htmlspecialchars($itemId) . '" data-type="' . htmlspecialchars($itemType) . '">Réserver cet ouvrage</button>';
        } else {
            $html .= '<button class="btn btn-secondary btn-lg" disabled>Hors stock</button>';
        }
    } else { // Pour les thèses
        if ($hasPhysicalCopy) {
            $html .= '<button id="reserveBtn" class="btn btn-primary btn-lg" data-id="' . htmlspecialchars($itemId) . '" data-type="' . htmlspecialchars($itemType) . '">Réserver l\'exemplaire</button>';
        } else {
            $html .= '<button class="btn btn-secondary btn-lg" disabled>Exemplaire physique indisponible</button>';
        }
    }
}

$html .= '</div>'; // Fin des boutons d'action

$html .= '</div>'; // Fin de la colonne de droite
$html .= '</div>'; // Fin de la row

echo $OUTPUT->box($html, 'p-3');
?>

<!--
  Inclusion de la classe MoodleNotificationHelper (identique à celle utilisée dans switchbooks.js)
  Elle est déclarée ici pour être disponible dans les scripts embarqués ci-dessous.
-->
<script>
/**
 * MoodleNotificationHelper
 * Classe utilitaire pour afficher des notifications et boîtes de confirmation
 * men utilisant core/notification de Moodle.
 */
class MoodleNotificationHelper {
    static async show(message, type = 'info', title = '', options = {}) {
        const Notification = await new Promise(resolve => require(['core/notification'], resolve));
        
        const icons = {
            success: 'fa-check-circle text-success',
            error:   'fa-times-circle text-danger',
            warning: 'fa-exclamation-triangle text-warning',
            info:    'fa-info-circle text-info',
            confirm: 'fa-question-circle text-primary'
        };

        const iconHtml = `<i class="fa ${icons[type] || icons.info}" aria-hidden="true"></i> `;
        const fullTitle = title ? (iconHtml + title) : '';

        return new Promise((resolve) => {
            if (type === 'confirm') {
                Notification.confirm(
                    fullTitle || 'Confirmation',
                    message,
                    options.confirmText || 'Confirmer',
                    options.cancelText || 'Annuler',
                    (confirmed) => resolve({ confirmed })
                );
            } else {
                try {
                    // Tente l'alerte modale si un titre existe
                    if (title) {
                        Notification.alert(fullTitle, message, type);
                    } else {
                        throw 'no-title'; 
                    }
                } catch (e) {
                    // Fallback vers notification flottante (Toast)
                    Notification.addNotification({
                        message: fullTitle ? `<strong>${fullTitle}</strong><br>${message}` : message,
                        type: type,
                        announce: true
                    });
                }
                resolve({ shown: true });
            }
        });
    }

    // Un seul point d'entrée pour les appels rapides
    static success(m, t) { return this.show(m, 'success', t); }
    static error(m, t)   { return this.show(m, 'error', t); }
    static info(m, t)    { return this.show(m, 'info', t); }
    static warning(m, t) { return this.show(m, 'warning', t); }
    static confirm(m, t) { return this.show(m, 'confirm', t); }
}
</script>

<script>


document.addEventListener('DOMContentLoaded', function() {
    const reserveBtn = document.getElementById('reserveBtn');
    // données injectées depuis PHP
    const userDocId = <?php echo json_encode($userDocId); ?>;
    const itemId = <?php echo json_encode($itemId); ?>;
    const itemType = <?php echo json_encode($itemType); ?>;
    const frontendItemName = <?php echo json_encode(addslashes($name)); ?>;
    // état local de l'exemplaire (affichage)
    let localExemplaireCount = <?php echo json_encode((int)$exemplaire); ?>;

    if (reserveBtn) {
        reserveBtn.addEventListener('click', async function() {
            const element = this;

            if (!userDocId) {
                // Utiliser le helper de notification au lieu d'alert
                await MoodleNotificationHelper.error(
                    'Impossible d\'identifier l\'utilisateur. Veuillez vous reconnecter.',
                    'Erreur d\'identification'
                );
                return;
            }

            // Message de confirmation adapté selon le type
            const confirmMessage = (itemType === 'books')
                ? `Vous êtes sur le point de réserver :<br><strong>${frontendItemName}</strong>`
                : `Vous êtes sur le point de réserver l'exemplaire physique de :<br><strong>${frontendItemName}</strong>`;

            // Utilisation du helper de confirmation (renvoie {confirmed: true/false})
            const confirmation = await MoodleNotificationHelper.confirm(confirmMessage, 'Confirmation de réservation');

            if (!confirmation || !confirmation.confirmed) {
                // L'utilisateur a annulé
                return;
            }

            // Sauvegarder l'état original du bouton pour restauration éventuelle
            const originalText = element.textContent;
            const originalClass = element.className;
            const originalDisabled = element.disabled;

            // Mettre à jour l'affichage du bouton pendant l'opération
            element.disabled = true;
            element.textContent = 'En cours...';
            element.style.cursor = 'wait';

            try {
                const reservationData = {
                    itemId: itemId,
                    itemType: itemType,
                    userDocId: userDocId
                };

                const response = await fetch('api_reserve.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(reservationData)
                });

                const data = await response.json();

                if (data.success) {
                    // Notification de succès via helper
                    await MoodleNotificationHelper.success(
                        'Votre réservation a été enregistrée avec succès !',
                        'Réservation réussie'
                    );

                    // Mettre à jour le bouton localement pour indiquer la réussite
                    element.textContent = (itemType === 'books') ? 'Réservé' : 'Exemplaire réservé';
                    element.classList.remove('btn-primary');
                    element.classList.add('btn-secondary');
                    element.disabled = true;
                    element.style.cursor = 'not-allowed';
                    element.style.opacity = '0.8';

                    // Mettre à jour le texte de disponibilité affiché si présent
                    try {
                        // décrémente le compteur local et met à jour l'affichage
                        if (typeof localExemplaireCount === 'number') {
                            localExemplaireCount = Math.max(0, localExemplaireCount - 1);
                            const availabilityEl = document.getElementById('availabilityText');
                            if (availabilityEl) {
                                if (localExemplaireCount > 0) {
                                    availabilityEl.classList.remove('text-danger');
                                    availabilityEl.classList.add('text-success');
                                    availabilityEl.innerHTML = '<strong>Disponibilité :</strong> En stock (' + localExemplaireCount + ' exemplaire(s) restant(s))';
                                } else {
                                    availabilityEl.classList.remove('text-success');
                                    availabilityEl.classList.add('text-danger');
                                    availabilityEl.innerHTML = '<strong>Disponibilité :</strong> Hors stock';
                                }
                            }
                        }
                    } catch (e) {
                        // Si la mise à jour échoue, on ignore (non critique)
                        console.warn('Échec mise à jour disponibilité locale', e);
                    }

                    // Petite temporisation puis rechargement (optionnel)
                    setTimeout(() => {
                        // On peut recharger pour refléter l'état serveur ; commenter si inutile
                        location.reload();
                    }, 900);

                } else {
                    // Échec côté serveur -> notification d'erreur
                    await MoodleNotificationHelper.error(
                        data.message || 'Une erreur est survenue lors de la réservation.',
                        'Échec de la réservation'
                    );

                    // Restaurer le bouton
                    element.disabled = originalDisabled;
                    element.textContent = originalText;
                    element.className = originalClass;
                    element.style.cursor = '';
                }

            } catch (error) {
                console.error('Erreur lors de la requête de réservation :', error);
                await MoodleNotificationHelper.error(
                    'Une erreur réseau est survenue. Veuillez vérifier votre connexion et réessayer.',
                    'Erreur réseau'
                );

                // Restaurer le bouton
                element.disabled = false;
                element.textContent = originalText;
                element.className = originalClass;
                element.style.cursor = '';
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>