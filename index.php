<?php
// Fichier : local/biblio_enspy/index.php
require_once('../../config.php');

// Redirection vers la page principale du catalogue
$url = new moodle_url('/local/biblio_enspy/explore.php');
redirect($url);
