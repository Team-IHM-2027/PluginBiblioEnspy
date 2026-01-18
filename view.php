<?php
/**
 * Page de détail d'un livre/mémoire - Plugin BiblioEnspy
 * Version avec fonctionnalité d'annulation
 */

require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// === 1. RÉCUPÉRATION DES PARAMÈTRES ===
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
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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

// === 5. EXTRACTION DES DONNÉES ===
if ($itemType === 'books') {
    $name = $fields['name']['stringValue'] ?? 'Titre non disponible';
    $category = $fields['cathegorie']['stringValue'] ?? 'Non disponible';
    $author = $fields['auteur']['stringValue'] ?? 'Non disponible';
    $description = $fields['desc']['stringValue'] ?? 'Aucune description.';
    $imageUrl = $fields['image']['stringValue'] ?? 'images/default-image.png';
    
    // Exemplaires
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

// === 6. RÉCUPÉRATION UTILISATEUR ET RÉSERVATIONS ===
global $USER;
$userDocId = null;
$userReservationIds = [];

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
            if (isset($document['fields']['email']['stringValue']) && 
                $document['fields']['email']['stringValue'] == $USER->email) {
                
                // ID du document utilisateur
                $pathParts = explode('/', $document['name']);
                $userDocId = end($pathParts);
                
                // Extraction des IDs réservés
                for ($i = 1; $i <= 3; $i++) {
                    $etatField = "etat{$i}";
                    $tabEtatField = "tabEtat{$i}";
                    
                    if (isset($document['fields'][$etatField]['stringValue'])) {
                        $status = $document['fields'][$etatField]['stringValue'];
                        
                        if (($status === 'reserv' || $status === 'emprunt') &&
                            isset($document['fields'][$tabEtatField]['arrayValue']['values'])) {
                            
                            $tabValues = $document['fields'][$tabEtatField]['arrayValue']['values'];
                            
                            if (count($tabValues) > 6 && isset($tabValues[6]['stringValue'])) {
                                $userReservationIds[] = $tabValues[6]['stringValue'];
                            }
                        }
                    }
                }
                break;
            }
        }
    }
} catch (Exception $e) {
    // Erreur non critique
    error_log("Erreur récupération utilisateur: " . $e->getMessage());
}

$isAlreadyReserved = in_array($itemId, $userReservationIds);

// === 7. AFFICHAGE DE LA PAGE ===
$PAGE->set_title($name);
$PAGE->set_heading($name);
echo $OUTPUT->header();

// Lien retour
$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

// === HTML PRINCIPAL ===
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
                <!-- LIVRE -->
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
                <!-- MÉMOIRE -->
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
                    <!-- DÉJÀ RÉSERVÉ - Afficher bouton ANNULATION -->
                    <div class="alert alert-warning d-inline-block">
                        <i class="fa fa-info-circle"></i> Vous avez déjà réservé ce document.
                    </div>
                    <br>
                    <button id="cancelBtn" 
                            class="btn btn-warning btn-lg mt-2"
                            data-id="<?php echo htmlspecialchars($itemId); ?>"
                            data-type="<?php echo htmlspecialchars($itemType); ?>"
                            data-name="<?php echo htmlspecialchars($name); ?>">
                        <i class="fa fa-times-circle"></i> Annuler la réservation
                    </button>
                    
                <?php else: ?>
                    <!-- NON RÉSERVÉ - Afficher bouton RÉSERVATION ou INDISPONIBLE -->
                    <?php 
                    $canReserve = ($itemType === 'books') ? $isAvailable : $hasPhysicalCopy;
                    $buttonText = ($itemType === 'books') ? 'Réserver cet ouvrage' : 'Réserver l\'exemplaire physique';
                    ?>
                    
                    <?php if ($canReserve): ?>
                        <button id="reserveBtn" 
                                class="btn btn-primary btn-lg"
                                data-id="<?php echo htmlspecialchars($itemId); ?>"
                                data-type="<?php echo htmlspecialchars($itemType); ?>"
                                data-name="<?php echo htmlspecialchars($name); ?>">
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

<!-- === JAVASCRIPT - GESTION RÉSERVATION/ANNULATION === -->
<script>
// Données injectées depuis PHP
const userDocId = <?php echo $userDocId ? json_encode($userDocId) : 'null'; ?>;
const currentItemId = <?php echo json_encode($itemId); ?>;
const currentItemType = <?php echo json_encode($itemType); ?>;
const currentItemName = <?php echo json_encode($name); ?>;
let localExemplaireCount = <?php echo isset($exemplaire) ? json_encode($exemplaire) : '1'; ?>;

// Helper de notification Moodle (simplifié)
class MoodleNotificationHelper {
    static async show(message, type = 'info', title = '') {
        return new Promise((resolve) => {
            if (type === 'confirm') {
                if (confirm(title + '\n\n' + message)) {
                    resolve({ confirmed: true });
                } else {
                    resolve({ confirmed: false });
                }
            } else {
                alert(title + ': ' + message);
                resolve({ shown: true });
            }
        });
    }
    
    static async confirm(message, title) {
        return this.show(message, 'confirm', title);
    }
    
    static async success(message, title = 'Succès') {
        return this.show(message, 'info', title);
    }
    
    static async error(message, title = 'Erreur') {
        return this.show(message, 'info', title);
    }
}

// === GESTION DE LA RÉSERVATION ===
document.addEventListener('DOMContentLoaded', function() {
    const reserveBtn = document.getElementById('reserveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    
    // --- RÉSERVATION ---
    if (reserveBtn) {
        reserveBtn.addEventListener('click', async function() {
            if (!userDocId) {
                await MoodleNotificationHelper.error(
                    'Vous devez être connecté pour réserver.',
                    'Connexion requise'
                );
                return;
            }
            
            const confirmed = await MoodleNotificationHelper.confirm(
                `Voulez-vous réserver :\n"${currentItemName}" ?`,
                'Confirmation de réservation'
            );
            
            if (!confirmed) return;
            
            // Désactiver bouton
            reserveBtn.disabled = true;
            reserveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> En cours...';
            
            try {
                const response = await fetch('api_reserve.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: currentItemId,
                        itemType: currentItemType,
                        userDocId: userDocId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await MoodleNotificationHelper.success(
                        'Réservation effectuée avec succès !',
                        'Réservation réussie'
                    );
                    
                    // Mettre à jour l'interface
                    reserveBtn.innerHTML = '<i class="fa fa-check"></i> Réservé';
                    reserveBtn.className = 'btn btn-secondary btn-lg mt-2';
                    reserveBtn.disabled = true;
                    
                    // Mettre à jour le compteur
                    if (localExemplaireCount > 0) {
                        localExemplaireCount--;
                        const availabilityEl = document.getElementById('availabilityText');
                        if (availabilityEl) {
                            if (localExemplaireCount > 0) {
                                availabilityEl.textContent = `Disponibilité : En stock (${localExemplaireCount} exemplaire(s))`;
                                availabilityEl.className = 'text-success';
                            } else {
                                availabilityEl.textContent = 'Disponibilité : Hors stock';
                                availabilityEl.className = 'text-danger';
                            }
                        }
                    }
                    
                    // Recharger après 2 secondes
                    setTimeout(() => location.reload(), 2000);
                    
                } else {
                    await MoodleNotificationHelper.error(
                        data.message || 'Erreur lors de la réservation',
                        'Échec'
                    );
                    reserveBtn.disabled = false;
                    reserveBtn.innerHTML = '<i class="fa fa-bookmark"></i> ' + 
                        (currentItemType === 'books' ? 'Réserver cet ouvrage' : 'Réserver l\'exemplaire physique');
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                await MoodleNotificationHelper.error(
                    'Erreur réseau. Veuillez réessayer.',
                    'Erreur réseau'
                );
                reserveBtn.disabled = false;
                reserveBtn.innerHTML = '<i class="fa fa-bookmark"></i> ' + 
                    (currentItemType === 'books' ? 'Réserver cet ouvrage' : 'Réserver l\'exemplaire physique');
            }
        });
    }
    
    // --- ANNULATION ---
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function() {
            if (!userDocId) {
                await MoodleNotificationHelper.error(
                    'Vous devez être connecté pour annuler.',
                    'Connexion requise'
                );
                return;
            }
            
            const confirmed = await MoodleNotificationHelper.confirm(
                `Voulez-vous vraiment annuler la réservation de :\n"${currentItemName}" ?`,
                'Confirmation d\'annulation'
            );
            
            if (!confirmed) return;
            
            // Désactiver bouton
            cancelBtn.disabled = true;
            cancelBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Annulation en cours...';
            
            try {
                const response = await fetch('api_cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: currentItemId,
                        itemType: currentItemType,
                        userDocId: userDocId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    await MoodleNotificationHelper.success(
                        'Réservation annulée avec succès !',
                        'Annulation réussie'
                    );
                    
                    // Mettre à jour l'interface
                    cancelBtn.innerHTML = '<i class="fa fa-check"></i> Réservation annulée';
                    cancelBtn.className = 'btn btn-secondary btn-lg mt-2';
                    cancelBtn.disabled = true;
                    
                    // Mettre à jour le compteur
                    localExemplaireCount++;
                    const availabilityEl = document.getElementById('availabilityText');
                    if (availabilityEl) {
                        if (currentItemType === 'books') {
                            availabilityEl.textContent = `Disponibilité : En stock (${localExemplaireCount} exemplaire(s))`;
                        } else {
                            availabilityEl.textContent = 'Exemplaire physique : Disponible à la bibliothèque';
                        }
                        availabilityEl.className = 'text-success';
                    }
                    
                    // Recharger après 2 secondes
                    setTimeout(() => location.reload(), 2000);
                    
                } else {
                    await MoodleNotificationHelper.error(
                        data.message || 'Erreur lors de l\'annulation',
                        'Échec d\'annulation'
                    );
                    cancelBtn.disabled = false;
                    cancelBtn.innerHTML = '<i class="fa fa-times-circle"></i> Annuler la réservation';
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                await MoodleNotificationHelper.error(
                    'Erreur réseau. Veuillez réessayer.',
                    'Erreur réseau'
                );
                cancelBtn.disabled = false;
                cancelBtn.innerHTML = '<i class="fa fa-times-circle"></i> Annuler la réservation';
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
?>