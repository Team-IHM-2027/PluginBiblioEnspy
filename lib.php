<?php
// /local/biblio_enspy/lib.php
// Fonctions utilitaires pour Firestore + gestion accès utilisateur

defined('MOODLE_INTERNAL') || die();

use Google\Auth\Credentials\ServiceAccountCredentials;

// Activer ou non les logs internes
global $LOCAL_BIBLIO_ENSPY_DEBUG;
$LOCAL_BIBLIO_ENSPY_DEBUG = false;

/**
 * Charge projectId + accessToken à partir du service account.
 * Cette version n'utilise PAS Google_Client.
 *
 * @return array [projectId|null, accessToken|null]
 */
function biblio_load_google_credentials() {
    global $CFG;

    $credfile = $CFG->dirroot . '/local/biblio_enspy/firebase_credentials.json';

    if (!file_exists($credfile)) {
        debugging("firebase_credentials.json manquant", DEBUG_DEVELOPER);
        return [null, null];
    }

    $json = json_decode(file_get_contents($credfile), true);
    if (!$json || empty($json['project_id'])) {
        debugging("firebase_credentials.json invalide : project_id absent", DEBUG_DEVELOPER);
        return [null, null];
    }

    // Charger vendor
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once($autoload);
    } else {
        debugging("vendor/autoload.php introuvable", DEBUG_DEVELOPER);
        return [null, null];
    }

    // Utilisation officielle google/auth
    $scopes = ['https://www.googleapis.com/auth/datastore'];
    try {
        $creds = new ServiceAccountCredentials($scopes, $credfile);
        $tokenData = $creds->fetchAuthToken();
        $accessToken = $tokenData['access_token'] ?? null;
    } catch (Exception $e) {
        debugging("Erreur création access token : " . $e->getMessage(), DEBUG_DEVELOPER);
        return [null, null];
    }

    return [$json['project_id'], $accessToken];
}


/**
 * Vérifie le statut Firestore d’un utilisateur.
 */
function biblio_check_user_status($USER, $projectId, $accessToken) {

    $collection = 'BiblioUser';
    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collection}";

    $res = local_biblio_enspy_firestore_get($url, $accessToken);

    if (!$res || !isset($res['documents'])) {
        return ['allowed' => true, 'userdata' => null, 'reason' => null];
    }

    foreach ($res['documents'] as $doc) {

        if (!isset($doc['fields']['email']['stringValue'])) continue;
        if ($doc['fields']['email']['stringValue'] !== $USER->email) continue;

        // Lecture des états
        $etat  = $doc['fields']['etat']['stringValue']  ?? 'ras';

        $blocked = ($etat !== 'ras');

        if ($blocked) {
            $reason = $doc['fields']['signalMessage']['stringValue'] ?? null;
            return [
                'allowed'  => false,
                'userdata' => $doc['fields'],
                'reason'   => $reason
            ];
        }

        return [
            'allowed'  => true,
            'userdata' => $doc['fields'],
            'reason'   => null
        ];
    }

    // L’utilisateur n’est pas enregistré dans la collection
    return [
        'allowed'  => false,
        'userdata' => null,
        'reason'   => 'register'
    ];
}

/**
 * GET simple Firestore
 */
function local_biblio_enspy_firestore_get($url, $token) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        debugging('Firestore GET error: ' . curl_error($ch), DEBUG_DEVELOPER);
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    return json_decode($response, true);
}


/**
 * Generic Firestore request (GET/POST/PATCH/DELETE)
 */
function local_biblio_enspy_firestore_request($method, $url, $accessToken, $body = null, $timeout = 10) {

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerr  = curl_errno($ch) ? curl_error($ch) : null;

    curl_close($ch);

    return [
        'httpcode'   => $httpcode,
        'response'   => $response,
        'curl_error' => $curlerr
    ];
}


/**
 * Formatage Firestore fields
 */
function local_biblio_enspy_format_firestore_data(array $data) {
    $formatted = [];

    foreach ($data as $key => $value) {

        if (is_int($value)) {
            $formatted[$key] = ['integerValue' => $value];

        } else if (is_float($value)) {
            $formatted[$key] = ['doubleValue' => $value];

        } else if (is_bool($value)) {
            $formatted[$key] = ['booleanValue' => $value];

        } else if (is_array($value)) {
            $vals = [];
            foreach ($value as $v) {
                $vals[] = ['stringValue' => (string)$v];
            }
            $formatted[$key] = ['arrayValue' => ['values' => $vals]];

        } else {
            $formatted[$key] = ['stringValue' => (string)$value];
        }
    }

    return $formatted;
}


/**
 * Vérifie que Firestore est disponible avant de continuer
 */
function local_biblio_enspy_require_service($exitOnFail = true) {
    global $OUTPUT;

    [$projectId, $accessToken] = biblio_load_google_credentials();

    if (!$projectId || !$accessToken) {
        $msg = "Impossible de charger les credentials Firestore.";
        if ($exitOnFail) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification($msg, \core\output\notification::NOTIFY_ERROR);
            echo $OUTPUT->footer();
            exit;
        }
        return false;
    }

    $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents";
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $msg = "Firestore est inaccessible.";
        if ($exitOnFail) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification($msg, \core\output\notification::NOTIFY_ERROR);
            echo $OUTPUT->footer();
            exit;
        }
        return false;
    }

    return true;
}


//==================================== NOTIFICATION ================================================

/**
 * convertit une notification Firebase en notification native Moodle.
 */

function local_biblio_enspy_add_to_moodle_notif($firebase_notif) {
    global $DB;

    // 1. Trouver l'utilisateur Moodle par son email (userId dans Firebase)
    $user = $DB->get_record('user', array('email' => $firebase_notif->userId));
    if (!$user) return;

    // 2. Création de l'objet de notification Moodle
    $eventdata = new \core\message\message();
    $eventdata->component         = 'local_biblio_enspy';    // Votre plugin
    $eventdata->name              = 'reservation_updates';   // Type défini dans messages.php
    $eventdata->userfrom          = \core_user::get_noreply_user();
    $eventdata->userto            = $user;
    $eventdata->subject           = $firebase_notif->title;
    $eventdata->fullmessage       = $firebase_notif->message;
    $eventdata->fullmessageformat = FORMAT_HTML;
    $eventdata->fullmessagehtml   = '<p>'.$firebase_notif->message.'</p>';
    $eventdata->smallmessage      = $firebase_notif->message;
    $eventdata->notification      = 1; // 1 pour cloche, 0 pour message privé

    // 3. Envoi vers la cloche native
    return message_send($eventdata);
}
