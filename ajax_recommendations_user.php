<?php
/**
 * Endpoint AJAX pour récupérer les recommandations utilisateur
 * 
 * @package    local_biblio_enspy
 * @copyright  2026
 */

require_once('../../config.php');
require_once('recommendation_api.php');

// Sécurité
require_login();

// Headers JSON
header('Content-Type: application/json');

try {
    global $USER;
    
    // Récupérer les données POST (tous les documents disponibles)
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $all_books = $data['booksData'] ?? [];
    $all_theses = $data['thesesData'] ?? [];
    $all_documents = array_merge($all_books, $all_theses);
    
    // Appeler l'API de recommandation
    $recommendations = RecommendationApiClient::get_similar_users_recommendations($USER->email);
    
    // Si l'API a renvoyé des recommandations valides
    if ($recommendations !== null && is_array($recommendations) && count($recommendations) > 0) {
        
        // Mapper les recommandations de l'API avec les documents locaux
        $mapped_recommendations = [];
        
        foreach ($recommendations as $rec) {
            $doc_name = $rec['nameDoc'] ?? '';
            
            if (empty($doc_name)) {
                continue;
            }
            
            // Chercher le document correspondant dans les données locales
            foreach ($all_documents as $doc) {
                $local_name = '';
                
                if (isset($doc['fields']['name']['stringValue'])) {
                    $local_name = $doc['fields']['name']['stringValue'];
                } else if (isset($doc['fields']['Nom']['stringValue'])) {
                    $local_name = $doc['fields']['Nom']['stringValue'];
                } else if (isset($doc['fields']['theme']['stringValue'])) {
                    $local_name = $doc['fields']['theme']['stringValue'];
                }
                
                // Comparaison insensible à la casse
                if (strcasecmp($local_name, $doc_name) === 0) {
                    // Ajouter le score de recommandation
                    $doc['recommendation_score'] = $rec['recommendation_score'] ?? 0;
                    $doc['recommended_by'] = $rec['recommended_by'] ?? 'API';
                    $mapped_recommendations[] = $doc;
                    break; // Document trouvé, passer au suivant
                }
            }
        }
        
        // Si on a trouvé des correspondances, les retourner
        if (count($mapped_recommendations) > 0) {
            echo json_encode([
                'success' => true,
                'source' => 'api',
                'recommendations' => $mapped_recommendations,
                'count' => count($mapped_recommendations)
            ]);
            exit;
        }
    }
    
    // FALLBACK : Sélection aléatoire
    $random_docs = RecommendationApiClient::get_random_fallback($all_documents, 10);
    
    echo json_encode([
        'success' => true,
        'source' => 'fallback',
        'recommendations' => $random_docs,
        'count' => count($random_docs),
        'message' => 'Recommandations aléatoires (API non disponible ou aucune correspondance)'
    ]);
    
} catch (Exception $e) {
    error_log("ajax_recommendations_user.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur',
        'message' => $e->getMessage()
    ]);
}