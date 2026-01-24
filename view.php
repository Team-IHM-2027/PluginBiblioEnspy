<?php
/**
 * Page de détail d'un livre/mémoire avec réservation et annulation
 * 
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// === 1. RÉCUPÉRATION ET VALIDATION DES PARAMÈTRES ===
$itemId = required_param('id', PARAM_ALPHANUMEXT);
$itemType = required_param('type', PARAM_ALPHA);
$collection = ($itemType === 'books') ? 'BiblioBooks' : 'BiblioThesis';

// === 2. CONFIGURATION PAGE MOODLE ===
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/biblio_enspy/view.php', ['id' => $itemId, 'type' => $itemType]));
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/biblio_enspy/css/styles.css');

// === 3. CONFIGURATION FIRESTORE ===
$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__ . '/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];

try {
    $credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
    $accessToken = $credentials->fetchAuthToken()['access_token'];
} catch (Exception $e) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger">Erreur de connexion à Firebase: ' . htmlspecialchars($e->getMessage()) . '</p>');
    echo $OUTPUT->footer();
    exit;
}

// === 4. RÉCUPÉRATION DU DOCUMENT ===
$itemUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}/{$itemId}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $itemUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
curl_close($ch);

$itemData = json_decode($response, true);

if (!$itemData || !isset($itemData['fields'])) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger">Document introuvable.</p>');
    echo $OUTPUT->footer();
    exit;
}

$fields = $itemData['fields'];

// === 5. EXTRACTION DES DONNÉES SELON LE TYPE ===
if ($itemType === 'books') {
    $name = $fields['name']['stringValue'] ?? 'Titre non disponible';
    $category = $fields['cathegorie']['stringValue'] ?? 'Non disponible';
    $author = $fields['auteur']['stringValue'] ?? 'Non disponible';
    $description = $fields['desc']['stringValue'] ?? 'Aucune description.';
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-image.png';
    
    // Gestion des exemplaires (integerValue ou doubleValue)
    if (isset($fields['exemplaire']['integerValue'])) {
        $exemplaire = (int)$fields['exemplaire']['integerValue'];
    } else if (isset($fields['exemplaire']['doubleValue'])) {
        $exemplaire = (int)$fields['exemplaire']['doubleValue'];
    } else {
        $exemplaire = 0;
    }
    
    $isAvailable = $exemplaire > 0;
    
} else {
    // Mémoires
    $name = $fields['theme']['stringValue'] ?? 'Thème non disponible';
    $category = $fields['département']['stringValue'] ?? 'Non disponible';
    $author = $fields['name']['stringValue'] ?? 'Auteur non disponible';
    $superviseur = $fields['superviseur']['stringValue'] ?? 'Non spécifié';
    $matricule = $fields['matricule']['stringValue'] ?? 'Non spécifié';
    $pdfUrl = $fields['pdfUrl']['stringValue'] ?? '';
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-thesis.png';
    
    if (isset($fields['exemplaire']['integerValue'])) {
        $exemplaire = (int)$fields['exemplaire']['integerValue'];
    } else if (isset($fields['exemplaire']['doubleValue'])) {
        $exemplaire = (int)$fields['exemplaire']['doubleValue'];
    } else {
        $exemplaire = 1;
    }
    
    $hasPhysicalCopy = $exemplaire > 0;
}

// === 6. RÉCUPÉRATION DE L'UTILISATEUR ET DE SES RÉSERVATIONS ===
global $USER;
$userDocId = null;
$userReservationIds = [];
$currentReservationStatus = null; // 'reserv' ou 'emprunt'

try {
    $userCollectionUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser";
    $chUser = curl_init();
    curl_setopt($chUser, CURLOPT_URL, $userCollectionUrl);
    curl_setopt($chUser, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chUser, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken, 
        'Content-Type: application/json'
    ]);
    curl_setopt($chUser, CURLOPT_SSL_VERIFYPEER, true);
    $userResponse = curl_exec($chUser);
    curl_close($chUser);
    
    $userData = json_decode($userResponse, true);
    
    if (isset($userData['documents'])) {
        foreach ($userData['documents'] as $document) {
            if (isset($document['fields']['email']['stringValue']) && 
                $document['fields']['email']['stringValue'] == $USER->email) {
                
                // ID du document utilisateur
                $pathParts = explode('/', $document['name']);
                $userDocId = end($pathParts);
                
                // Extraction des IDs réservés/empruntés
                for ($i = 1; $i <= 3; $i++) {
                    $etatField = "etat{$i}";
                    $tabEtatField = "tabEtat{$i}";
                    
                    if (isset($document['fields'][$etatField]['stringValue'])) {
                        $status = $document['fields'][$etatField]['stringValue'];
                        
                        if (($status === 'reserv' || $status === 'emprunt') &&
                            isset($document['fields'][$tabEtatField]['arrayValue']['values'])) {
                            
                            $tabValues = $document['fields'][$tabEtatField]['arrayValue']['values'];
                            
                            if (count($tabValues) > 6 && isset($tabValues[6]['stringValue'])) {
                                $docId = $tabValues[6]['stringValue'];
                                $userReservationIds[] = $docId;
                                
                                // Si c'est le document actuel, mémoriser son statut
                                if ($docId === $itemId) {
                                    $currentReservationStatus = $status;
                                }
                            }
                        }
                    }
                }
                break;
            }
        }
    }
} catch (Exception $e) {
    // Erreur non critique, on continue
    error_log("Erreur récupération utilisateur: " . $e->getMessage());
}

$isAlreadyReserved = in_array($itemId, $userReservationIds);
$canCancel = ($isAlreadyReserved && $currentReservationStatus === 'reserv');

// === 7. AFFICHAGE DE LA PAGE ===
$PAGE->set_title($name);
$PAGE->set_heading($name);
echo $OUTPUT->header();

// Lien retour
$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

// === 8. CONTENU PRINCIPAL ===
?>
<div class="container-fluid">
    <div class="row">
        <!-- Colonne image -->
        <div class="col-md-4 text-center mb-4">
            <img src="<?php echo htmlspecialchars($imageUrl); ?>" 
                 class="img-fluid rounded book-detail-image" 
                 alt="<?php echo htmlspecialchars($name); ?>"
                 style="max-height: 400px; object-fit: contain;">
        </div>
        
        <!-- Colonne informations -->
        <div class="col-md-8">
            <h2 class="mb-3"><?php echo htmlspecialchars($name); ?></h2>
            
            <?php if ($itemType === 'books'): ?>
                <!-- === LIVRE === -->
                <p class="text-muted"><em>Par <?php echo htmlspecialchars($author); ?></em></p>
                <hr>
                <p><strong>Catégorie :</strong> <?php echo htmlspecialchars($category); ?></p>
                
                <!-- Disponibilité -->
                <div id="availabilitySection">
                    <?php if ($isAvailable): ?>
                        <p id="availabilityText" class="text-success">
                            <strong>Disponibilité :</strong> En stock (<?php echo $exemplaire; ?> exemplaire(s))
                        </p>
                    <?php else: ?>
                        <p id="availabilityText" class="text-danger">
                            <strong>Disponibilité :</strong> Hors stock
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- Description -->
                <div class="mt-4">
                    <h4>Description</h4>
                    <p><?php echo nl2br(htmlspecialchars($description)); ?></p>
                </div>
                
            <?php else: ?>
                <!-- === MÉMOIRE === -->
                <p class="text-muted">
                    <em>Par <?php echo htmlspecialchars($author); ?> 
                    (Matricule: <?php echo htmlspecialchars($matricule); ?>)</em>
                </p>
                <hr>
                <p><strong>Département :</strong> <?php echo htmlspecialchars($category); ?></p>
                <p><strong>Superviseur :</strong> <?php echo htmlspecialchars($superviseur); ?></p>
                
                <!-- Disponibilité mémoire -->
                <div id="availabilitySection">
                    <?php if ($hasPhysicalCopy): ?>
                        <p id="availabilityText" class="text-success">
                            <strong>Exemplaire physique :</strong> Disponible à la bibliothèque
                        </p>
                    <?php else: ?>
                        <p id="availabilityText" class="text-danger">
                            <strong>Exemplaire physique :</strong> Non disponible
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($pdfUrl)): ?>
                        <p class="text-info">
                            <strong>Version numérique :</strong> Disponible
                            <a href="<?php echo htmlspecialchars($pdfUrl); ?>" 
                               target="_blank" 
                               class="btn btn-sm btn-outline-info ml-2">
                               <i class="fa fa-file-pdf"></i> Consulter le PDF
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- === SECTION BOUTONS D'ACTION === -->
            <div class="mt-5 pt-3 border-top text-center action-buttons">
                <?php if ($isAlreadyReserved): ?>
                    <!-- DÉJÀ RÉSERVÉ/EMPRUNTÉ -->
                    <?php if ($currentReservationStatus === 'emprunt'): ?>
                        <!-- Emprunté : pas d'annulation possible -->
                        <div class="alert alert-info d-inline-block">
                            <i class="fa fa-book"></i> Vous avez emprunté ce document.
                        </div>
                        <p class="text-muted small mt-2">
                            L'annulation n'est pas possible pour les emprunts validés.
                            Veuillez retourner le document à la bibliothèque.
                        </p>
                        
                    <?php else: ?>
                        <!-- Réservé : annulation possible -->
                        <div class="alert alert-warning d-inline-block">
                            <i class="fa fa-info-circle"></i> Vous avez réservé ce document.
                        </div>
                        <br>
                        <button id="cancelBtn" 
                                class="btn btn-warning btn-lg mt-2"
                                data-id="<?php echo htmlspecialchars($itemId); ?>"
                                data-type="<?php echo htmlspecialchars($itemType); ?>"
                                data-name="<?php echo htmlspecialchars($name); ?>"
                                data-userdocid="<?php echo htmlspecialchars($userDocId); ?>">
                            <i class="fa fa-times-circle"></i> Annuler la réservation
                        </button>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- NON RÉSERVÉ : bouton de réservation -->
                    <?php 
                    $canReserve = ($itemType === 'books') ? $isAvailable : $hasPhysicalCopy;
                    $buttonText = ($itemType === 'books') ? 'Réserver cet ouvrage' : 'Réserver l\'exemplaire physique';
                    ?>
                    
                    <?php if ($canReserve): ?>
                        <button id="reserveBtn" 
                                class="btn btn-primary btn-lg"
                                data-id="<?php echo htmlspecialchars($itemId); ?>"
                                data-type="<?php echo htmlspecialchars($itemType); ?>"
                                data-name="<?php echo htmlspecialchars($name); ?>"
                                data-userdocid="<?php echo htmlspecialchars($userDocId); ?>">
                            <i class="fa fa-bookmark"></i> <?php echo $buttonText; ?>
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg" disabled>
                            <i class="fa fa-ban"></i> 
                            <?php echo ($itemType === 'books') ? 'Hors stock' : 'Exemplaire physique indisponible'; ?>
                        </button>
                        <p class="text-muted small mt-2">
                            Ce document n'est pas disponible pour le moment.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <!-- FIN SECTION BOUTONS -->
            
        </div>
    </div>
</div>

<!-- === CLASSE HELPER POUR NOTIFICATIONS MOODLE === -->
<script>
/**
 * MoodleNotificationHelper - Gestion des notifications avec l'API Moodle
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
                    () => resolve({ confirmed: true }),
                    () => resolve({ confirmed: false })
                );
            } else {
                try {
                    if (title) {
                        Notification.alert(fullTitle, message, type);
                    } else {
                        throw 'no-title'; 
                    }
                } catch (e) {
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

    static success(m, t) { return this.show(m, 'success', t); }
    static error(m, t)   { return this.show(m, 'error', t); }
    static info(m, t)    { return this.show(m, 'info', t); }
    static warning(m, t) { return this.show(m, 'warning', t); }
    static confirm(m, t, opts) { return this.show(m, 'confirm', t, opts); }
}

// === DONNÉES INJECTÉES DEPUIS PHP ===
const PAGE_DATA = {
    userDocId: <?php echo $userDocId ? json_encode($userDocId) : 'null'; ?>,
    itemId: <?php echo json_encode($itemId); ?>,
    itemType: <?php echo json_encode($itemType); ?>,
    itemName: <?php echo json_encode($name); ?>,
    exemplaire: <?php echo json_encode($exemplaire); ?>
};
</script>

<!-- === GESTION RÉSERVATION ET ANNULATION === -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const reserveBtn = document.getElementById('reserveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    let localExemplaireCount = PAGE_DATA.exemplaire;

    // === FONCTION DE RÉSERVATION ===
    if (reserveBtn) {
        reserveBtn.addEventListener('click', async function() {
            if (!PAGE_DATA.userDocId) {
                await MoodleNotificationHelper.error(
                    'Impossible d\'identifier l\'utilisateur. Veuillez vous reconnecter.',
                    'Erreur d\'identification'
                );
                return;
            }

            const confirmMessage = (PAGE_DATA.itemType === 'books')
                ? `Vous êtes sur le point de réserver :<br><strong>${PAGE_DATA.itemName}</strong>`
                : `Vous êtes sur le point de réserver l'exemplaire physique de :<br><strong>${PAGE_DATA.itemName}</strong>`;

            const confirmation = await MoodleNotificationHelper.confirm(
                confirmMessage, 
                'Confirmation de réservation'
            );

            if (!confirmation || !confirmation.confirmed) {
                return;
            }

            reserveBtn.disabled = true;
            reserveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> En cours...';

            try {
                const response = await fetch('api_reserve.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: PAGE_DATA.itemId,
                        itemType: PAGE_DATA.itemType,
                        userDocId: PAGE_DATA.userDocId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    await MoodleNotificationHelper.success(
                        'Votre réservation a été enregistrée avec succès !',
                        'Réservation réussie'
                    );

                    reserveBtn.innerHTML = '<i class="fa fa-check"></i> Réservé';
                    reserveBtn.className = 'btn btn-secondary btn-lg';
                    reserveBtn.disabled = true;

                    // Mise à jour du compteur
                    if (localExemplaireCount > 0) {
                        localExemplaireCount--;
                        updateAvailabilityDisplay(localExemplaireCount);
                    }

                    setTimeout(() => location.reload(), 1500);

                } else {
                    await MoodleNotificationHelper.error(
                        data.message || 'Une erreur est survenue lors de la réservation.',
                        'Échec de la réservation'
                    );
                    resetReserveButton();
                }

            } catch (error) {
                console.error('Erreur:', error);
                await MoodleNotificationHelper.error(
                    'Erreur réseau. Veuillez réessayer.',
                    'Erreur réseau'
                );
                resetReserveButton();
            }
        });
    }

    // === FONCTION D'ANNULATION ===
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function() {
            if (!PAGE_DATA.userDocId) {
                await MoodleNotificationHelper.error(
                    'Impossible d\'identifier l\'utilisateur.',
                    'Erreur'
                );
                return;
            }

            const confirmation = await MoodleNotificationHelper.confirm(
                `Voulez-vous vraiment annuler la réservation de :<br><strong>${PAGE_DATA.itemName}</strong> ?<br><br>` +
                `Cette action est irréversible.`,
                'Confirmation d\'annulation',
                {
                    confirmText: 'Oui, annuler',
                    cancelText: 'Non, conserver'
                }
            );

            if (!confirmation || !confirmation.confirmed) {
                return;
            }

            cancelBtn.disabled = true;
            cancelBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Annulation...';

            try {
                const response = await fetch('api_cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: PAGE_DATA.itemId,
                        itemType: PAGE_DATA.itemType,
                        userDocId: PAGE_DATA.userDocId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    await MoodleNotificationHelper.success(
                        'Réservation annulée avec succès !',
                        'Annulation réussie'
                    );

                    cancelBtn.innerHTML = '<i class="fa fa-check"></i> Annulée';
                    cancelBtn.className = 'btn btn-secondary btn-lg mt-2';
                    cancelBtn.disabled = true;

                    // Mise à jour du compteur
                    localExemplaireCount++;
                    updateAvailabilityDisplay(localExemplaireCount);

                    setTimeout(() => location.reload(), 1500);

                } else {
                    await MoodleNotificationHelper.error(
                        data.message || 'Erreur lors de l\'annulation',
                        'Échec d\'annulation'
                    );
                    resetCancelButton();
                }

            } catch (error) {
                console.error('Erreur:', error);
                await MoodleNotificationHelper.error(
                    'Erreur réseau. Veuillez réessayer.',
                    'Erreur réseau'
                );
                resetCancelButton();
            }
        });
    }

    // === FONCTIONS HELPER ===
    function updateAvailabilityDisplay(count) {
        const availabilityEl = document.getElementById('availabilityText');
        if (!availabilityEl) return;

        if (PAGE_DATA.itemType === 'books') {
            if (count > 0) {
                availabilityEl.className = 'text-success';
                availabilityEl.innerHTML = `<strong>Disponibilité :</strong> En stock (${count} exemplaire(s))`;
            } else {
                availabilityEl.className = 'text-danger';
                availabilityEl.innerHTML = '<strong>Disponibilité :</strong> Hors stock';
            }
        } else {
            if (count > 0) {
                availabilityEl.className = 'text-success';
                availabilityEl.innerHTML = '<strong>Exemplaire physique :</strong> Disponible à la bibliothèque';
            } else {
                availabilityEl.className = 'text-danger';
                availabilityEl.innerHTML = '<strong>Exemplaire physique :</strong> Non disponible';
            }
        }
    }

    function resetReserveButton() {
        const buttonText = (PAGE_DATA.itemType === 'books') 
            ? 'Réserver cet ouvrage' 
            : 'Réserver l\'exemplaire';
        reserveBtn.disabled = false;
        reserveBtn.innerHTML = `<i class="fa fa-bookmark"></i> ${buttonText}`;
        reserveBtn.className = 'btn btn-primary btn-lg';
    }

    function resetCancelButton() {
        cancelBtn.disabled = false;
        cancelBtn.innerHTML = '<i class="fa fa-times-circle"></i> Annuler la réservation';
        cancelBtn.className = 'btn btn-warning btn-lg mt-2';
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>