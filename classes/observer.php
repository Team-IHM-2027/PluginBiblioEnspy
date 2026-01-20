<?php
// local/biblio_enspy/classes/observer.php

namespace local_biblio_enspy;

defined('MOODLE_INTERNAL') || die();

class observer {
    
    public static function notification_sent(\core\event\notification_sent $event) {
        global $CFG;
        
        // Optionnel: Log ou actions supplémentaires
        if ($event->other['component'] === 'local_biblio_enspy') {
            // Notification de votre plugin envoyée
        }
    }
}
