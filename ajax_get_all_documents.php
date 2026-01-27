<?php
/**
 * Endpoint helper pour récupérer tous les documents (books + theses)
 * Utilisé par view.php pour les recommandations de documents similaires
 * 
 * @package    local_biblio_enspy
 * @copyright  2026
 */

require_once __DIR__ . '/vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once('../../config.php');

// Sécurité
require_login();

// Headers JSON
header('Content-Type: application/json');

try {
    // Configuration Firestore
    $projectId = "biblio-cc84b";
    $serviceAccountJson = __DIR__ . '/firebase_credentials.json';
    $scopes = ['https://www.googleapis.com/auth/datastore'];
    
    $credentials = new ServiceAccountCredentials($scopes, $serviceAccountJson);
    $accessToken = $credentials->fetchAuthToken()['access_token'];
    
    // === RÉCUPÉRATION DES LIVRES ===
    $booksUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioBooks";
    
    $chBooks = curl_init();
    curl_setopt($chBooks, CURLOPT_URL, $booksUrl);
    curl_setopt($chBooks, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chBooks, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($chBooks, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($chBooks, CURLOPT_TIMEOUT, 15);
    
    $booksResponse = curl_exec($chBooks);
    curl_close($chBooks);
    
    $booksData = json_decode($booksResponse, true);
    $booksDocuments = $booksData['documents'] ?? [];
    
    // Ajouter le nombre d'exemplaires à chaque livre
    $booksWithExemplaires = [];
    foreach ($booksDocuments as $book) {
        $bookCopy = $book;
        
        // Extraire exemplaire
        if (isset($book['fields']['exemplaire']['integerValue'])) {
            $bookCopy['exemplaire'] = (int)$book['fields']['exemplaire']['integerValue'];
        } else if (isset($book['fields']['exemplaire']['doubleValue'])) {
            $bookCopy['exemplaire'] = (int)$book['fields']['exemplaire']['doubleValue'];
        } else {
            $bookCopy['exemplaire'] = 0;
        }
        
        $booksWithExemplaires[] = $bookCopy;
    }
    
    // === RÉCUPÉRATION DES MÉMOIRES ===
    $thesesUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/BiblioThesis";
    
    $chTheses = curl_init();
    curl_setopt($chTheses, CURLOPT_URL, $thesesUrl);
    curl_setopt($chTheses, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chTheses, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($chTheses, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($chTheses, CURLOPT_TIMEOUT, 15);
    
    $thesesResponse = curl_exec($chTheses);
    curl_close($chTheses);
    
    $thesesData = json_decode($thesesResponse, true);
    $thesesDocuments = $thesesData['documents'] ?? [];
    
    // Ajouter le nombre d'exemplaires à chaque mémoire
    $thesesWithExemplaires = [];
    foreach ($thesesDocuments as $thesis) {
        $thesisCopy = $thesis;
        
        if (isset($thesis['fields']['exemplaire']['integerValue'])) {
            $thesisCopy['exemplaire'] = (int)$thesis['fields']['exemplaire']['integerValue'];
        } else if (isset($thesis['fields']['exemplaire']['doubleValue'])) {
            $thesisCopy['exemplaire'] = (int)$thesis['fields']['exemplaire']['doubleValue'];
        } else {
            $thesisCopy['exemplaire'] = 1; // Par défaut 1 pour les mémoires
        }
        
        $thesesWithExemplaires[] = $thesisCopy;
    }
    
    // Retourner les données
    echo json_encode([
        'success' => true,
        'books' => $booksWithExemplaires,
        'theses' => $thesesWithExemplaires,
        'total_books' => count($booksWithExemplaires),
        'total_theses' => count($thesesWithExemplaires)
    ]);
    
} catch (Exception $e) {
    error_log("ajax_get_all_documents.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur',
        'message' => $e->getMessage(),
        'books' => [],
        'theses' => []
    ]);
}