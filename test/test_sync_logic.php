<?php
/**
 * Test de la logique de synchronisation
 */

require_once(__DIR__ . '../../../../config.php');
require_once($CFG->dirroot . '/local/biblio_enspy/classes/firestore_listener.php');
require_once($CFG->dirroot . '/local/biblio_enspy/classes/notification_manager.php');

require_login();
set_debugging(DEBUG_DEVELOPER, true);

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>Test Logique Sync</title>";
echo "<style>
body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
.box { background: #252526; margin: 15px 0; padding: 15px; border-left: 4px solid #007acc; }
.success { border-left-color: #4CAF50; }
.error { border-left-color: #f44336; }
.warning { border-left-color: #ff9800; }
pre { background: #1e1e1e; padding: 10px; overflow-x: auto; }
.timestamp { color: #ce9178; }
.count { color: #4ec9b0; font-weight: bold; font-size: 18px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; border: 1px solid #3c3c3c; text-align: left; }
th { background: #2d2d30; }
</style></head><body>";

echo "<h1>🔬 Test de la logique de synchronisation</h1>";

try {
    echo "<div class='box'>";
    echo "<h2>📊 État actuel</h2>";
    echo "<p>Utilisateur: <span class='count'>{$USER->email}</span></p>";
    
    // 1. Vérifier les notifications existantes dans Moodle
    $existing_notifs = $DB->get_records_sql(
        "SELECT n.*, ns.firestore_id
         FROM {notifications} n
         LEFT JOIN {local_biblio_notif_sync} ns ON n.id = ns.moodle_notification_id
         WHERE n.useridto = :userid
         AND n.component = 'local_biblio_enspy'
         ORDER BY n.timecreated DESC
         LIMIT 10",
        ['userid' => $USER->id]
    );
    
    echo "<p>Notifications dans Moodle: <span class='count'>" . count($existing_notifs) . "</span></p>";
    
    if (!empty($existing_notifs)) {
        echo "<table>";
        echo "<tr><th>Date création</th><th>Sujet</th><th>Firestore ID</th></tr>";
        foreach (array_slice($existing_notifs, 0, 5) as $notif) {
            $date = date('Y-m-d H:i:s', $notif->timecreated);
            echo "<tr>";
            echo "<td class='timestamp'>$date</td>";
            echo "<td>" . htmlspecialchars($notif->subject) . "</td>";
            echo "<td>" . ($notif->firestore_id ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // 2. Calculer le dernier timestamp
    echo "<div class='box warning'>";
    echo "<h2>⏰ Calcul du dernier timestamp</h2>";
    
    $last_sync = \local_biblio_enspy\notification_manager::get_last_sync_timestamp($USER->email);
    echo "<p>Dernier timestamp: <span class='count'>$last_sync</span></p>";
    echo "<p>Date: <span class='timestamp'>" . ($last_sync > 0 ? date('Y-m-d H:i:s', $last_sync) : 'Jamais') . "</span></p>";
    echo "</div>";
    
    // 3. Récupérer les notifications Firestore
    echo "<div class='box'>";
    echo "<h2>🔥 Notifications depuis Firestore</h2>";
    
    $listener = new \local_biblio_enspy\firestore_listener();
    $firestore_notifs = $listener->fetch_new_notifications($USER->email, 0); // Sans filtre temporel
    
    echo "<p>Total dans Firestore: <span class='count'>" . count($firestore_notifs) . "</span></p>";
    
    if (!empty($firestore_notifs)) {
        echo "<h3>Les 5 plus récentes:</h3>";
        echo "<table>";
        echo "<tr><th>Timestamp</th><th>Titre</th><th>ID</th><th>Plus récent que last_sync?</th></tr>";
        
        foreach (array_slice($firestore_notifs, 0, 5) as $notif) {
            $notif_timestamp = strtotime($notif['timestamp']);
            $is_newer = $notif_timestamp > $last_sync;
            $color = $is_newer ? '#4CAF50' : '#f44336';
            
            echo "<tr>";
            echo "<td class='timestamp'>{$notif['timestamp']}<br><small>Unix: $notif_timestamp</small></td>";
            echo "<td>" . htmlspecialchars($notif['title']) . "</td>";
            echo "<td><small>{$notif['id']}</small></td>";
            echo "<td style='color:$color; font-weight:bold;'>" . ($is_newer ? '✓ OUI' : '✗ NON (sera ignorée)') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // 4. Filtrer avec le timestamp
    echo "<div class='box'>";
    echo "<h2>🔍 Filtrage avec timestamp</h2>";
    
    $filtered_notifs = $listener->fetch_new_notifications($USER->email, $last_sync);
    
    echo "<p>Notifications filtrées (plus récentes que last_sync): <span class='count'>" . count($filtered_notifs) . "</span></p>";
    
    if (!empty($filtered_notifs)) {
        echo "<table>";
        echo "<tr><th>Titre</th><th>Existe déjà?</th><th>Action</th></tr>";
        
        foreach ($filtered_notifs as $notif) {
            $exists = \local_biblio_enspy\notification_manager::notification_exists($notif['id']);
            $action = $exists ? 'Ignorer (already_exists)' : 'Créer (success)';
            $color = $exists ? '#ff9800' : '#4CAF50';
            
            echo "<tr>";
            echo "<td>" . htmlspecialchars($notif['title']) . "</td>";
            echo "<td>" . ($exists ? '✓ OUI' : '✗ NON') . "</td>";
            echo "<td style='color:$color; font-weight:bold;'>$action</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    echo "</div>";
    
    // 5. Simulation de sync
    echo "<div class='box success'>";
    echo "<h2>🎯 Simulation de synchronisation</h2>";
    
    $simulated_success = 0;
    $simulated_exists = 0;
    
    foreach ($filtered_notifs as $notif) {
        if (\local_biblio_enspy\notification_manager::notification_exists($notif['id'])) {
            $simulated_exists++;
        } else {
            $simulated_success++;
        }
    }
    
    echo "<p><strong>Résultat attendu:</strong></p>";
    echo "<ul>";
    echo "<li>success: <span class='count'>$simulated_success</span></li>";
    echo "<li>already_exists: <span class='count'>$simulated_exists</span></li>";
    echo "</ul>";
    
    if ($simulated_success > 0) {
        echo "<p style='color:#4CAF50; font-weight:bold;'>✓ Le toast devrait s'afficher!</p>";
    } else {
        echo "<p style='color:#ff9800; font-weight:bold;'>⚠️ Aucune nouvelle notification - Le toast ne s'affichera pas</p>";
    }
    echo "</div>";
    
    // 6. Exécuter une vraie synchronisation
    echo "<div class='box'>";
    echo "<h2>🚀 Exécution réelle</h2>";
    echo "<form method='post'>";
    echo "<input type='hidden' name='do_sync' value='1'>";
    echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
    echo "<button type='submit' style='padding:10px 20px; background:#4CAF50; color:white; border:none; cursor:pointer; border-radius:4px;'>Exécuter la synchronisation</button>";
    echo "</form>";
    
    if (isset($_POST['do_sync']) && confirm_sesskey()) {
        echo "<div style='background:#2d2d30; padding:15px; margin-top:10px;'>";
        echo "<h3>Résultat de la synchronisation:</h3>";
        
        $results = $listener->sync_notifications_for_user($USER->email);
        
        echo "<pre>" . json_encode($results, JSON_PRETTY_PRINT) . "</pre>";
        
        if ($results['success'] > 0) {
            echo "<p style='color:#4CAF50; font-weight:bold;'>✓ {$results['success']} notification(s) créée(s)!</p>";
        }
        if ($results['already_exists'] > 0) {
            echo "<p style='color:#ff9800;'>⚠️ {$results['already_exists']} notification(s) existait déjà</p>";
        }
        if ($results['errors'] > 0) {
            echo "<p style='color:#f44336;'>✗ {$results['errors']} erreur(s)</p>";
        }
        echo "</div>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='box error'>";
    echo "<h2>❌ Erreur</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "</body></html>";
