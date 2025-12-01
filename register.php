<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

require_once('../../config.php');
require_once($CFG->libdir.'/formslib.php');

// --- SETUP DE BASE DE LA PAGE MOODLE ---
$PAGE->set_url('/local/biblio_enspy/register.php');
$PAGE->set_pagelayout('standard');
$context = context_system::instance();
$PAGE->set_context($context);
require_login();

$PAGE->set_title('Inscription à la bibliothèque');
$PAGE->set_heading('Formulaire d\'inscription');


// --- FONCTION API FIRESTORE  ---
function callFirestoreAPIForDepartments($url, $accessToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    if (curl_errno($ch)) { return null; }
    curl_close($ch);
    return json_decode($response, true);
}

// --- CLASSE DU FORMULAIRE (INCHANGÉE) ---
class local_registration_form extends moodleform {
    public function definition() {
        global $USER;
        $mform = $this->_form;
        $departments = $this->_customdata['departments'] ?? [];

        $mform->addElement('text', 'nom', 'Nom complet');
        $mform->setType('nom', PARAM_TEXT);
        $mform->setDefault('nom', fullname($USER));
        $mform->addRule('nom', 'Champ obligatoire', 'required', null, 'client');

        $mform->addElement('text', 'matricule', 'Matricule');
        $mform->setType('matricule', PARAM_ALPHANUMEXT);
        $mform->addRule('matricule', 'Champ obligatoire', 'required', null, 'client');

        $mform->addElement('text', 'tel', 'Téléphone');
        $mform->setType('tel', PARAM_TEXT);

        $mform->addElement('select', 'departement', 'Département', $departments);
        $mform->addRule('departement', 'Champ obligatoire', 'required', null, 'client');

        $niveaux = ['' => 'Sélectionnez votre niveau', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'];
        $mform->addElement('select', 'niveau', 'Niveau d\'études', $niveaux);
        $mform->addRule('niveau', 'Champ obligatoire', 'required', null, 'client');

        $this->add_action_buttons(false, 'Enregistrer');
    }
}

// --- LOGIQUE PRINCIPALE ---
// Récupération des données
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];
$projectId = "biblio-cc84b";
$collectionDepartments = 'Departements';
$urlDepartments = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionDepartments}";
$departmentsData = callFirestoreAPIForDepartments($urlDepartments, $accessToken);

$departmentsList = ['' => 'Sélectionnez un département'];
if (isset($departmentsData['documents'])) {
    foreach ($departmentsData['documents'] as $doc) {
        if (isset($doc['fields']['nom']['stringValue'])) {
            $deptName = $doc['fields']['nom']['stringValue'];
            $departmentsList[$deptName] = $deptName;
        }
    }
}

// Initialisation du formulaire
$mform = new local_registration_form(null, ['departments' => $departmentsList]);


// Traitement du formulaire
if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot);
} else if ($fromform = $mform->get_data()) {
    
    global $USER;
    $collectionUsers = 'BiblioUser';

    // *** CORRECTION CLÉ : Utiliser POST avec documentId pour forcer l'ID de l'email ***
    // 1. L'URL pointe vers la collection (BiblioUser).
    // 2. Le paramètre documentId contient l'email de l'utilisateur (URL-encoded).
    $urlAddUser = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionUsers}?documentId=" . urlencode($USER->email);
    
    $newUser = [
        'createdAt' => time(), 'name' => $fromform->nom, 'matricule' => $fromform->matricule,
        'email' => $USER->email, 'departement' => $fromform->departement, 'niveau' => $fromform->niveau,
        'tel' => $fromform->tel, 'profilePicture' => "", 'teste' => "", 'messages' => [],
        'signalMessage' => '', 'tabMessages' => [""], 'etat' => 'ras', 'etat1' => 'ras',
        'etat2' => 'ras', 'etat3' => 'ras', 'tabEtat1' => ['', '', ''], 'tabEtat2' => ['', '', ''],
        'tabEtat3' => ['', '', ''], 'docRecent' => [], 'historique' => []
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $urlAddUser);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // On repasse à la méthode POST
    curl_setopt($ch, CURLOPT_POST, true);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["fields" => formatFirestoreData($newUser)]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode >= 200 && $httpcode < 300) {
        redirect(new moodle_url('/local/biblio_enspy/explore.php'), 'Votre inscription a été enregistrée avec succès.', \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // La réponse Firestore contient l'erreur réelle
        $error_details = json_decode($response, true);
        $errorMessage = $error_details['error']['message'] ?? 'Erreur inconnue de Firestore.';
        
        throw new \moodle_exception('Erreur lors de l\'enregistrement dans Firestore: ' . $errorMessage, 'local_biblio_enspy');
    }
}

// --- AFFICHAGE DE LA PAGE ---
echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

// --- FONCTION UTILITAIRE (INCHANGÉE) ---
function formatFirestoreData($data) {
    $formattedData = [];
    foreach ($data as $key => $value) {
        if (is_int($value)) $formattedData[$key] = ["integerValue" => $value];
        else if (is_float($value)) $formattedData[$key] = ["doubleValue" => $value];
        else if (is_bool($value)) $formattedData[$key] = ["booleanValue" => $value];
        else if (is_array($value)) {
            $arrayValues = []; foreach ($value as $v) { if(is_string($v)) $arrayValues[] = ["stringValue" => $v]; }
            $formattedData[$key] = ["arrayValue" => ["values" => $arrayValues]];
        }
        else $formattedData[$key] = ["stringValue" => (string) $value];
    }
    return $formattedData;
}