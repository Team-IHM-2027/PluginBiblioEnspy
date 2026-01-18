<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Créer un fichier de log personnalisé dans le dossier du plugin
$logFile = __DIR__ . '/biblio_debug.log';

// Affichage HTML
echo '<!DOCTYPE html>
<html>
<head>
    <title>Logs Biblio ENSPY</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .log-container { background: #252526; padding: 20px; border-radius: 5px; }
        .error { color: #f48771; }
        .success { color: #89d185; }
        .info { color: #4fc1ff; }
        .warning { color: #dcdcaa; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
        h1 { color: #4fc1ff; }
        .refresh { 
            background: #0e639c; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 3px; 
            cursor: pointer;
            margin-bottom: 20px;
        }
        .clear { 
            background: #d16969; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 3px; 
            cursor: pointer;
            margin-bottom: 20px;
            margin-left: 10px;
        }
        .info-box {
            background: #1e3a5f;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
    <meta http-equiv="refresh" content="3">
</head>
<body>';

echo '<h1>🔍 Logs en temps réel - Biblio ENSPY</h1>';

echo '<div class="info-box">';
echo '<strong>Fichier de log :</strong> ' . $logFile . '<br>';
echo '<strong>Dernière mise à jour :</strong> ' . date('Y-m-d H:i:s') . '<br>';
echo '<strong>Taille du fichier :</strong> ' . (file_exists($logFile) ? round(filesize($logFile) / 1024, 2) . ' KB' : '0 KB') . '<br>';
echo '<strong>Auto-refresh :</strong> Toutes les 3 secondes';
echo '</div>';

echo '<button class="refresh" onclick="location.reload();">🔄 Rafraîchir maintenant</button>';
echo '<button class="clear" onclick="if(confirm(\'Effacer tous les logs ?\')) { window.location.href=\'?clear=1\'; }">🗑️ Effacer les logs</button>';

// Gérer l'effacement des logs
if (isset($_GET['clear'])) {
    file_put_contents($logFile, '');
    echo '<p class="success">✅ Logs effacés avec succès !</p>';
    echo '<script>setTimeout(function(){ location.href="debug_log.php"; }, 1000);</script>';
}

echo '<div class="log-container">';

if (file_exists($logFile)) {
    $logs = file_get_contents($logFile);
    
    if (empty($logs)) {
        echo '<p class="info">ℹ️ Aucun log pour le moment. Effectuez une action dans l\'application.</p>';
    } else {
        // Afficher les 200 dernières lignes seulement
        $lines = explode("\n", $logs);
        $lines = array_slice($lines, -200);
        $logs = implode("\n", $lines);
        
        // Coloriser les logs
        $logs = str_replace('[ERROR]', '<span class="error">[ERROR]</span>', $logs);
        $logs = str_replace('[SUCCESS]', '<span class="success">[SUCCESS]</span>', $logs);
        $logs = str_replace('[INFO]', '<span class="info">[INFO]</span>', $logs);
        $logs = str_replace('[WARNING]', '<span class="warning">[WARNING]</span>', $logs);
        
        echo '<pre>' . htmlspecialchars($logs, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
} else {
    echo '<p class="warning">⚠️ Fichier de log non trouvé. Il sera créé lors de la première action.</p>';
}

echo '</div>';

echo '</body></html>';
?>
