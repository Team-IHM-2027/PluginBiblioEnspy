<?php
// ====== FONCTION DE LOG PERSONNALISÉE ======
function logDebug($message, $type = 'INFO') {
    $logFile = __DIR__ . '/biblio_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$type}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    error_log($message); // Log aussi dans les logs PHP standard
}

logDebug("=== API CANCEL DÉMARRÉ - VERSION COMMIT ATOMIQUE ===", "INFO");

header('Content-Type: application/json');
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

require_login();
if (!isloggedin() || isguestuser()) {
    logDebug("Accès refusé - utilisateur non connecté", "ERROR");
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
logDebug("Données reçues : " . json_encode($input), "INFO");

if (!$input || !isset($input['itemId'], $input['userDocId'])) {
    logDebug("Données manquantes dans la requête", "ERROR");
    echo json_encode(['success' => false, 'message' => 'Données manquantes.']);
    exit;
}

$itemId = $input['itemId'];
$userDocId = $input['userDocId'];
logDebug("ItemID: {$itemId}, UserDocID: {$userDocId}", "INFO");

$projectId = "biblio-cc84b";
$serviceAccountJson = __DIR__.'/firebase_credentials.json';
$scopes = ['https://www.googleapis.com/auth/datastore'];
$credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
$accessToken = $credentials->fetchAuthToken()['access_token'];

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
        logDebug('Erreur cURL pour ' . $url . ' : ' . curl_error($ch), "ERROR");
        return null;
    }
    curl_close($ch);
    return ['httpcode' => $httpcode, 'response' => json_decode($response, true)];
}

try {
    logDebug("Début de la récupération de l'utilisateur", "INFO");
    
    // 1. Récupérer l'utilisateur
    $userUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser/{$userDocId}";
    $userDataResult = callFirestoreAPI($userUrl, $accessToken);
    
    logDebug("Récupération utilisateur - HTTP Code: {$userDataResult['httpcode']}", "INFO");
    
    if ($userDataResult['httpcode'] !== 200 || !isset($userDataResult['response']['fields'])) {
        logDebug("Utilisateur introuvable ou réponse invalide", "ERROR");
        throw new Exception('Utilisateur introuvable.');
    }
    
    $userFields = $userDataResult['response']['fields'];
    logDebug("Utilisateur trouvé - Recherche du slot de réservation", "INFO");
    
    // 2. Trouver le slot contenant cette réservation
    $slotFound = null;
    $collectionName = null;
    $maxReservations = 3;
    
    for ($i = 1; $i <= $maxReservations; $i++) {
        $etatField = "etat{$i}";
        $tabEtatField = "tabEtat{$i}";
        
        $currentStatus = $userFields[$etatField]['stringValue'] ?? 'ras';
        logDebug("Vérification slot {$i} - Status: {$currentStatus}", "INFO");
        
        // On annule UNIQUEMENT les réservations, pas les emprunts
        if ($currentStatus === 'reserv' && isset($userFields[$tabEtatField]['arrayValue']['values'])) {
            $tabValues = $userFields[$tabEtatField]['arrayValue']['values'];
            
            // Vérifier si c'est le bon document (index 6 = docId)
            if (isset($tabValues[6]['stringValue']) && $tabValues[6]['stringValue'] === $itemId) {
                $slotFound = $i;
                // Récupérer la collection (index 4)
                $collectionName = $tabValues[4]['stringValue'] ?? 'BiblioBooks';
                logDebug("✅ Réservation trouvée dans slot {$i}, collection: {$collectionName}", "SUCCESS");
                break;
            }
        }
    }
    
    if ($slotFound === null) {
        logDebug("Aucune réservation trouvée pour cet item", "WARNING");
        throw new Exception('Réservation introuvable. Vous ne pouvez annuler que les réservations en attente.');
    }
    
    $etatField = "etat{$slotFound}";
    $tabEtatField = "tabEtat{$slotFound}";
    
    logDebug("Préparation de la transaction commit", "INFO");
    
    // 3. Préparer la transaction atomique Firestore (IDENTIQUE à api_reserve.php)
    $commitData = [
        'writes' => [
            // Incrémenter le stock (opération atomique inverse de la réservation)
            [
                'transform' => [
                    'document' => "projects/{$projectId}/databases/(default)/documents/{$collectionName}/{$itemId}",
                    'fieldTransforms' => [
                        [
                            'fieldPath' => 'exemplaire',
                            'increment' => ['integerValue' => '1'] // +1 pour annulation
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
                        $tabEtatField => ['arrayValue' => ['values' => []]] // Tableau vide
                    ]
                ],
                'updateMask' => [
                    'fieldPaths' => [$etatField, $tabEtatField]
                ]
            ]
        ]
    ];
    
    logDebug("Transaction commit préparée : " . json_encode($commitData), "INFO");
    
    // 4. Exécuter la transaction
    $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
    $result = callFirestoreAPI($commitUrl, $accessToken, 'POST', $commitData);
    
    logDebug("Transaction commit exécutée - HTTP Code: {$result['httpcode']}", "INFO");
    
    if ($result['httpcode'] !== 200) {
        logDebug('ERREUR commit Firestore : ' . json_encode($result['response']), "ERROR");
        throw new Exception('Erreur lors de l\'annulation de la réservation.');
    }
    
    logDebug("✅ ANNULATION RÉUSSIE - Slot: {$slotFound}, Collection: {$collectionName}", "SUCCESS");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Réservation annulée avec succès.',
        'slot' => $slotFound,
        'collection' => $collectionName
    ]);

} catch (Exception $e) {
    logDebug("❌ EXCEPTION: " . $e->getMessage(), "ERROR");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

logDebug("=== API CANCEL TERMINÉ ===", "INFO");
exit;
?>
