<?php
echo "Chemin actuel : " . __DIR__ . "\n";
echo "Fichier api_cancel.php existe : " . (file_exists(__DIR__ . '/api_cancel.php') ? 'OUI' : 'NON') . "\n";
echo "Dernière modification : " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/api_cancel.php')) . "\n";
echo "Contenu (100 premiers caractères) : " . substr(file_get_contents(__DIR__ . '/api_cancel.php'), 0, 100);
?>
