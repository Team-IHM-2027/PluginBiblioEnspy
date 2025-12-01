<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

// Charger projectId + accessToken
list($projectId, $accessToken) = biblio_load_google_credentials();

if (!$projectId || !$accessToken) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification("Impossible de contacter la base de données Firestore.", 'error');
    echo $OUTPUT->footer();
    exit;
}

require_login();


// Vérification statut utilisateur Firestore
$status = biblio_check_user_status($USER, $projectId, $accessToken);

if ($status['reason'] === 'register') {
    redirect(new moodle_url('/local/biblio_enspy/register.php'));
    exit;
}

if (!$status['allowed']) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification("Votre accès à la bibliothèque a été bloqué.", 'error');

    if (!empty($status['reason'])) {
        echo html_writer::div("Motif : " . s($status['reason']), 'alert alert-danger');
    }

    // Infos utilisateur
    if (!empty($status['userdata'])) {
        $fields = $status['userdata'];
        echo html_writer::start_tag('ul', ['class' => 'list-group']);
        echo html_writer::tag('li', "Nom : " . ($fields['name']['stringValue'] ?? ''), ['class' => 'list-group-item']);
        echo html_writer::tag('li', "Matricule : " . ($fields['matricule']['stringValue'] ?? ''), ['class' => 'list-group-item']);
        echo html_writer::tag('li', "Département : " . ($fields['departement']['stringValue'] ?? ''), ['class' => 'list-group-item']);
        echo html_writer::tag('li', "Niveau : " . ($fields['niveau']['stringValue'] ?? ''), ['class' => 'list-group-item']);
        echo html_writer::end_tag('ul');
    }

    echo html_writer::div('<a class="btn btn-primary mt-3" href="'.$CFG->wwwroot.'">Retour</a>');
    echo $OUTPUT->footer();
    exit;
}

// Vérifie si l'utilisateur est connecté et n'est pas un invité
if(!isloggedin() || isguestuser()) {
    redirect(new moodle_url('/login/index.php'));
    exit;
}

// Configuration Firebase
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

// Configuration de la page Moodle
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/biblio_enspy/explore.php'));
$PAGE->set_title('Bibliothèque ENSPY');
$PAGE->set_heading('Bibliothèque ENSPY');
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/biblio_enspy/css/styles.css');
$PAGE->requires->js('/local/biblio_enspy/amd/src/switchBooks.js');

// Configuration Firestore
$projectId = "biblio-cc84b";
$collectionBooks = 'BiblioBooks';
$collectionTheses = 'BiblioThesis';
$collectionUsers = 'BiblioUser';
$collectionDepartments = 'Departements';

$urlBooks = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionBooks}";
$urlTheses = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionTheses}";
$urlUsers = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionUsers}";
$urlDepartments = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionDepartments}";

// Fonction pour appeler l'API Firestore
function callFirestoreAPI($url, $accessToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if (curl_errno($ch)) { 
        error_log('Erreur cURL pour ' . $url . ' : ' . curl_error($ch)); 
        return null; 
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Récupération de toutes les données
global $USER;
$userData = callFirestoreAPI($urlUsers, $accessToken);
$booksData = callFirestoreAPI($urlBooks, $accessToken);
$thesesData = callFirestoreAPI($urlTheses, $accessToken);
$departmentsData = callFirestoreAPI($urlDepartments, $accessToken);

// Traitement des départements pour le filtre
$departmentsList = ['' => 'Tous les départements'];
if (isset($departmentsData['documents'])) {
    foreach ($departmentsData['documents'] as $doc) {
        if (isset($doc['fields']['nom']['stringValue'])) {
            $deptName = $doc['fields']['nom']['stringValue'];
            $departmentsList[$deptName] = $deptName;
        }
    }
}

// Récupérer l'ID ET les réservations de l'utilisateur ---
$userExists = false;
$userDocId = null;
$userReservationIds = []; // Tableau pour stocker les IDs des documents réservés

if (isset($userData['documents'])) {
    foreach ($userData['documents'] as $document) {
        if (isset($document['fields']['email']['stringValue']) && $document['fields']['email']['stringValue'] == $USER->email) {
            $userExists = true;
            $pathParts = explode('/', $document['name']);
            $userDocId = end($pathParts);

            // On récupère la liste des IDs des documents réservés
            if (isset($document['fields']['reservations']['arrayValue']['values'])) {
                foreach ($document['fields']['reservations']['arrayValue']['values'] as $reservation) {
                    if (isset($reservation['mapValue']['fields']['docId']['stringValue'])) {
                        $userReservationIds[] = $reservation['mapValue']['fields']['docId']['stringValue'];
                    }
                }
            }
            break;
        }
    }
}

if (!$userExists) {
    redirect(new moodle_url('/local/biblio_enspy/register.php'));
}

// ---- AFFICHAGE DE LA PAGE ----
echo $OUTPUT->header();

// Boîte de filtres
$filter_html = '<div class="form-group">
                    <input type="text" id="searchBar" class="form-control" placeholder="Rechercher par titre, auteur, catégorie..." />
                </div>';
$filter_html .= '<div class="form-group">
                    <label for="departmentFilter">Filtrer par département :</label>';
$filter_html .= html_writer::select($departmentsList, 'departmentFilter', '', [], ['id' => 'departmentFilter', 'class' => 'form-control custom-select']);
$filter_html .= '</div>';
echo $OUTPUT->box($filter_html, 'p-3 mb-4');

// Lien vers "Mes Réservations" ---
echo '<div class="text-center mb-4">';
$reservations_url = new moodle_url('/local/biblio_enspy/my_reservations.php');
echo html_writer::link($reservations_url, 'Mes Réservations', ['class' => 'btn btn-info']);
echo '</div>';

// Boutons de sélection Livres/Mémoires
echo '<div class="selection text-center mb-4">';
echo '<button id="switchBooks" class="btn btn-primary">Livres</button>';
echo '<button id="switchTheses" class="btn btn-secondary">Mémoires</button>';
echo '</div>';

// Zone de contenu principale pour les listes
echo '<div id="contentArea">';
echo '<div id="booksList" class="books-list" style="display: none;"></div>';
echo '<div id="thesesList" class="books-list" style="display: none;"></div>';
echo '</div>';

// Section Recommandations
$reco_icon = $OUTPUT->pix_icon('i/recommend', 'Recommandations');
$reco_header = $OUTPUT->heading($reco_icon . ' Recommandations pour vous', 3, ['class' => 'text-center']);
$reco_content = '<div id="recommendationsList" class="recommendations-list"></div>';
echo $OUTPUT->box($reco_header . $reco_content, 'p-3 mt-4', 'recommendationsArea');

//Passer les IDs des réservations au JS ---
echo "<script>
    var booksData = " . json_encode(isset($booksData['documents']) ? $booksData['documents'] : []) . ";
    var thesesData = " . json_encode(isset($thesesData['documents']) ? $thesesData['documents'] : []) . ";
    var userDocId = " . json_encode($userDocId) . ";
    var userReservationIds = " . json_encode($userReservationIds) . "; // <-- NOUVEAU
</script>";

echo $OUTPUT->footer();
?>
