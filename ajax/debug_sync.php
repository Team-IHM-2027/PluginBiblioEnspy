<?php
/**
 * Debug détaillé avec capture de toutes les erreurs
 */

// Activer l'affichage des erreurs
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Capturer toutes les erreurs
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "ERROR: [$errno] $errstr in $errfile on line $errline<br>";
});

echo "<!DOCTYPE html><html><head><title>Debug Détaillé</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;} 
.step{background:white;margin:10px 0;padding:15px;border-left:4px solid #4CAF50;} 
.error{border-left-color:#f44336;background:#ffebee;} 
.success{border-left-color:#4CAF50;} 
.info{border-left-color:#2196F3;background:#e3f2fd;} 
pre{background:#263238;color:#aed581;padding:10px;overflow-x:auto;}
h3{margin:0 0 10px 0;}</style>";
echo "</head><body>";

echo "<h1>🔍 Debug Détaillé - Synchronisation Notifications</h1>";

// Fonction helper pour afficher les étapes
function log_step($title, $content, $type = 'info') {
    echo "<div class='step $type'>";
    echo "<h3>$title</h3>";
    echo "<div>$content</div>";
    echo "</div>";
}

try {
    log_step("Étape 1", "Chargement de config.php", "info");
    require_once(__DIR__ . '/../../../config.php');
    log_step("Étape 1", "✓ config.php chargé", "success");
    
    log_step("Étape 2", "Vérification session utilisateur", "info");
    require_login(null, false, null, false, true);
    log_step("Étape 2", "✓ Utilisateur connecté: {$USER->email} (ID: {$USER->id})", "success");
    
} catch (Exception $e) {
    log_step("ERREUR CONFIG", $e->getMessage() . "<pre>" . $e->getTraceAsString() . "</pre>", "error");
    die();
}

// Test Firebase credentials
log_step("Étape 3", "Vérification Firebase credentials", "info");
$firebase_path = __DIR__ . '/../firebase_credentials.json';
if (!file_exists($firebase_path)) {
    log_step("Étape 3", "✗ Fichier non trouvé: $firebase_path", "error");
    die();
}

$firebase_data = json_decode(file_get_contents($firebase_path), true);
if (!$firebase_data) {
    log_step("Étape 3", "✗ JSON invalide", "error");
    die();
}

log_step("Étape 3", "✓ Firebase credentials OK<br>Project ID: {$firebase_data['project_id']}", "success");

// Test autoload Composer
log_step("Étape 4", "Vérification Composer", "info");
$composer_paths = [
    $CFG->dirroot . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$composer_found = false;
foreach ($composer_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $composer_found = true;
        log_step("Étape 4", "✓ Composer autoload trouvé: $path", "success");
        break;
    }
}

if (!$composer_found) {
    log_step("Étape 4", "⚠️ Composer autoload non trouvé - Utilisation de la méthode manuelle JWT", "info");
}

// Test Google Auth
log_step("Étape 5", "Vérification Google Auth", "info");
if (class_exists('\Google\Auth\Credentials\ServiceAccountCredentials')) {
    log_step("Étape 5", "✓ Google Auth disponible", "success");
} else {
    log_step("Étape 5", "⚠️ Google Auth non disponible - Utilisation JWT manuel", "info");
}

// Test chargement des classes
log_step("Étape 6", "Chargement des classes du plugin", "info");
try {
    require_once($CFG->dirroot . '/local/biblio_enspy/classes/notification_manager.php');
    log_step("Étape 6", "✓ notification_manager.php chargé", "success");
} catch (Exception $e) {
    log_step("Étape 6", "✗ Erreur notification_manager: " . $e->getMessage(), "error");
    die();
}

try {
    require_once($CFG->dirroot . '/local/biblio_enspy/classes/firestore_listener.php');
    log_step("Étape 6", "✓ firestore_listener.php chargé", "success");
} catch (Exception $e) {
    log_step("Étape 6", "✗ Erreur firestore_listener: " . $e->getMessage(), "error");
    die();
}

// Test création du listener
log_step("Étape 7", "Création du Firestore Listener", "info");
try {
    $listener = new \local_biblio_enspy\firestore_listener();
    log_step("Étape 7", "✓ Listener créé avec succès", "success");
} catch (Exception $e) {
    log_step("Étape 7", "✗ Erreur création listener:<br>" . $e->getMessage() . "<pre>" . $e->getTraceAsString() . "</pre>", "error");
    die();
}

// Test récupération notifications
log_step("Étape 8", "Récupération des notifications Firestore pour: {$USER->email}", "info");
try {
    $notifications = $listener->fetch_new_notifications($USER->email, 0);
    log_step("Étape 8", "✓ Récupération réussie<br>Nombre de notifications: " . count($notifications), "success");
    
    if (count($notifications) > 0) {
        echo "<div class='step info'>";
        echo "<h3>Aperçu des notifications Firestore</h3>";
        echo "<pre>" . print_r(array_slice($notifications, 0, 2), true) . "</pre>";
        echo "</div>";
    } else {
        log_step("Étape 8", "ℹ️ Aucune notification trouvée pour cet utilisateur dans Firestore", "info");
    }
} catch (Exception $e) {
    log_step("Étape 8", "✗ Erreur récupération:<br>" . $e->getMessage() . "<pre>" . $e->getTraceAsString() . "</pre>", "error");
    die();
}

// Test vérification table
log_step("Étape 9", "Vérification table de synchronisation", "info");
try {
    $table_exists = $DB->get_manager()->table_exists('local_biblio_notif_sync');
    if ($table_exists) {
        $count = $DB->count_records('local_biblio_notif_sync');
        log_step("Étape 9", "✓ Table existe - $count enregistrements", "success");
    } else {
        log_step("Étape 9", "✗ Table n'existe pas! Exécutez la mise à jour de la base de données.", "error");
        die();
    }
} catch (Exception $e) {
    log_step("Étape 9", "✗ Erreur table: " . $e->getMessage(), "error");
    die();
}

// Test vérification message providers
log_step("Étape 10", "Vérification message providers", "info");
try {
    $providers = $DB->get_records('message_providers', ['component' => 'local_biblio_enspy']);
    if (empty($providers)) {
        log_step("Étape 10", "✗ Aucun message provider! Exécutez la mise à jour de la base de données.", "error");
        die();
    }
    log_step("Étape 10", "✓ " . count($providers) . " providers trouvés:<br>" . 
        implode(', ', array_column($providers, 'name')), "success");
} catch (Exception $e) {
    log_step("Étape 10", "✗ Erreur providers: " . $e->getMessage(), "error");
    die();
}

// Test synchronisation complète
log_step("Étape 11", "TEST SYNCHRONISATION COMPLÈTE", "info");
try {
    $results = $listener->sync_notifications_for_user($USER->email);
    
    echo "<div class='step success'>";
    echo "<h3>✓ Synchronisation terminée avec succès!</h3>";
    echo "<ul>";
    echo "<li><strong>Nouvelles notifications créées:</strong> {$results['success']}</li>";
    echo "<li><strong>Déjà existantes:</strong> {$results['already_exists']}</li>";
    echo "<li><strong>Erreurs:</strong> {$results['errors']}</li>";
    echo "</ul>";
    
    if (!empty($results['notifications'])) {
        echo "<h4>Notifications créées:</h4>";
        echo "<pre>" . print_r($results['notifications'], true) . "</pre>";
    }
    
    if (!empty($results['error_message'])) {
        echo "<div style='color:red'>Message d'erreur: {$results['error_message']}</div>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    log_step("Étape 11", "✗ Erreur synchronisation:<br>" . $e->getMessage() . "<pre>" . $e->getTraceAsString() . "</pre>", "error");
    die();
}

// Vérifier notifications Moodle créées
log_step("Étape 12", "Vérification notifications dans Moodle", "info");
try {
    $moodle_notifs = $DB->get_records_sql(
        "SELECT n.*, mp.name as provider_name
         FROM {notifications} n
         LEFT JOIN {message_providers} mp ON n.component = mp.component AND n.eventtype = mp.name
         WHERE n.useridto = :userid 
         AND n.component = 'local_biblio_enspy'
         ORDER BY n.timecreated DESC
         LIMIT 10",
        ['userid' => $USER->id]
    );
    
    if (empty($moodle_notifs)) {
        log_step("Étape 12", "ℹ️ Aucune notification Moodle pour cet utilisateur", "info");
    } else {
        echo "<div class='step success'>";
        echo "<h3>✓ " . count($moodle_notifs) . " notification(s) Moodle trouvée(s)</h3>";
        echo "<table style='width:100%;border-collapse:collapse;'>";
        echo "<tr style='background:#e0e0e0;'>";
        echo "<th style='padding:8px;text-align:left;'>Date</th>";
        echo "<th style='padding:8px;text-align:left;'>Sujet</th>";
        echo "<th style='padding:8px;text-align:left;'>Type</th>";
        echo "<th style='padding:8px;text-align:left;'>Lu</th>";
        echo "</tr>";
        
        foreach ($moodle_notifs as $notif) {
            $read = $notif->timeread ? '✓' : '✗';
            echo "<tr style='border-bottom:1px solid #ddd;'>";
            echo "<td style='padding:8px;'>" . userdate($notif->timecreated, '%d/%m/%Y %H:%M') . "</td>";
            echo "<td style='padding:8px;'>" . s($notif->subject) . "</td>";
            echo "<td style='padding:8px;'>" . s($notif->eventtype) . "</td>";
            echo "<td style='padding:8px;'>$read</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
    }
} catch (Exception $e) {
    log_step("Étape 12", "✗ Erreur: " . $e->getMessage(), "error");
}

// Test final: simuler l'appel AJAX
log_step("Étape 13", "Simulation appel AJAX (comme dans notification_listener.js)", "info");
echo "<div class='step info'>";
echo "<h3>Test AJAX</h3>";
echo "<button onclick='testAjax()' style='padding:10px 20px;background:#4CAF50;color:white;border:none;cursor:pointer;'>Tester l'appel AJAX</button>";
echo "<div id='ajax-result' style='margin-top:10px;'></div>";
echo "<script>
function testAjax() {
    var resultDiv = document.getElementById('ajax-result');
    resultDiv.innerHTML = 'En cours...';
    
    fetch('" . new moodle_url('/local/biblio_enspy/ajax/sync_notifications.php') . "', {
        method: 'POST',
        credentials: 'same-origin'
    })
    .then(response => {
        return response.text().then(text => ({
            status: response.status,
            text: text
        }));
    })
    .then(data => {
        resultDiv.innerHTML = '<strong>Status:</strong> ' + data.status + '<br><pre>' + data.text + '</pre>';
    })
    .catch(error => {
        resultDiv.innerHTML = '<span style=\"color:red\">Erreur: ' + error + '</span>';
    });
}
</script>";
echo "</div>";

echo "<hr>";
echo "<h2>🎉 Tous les tests sont passés!</h2>";
echo "<p>Si vous voyez ce message, la synchronisation devrait fonctionner.</p>";
echo "<p>Rechargez explore.php et ouvrez la console (F12) pour voir les logs.</p>";

echo "</body></html>";