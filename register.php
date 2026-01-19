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

// --- FONCTION API FIRESTORE ---
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

// --- CLASSE DU FORMULAIRE ---
class local_registration_form extends moodleform {
    public function definition() {
        global $USER;
        $mform = $this->_form;
        $departments = $this->_customdata['departments'] ?? [];

        $mform->addElement('header', 'userinfo', 'Informations personnelles');
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

        $mform->addElement('header', 'authinfo', 'Accès Web et Mobile');
        $mform->addElement('static', 'info', '', 'Définissez un mot de passe pour accéder à la bibliothèque sur smartphone et web.');
        
        $mform->addElement('password', 'biblio_password', 'Mot de passe Bibliothèque');
        $mform->setType('biblio_password', PARAM_RAW);
        $mform->addRule('biblio_password', 'Le mot de passe doit faire au moins 6 caractères', 'required', null, 'client');
        $mform->addRule('biblio_password', 'Minimum 6 caractères', 'minlength', 6, 'client');

        $this->add_action_buttons(false, 'Enregistrer mon inscription');
    }
}

// --- LOGIQUE PRINCIPALE ---
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

$firebase_data = json_decode(file_get_contents($serviceAccountJson), true);
$projectId = $firebase_data['project_id'];
$web = $firebase_data['web_config'];
$firebaseApiKey = $web['apiKey']; 

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

$mform = new local_registration_form(null, ['departments' => $departmentsList]);

if ($mform->is_cancelled()) {
    redirect($CFG->wwwroot);
} else if ($fromform = $mform->get_data()) {
    global $USER;

    // --- 1. FIREBASE AUTH ---
    $authUrl = "https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=" . $firebaseApiKey;
    $authPayload = [
        'email'             => $USER->email,
        'password'          => $fromform->biblio_password,
        'displayName'       => $fromform->nom,
        'returnSecureToken' => true
    ];

    $chAuth = curl_init($authUrl);
    curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chAuth, CURLOPT_POST, true);
    curl_setopt($chAuth, CURLOPT_POSTFIELDS, json_encode($authPayload));
    curl_setopt($chAuth, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($chAuth, CURLOPT_SSL_VERIFYPEER, false);
    $authResponse = curl_exec($chAuth);
    $authHttpCode = curl_getinfo($chAuth, CURLINFO_HTTP_CODE);
    $authResult = json_decode($authResponse, true);
    curl_close($chAuth);

    if ($authHttpCode !== 200 && ($authResult['error']['message'] ?? '') !== 'EMAIL_EXISTS') {
        throw new \moodle_exception('Erreur Authentication : ' . ($authResult['error']['message'] ?? 'Erreur inconnue'), 'local_biblio_enspy');
    }

    $firebaseUid = $authResult['localId'] ?? '';
    $now = date('Y-m-d\TH:i:s\Z'); // Format Timestamp Firestore ISO

    // --- 2. STRUCTURE CLONE WEB POUR FIRESTORE ---
    $newUser = [
        'adminLastReadTimestamp' => $now,
        'createdAt' => $now,
        'departement' => $fromform->departement,
        'docRecent' => [],
        'email' => $USER->email,
        'emailVerified' => false,
        'etat' => 'ras',
        'etat1' => 'ras',
        'etat2' => 'ras',
        'etat3' => 'ras',
        'etat4' => 'ras',
        'etat5' => 'ras',
        'historique' => [],
        'imageUri' => "",
        'inscritArchi' => "",
        'lastLoginAt' => $now,
        'level' => 'level1',
        'matricule' => $fromform->matricule,
        'messages' => [],
        'name' => $fromform->nom,
        'niveau' => $fromform->niveau,
        'notifications' => [],
        'profilePicture' => "",
        'reservations' => [],
        'searchHistory' => [],
        'statut' => 'etudiant',
        'tabEtat1' => ["", "", "", "", "", $now, 0],
        'tabEtat2' => ["", "", "", "", "", $now, 0],
        'tabEtat3' => ["", "", "", 0, "", ""],
        'tabEtat4' => null,
        'tabEtat5' => ["", "", "", "", "", $now],
        'tel' => $fromform->tel,
        'updated_at' => $now,
        'username' => strtolower(str_replace(' ', '', $fromform->nom)),
        'teste' => $firebaseUid 
    ];

    $collectionUsers = 'BiblioUser';
    $urlAddUser = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionUsers}?documentId=" . urlencode($USER->email);
    
    $ch = curl_init($urlAddUser);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["fields" => formatFirestoreData($newUser)]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode >= 200 && $httpcode < 300) {
        redirect(new moodle_url('/local/biblio_enspy/explore.php'), 'Inscription réussie ! Votre profil est synchronisé.', \core\output\notification::NOTIFY_SUCCESS);
    } else {
        throw new \moodle_exception('Erreur Firestore : ' . $response, 'local_biblio_enspy');
    }
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

// --- FONCTION UTILITAIRE MISE À JOUR POUR TIMESTAMPS ET TYPES MIXTES ---
function formatFirestoreData($data) {
    $formattedData = [];
    if ($data === null) return null;

    foreach ($data as $key => $value) {
        if ($value === null) {
            $formattedData[$key] = ["nullValue" => null];
        } else if (is_bool($value)) {
            $formattedData[$key] = ["booleanValue" => $value];
        } else if (is_int($value)) {
            $formattedData[$key] = ["integerValue" => (string)$value];
        } else if (is_array($value)) {
            $arrayValues = [];
            foreach ($value as $v) {
                if (is_int($v)) $arrayValues[] = ["integerValue" => (string)$v];
                else if (preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $v)) $arrayValues[] = ["timestampValue" => $v];
                else $arrayValues[] = ["stringValue" => (string)$v];
            }
            $formattedData[$key] = ["arrayValue" => ["values" => $arrayValues]];
        } else if (preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $value)) {
            $formattedData[$key] = ["timestampValue" => $value];
        } else {
            $formattedData[$key] = ["stringValue" => (string)$value];
        }
    }
    return $formattedData;
}