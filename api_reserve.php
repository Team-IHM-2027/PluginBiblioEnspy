<?php
header('Content-Type: application/json');
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// 1. Vérifier si la requête est bien en POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Option A : Rediriger vers l'accueil du plugin
    $url = new moodle_url('/local/biblio_enspy/explore.php');
    redirect($url, "Accès direct interdit. Redirection...", 3);
    
    // Option B : Envoyer une erreur 404 (plus sécurisé)
    // send_header_404();
    // die();
}

require_login();
if (!isloggedin() || isguestuser()) {
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['itemId'], $input['itemType'], $input['userDocId'])) {
    echo json_encode(['success' => false, 'message' => 'Données manquantes.']);
    exit;
}

$itemId = $input['itemId'];
$itemType = $input['itemType'];
$userDocId = $input['userDocId'];

// Détermination des collections à vérifier
$primaryCollection = $input['collectionName'] ?? (($itemType === 'books') ? 'BiblioBooks' : 'BiblioThesis');

// Liste des collections de secours
$fallbackCollections = [
    'BiblioGM', 
    'BiblioGE', 
    'BiblioGI', 
    'BiblioGT', 
    'BiblioInformatique',
    'BiblioBooks',
    'BiblioThesis'
];

// Garantir que la collection primaire est vérifiée en premier et supprimer les doublons
$collectionsToSearch = array_unique(array_merge([$primaryCollection], $fallbackCollections));
$collection = $primaryCollection;

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
        error_log('Erreur cURL pour ' . $url . ' : ' . curl_error($ch));
        return null;
    }
    curl_close($ch);
    return ['httpcode' => $httpcode, 'response' => json_decode($response, true)];
}

try {
    // Récupérer le livre/mémoire (avec tentatives multiples) ---
    $itemFound = false;
    $itemFields = null;
    $collectionFound = null;

    foreach ($collectionsToSearch as $currentCollection) {
        $itemUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$currentCollection}/{$itemId}";
        $itemDataResult = callFirestoreAPI($itemUrl, $accessToken);
        
        // Journalisation pour le débogage (à retirer en production)
        error_log("Tentative de récupération de {$itemId} dans {$currentCollection}. HTTP Code: " . ($itemDataResult['httpcode'] ?? 'N/A'));

        if (($itemDataResult['httpcode'] ?? 0) === 200 && isset($itemDataResult['response']['fields'])) {
            $itemFields = $itemDataResult['response']['fields'];
            $collectionFound = $currentCollection;
            $itemFound = true;
            break;
        }
    }

    if (!$itemFound) {
        throw new Exception('Document de livre/mémoire introuvable après vérification de toutes les collections.');
    }
    
    // Mettre à jour la variable $collection avec la collection où le livre a été trouvé
    $collection = $collectionFound; 

    $nombreExemplaire = (int)($itemFields['exemplaire']['integerValue'] ?? 0);
    $exemplaireApresReservation = $nombreExemplaire - 1; 

    if ($exemplaireApresReservation < 0) {
        throw new Exception('Plus d\'exemplaires disponibles.');
    }
    
    // Extraction des champs nécessaires pour tabEtat{i}
    $itemName = $itemFields['name']['stringValue'] ?? $itemFields['Nom']['stringValue'] ?? 'Titre inconnu';
    $itemCathegorie = $itemFields['cathegorie']['stringValue'] ?? 'cathegorie inconnu';
    $itemImage = $itemFields['image']['stringValue'] ?? 'none';
    $currentTimestamp = gmdate('Y-m-d\TH:i:s\Z');


    // Récupérer l'utilisateur
    $userUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioUser/{$userDocId}";
    $userDataResult = callFirestoreAPI($userUrl, $accessToken);
    if ($userDataResult['httpcode'] !== 200 || !isset($userDataResult['response']['fields'])) {
        throw new Exception('Utilisateur introuvable.');
    }
    $userFields = $userDataResult['response']['fields'];

    $etatIndex = 0;
    $maxReservations = 5; 

for ($i = 0; $i < $maxReservations; $i++) {
    $val = $userFields["etat{$i}"]['stringValue'] ?? 'ras';
    
    if ($val === 'reserv' || $val === 'emprunt') {
        // Vérifier si le livre est déjà réservé par l'utilisateur
        if (isset($userFields["tabEtat{$i}"]['arrayValue']['values']) && 
            is_array($userFields["tabEtat{$i}"]['arrayValue']['values'])) {
            
            $tabEtatValues = $userFields["tabEtat{$i}"]['arrayValue']['values'];
            
            // Vérifier par docId (index 6) 
            if (isset($tabEtatValues[6]['stringValue']) && 
                $tabEtatValues[6]['stringValue'] === $itemId) {
                
                if ($val === 'reserv') {
                    throw new Exception('Vous avez déjà réservé ce livre.');
                }
                elseif ($val === 'emprunt') {
                    throw new Exception('Vous avez déjà emprunté ce livre.');
                }
            }
        }
    }
    
    if ($val === 'ras' && $etatIndex === 0) {
        $etatIndex = $i;
    }
}

    $etatField = "etat{$etatIndex}";
    $tabEtatField = "tabEtat{$etatIndex}";


    // Préparer les données de tabEtat{i} (Liste/Array) ---
    // Structure du tableau (index 0 à 6): [name, cathegorie, image, exemplaires_restants, collectionName, Timestamp.now(), bookDoc.id]
    $tabEtatValues = [
        ['stringValue' => $itemName],                         // Index 0: name
        ['stringValue' => $itemCathegorie],                   // Index 1: cathegorie
        ['stringValue' => $itemImage],                        // Index 2: image
        ['integerValue' => (string)$exemplaireApresReservation], // Index 3: exemplaires_restants (doit être une string)
        ['stringValue' => $collectionFound],                  // Index 4: collectionName
        ['timestampValue' => $currentTimestamp],              // Index 5: Timestamp.now()
        ['stringValue' => $itemId]                            // Index 6: bookDoc.id
    ];
    
// Construction de la transaction COMMIT (Opérations Atomiques)
    $commitData = [
        'writes' => [
            // Décrémenter le stock (Atomic: transform/increment)
            [
                'transform' => [
                    'document' => "projects/{$projectId}/databases/(default)/documents/{$collection}/{$itemId}", 
                    'fieldTransforms' => [
                        [
                            'fieldPath' => 'exemplaire',
                            'increment' => ['integerValue' => '-1'] 
                        ]
                    ]
                ]
            ],
            // Mettre à jour l'état et les détails de réservation (Update simple)
            [
                'update' => [
                    'name' => "projects/{$projectId}/databases/(default)/documents/BiblioUser/{$userDocId}",
                    'fields' => [
                        $etatField => ['stringValue' => 'reserv'], // Met à jour etat{i}
                        $tabEtatField => ['arrayValue' => ['values' => $tabEtatValues]] // NOUVEAU: Met à jour tabEtat{i}
                    ],
                ],
                'updateMask' => [
                    'fieldPaths' => [$etatField, $tabEtatField] // Indiquez explicitement les deux champs mis à jour
                ]
            ]
        ]
    ];

    $commitUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents:commit";
    $result = callFirestoreAPI($commitUrl, $accessToken, 'POST', $commitData);
    if ($result['httpcode'] !== 200) {
        error_log('Erreur commit Firestore: ' . print_r($result, true));
        throw new Exception('Erreur lors de la réservation.');
    }

    echo json_encode(['success' => true, 'message' => "Réservation réussie dans la collection: {$collection}. Emplacement: {$etatIndex}/{$maxReservations}"]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;