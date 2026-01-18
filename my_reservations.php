<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

require_login();
global $USER, $OUTPUT; 
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/biblio_enspy/my_reservations.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes Réservations & Emprunts');
$PAGE->set_heading('Mes Réservations et Emprunts');

$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'] ?? null;

if (!$accessToken) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger text-center">Erreur de configuration Firebase.</p>');
    echo $OUTPUT->footer();
    exit;
}

$usersUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $usersUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$userData = json_decode($response, true);
$userFields = null;
$userDocId = null;

if (isset($userData['documents'])) {
    foreach ($userData['documents'] as $document) {
        if (isset($document['fields']['email']['stringValue']) && $document['fields']['email']['stringValue'] == $USER->email) {
            $userFields = $document['fields'];
            $pathParts = explode('/', $document['name']);
            $userDocId = end($pathParts);
            break;
        }
    }
}

$activeReservations = [];
$currentLoans = [];
$maxReservations = 3;

if ($userFields) {
    for ($i = 1; $i <= $maxReservations; $i++) {
        $statusField = "etat{$i}";
        $tabField = "tabEtat{$i}";
        
        $status = $userFields[$statusField]['stringValue'] ?? 'ras';
        
        if ($status === 'reserv' || $status === 'emprunt') {
            $tabValues = $userFields[$tabField]['arrayValue']['values'] ?? [];

            if (count($tabValues) >= 7) { 
                $dateValue = $tabValues[5]['timestampValue'] ?? null;
                $reservationDate = $dateValue ? (new DateTime($dateValue))->format('d/m/Y à H:i') : 'Date inconnue';

                $item = [
                    'status'        => $status,
                    'name'          => $tabValues[0]['stringValue'] ?? 'Titre inconnu',
                    'cathegorie'    => $tabValues[1]['stringValue'] ?? '',
                    'image'         => $tabValues[2]['stringValue'] ?? 'none',
                    'collectionName'=> $tabValues[4]['stringValue'] ?? 'BiblioBooks',
                    'date'          => $reservationDate,
                    'docId'         => $tabValues[6]['stringValue'] ?? null,
                    'slotNumber'    => $i
                ];

                if ($status === 'reserv') {
                    $activeReservations[] = $item;
                } elseif ($status === 'emprunt') {
                    $currentLoans[] = $item;
                }
            }
        }
    }
}

echo $OUTPUT->header();

$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);

function display_list(array $items, string $title, string $color_class, string $action_label, bool $is_loan = false, $userDocId = null) {
    global $OUTPUT;
    
    $html = html_writer::tag('h3', $title, ['class' => "mt-4 pb-2 border-bottom text-{$color_class}"]);
    
    if (empty($items)) {
        $html .= $OUTPUT->box('<p class="text-center">Aucun document dans cette catégorie.</p>', $color_class . 'bg-light');
    } else {
        $html .= '<ul class="list-group shadow-sm">';
        foreach ($items as $item) {
            $detailsUrl = new moodle_url('/local/biblio_enspy/view.php', ['id' => $item['docId'], 'type' => 'books']);
            
            $status_badge = html_writer::tag('span', ($is_loan ? 'En prêt' : 'En attente'), ['class' => "badge badge-{$color_class} text-white ml-2"]);

            $html .= '<li class="list-group-item d-flex justify-content-between align-items-center">';
            $html .= '<div>';
            $html .= '<strong>' . htmlspecialchars($item['name']) . '</strong> ' . $status_badge . '<br>';
            $html .= '<small class="text-muted">' . htmlspecialchars($item['cathegorie']) . ' | Date: ' . $item['date'] . '</small>';
            $html .= '</div>';
            
            $html .= '<div class="btn-group" role="group">';
            $html .= html_writer::link($detailsUrl, 'Détails', ['class' => 'btn btn-sm btn-' . $color_class]);
            
            if (!$is_loan && $userDocId) {
                $html .= '<button class="btn btn-sm btn-danger cancel-reservation-btn" 
                          data-item-id="' . htmlspecialchars($item['docId']) . '" 
                          data-item-name="' . htmlspecialchars($item['name']) . '"
                          data-user-doc-id="' . htmlspecialchars($userDocId) . '"
                          title="Annuler cette réservation">
                          <i class="fa fa-times"></i> Annuler
                          </button>';
            }
            
            $html .= '</div>';
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    return $html;
}

echo display_list($currentLoans, 'Documents Empruntés', 'success', 'Détails', true, $userDocId);
echo display_list($activeReservations, 'Réservations en Cours', 'warning', 'Détails', false, $userDocId);

if (empty($currentLoans) && empty($activeReservations)) {
    echo $OUTPUT->box('<p class="text-center mt-5">Vous n\'avez actuellement aucune réservation ou aucun emprunt actif.</p>', 'info');
}
?>

<script>
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
    static confirm(m, t) { return this.show(m, 'confirm', t); }
}

document.addEventListener('DOMContentLoaded', function() {
    const cancelButtons = document.querySelectorAll('.cancel-reservation-btn');
    
    cancelButtons.forEach(button => {
        button.addEventListener('click', async function() {
            const itemId = this.getAttribute('data-item-id');
            const itemName = this.getAttribute('data-item-name');
            const userDocId = this.getAttribute('data-user-doc-id');
            
            console.log('Annulation pour:', { itemId, itemName, userDocId });
            
            const confirmation = await MoodleNotificationHelper.confirm(
                `Êtes-vous sûr de vouloir annuler la réservation de :<br><strong>"${itemName}"</strong>`,
                'Confirmer l\'annulation'
            );
            
            if (!confirmation.confirmed) {
                return;
            }
            
            const originalText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<i class="fa fa-spinner fa-spin"></i> En cours...';
            
            try {
                const response = await fetch('api_cancel.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itemId: itemId,
                        userDocId: userDocId
                    })
                });
                
                const data = await response.json();
                console.log('Réponse:', data);
                
                if (data.success) {
                    await MoodleNotificationHelper.success(
                        'Votre réservation a été annulée avec succès !',
                        'Annulation réussie'
                    );
                    
                    setTimeout(() => { location.reload(); }, 800);
                    
                } else {
                    await MoodleNotificationHelper.error(
                        data.message || 'Une erreur est survenue.',
                        'Échec de l\'annulation'
                    );
                    
                    this.disabled = false;
                    this.innerHTML = originalText;
                }
                
            } catch (error) {
                console.error('Erreur:', error);
                await MoodleNotificationHelper.error(
                    'Une erreur réseau est survenue.',
                    'Erreur réseau'
                );
                
                this.disabled = false;
                this.innerHTML = originalText;
            }
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>