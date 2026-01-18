<?php
/**
 * Page "Mes Réservations" - Plugin BiblioEnspy
 * Version avec boutons d'annulation
 */

require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// === CONFIGURATION PAGE ===
require_login();
global $USER, $OUTPUT, $PAGE;
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/biblio_enspy/my_reservations.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes Réservations & Emprunts');
$PAGE->set_heading('Mes Réservations et Emprunts');

// === CONFIGURATION FIRESTORE ===
$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__ . '/firebase_credentials.json';

if (!file_exists($serviceAccountJson)) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger">Fichier Firebase credentials introuvable.</p>');
    echo $OUTPUT->footer();
    exit;
}

$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);

try {
    $accessToken = $credentials->fetchAuthToken()['access_token'];
} catch (Exception $e) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger">Erreur Firebase: ' . htmlspecialchars($e->getMessage()) . '</p>');
    echo $OUTPUT->footer();
    exit;
}

// === RÉCUPÉRATION DONNÉES UTILISATEUR ===
$usersUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $usersUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$userData = json_decode($response, true);
$userFields = null;
$userDocId = null;

// Trouver l'utilisateur courant
if (isset($userData['documents'])) {
    foreach ($userData['documents'] as $document) {
        if (isset($document['fields']['email']['stringValue']) && 
            $document['fields']['email']['stringValue'] == $USER->email) {
            
            $userFields = $document['fields'];
            $pathParts = explode('/', $document['name']);
            $userDocId = end($pathParts);
            break;
        }
    }
}

// === EXTRACTION DES RÉSERVATIONS ET EMPRUNTS ===
$reservations = []; // Réservations en cours
$emprunts = [];     // Emprunts en cours
$maxSlots = 3;

if ($userFields) {
    for ($i = 1; $i <= $maxSlots; $i++) {
        $statusField = "etat{$i}";
        $detailsField = "tabEtat{$i}";
        
        $status = $userFields[$statusField]['stringValue'] ?? 'ras';
        
        if ($status === 'reserv' || $status === 'emprunt') {
            $details = $userFields[$detailsField]['arrayValue']['values'] ?? [];
            
            if (count($details) >= 7) {
                $dateValue = $details[5]['timestampValue'] ?? null;
                $dateStr = $dateValue ? date('d/m/Y H:i', strtotime($dateValue)) : 'Date inconnue';
                
                $item = [
                    'slot' => $i,
                    'status' => $status,
                    'name' => $details[0]['stringValue'] ?? 'Sans titre',
                    'categorie' => $details[1]['stringValue'] ?? '',
                    'image' => $details[2]['stringValue'] ?? '',
                    'collection' => $details[4]['stringValue'] ?? 'BiblioBooks',
                    'date' => $dateStr,
                    'docId' => $details[6]['stringValue'] ?? null
                ];
                
                if ($status === 'reserv') {
                    $reservations[] = $item;
                } else {
                    $emprunts[] = $item;
                }
            }
        }
    }
}

// === AFFICHAGE DE LA PAGE ===
echo $OUTPUT->header();

// Lien retour
$backUrl = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($backUrl, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

// === FONCTION D'AFFICHAGE D'UNE LISTE ===
function afficherListe($items, $titre, $typeCouleur, $estEmprunt = false) {
    global $userDocId;
    
    $html = '<div class="card mb-4">';
    $html .= '<div class="card-header bg-' . $typeCouleur . ' text-white">';
    $html .= '<h4 class="mb-0"><i class="fa fa-' . ($estEmprunt ? 'book' : 'clock') . '"></i> ' . $titre . '</h4>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    
    if (empty($items)) {
        $html .= '<p class="text-center text-muted">Aucun document.</p>';
    } else {
        $html .= '<div class="list-group">';
        
        foreach ($items as $index => $item) {
            $detailUrl = new moodle_url('/local/biblio_enspy/view.php', [
                'id' => $item['docId'],
                'type' => ($item['collection'] === 'BiblioThesis') ? 'theses' : 'books'
            ]);
            
            $html .= '<div class="list-group-item" id="item-' . $index . '">';
            $html .= '<div class="d-flex w-100 justify-content-between">';
            $html .= '<div>';
            $html .= '<h5 class="mb-1">' . htmlspecialchars($item['name']) . '</h5>';
            $html .= '<p class="mb-1">';
            $html .= '<span class="badge badge-' . $typeCouleur . '">';
            $html .= $estEmprunt ? 'Emprunté' : 'Réservé';
            $html .= '</span>';
            $html .= ' <small class="text-muted">' . htmlspecialchars($item['categorie']) . '</small>';
            $html .= '</p>';
            $html .= '<small>Réservé le : ' . $item['date'] . '</small>';
            $html .= '</div>';
            
            $html .= '<div class="btn-group">';
            $html .= '<a href="' . $detailUrl . '" class="btn btn-sm btn-outline-' . $typeCouleur . '">';
            $html .= '<i class="fa fa-eye"></i> Détails';
            $html .= '</a>';
            
            // Bouton ANNULATION uniquement pour les réservations
            if (!$estEmprunt && $item['docId'] && $userDocId) {
                $html .= '<button class="btn btn-sm btn-outline-danger ml-2 btn-annuler" ';
                $html .= 'data-docid="' . htmlspecialchars($item['docId']) . '" ';
                $html .= 'data-name="' . htmlspecialchars($item['name']) . '" ';
                $html .= 'data-index="' . $index . '" ';
                $html .= 'title="Annuler cette réservation">';
                $html .= '<i class="fa fa-times"></i> Annuler';
                $html .= '</button>';
            }
            
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div></div>';
    return $html;
}

// Affichage des sections
echo afficherListe($emprunts, 'Documents Empruntés', 'success', true);
echo afficherListe($reservations, 'Réservations en Cours', 'warning', false);

if (empty($emprunts) && empty($reservations)) {
    echo '<div class="alert alert-info text-center">';
    echo '<i class="fa fa-info-circle fa-2x mb-3"></i><br>';
    echo '<h4>Vous n\'avez aucune réservation ou emprunt actif</h4>';
    echo '<p class="mb-0">Visitez la bibliothèque pour découvrir les documents disponibles.</p>';
    echo '</div>';
}

// === JAVASCRIPT POUR L'ANNULATION ===
?>
<script>
// Données PHP injectées
const userDocId = <?php echo $userDocId ? json_encode($userDocId) : 'null'; ?>;

// Gestion des boutons d'annulation
document.addEventListener('DOMContentLoaded', function() {
    const boutonsAnnuler = document.querySelectorAll('.btn-annuler');
    
    boutonsAnnuler.forEach(btn => {
        btn.addEventListener('click', async function() {
            const docId = this.getAttribute('data-docid');
            const nomDoc = this.getAttribute('data-name');
            const index = this.getAttribute('data-index');
            
            // Confirmation
            if (!confirm(`Voulez-vous annuler la réservation de :\n"${nomDoc}" ?`)) {
                return;
            }
            
            // Désactiver le bouton
            const texteOriginal = this.innerHTML;
            this.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
            this.disabled = true;
            
            try {
                // Appel API
                const reponse = await fetch('api_cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: docId,
                        itemType: 'books', // À ajuster si mémoires
                        userDocId: userDocId
                    })
                });
                
                const donnees = await reponse.json();
                
                if (donnees.success) {
                    // Succès - masquer l'élément
                    const element = document.getElementById('item-' + index);
                    if (element) {
                        element.style.opacity = '0.5';
                        element.style.transition = 'opacity 0.5s';
                        
                        setTimeout(() => {
                            element.remove();
                            
                            // Si plus d'éléments, recharger
                            const itemsRestants = document.querySelectorAll('.list-group-item').length;
                            if (itemsRestants === 0) {
                                location.reload();
                            }
                        }, 500);
                    }
                    
                    alert('✅ Réservation annulée avec succès !');
                    
                } else {
                    // Erreur
                    alert('❌ Erreur: ' + (donnees.message || 'Inconnue'));
                    this.innerHTML = texteOriginal;
                    this.disabled = false;
                }
                
            } catch (erreur) {
                console.error('Erreur réseau:', erreur);
                alert('❌ Erreur réseau. Veuillez réessayer.');
                this.innerHTML = texteOriginal;
                this.disabled = false;
            }
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>