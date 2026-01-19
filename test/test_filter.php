<?php
/**
 * Test du filtrage des notifications par userId
 */

require_once(__DIR__ . '../../../../config.php');
require_once($CFG->dirroot . '/local/biblio_enspy/classes/firestore_listener.php');
require_once($CFG->dirroot . '/local/biblio_enspy/classes/notification_manager.php');

require_login();

// Activer le mode debug
set_debugging(DEBUG_DEVELOPER, true);

echo "<!DOCTYPE html><html><head><title>Test Filtrage Notifications</title>";
echo "<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
h1 { color: #333; }
.info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 10px 0; }
.success { background: #e8f5e9; padding: 15px; border-left: 4px solid #4CAF50; margin: 10px 0; }
.warning { background: #fff3e0; padding: 15px; border-left: 4px solid #ff9800; margin: 10px 0; }
.error { background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
th { background: #f5f5f5; font-weight: bold; }
tr:hover { background: #f9f9f9; }
.match { color: #4CAF50; font-weight: bold; }
.nomatch { color: #f44336; font-weight: bold; }
pre { background: #263238; color: #aed581; padding: 15px; overflow-x: auto; border-radius: 4px; }
</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔍 Test de Filtrage des Notifications Firestore</h1>";

// Informations utilisateur
echo "<div class='info'>";
echo "<h2>👤 Utilisateur connecté</h2>";
echo "<p><strong>Email:</strong> {$USER->email}</p>";
echo "<p><strong>ID:</strong> {$USER->id}</p>";
echo "<p><strong>Nom:</strong> {$USER->firstname} {$USER->lastname}</p>";
echo "</div>";

try {
    // Créer le listener
    echo "<div class='info'><h2>🔗 Connexion à Firestore...</h2></div>";
    $listener = new \local_biblio_enspy\firestore_listener();
    echo "<div class='success'><p>✓ Connexion réussie</p></div>";
    
    // Récupérer TOUTES les notifications (sans filtre temporel)
    echo "<div class='info'><h2>📥 Récupération des notifications...</h2></div>";
    $notifications = $listener->fetch_new_notifications($USER->email, 0);
    
    echo "<div class='success'>";
    echo "<h3>✓ Récupération terminée</h3>";
    echo "<p><strong>Nombre de notifications reçues:</strong> " . count($notifications) . "</p>";
    echo "</div>";
    
    if (empty($notifications)) {
        echo "<div class='warning'>";
        echo "<h3>⚠️ Aucune notification trouvée</h3>";
        echo "<p>Vérifiez que:</p>";
        echo "<ul>";
        echo "<li>Des notifications existent dans Firestore pour l'utilisateur <code>{$USER->email}</code></li>";
        echo "<li>Le champ <code>userId</code> dans Firestore correspond exactement à <code>{$USER->email}</code></li>";
        echo "<li>Les index Firestore sont créés (voir Firebase Console)</li>";
        echo "</ul>";
        echo "</div>";
    } else {
        // Analyser les notifications
        $matching = 0;
        $not_matching = 0;
        $users_found = [];
        
        echo "<div class='info'>";
        echo "<h2>📊 Analyse des notifications</h2>";
        echo "<table>";
        echo "<thead>";
        echo "<tr>";
        echo "<th>ID</th>";
        echo "<th>Titre</th>";
        echo "<th>userId (Firestore)</th>";
        echo "<th>Match</th>";
        echo "<th>Type</th>";
        echo "<th>Date</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        foreach ($notifications as $notif) {
            $is_match = ($notif['userId'] === $USER->email);
            
            if ($is_match) {
                $matching++;
            } else {
                $not_matching++;
            }
            
            if (!in_array($notif['userId'], $users_found)) {
                $users_found[] = $notif['userId'];
            }
            
            $match_class = $is_match ? 'match' : 'nomatch';
            $match_text = $is_match ? '✓ OUI' : '✗ NON';
            
            echo "<tr>";
            echo "<td>" . substr($notif['id'], 0, 8) . "...</td>";
            echo "<td>" . htmlspecialchars($notif['title'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($notif['userId'] ?? 'N/A') . "</td>";
            echo "<td class='$match_class'>$match_text</td>";
            echo "<td>" . htmlspecialchars($notif['type'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($notif['timestamp'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
        
        // Résumé
        echo "<div class='" . ($not_matching > 0 ? 'error' : 'success') . "'>";
        echo "<h2>📈 Résumé du filtrage</h2>";
        echo "<ul>";
        echo "<li><strong>Total de notifications reçues:</strong> " . count($notifications) . "</li>";
        echo "<li class='match'><strong>Notifications correspondantes:</strong> $matching</li>";
        echo "<li class='nomatch'><strong>Notifications NON correspondantes:</strong> $not_matching</li>";
        echo "</ul>";
        
        if ($not_matching > 0) {
            echo "<h3 class='nomatch'>⚠️ PROBLÈME DÉTECTÉ!</h3>";
            echo "<p>Des notifications d'autres utilisateurs sont récupérées. Utilisateurs trouvés:</p>";
            echo "<ul>";
            foreach ($users_found as $email) {
                $is_current = ($email === $USER->email);
                echo "<li class='" . ($is_current ? 'match' : 'nomatch') . "'>$email</li>";
            }
            echo "</ul>";
            
            echo "<h4>Solutions possibles:</h4>";
            echo "<ol>";
            echo "<li>Vérifier que le champ <code>userId</code> dans Firestore est bien de type <strong>string</strong></li>";
            echo "<li>Vérifier que l'index Firestore existe pour le champ <code>userId</code></li>";
            echo "<li>Vérifier les règles de sécurité Firestore</li>";
            echo "<li>Le filtre de sécurité PHP devrait bloquer ces notifications</li>";
            echo "</ol>";
        } else {
            echo "<h3 class='match'>✓ Filtrage correct!</h3>";
            echo "<p>Toutes les notifications récupérées correspondent bien à l'utilisateur connecté.</p>";
        }
        echo "</div>";
        
        // Détails de la première notification
        if (!empty($notifications)) {
            echo "<div class='info'>";
            echo "<h2>🔍 Détails de la première notification</h2>";
            echo "<pre>" . print_r($notifications[0], true) . "</pre>";
            echo "</div>";
        }
    }
    
    // Vérifier les logs de debug
    echo "<div class='info'>";
    echo "<h2>📝 Logs de debug</h2>";
    echo "<p>Consultez aussi le fichier: <code>/local/biblio_enspy/ajax/sync_debug.log</code></p>";
    echo "<p>Et activez le mode debug dans Moodle: Site administration → Development → Debugging</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Erreur</h2>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

// Bouton pour rafraîchir
echo "<div style='margin-top: 20px;'>";
echo "<button onclick='location.reload()' style='padding: 12px 24px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;'>🔄 Rafraîchir le test</button>";
echo "</div>";

echo "</div>"; // container
echo "</body></html>";
