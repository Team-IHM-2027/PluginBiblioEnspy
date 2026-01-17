<?php
// VERSION: 2024-01-18 - Transaction atomique Firestore
error_log("=== API CANCEL CHARGÉ - VERSION TRANSACTION COMMIT ===");

header('Content-Type: application/json');
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

require_login();
if (!isloggedin() || isguestuser()) {
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
error_log("Input reçu: " . json_encode($input));

if (!$input || !isset($input['itemId'], $input['userDocId'])) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes (itemId ou userDocId).']);
    exit;
}

$itemId = $input['itemId'];
$userDocId = $input['userDocId'];

$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

if (!$accessToken) {
    echo json_encode(['success' => false, 'message' => 'Erreur d\'authentification Firebase.']);
    exit;
}

function callFirestoreAPI($url, $accessToken, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'PATCH' || $method === 'POST') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        error_log('Erreur cURL: ' . curl_error($ch));
        return null;
    }
    curl_close($ch);
    return ['httpcode' => $httpcode, 'response' => json_decode($response, true)];
}

try {
    // 1. Récupérer l'utilisateur
    $userUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser/{$userDocId}";
    $userDataResult = callFirestoreAPI($userUrl, $accessToken);
    
    if ($userDataResult['httpcode'] !== 200 || !isset($userDataResult['response']['fields'])) {
        throw new Exception('Utilisateur introuvable.');
    }
    
    $userFields = $userDataResult['response']['fields'];
    
    // 2. Trouver le slot contenant cette réservation
    $slotFound = null;
    $collectionName = null;
    $maxReservations = 3;
    
    for ($i = 1; $i <= $maxReservations; $i++) {
        $etatField = "etat{$i}";
        $tabEtatField = "tabEtat{$i}";
        
        $currentStatus = $userFields[$etatField]['stringValue'] ?? 'ras';
        
        // On annule UNIQUEMENT les réservations, pas les emprunts
        if ($currentStatus === 'reserv' && isset($userFields[$tabEtatField]['arrayValue']['values'])) {
            $tabValues = $userFields[$tabEtatField]['arrayValue']['values'];
            
            // Vérifier si c'est le bon document (index 6 = docId)
            if (isset($tabValues[6]['stringValue']) && $tabValues[6]['stringValue'] === $itemId) {
                $slotFound = $i;
                // Récupérer la collection (index 4)
                $collectionName = $tabValues[4]['stringValue'] ?? 'BiblioBooks';
                error_log("Réservation trouvée: slot={$slotFound}, collection={$collectionName}");
                break;
            }
        }
    }
    
    if ($slotFound === null) {
        throw new Exception('Réservation introuvable. Vous ne pouvez annuler que les réservations en attente.');
    }
    
    $etatField = "etat{$slotFound}";
    $tabEtatField = "tabEtat{$slotFound}";
    
    // 3. Préparer la transaction atomique Firestore
    $commitData = [
        'writes' => [
            // Incrémenter le stock (+1 car on annule la réservation)
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
            // Réinitialiser l'état de réservation
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
    
    error_log("Transaction commit data: " . json_encode($commitData));
    
    // 4. Exécuter la transaction
    $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
    $result = callFirestoreAPI($commitUrl, $accessToken, 'POST', $commitData);
    
    error_log("Commit response HTTP: " . ($result['httpcode'] ?? 'N/A'));
    
    if ($result['httpcode'] !== 200) {
        error_log('Erreur commit Firestore: ' . json_encode($result['response']));
        throw new Exception('Erreur lors de l\'annulation de la réservation.');
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Réservation annulée avec succès.',
        'slot' => $slotFound,
        'collection' => $collectionName
    ]);

} catch (Exception $e) {
    http_response_code(400);
    error_log('Erreur API Cancel: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
?>