<?php
// test_auth.php - à mettre dans local/biblio_enspy/
require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

echo "<pre>";
echo "=== TEST AUTHENTIFICATION FIREBASE ===\n";

$serviceAccountJson = __DIR__ . '/firebase_credentials.json';
echo "1. Fichier credentials: " . (file_exists($serviceAccountJson) ? "OK" : "MANQUANT") . "\n";

if (file_exists($serviceAccountJson)) {
    $json = json_decode(file_get_contents($serviceAccountJson), true);
    echo "2. JSON valide: " . ($json ? "OUI" : "NON") . "\n";
    echo "3. Project ID dans JSON: " . ($json['project_id'] ?? 'NON TROUVÉ') . "\n";
    
    try {
        $scopes = ['https://www.googleapis.com/auth/datastore'];
        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
        
        echo "4. Tentative d'obtention du token...\n";
        $token = $credentials->fetchAuthToken();
        
        if (isset($token['access_token'])) {
            echo "5. ✅ TOKEN OBTENU\n";
            echo "   Longueur: " . strlen($token['access_token']) . " caractères\n";
            echo "   Type: " . ($token['token_type'] ?? 'inconnu') . "\n";
            echo "   Expire dans: " . ($token['expires_in'] ?? '?') . " secondes\n";
            
            // Test simple Firestore
            echo "\n6. Test Firestore...\n";
            $url = "https://firestore.googleapis.com/v1/projects/biblio-cc84b/databases/(default)/documents/BiblioBooks?pageSize=1";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token['access_token']]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo "   HTTP Code: $httpCode\n";
            
            if ($httpCode === 200) {
                echo "   ✅ CONNEXION FIREBASE RÉUSSIE\n";
                $data = json_decode($response, true);
                echo "   Documents trouvés: " . (isset($data['documents']) ? count($data['documents']) : 0) . "\n";
            } else {
                echo "   ❌ ÉCHEC: " . substr($response, 0, 200) . "\n";
            }
            
        } else {
            echo "5. ❌ ÉCHEC: Pas de token dans la réponse\n";
            print_r($token);
        }
        
    } catch (Exception $e) {
        echo "5. ❌ EXCEPTION: " . $e->getMessage() . "\n";
    }
}

echo "</pre>";
