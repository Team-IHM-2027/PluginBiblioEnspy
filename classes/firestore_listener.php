<?php
/**
 * Firestore listener - Version simplifiée
 * Le filtrage est fait côté JavaScript, PHP ne fait que créer les notifications
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_biblio_enspy;

defined('MOODLE_INTERNAL') || die();

class firestore_listener {
    
    // Cette classe n'est plus nécessaire pour la synchronisation temps réel
    // mais gardée pour compatibilité et tests manuels
    
    private $access_token;
    private $project_id;

    /**
     * Constructor
     */
    public function __construct() {
        global $CFG;
        
        $vendor_autoload = $CFG->dirroot . '/vendor/autoload.php';
        if (file_exists($vendor_autoload)) {
            require_once($vendor_autoload);
        } else {
            throw new \Exception('Composer autoload not found');
        }
        
        if (!class_exists('\Google\Auth\Credentials\ServiceAccountCredentials')) {
            throw new \Exception('Google Auth library not found');
        }
        
        $service_account_path = $CFG->dirroot . '/local/biblio_enspy/firebase_credentials.json';
        
        if (!file_exists($service_account_path)) {
            throw new \Exception('Firebase credentials file not found');
        }
        
        $firebase_data = json_decode(file_get_contents($service_account_path), true);
        
        if (!isset($firebase_data['project_id'])) {
            throw new \Exception('Invalid firebase_credentials.json');
        }
        
        $this->project_id = $firebase_data['project_id'];
        $this->access_token = $this->get_access_token($service_account_path);
    }

    /**
     * Obtenir le token d'accès Firebase
     */
    private function get_access_token($service_account_path) {
        try {
            $scopes = ['https://www.googleapis.com/auth/datastore'];
            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $service_account_path);
            $auth_token = $credentials->fetchAuthToken();
            
            if (!isset($auth_token['access_token'])) {
                throw new \Exception('Failed to get access token');
            }
            
            return $auth_token['access_token'];
        } catch (\Exception $e) {
            throw new \Exception('Error getting Firebase access token: ' . $e->getMessage());
        }
    }

    /**
     * Récupérer toutes les notifications d'un utilisateur (pour tests manuels)
     * 
     * @param string $user_email
     * @return array
     */
    public function fetch_all_user_notifications($user_email) {
        $url = "https://firestore.googleapis.com/v1/projects/{$this->project_id}/databases/(default)/documents/Notifications";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->access_token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return [];
        }
        
        $data = json_decode($response, true);
        $documents = $data['documents'] ?? [];
        
        $notifications = [];
        
        foreach ($documents as $doc) {
            $fields = $doc['fields'] ?? [];
            $doc_user_id = $this->extract_field_value($fields, 'userId');
            
            if ($doc_user_id !== $user_email) {
                continue;
            }
            
            $path_parts = explode('/', $doc['name']);
            $doc_id = end($path_parts);
            
            $notifications[] = [
                'id' => $doc_id,
                'userId' => $doc_user_id,
                'title' => $this->extract_field_value($fields, 'title'),
                'message' => $this->extract_field_value($fields, 'message'),
                'type' => $this->extract_field_value($fields, 'type'),
                'bookId' => $this->extract_field_value($fields, 'bookId'),
                'bookTitle' => $this->extract_field_value($fields, 'bookTitle'),
                'status' => $this->extract_field_value($fields, 'status'),
                'timestamp' => $this->extract_field_value($fields, 'timestamp', 'timestampValue'),
            ];
        }
        
        return $notifications;
    }

    /**
     * Extraire la valeur d'un champ Firestore
     */
    private function extract_field_value($fields, $field_name, $value_type = 'stringValue') {
        if (!isset($fields[$field_name])) {
            return null;
        }
        
        $field = $fields[$field_name];
        
        if (isset($field[$value_type])) {
            return $field[$value_type];
        }
        
        $types = ['stringValue', 'integerValue', 'booleanValue', 'timestampValue', 'doubleValue'];
        foreach ($types as $type) {
            if (isset($field[$type])) {
                return $field[$type];
            }
        }
        
        return null;
    }
}