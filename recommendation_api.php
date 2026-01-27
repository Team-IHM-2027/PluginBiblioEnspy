<?php
/**
 * Client API pour le système de recommandation BiblioEnspy
 * 
 * @package    local_biblio_enspy
 * @copyright  2026
 */

defined('MOODLE_INTERNAL') || die();

class RecommendationApiClient {
    
    /**
     * URL de base de l'API Railway
     */
    private const API_BASE_URL = 'https://recommendation.up.railway.app';
    
    /**
     * Timeout pour les requêtes (en secondes)
     */
    private const TIMEOUT = 20;
    
    /**
     * Obtenir les recommandations basées sur des utilisateurs similaires
     * 
     * @param string $user_email Email de l'utilisateur
     * @return array|null Tableau des recommandations ou null en cas d'erreur
     */
    public static function get_similar_users_recommendations($user_email) {
        $endpoint = self::API_BASE_URL . '/recommendations/similar-users/' . urlencode($user_email);
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            // Gestion des erreurs cURL
            if ($response === false) {
                error_log("RecommendationAPI: Erreur cURL pour $user_email: $curl_error");
                return null;
            }
            
            // Vérifier le code HTTP
            if ($http_code !== 200) {
                error_log("RecommendationAPI: HTTP $http_code pour $user_email");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("RecommendationAPI: Erreur JSON: " . json_last_error_msg());
                return null;
            }
            
            // Retourner les recommandations (peut être un tableau vide)
            return $data['recommendations'] ?? [];
            
        } catch (Exception $e) {
            error_log("RecommendationAPI: Exception pour $user_email: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtenir des documents similaires via TF-IDF
     * 
     * @param string $document_title Titre du document
     * @return array|null Tableau des documents similaires ou null en cas d'erreur
     */
    public static function get_similar_documents($document_title) {
        $endpoint = self::API_BASE_URL . '/similarbooks';
        
        try {
            $payload = json_encode(['title' => $document_title]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($response === false) {
                error_log("RecommendationAPI: Erreur cURL pour '$document_title': $curl_error");
                return null;
            }
            
            if ($http_code !== 200) {
                error_log("RecommendationAPI: HTTP $http_code pour '$document_title'");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("RecommendationAPI: Erreur JSON: " . json_last_error_msg());
                return null;
            }
            
            return $data['similar_documents'] ?? [];
            
        } catch (Exception $e) {
            error_log("RecommendationAPI: Exception pour '$document_title': " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Sélectionner des documents aléatoires comme fallback
     * 
     * @param array $all_documents Tous les documents disponibles
     * @param int $count Nombre de documents à retourner
     * @param string $exclude_id ID du document à exclure (optionnel)
     * @return array Tableau de documents aléatoires
     */
    public static function get_random_fallback($all_documents, $count = 10, $exclude_id = null) {
        if (empty($all_documents)) {
            return [];
        }
        
        // Filtrer le document actuel si spécifié
        if ($exclude_id) {
            $all_documents = array_filter($all_documents, function($doc) use ($exclude_id) {
                $doc_id = is_array($doc) ? ($doc['id'] ?? '') : '';
                return $doc_id !== $exclude_id;
            });
        }
        
        // Mélanger et prendre les N premiers
        shuffle($all_documents);
        return array_slice($all_documents, 0, $count);
    }
    
    /**
     * Vérifier la disponibilité de l'API
     * 
     * @return bool True si l'API répond, false sinon
     */
    public static function health_check() {
        $endpoint = self::API_BASE_URL . '/test';
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $http_code === 200;
            
        } catch (Exception $e) {
            return false;
        }
    }
}