<?php
/**
 * Notification manager for Firestore to Moodle sync
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_biblio_enspy;

require_once __DIR__ . '/../vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

defined('MOODLE_INTERNAL') || die();

class notification_manager {

    /**
     * Créer une notification Moodle depuis les données Firestore
     *
     * @param object $user Utilisateur Moodle
     * @param array $firestore_data Données depuis Firestore
     * @return int|false ID de la notification ou false en cas d'échec
     */
    public static function create_moodle_notification($user, $firestore_data) {
        global $DB, $CFG, $PAGE;
        
        require_once($CFG->dirroot . '/message/lib.php');
        
        // S'assurer que le contexte est défini (requis pour format_text)
        if (!isset($PAGE->context) || $PAGE->context === null) {
            $PAGE->set_context(\context_system::instance());
        }
        
        // Déterminer le type de notification
        $notification_type = $firestore_data['type'] ?? 'general';
        
        // Mapper le type Firestore au type Moodle
        $message_name = self::map_notification_type($notification_type);
        
        // Préparer le message
        $message = new \core\message\message();
        $message->component = 'local_biblio_enspy';
        $message->name = $message_name;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $firestore_data['title'] ?? 'Notification';
        $message->fullmessage = strip_tags($firestore_data['message'] ?? '');
        $message->fullmessageformat = FORMAT_PLAIN;
        
        // Utiliser format_text de manière sécurisée
        $message_html = $firestore_data['message'] ?? '';
        try {
            $message->fullmessagehtml = format_text($message_html, FORMAT_HTML, ['context' => \context_system::instance()]);
        } catch (\Exception $e) {
            // Si format_text échoue, utiliser une version simple
            $message->fullmessagehtml = '<p>' . s($message_html) . '</p>';
        }
        
        $message->smallmessage = $firestore_data['title'] ?? '';
        $message->notification = 1;
        
        // Ajouter l'URL si disponible (lien vers le livre)
        if (!empty($firestore_data['bookId'])) {
            $message->contexturl = new \moodle_url('/local/biblio_enspy/explore.php', [
                'bookid' => $firestore_data['bookId']
            ]);
            $message->contexturlname = 'Voir le livre';
        }
        
        // Ajouter des données personnalisées
        $message->customdata = [
            'firestore_type' => $notification_type,
            'bookId' => $firestore_data['bookId'] ?? null,
            'bookTitle' => $firestore_data['bookTitle'] ?? null,
            'status' => $firestore_data['status'] ?? null,
        ];
        
        // Envoyer la notification
        try {
            $messageid = message_send($message);
            return $messageid;
        } catch (\Exception $e) {
            debugging('Erreur lors de l\'envoi de la notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Mapper le type de notification Firestore au type Moodle
     *
     * @param string $firestore_type
     * @return string
     */
    private static function map_notification_type($firestore_type) {
        $mapping = [
            'reservation_update' => 'reservation_update',
            'reminder' => 'reminder',
            'general' => 'general',
        ];
        
        return $mapping[$firestore_type] ?? 'general';
    }

    /**
     * Vérifier si une notification existe déjà
     *
     * @param string $firestore_id
     * @return bool
     */
    public static function notification_exists($firestore_id) {
        global $DB;
        
        return $DB->record_exists('local_biblio_notif_sync', [
            'firestore_id' => $firestore_id
        ]);
    }

    /**
     * Enregistrer la synchronisation dans la base de données
     *
     * @param string $firestore_id
     * @param int $userid
     * @param string $user_email
     * @param int $moodle_notification_id
     * @param array $firestore_data
     * @return int|false
     */
    public static function save_sync_record($firestore_id, $userid, $user_email, $moodle_notification_id, $firestore_data) {
        global $DB;
        
        $record = new \stdClass();
        $record->firestore_id = $firestore_id;
        $record->userid = $userid;
        $record->user_email = $user_email;
        $record->moodle_notification_id = $moodle_notification_id;
        $record->notification_type = $firestore_data['type'] ?? 'general';
        
        // Convertir le timestamp Firestore
        $firestore_timestamp = $firestore_data['timestamp'] ?? null;
        if ($firestore_timestamp) {
            if (is_string($firestore_timestamp)) {
                $record->firestore_timestamp = strtotime($firestore_timestamp);
            } else {
                $record->firestore_timestamp = time();
            }
        } else {
            $record->firestore_timestamp = time();
        }
        
        $record->is_read = isset($firestore_data['read']) ? ($firestore_data['read'] ? 1 : 0) : 0;
        $record->timecreated = time();
        $record->timemodified = time();
        
        try {
            return $DB->insert_record('local_biblio_notif_sync', $record);
        } catch (\Exception $e) {
            debugging('Erreur lors de l\'enregistrement de la sync: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Obtenir l'utilisateur Moodle depuis l'email
     *
     * @param string $email
     * @return object|false
     */
    public static function get_user_by_email($email) {
        global $DB;
        
        try {
            return $DB->get_record('user', [
                'email' => $email,
                'deleted' => 0,
                'suspended' => 0
            ]);
        } catch (\Exception $e) {
            debugging('Erreur lors de la récupération de l\'utilisateur: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Marquer une notification comme lue
     *
     * @param string $firestore_id
     * @return bool
     */
    public static function mark_as_read($firestore_id) {
        global $DB;
        
        try {
            return $DB->set_field('local_biblio_notif_sync', 'is_read', 1, [
                'firestore_id' => $firestore_id
            ]);
        } catch (\Exception $e) {
            debugging('Erreur lors du marquage comme lu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Obtenir le dernier timestamp de synchronisation pour un utilisateur
     *
     * @param string $user_email
     * @return int
     */
    public static function get_last_sync_timestamp($user_email) {
        global $DB;
        
        try {
            $record = $DB->get_record_sql(
                "SELECT MAX(firestore_timestamp) as last_sync 
                 FROM {local_biblio_notif_sync} 
                 WHERE user_email = ?",
                [$user_email]
            );
            
            return $record && $record->last_sync ? $record->last_sync : 0;
        } catch (\Exception $e) {
            debugging('Erreur lors de la récupération du dernier timestamp: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }
}