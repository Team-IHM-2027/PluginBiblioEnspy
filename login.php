<?php
require_once('../../config.php');

$PAGE->set_url(new moodle_url('/local/biblio_enspy/login.php')); // Utiliser login.php ici, pas index.php
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Bibliothèque - Connexion');
$PAGE->set_heading('Bibliothèque');

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/local/biblio_enspy/explore.php'));
}

echo $OUTPUT->header();

// 5. Affichage du contenu
echo $OUTPUT->box_start();
echo '<p>Vous devez vous connecter pour accéder à la bibliothèque.</p>';
echo '<p><a href="' . new moodle_url('/login/index.php') . '">Se connecter</a></p>';
echo $OUTPUT->box_end();

echo $OUTPUT->footer();
?>
