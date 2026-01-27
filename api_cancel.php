<?php
/**
 * API d'annulation de réservation
 * Utilise une transaction atomique Firestore pour garantir la cohérence
 * 
 * @package    local_biblio_enspy
 * @copyright  2026
 */

header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

//  Vérifier si la requête est bien en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Option A : Rediriger vers l'accueil du plugin
    $url = new moodle_url('/local/biblio_enspy/explore.php');
    redirect($url, "Accès direct interdit. Redirection...", 3);
}

// Vérification de l'authentification
require_login();
if (!isloggedin() || isguestuser()) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Accès refusé. Connexion requise.'
    ]);
    exit;
}


// Maintenance check
list($maintenanceProjectId, $maintenanceToken) = biblio_load_google_credentials();
if ($maintenanceProjectId && $maintenanceToken) {
    biblio_require_no_maintenance($maintenanceProjectId, $maintenanceToken, true);
}

// Récupération et validation des données
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['itemId'], $input['userDocId'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Données manquantes dans la requête.'
    ]);
    exit;
}

$itemId = clean_param($input['itemId'], PARAM_ALPHANUMEXT);
$userDocId = clean_param($input['userDocId'], PARAM_RAW);
$itemType = isset($input['itemType']) ? clean_param($input['itemType'], PARAM_ALPHA) : 'books';

// Configuration Firestore
$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__ . '/firebase_credentials.json';

if (!file_exists($serviceAccountJson)) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Configuration Firebase manquante.'
    ]);
    exit;
}

try {
    $scopes = ['https://www.googleapis.com/auth/datastore'];
    $credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
    $accessToken = $credentials->fetchAuthToken()['access_token'];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Erreur d\'authentification Firebase.'
    ]);
    exit;
}

/**
 * Appel API Firestore générique
 */
function callFirestoreAPI($url, $accessToken, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    if ($method === 'PATCH' || $method === 'POST') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('Erreur réseau: ' . $error);
    }
    
    return [
        'httpcode' => $httpcode, 
        'response' => json_decode($response, true)
    ];
}

try {
    // 1. Récupérer le document utilisateur
    // Modifiez la ligne 89
    $userUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser/" . urlencode($userDocId);
    $userDataResult = callFirestoreAPI($userUrl, $accessToken);
    
    if ($userDataResult['httpcode'] !== 200 || !isset($userDataResult['response']['fields'])) {
        throw new Exception('Utilisateur introuvable. :');
    }
    
    $userFields = $userDataResult['response']['fields'];
    
    // 2. Rechercher le slot contenant cette réservation
    $slotFound = null;
    $collectionName = null;
    $maxReservations = 5;
    
    for ($i = 0; $i < $maxReservations; $i++) {
        $etatField = "etat{$i}";
        $tabEtatField = "tabEtat{$i}";
        
        $currentStatus = $userFields[$etatField]['stringValue'] ?? 'ras';
        
        // On ne peut annuler QUE les réservations (pas les emprunts)
        if ($currentStatus === 'reserv' && isset($userFields[$tabEtatField]['arrayValue']['values'])) {
            $tabValues = $userFields[$tabEtatField]['arrayValue']['values'];
            
            // Vérifier si c'est le bon document (index 6 = docId)
            if (isset($tabValues[6]['stringValue']) && $tabValues[6]['stringValue'] === $itemId) {
                $slotFound = $i;
                // Récupérer la collection (index 4)
                $collectionName = $tabValues[4]['stringValue'] ?? 
                    (($itemType === 'books') ? 'BiblioBooks' : 'BiblioThesis');
                break;
            }
        }
    }
    
    if ($slotFound === null) {
        throw new Exception(
            'Réservation introuvable. Vous ne pouvez annuler que les réservations en attente ' .
            '(les emprunts validés ne peuvent pas être annulés ici).'
        );
    }
    
    $etatField = "etat{$slotFound}";
    $tabEtatField = "tabEtat{$slotFound}";
    
    // 3. Préparer la transaction atomique Firestore
    // Cette transaction garantit que le stock est incrémenté ET l'état utilisateur réinitialisé
    // de manière atomique (tout réussit ou tout échoue)
    $commitData = [
        'writes' => [
            // Opération 1 : Incrémenter le stock du document (+1 car annulation)
            [
                'transform' => [
                    'document' => "projects/{$projectId}/databases/(default)/documents/{$collectionName}/{$itemId}",
                    'fieldTransforms' => [
                        [
                            'fieldPath' => 'exemplaire',
                            'increment' => ['integerValue' => '1']
                        ]
                    ]
                ]
            ],
            // Opération 2 : Réinitialiser l'état de réservation de l'utilisateur
            [
                'update' => [
                    'name' => "projects/{$projectId}/databases/(default)/documents/BiblioUser/{$userDocId}",
                    'fields' => [
                        $etatField => ['stringValue' => 'ras'],
                        $tabEtatField => ['arrayValue' => ['values' => []]]
                    ]
                ],
                'updateMask' => [
                    'fieldPaths' => [$etatField, $tabEtatField]
                ]
            ]
        ]
    ];
    
    // 4. Exécuter la transaction atomique
    $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
    $result = callFirestoreAPI($commitUrl, $accessToken, 'POST', $commitData);
    
    if ($result['httpcode'] !== 200) {
        throw new Exception('Erreur lors de l\'annulation de la réservation. Veuillez réessayer.');
    }
    
    // Succès
    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => 'Réservation annulée avec succès.',
        'slot' => $slotFound,
        'collection' => $collectionName
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

exit;
