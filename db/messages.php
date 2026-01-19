<?php
/**
 * Message providers for local_biblio_enspy
 *
 * @package    local_biblio_enspy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    // Notification pour les mises à jour de réservation
    'reservation_update' => [
        'capability' => 'local/biblio_enspy:receivenotifications',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DISALLOWED,
        ],
    ],
    // Notification pour les rappels
    'reminder' => [
        'capability' => 'local/biblio_enspy:receivenotifications',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DISALLOWED,
        ],
    ],
    // Notification générique
    'general' => [
        'capability' => 'local/biblio_enspy:receivenotifications',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DISALLOWED,
        ],
    ],
];