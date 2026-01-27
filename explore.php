<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
// Configuration de la page Moodle
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/biblio_enspy/explore.php'));
$PAGE->set_title('Bibliothèque ENSPY');
$PAGE->set_heading('Bibliothèque ENSPY');
$PAGE->set_pagelayout('standard');
$PAGE->requires->css('/local/biblio_enspy/css/styles.css');
$PAGE->requires->js('/local/biblio_enspy/js/switchBooks.js');

// Charger projectId + accessToken
list($projectId, $accessToken) = biblio_load_google_credentials();

if (!$projectId || !$accessToken) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification("Impossible de contacter la base de données Firestore.", 'error');
    echo $OUTPUT->footer();
    exit;
}

// Maintenance check
biblio_require_no_maintenance($projectId, $accessToken);

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
$firebase_data = json_decode(file_get_contents($serviceAccountJson), true);
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

// Extraire les variables
$projectId = $firebase_data['project_id'];
$web = $firebase_data['web_config']; // Le nouveau bloc ajouté


$config = [
    'apiKey'            => $web['apiKey'],
    'authDomain'        => $projectId . ".firebaseapp.com",
    'projectId'         => $projectId,
    'storageBucket'     => $projectId . ".appspot.com",
    'messagingSenderId' => (string)$web['messagingSenderId'],
    'appId'             => $web['appId']
];

// NOUVEAU CODE POUR LES NOTIFICATIONS EN TEMPS RÉEL

// Charger les bibliothèques Firebase depuis CDN
$PAGE->requires->js(new moodle_url('https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js'), true);
$PAGE->requires->js(new moodle_url('https://www.gstatic.com/firebasejs/9.22.0/firebase-firestore-compat.js'), true);

// Charger le listener de notifications
$PAGE->requires->js('/local/biblio_enspy/js/notification_listener.js');

// Initialiser le listener après le chargement de la page
$PAGE->requires->js_init_code("
    require(['jquery'], function($) {
        var attempts = 0;
        var checkListener = setInterval(function() {
            attempts++;
            if (typeof BiblioNotificationListener !== 'undefined' && typeof BiblioNotificationListener.init === 'function') {
                clearInterval(checkListener);
                var firebaseConfig = " . json_encode($config) . ";
                BiblioNotificationListener.init(firebaseConfig, '" . $USER->email . "');
                console.log('BiblioNotificationListener initialized after ' + attempts + ' attempts');
            }
            if (attempts > 50) { // Sécurité 5 secondes
                clearInterval(checkListener);
                console.error('BiblioNotificationListener failed to load');
            }
        }, 100);
    });
");



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

// Traitement des données pour extraire les exemplaires
$booksDataWithExemplaires = [];
if (isset($booksData['documents'])) {
    foreach ($booksData['documents'] as $book) {
        $book['exemplaire'] = 0;
        if (isset($book['fields']['exemplaire'])) {
            if (isset($book['fields']['exemplaire']['integerValue'])) {
                $book['exemplaire'] = (int)$book['fields']['exemplaire']['integerValue'];
            } elseif (isset($book['fields']['exemplaire']['doubleValue'])) {
                $book['exemplaire'] = (int)$book['fields']['exemplaire']['doubleValue'];
            }
        }
        $booksDataWithExemplaires[] = $book;
    }
}

$thesesDataWithExemplaires = [];
if (isset($thesesData['documents'])) {
    foreach ($thesesData['documents'] as $thesis) {
        $thesis['exemplaire'] = 1; // Les mémoires ont toujours 1 exemplaire
        $thesesDataWithExemplaires[] = $thesis;
    }
}

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

// ================================================================
// Fonction pour extraire les IDs de réservation depuis tabEtat{i}
//================================================================
function extractReservationIdsFromUserData($userFields) {
    $reservationIds = [];
    $maxReservations = 5; // 3 états maximum
    
    for ($i = 0; $i < $maxReservations; $i++) { 
        $etatField = "etat{$i}";
        $tabEtatField = "tabEtat{$i}";
        
        // Vérifier si l'état est "reserv" ou "emprunt"
        if (isset($userFields[$etatField]['stringValue'])) {
            $currentStatus = $userFields[$etatField]['stringValue'];
            
            if (($currentStatus === 'reserv' || $currentStatus === 'emprunt') &&
                isset($userFields[$tabEtatField]['arrayValue']['values'])) {
                
                $tabEtatValues = $userFields[$tabEtatField]['arrayValue']['values'];
                
                // Structure de tabEtat{i} : [0:name, 1:cathegorie, 2:image, 3:exemplaires, 4:collection, 5:timestamp, 6:docId]
                if (count($tabEtatValues) > 6 && isset($tabEtatValues[6]['stringValue'])) {
                    $reservationIds[] = $tabEtatValues[6]['stringValue'];
                }
            }
        }
    }
    
    return $reservationIds;
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

            // Extraire les IDs de réservation
            $userReservationIds = extractReservationIdsFromUserData($document['fields']);
            break;
        }
    }
}

if (!$userExists) {
    redirect(new moodle_url('/local/biblio_enspy/register.php'));
}


// ============ AFFICHAGE DE LA PAGE ====================================

echo $OUTPUT->header();

// Boîte de filtres
// Début du conteneur Flexbox principal
$filter_html = '<div class="d-flex flex-wrap align-items-start gap-4">';

// Conteneur Flexbox principal (d-flex, flex-wrap, align-items-start, gap-3)
$filter_html = '<div class="d-flex flex-wrap align-items-start gap-3">';

// 1. Champ de recherche (Input)
$filter_html .= '<div class="form-group flex-fill me-2">';
$filter_html .= '<label for="searchBar" class="form-label">Rechercher :</label>';
$filter_html .= '<input type="text" id="searchBar" class="form-control" placeholder="Rechercher par titre, auteur, catégorie..." />';
$filter_html .= '</div>';

// 2. Filtre par département (Select)
$filter_html .= '<div class="form-group">'; 
$filter_html .= '<label for="departmentFilter" class="form-label">Filtrer par département : </label>';
$filter_html .= html_writer::select($departmentsList, 'departmentFilter', '', [], ['id' => 'departmentFilter', 'class' => 'form-control custom-select']);
$filter_html .= '</div>';

// 3. Trier par (Select)
$filter_html .= '<div class="form-group">';
$filter_html .= '<label for="sortFilter" class="form-label">Trier par :</label>';
$filter_html .= '<select id="sortFilter" class="form-control custom-select">
                    <option value="title_asc">A → Z (Titre)</option>
                    <option value="title_desc">Z → A (Titre)</option>
                    <option value="department_asc">Département (A→Z)</option>
                    <option value="department_desc">Département (Z→A)</option>
                    <option value="availability_desc">Disponibilité (Plus d\'exemplaires)</option>
                    <option value="availability_asc">Disponibilité (Moins d\'exemplaires)</option>
                </select>';
$filter_html .= '</div>';

$filter_html .= '</div>'; 

echo $OUTPUT->box($filter_html, 'p-3 mb-4');

// Lien vers "Mes Réservations" ---
echo '<div class="text-center mb-4">';
$reservations_url = new moodle_url('/local/biblio_enspy/my_reservations.php');
echo html_writer::link($reservations_url, 'Mes Réservations', ['class' => 'btn btn-info']);
echo '</div>';

// Boutons de sélection Livres/Mémoires
echo '<div class="selection text-center mb-4">';
echo '<button id="switchBooks" class="btn btn-primary active">Livres</button>';
echo '<button id="switchTheses" class="btn btn-secondary">Mémoires</button>';
echo '</div>';

// Zone de contenu principale pour les listes
echo '<div id="contentArea">';
echo '<div id="booksList" class="books-list" style="display: none;"></div>';
echo '<div id="thesesList" class="books-list" style="display: none;"></div>';
echo '</div>';

// Section Recommandations
$reco_icon = $OUTPUT->pix_icon('recommend', 'Recommandations', 'local_biblio_enspy', ['class' => 'recommend-icon']);
$reco_header = $OUTPUT->heading($reco_icon . ' Recommandations pour vous', 3, ['class' => 'text-center mb-4']);

// Structure très simple
$reco_content = '<div class="recommendations-section">
                    <div class="recommendations-scroll-container">
                        <div id="recommendationsList" class="recommendations-list"></div>
                    </div>
                    <button class="scroll-left" aria-label="Left">&lsaquo;</button>
                    <button class="scroll-right" aria-label="Right">&rsaquo;</button>
                 </div>';

echo $OUTPUT->box($reco_header . $reco_content, 'p-3 mt-4', 'recommendationsArea');

//Passer les IDs des réservations et les données avec exemplaires au JS
echo "<script>
    var booksData = " . json_encode($booksDataWithExemplaires) . ";
    var thesesData = " . json_encode($thesesDataWithExemplaires) . ";
    var userDocId = " . json_encode($userDocId) . ";
    var userReservationIds = " . json_encode($userReservationIds) . ";
</script>";

echo $OUTPUT->footer();
?>