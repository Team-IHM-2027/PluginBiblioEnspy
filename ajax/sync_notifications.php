<?php
/**
 * AJAX endpoint for real-time notification synchronization
 * Version avec logs détaillés pour debug
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// Log file pour debug
$log_file = __DIR__ . '/sync_debug.log';
function write_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

write_log("=== DÉBUT APPEL AJAX ===");

// Capturer les erreurs fatales
register_shutdown_function(function() use ($log_file) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $msg = "FATAL ERROR: {$error['message']} in {$error['file']} on line {$error['line']}";
        file_put_contents($log_file, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND);
        
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error occurred',
            'details' => $msg
        ]);
    }
});

try {
    write_log("1. Chargement config.php");
    require_once(__DIR__ . '/../../../config.php');
    write_log("2. Config chargé - User: " . (isset($USER) ? $USER->email : 'non connecté'));
    
    // Charger autoload Composer si disponible
    $vendor_paths = [
        $CFG->dirroot . '/vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
    ];
    
    foreach ($vendor_paths as $vendor_path) {
        if (file_exists($vendor_path)) {
            require_once($vendor_path);
            write_log("3. Composer autoload chargé: $vendor_path");
            break;
        }
    }
    
    write_log("4. Chargement des classes");
    require_once($CFG->dirroot . '/local/biblio_enspy/classes/firestore_listener.php');
    require_once($CFG->dirroot . '/local/biblio_enspy/classes/notification_manager.php');
    write_log("5. Classes chargées");
    
    // Définir le type de contenu
    header('Content-Type: application/json');
    
    write_log("6. Vérification session");
    // Vérifier que l'utilisateur est connecté
    require_login(null, false, null, false, true);
    write_log("7. Session OK");
    
    global $USER, $DB, $PAGE;
    
    // IMPORTANT: Définir le contexte pour éviter l'erreur dans format_text()
    $context = context_system::instance();
    $PAGE->set_context($context);
    write_log("7.5. Contexte défini");
    
    // Récupérer l'email de l'utilisateur
    $user_email = $USER->email;
    write_log("8. Email utilisateur: $user_email");
    
    if (empty($user_email)) {
        throw new Exception('User email not found');
    }
    
    write_log("9. Création listener");
    // Créer le listener Firestore
    $listener = new \local_biblio_enspy\firestore_listener();
    write_log("10. Listener créé");
    
    write_log("11. Début synchronisation");
    // Synchroniser les notifications
    $results = $listener->sync_notifications_for_user($user_email);
    write_log("12. Sync terminée: " . json_encode($results));
    
    write_log("13. Comptage notifications non lues");
    // Obtenir le nombre de notifications non lues
    $unread_count = $DB->count_records_sql(
        "SELECT COUNT(*) 
         FROM {notifications} n
         WHERE n.useridto = :userid 
         AND n.timeread IS NULL",
        ['userid' => $USER->id]
    );
    write_log("14. Notifications non lues: $unread_count");
    
    // Retourner les résultats
    $response = [
        'success' => true,
        'results' => $results,
        'unread_count' => $unread_count,
        'user_email' => $user_email,
        'timestamp' => time()
    ];
    
    write_log("15. Envoi réponse: " . json_encode($response));
    echo json_encode($response, JSON_PRETTY_PRINT);
    write_log("=== FIN APPEL AJAX (SUCCESS) ===\n");
    
} catch (Exception $e) {
    write_log("ERREUR: " . $e->getMessage());
    write_log("TRACE: " . $e->getTraceAsString());
    write_log("=== FIN APPEL AJAX (ERROR) ===\n");
    
    http_response_code(500);
    
    // Log l'erreur dans Moodle aussi
    if (function_exists('debugging')) {
        debugging('Sync notification error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}