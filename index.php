<?php
// Fichier : local/biblio_enspy/index.php
require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

// Maintenance check
list($projectId, $accessToken) = biblio_load_google_credentials();
if ($projectId && $accessToken) {
    biblio_require_no_maintenance($projectId, $accessToken);
}

// Redirection vers la page principale du catalogue
$url = new moodle_url('/local/biblio_enspy/explore.php');
redirect($url);
