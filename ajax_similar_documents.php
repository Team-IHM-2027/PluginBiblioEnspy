<?php
/**
 * Endpoint AJAX pour récupérer des documents similaires
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
    // Récupérer les paramètres
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    $document_title = $data['title'] ?? '';
    $all_books = $data['booksData'] ?? [];
    $all_theses = $data['thesesData'] ?? [];
    $current_doc_id = $data['currentDocId'] ?? null;
    
    if (empty($document_title)) {
        throw new Exception('Titre du document requis');
    }
    
    $all_documents = array_merge($all_books, $all_theses);
    
    // Appeler l'API de recommandation
    $similar_docs = RecommendationApiClient::get_similar_documents($document_title);
    
    // Si l'API a renvoyé des documents similaires
    if ($similar_docs !== null && is_array($similar_docs) && count($similar_docs) > 0) {
        
        $mapped_similar = [];
        
        foreach ($similar_docs as $sim_doc) {
            $sim_name = $sim_doc['name'] ?? '';
            $similarity_score = $sim_doc['similarity_score'] ?? 0;
            
            if (empty($sim_name)) {
                continue;
            }
            
            // Chercher dans les documents locaux
            foreach ($all_documents as $local_doc) {
                $local_name = '';
                
                if (isset($local_doc['fields']['name']['stringValue'])) {
                    $local_name = $local_doc['fields']['name']['stringValue'];
                } else if (isset($local_doc['fields']['Nom']['stringValue'])) {
                    $local_name = $local_doc['fields']['Nom']['stringValue'];
                } else if (isset($local_doc['fields']['theme']['stringValue'])) {
                    $local_name = $local_doc['fields']['theme']['stringValue'];
                }
                
                // Comparaison insensible à la casse
                if (strcasecmp($local_name, $sim_name) === 0) {
                    // Ajouter le score de similarité
                    $local_doc['similarity_score'] = $similarity_score;
                    $local_doc['source_type'] = $sim_doc['source_type'] ?? 'Unknown';
                    $mapped_similar[] = $local_doc;
                    break;
                }
            }
        }
        
        // Si on a trouvé des correspondances
        if (count($mapped_similar) > 0) {
            echo json_encode([
                'success' => true,
                'source' => 'api',
                'similar_documents' => $mapped_similar,
                'count' => count($mapped_similar)
            ]);
            exit;
        }
    }
    
    // FALLBACK : Sélection aléatoire (exclure le document actuel)
    $random_docs = RecommendationApiClient::get_random_fallback($all_documents, 10, $current_doc_id);
    
    echo json_encode([
        'success' => true,
        'source' => 'fallback',
        'similar_documents' => $random_docs,
        'count' => count($random_docs),
        'message' => 'Documents aléatoires (API non disponible)'
    ]);
    
} catch (Exception $e) {
    error_log("ajax_similar_documents.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur',
        'message' => $e->getMessage()
    ]);
}