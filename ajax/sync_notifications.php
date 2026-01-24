<?php
/**
 * AJAX endpoint for real-time notification synchronization
 * Reçoit les notifications déjà filtrées depuis JavaScript
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/biblio_enspy/classes/notification_manager.php');

// Définir le type de contenu
header('Content-Type: application/json');

try {
    global $USER, $DB, $PAGE;
    
    // Vérifier que l'utilisateur est connecté
    require_login(null, false, null, false, true);
    
    // Définir le contexte
    $PAGE->set_context(context_system::instance());
    
    // Lire les données JSON envoyées depuis JavaScript
    $json_input = file_get_contents('php://input');
    $input_data = json_decode($json_input, true);
    
    if (!isset($input_data['notifications']) || !is_array($input_data['notifications'])) {
        throw new Exception('Invalid input: notifications array required');
    }
    
    $notifications = $input_data['notifications'];
    
    $results = [
        'success' => 0,
        'errors' => 0,
        'already_exists' => 0,
        'notifications' => []
    ];
    
    // Obtenir l'utilisateur
    $user = $DB->get_record('user', [
        'id' => $USER->id,
        'deleted' => 0,
        'suspended' => 0
    ]);
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Traiter chaque notification
    foreach ($notifications as $notif_data) {
        // Vérifier les champs requis
        if (empty($notif_data['id'])) {
            $results['errors']++;
            continue;
        }
        
        // Vérifier si la notification existe déjà
        if (\local_biblio_enspy\notification_manager::notification_exists($notif_data['id'])) {
            $results['already_exists']++;
            continue;
        }
        
        // Créer la notification Moodle
        $moodle_notif_id = \local_biblio_enspy\notification_manager::create_moodle_notification($user, $notif_data);
        
        if ($moodle_notif_id) {
            // Enregistrer la synchronisation
            $sync_result = \local_biblio_enspy\notification_manager::save_sync_record(
                $notif_data['id'],
                $user->id,
                $user->email,
                $moodle_notif_id,
                $notif_data
            );
            
            if ($sync_result) {
                $results['success']++;
                $results['notifications'][] = [
                    'firestore_id' => $notif_data['id'],
                    'moodle_id' => $moodle_notif_id,
                    'title' => $notif_data['title'] ?? 'Notification'
                ];
            } else {
                $results['errors']++;
            }
        } else {
            $results['errors']++;
        }
    }
    
    // Compter les notifications non lues
    $unread_count = $DB->count_records_sql(
        "SELECT COUNT(*) 
         FROM {notifications} n
         WHERE n.useridto = :userid 
         AND n.timeread IS NULL",
        ['userid' => $USER->id]
    );
    
    // Retourner les résultats
    echo json_encode([
        'success' => true,
        'results' => $results,
        'unread_count' => $unread_count,
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    
    debugging('Sync notification error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}