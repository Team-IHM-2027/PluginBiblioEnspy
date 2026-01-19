<?php
/**
 * Firestore listener for real-time notifications
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_biblio_enspy;

defined('MOODLE_INTERNAL') || die();

class firestore_listener {

    private $access_token;
    private $project_id;

    /**
     * Constructor
     */
    public function __construct() {
        global $CFG;
        
        // Charger l'autoload Composer si pas déjà fait
        $vendor_autoload = $CFG->dirroot . '/vendor/autoload.php';
        if (file_exists($vendor_autoload)) {
            require_once($vendor_autoload);
        } else {
            throw new \Exception('Composer autoload not found. Run: composer install');
        }
        
        // Vérifier que la classe Google Auth existe
        if (!class_exists('\Google\Auth\Credentials\ServiceAccountCredentials')) {
            throw new \Exception('Google Auth library not found. Run: composer require google/auth');
        }
        
        // Charger les credentials Firebase
        $service_account_path = $CFG->dirroot . '/local/biblio_enspy/firebase_credentials.json';
        
        if (!file_exists($service_account_path)) {
            throw new \Exception('Firebase credentials file not found at: ' . $service_account_path);
        }
        
        $firebase_data = json_decode(file_get_contents($service_account_path), true);
        
        if (!isset($firebase_data['project_id'])) {
            throw new \Exception('Invalid firebase_credentials.json: project_id not found');
        }
        
        $this->project_id = $firebase_data['project_id'];
        
        // Obtenir le token d'accès
        $this->access_token = $this->get_access_token($service_account_path);
    }

    /**
     * Obtenir le token d'accès Firebase
     *
     * @param string $service_account_path
     * @return string
     */
    private function get_access_token($service_account_path) {
        try {
            $scopes = ['https://www.googleapis.com/auth/datastore'];
            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $service_account_path);
            $auth_token = $credentials->fetchAuthToken();
            
            if (!isset($auth_token['access_token'])) {
                throw new \Exception('Failed to get access token from Google Auth');
            }
            
            return $auth_token['access_token'];
        } catch (\Exception $e) {
            throw new \Exception('Error getting Firebase access token: ' . $e->getMessage());
        }
    }

    /**
     * Récupérer les nouvelles notifications depuis Firestore
     * Méthode alternative utilisant listDocuments au lieu de runQuery
     *
     * @param string $user_email Email de l'utilisateur
     * @param int $since_timestamp Timestamp depuis lequel récupérer
     * @return array
     */
    public function fetch_new_notifications($user_email, $since_timestamp = 0) {
        // MÉTHODE 1: Essayer avec listDocuments
        $url = "https://firestore.googleapis.com/v1/projects/{$this->project_id}/databases/(default)/documents/Notifications";
        
        debugging('Firestore: Fetching ALL documents then filtering for user: ' . $user_email, DEBUG_DEVELOPER);
        
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
            debugging('Erreur Firestore: HTTP ' . $http_code . ' - ' . $response, DEBUG_DEVELOPER);
            return [];
        }
        
        $data = json_decode($response, true);
        
        // Extraire les documents
        $documents = $data['documents'] ?? [];
        
        debugging('Firestore: Total documents fetched: ' . count($documents), DEBUG_DEVELOPER);
        
        // Parser et filtrer manuellement
        $notifications = [];
        
        foreach ($documents as $doc) {
            $fields = $doc['fields'] ?? [];
            
            // Extraire userId AVANT le parsing
            $doc_user_id = $this->extract_field_value($fields, 'userId');
            
            debugging("Firestore: Document userId = '$doc_user_id' (looking for '$user_email')", DEBUG_DEVELOPER);
            
            // FILTRE STRICT: Ignorer si userId ne correspond pas EXACTEMENT
            if ($doc_user_id !== $user_email) {
                debugging("Firestore: SKIPPING document - userId mismatch", DEBUG_DEVELOPER);
                continue;
            }
            
            // Extraire le timestamp
            $timestamp_str = $this->extract_field_value($fields, 'timestamp', 'timestampValue');
            
            // Filtrer par timestamp si nécessaire
            if ($since_timestamp > 0 && $timestamp_str) {
                $notif_timestamp = strtotime($timestamp_str);
                if ($notif_timestamp <= $since_timestamp) {
                    debugging("Firestore: SKIPPING document - too old", DEBUG_DEVELOPER);
                    continue;
                }
            }
            
            // Extraire l'ID du document
            $path_parts = explode('/', $doc['name']);
            $doc_id = end($path_parts);
            
            // Parser la notification complète
            $notification = [
                'id' => $doc_id,
                'userId' => $doc_user_id,
                'title' => $this->extract_field_value($fields, 'title'),
                'message' => $this->extract_field_value($fields, 'message'),
                'type' => $this->extract_field_value($fields, 'type'),
                'bookId' => $this->extract_field_value($fields, 'bookId'),
                'bookTitle' => $this->extract_field_value($fields, 'bookTitle'),
                'status' => $this->extract_field_value($fields, 'status'),
                'reason' => $this->extract_field_value($fields, 'reason'),
                'librarianName' => $this->extract_field_value($fields, 'librarianName'),
                'updateDate' => $this->extract_field_value($fields, 'updateDate'),
                'read' => $this->extract_field_value($fields, 'read', 'booleanValue'),
                'timestamp' => $timestamp_str,
            ];
            
            $notifications[] = $notification;
            debugging("Firestore: ACCEPTED notification: {$notification['title']}", DEBUG_DEVELOPER);
        }
        
        debugging('Firestore: Final filtered notifications count: ' . count($notifications), DEBUG_DEVELOPER);
        
        // Trier par timestamp décroissant
        usort($notifications, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        
        // Limiter à 50
        return array_slice($notifications, 0, 50);
    }

    /**
     * Extraire la valeur d'un champ Firestore
     *
     * @param array $fields
     * @param string $field_name
     * @param string $value_type
     * @return mixed
     */
    private function extract_field_value($fields, $field_name, $value_type = 'stringValue') {
        if (!isset($fields[$field_name])) {
            return null;
        }
        
        $field = $fields[$field_name];
        
        if (isset($field[$value_type])) {
            return $field[$value_type];
        }
        
        // Essayer tous les types possibles
        $types = ['stringValue', 'integerValue', 'booleanValue', 'timestampValue', 'doubleValue'];
        foreach ($types as $type) {
            if (isset($field[$type])) {
                return $field[$type];
            }
        }
        
        return null;
    }

    /**
     * Synchroniser les notifications pour un utilisateur
     *
     * @param string $user_email
     * @return array Résultats de la synchronisation
     */
    public function sync_notifications_for_user($user_email) {
        $results = [
            'success' => 0,
            'errors' => 0,
            'already_exists' => 0,
            'notifications' => []
        ];
        
        // Obtenir l'utilisateur Moodle
        $user = notification_manager::get_user_by_email($user_email);
        if (!$user) {
            $results['errors']++;
            return $results;
        }
        
        // Obtenir le dernier timestamp de sync
        $last_sync = notification_manager::get_last_sync_timestamp($user_email);
        
        // Récupérer les nouvelles notifications
        $notifications = $this->fetch_new_notifications($user_email, $last_sync);
        
        foreach ($notifications as $notif) {
            // Vérifier si la notification existe déjà
            if (notification_manager::notification_exists($notif['id'])) {
                $results['already_exists']++;
                continue;
            }
            
            // Créer la notification Moodle
            $moodle_notif_id = notification_manager::create_moodle_notification($user, $notif);
            
            if ($moodle_notif_id) {
                // Enregistrer la synchronisation
                $sync_result = notification_manager::save_sync_record(
                    $notif['id'],
                    $user->id,
                    $user_email,
                    $moodle_notif_id,
                    $notif
                );
                
                if ($sync_result) {
                    $results['success']++;
                    $results['notifications'][] = [
                        'firestore_id' => $notif['id'],
                        'moodle_id' => $moodle_notif_id,
                        'title' => $notif['title']
                    ];
                } else {
                    $results['errors']++;
                }
            } else {
                $results['errors']++;
            }
        }
        
        return $results;
    }
}