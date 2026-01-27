<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// Setup de la page
require_login();
global $USER, $OUTPUT; 
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/biblio_enspy/my_reservations.php');
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Mes Réservations & Emprunts');
$PAGE->set_heading('Mes Réservations et Emprunts');

// Configuration et récupération des données de l'utilisateur
$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'] ?? null;

if (!$accessToken) {
    echo $OUTPUT->header();
    echo $OUTPUT->box('<p class="text-danger text-center">Erreur de configuration : jeton d’accès Firebase manquant.</p>');
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

// Trouver le document utilisateur par email
if (isset($userData['documents'])) {
    foreach ($userData['documents'] as $document) {
        if (isset($document['fields']['email']['stringValue']) && $document['fields']['email']['stringValue'] == $USER->email) {
            $userFields = $document['fields'];
            break;
        }
    }
}

$activeReservations = [];
$currentLoans = [];
$maxReservations = 5; // A RENDRE DYNAMIQUE

if ($userFields) {
    //Extraction et catégorisation des états actifs (reserv ou emprunt)
    for ($i = 0; $i < $maxReservations; $i++) {
        $statusField = "etat{$i}";
        $tabField = "tabEtat{$i}";
        
        $status = $userFields[$statusField]['stringValue'] ?? 'ras';
        
        if ($status === 'reserv' || $status === 'emprunt') {
            
            $tabValues = $userFields[$tabField]['arrayValue']['values'] ?? [];

            // Structure attendue de tabEtat{X} : 
            // [0: name, 1: cathegorie, 2: image, 3: exemplaires_restants, 4: collectionName, 5: Timestamp.now(), 6: bookDoc.id]
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
                ];

                if ($status === 'reserv') {
                    $activeReservations[] = $item;
                } elseif ($status === 'emprunt') {
                    $currentLoans[] = $item;
                }
            } else {
                error_log("Biblio: Données de réservation incohérentes pour le slot {$i} de l'utilisateur.");
            }
        }
    }
}


// Affichage
echo $OUTPUT->header();

// Lien de retour
$back_url = new moodle_url('/local/biblio_enspy/explore.php');
echo html_writer::link($back_url, '‹ Retour à la bibliothèque', ['class' => 'btn btn-outline-secondary mb-4']);


/**
 * Fonction d'affichage des listes
 */
function display_list(array $items, string $title, string $color_class, string $action_label, bool $is_loan = false) {
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
            
            $html .= html_writer::link($detailsUrl, $action_label, ['class' => 'btn btn-sm btn-' . $color_class]);
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    return $html;
}


// Affichage des emprunts
echo display_list($currentLoans, 'Documents Empruntés', 'success', 'Détails', true);

// Affichage des réservations
echo display_list($activeReservations, 'Réservations en Cours', 'warning', 'Détails', false);


if (empty($currentLoans) && empty($activeReservations)) {
    echo $OUTPUT->box('<p class="text-center mt-5">Vous n\'avez actuellement aucune réservation ou aucun emprunt actif.</p>', 'info');
}

echo $OUTPUT->footer();
?>