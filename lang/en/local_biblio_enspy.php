<?php
/**
 * English language strings for local_biblio_enspy
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Bibliothèque ENSPY';
$string['biblio_enspy:receivenotifications'] = 'Recevoir les notifications de la bibliothèque';

// Messages
$string['messageprovider:reservation_update'] = 'Mises à jour de réservation';
$string['messageprovider:reminder'] = 'Rappels de bibliothèque';
$string['messageprovider:general'] = 'Notifications générales';

// Privacy
$string['privacy:metadata:local_biblio_notif_sync'] = 'Stocke les informations de synchronisation entre Firestore et Moodle';
$string['privacy:metadata:local_biblio_notif_sync:userid'] = 'L\'ID de l\'utilisateur';
$string['privacy:metadata:local_biblio_notif_sync:user_email'] = 'L\'email de l\'utilisateur';
$string['privacy:metadata:local_biblio_notif_sync:firestore_id'] = 'L\'identifiant de la notification dans Firestore';
$string['privacy:metadata:local_biblio_notif_sync:notification_type'] = 'Le type de notification';