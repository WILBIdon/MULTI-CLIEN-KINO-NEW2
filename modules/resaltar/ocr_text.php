<?php
/**
 * OCR Text Extraction Endpoint for Scanned PDFs
 * 
 * SOLO se llama cuando PDF.js no encuentra texto en una página.
 * Usa Tesseract OCR para extraer texto de documentos escaneados.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';

header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$clientCode = $_SESSION['client_code'];

try {
    $documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
    $filePath = isset($_GET['file']) ? $_GET['file'] : '';
    $termsParam = isset($_GET['terms']) ? $_GET['terms'] : '';
    $pageNum = isset($_GET['page']) ? (int) $_GET['page'] : 0;

    // Parse terms to search
    $terms = array_filter(array_map('trim', explode(',', $termsParam)));

    if (empty($terms)) {
        echo json_encode(['success' => true, 'matches' => [], 'text' => '']);
        exit;
    }

    // Resolve PDF path
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
    $pdfPath = null;

    if ($documentId > 0) {
        $db = open_client_db($clientCode);
        $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($document) {
            $pdfPath = resolve_pdf_path($clientCode, $document);
        }
    } elseif (!empty($filePath)) {
        $pdfPath = $uploadsDir . $filePath;
    }

    if (!$pdfPath || !file_exists($pdfPath)) {
        throw new Exception('Archivo PDF no encontrado');
    }

    // Extract text using OCR (uses Tesseract from pdf_extractor.php)
    $text = extract_text_from_pdf($pdfPath);

    if (empty(trim($text))) {
        // OCR also failed - maybe Tesseract not installed
        echo json_encode([
            'success' => true,
            'matches' => [],
            'text' => '',
            'message' => 'No se pudo extraer texto del documento (OCR no disponible)'
        ]);
        exit;
    }

    // Find matches for each term (case-insensitive)
    $matches = [];
    foreach ($terms as $term) {
        $termLower = mb_strtolower($term, 'UTF-8');
        $textLower = mb_strtolower($text, 'UTF-8');

        $offset = 0;
        while (($pos = mb_strpos($textLower, $termLower, $offset, 'UTF-8')) !== false) {
            $matches[] = [
                'term' => $term,
                'position' => $pos,
                'context' => mb_substr($text, max(0, $pos - 20), mb_strlen($term) + 40, 'UTF-8')
            ];
            $offset = $pos + 1;
        }
    }

    echo json_encode([
        'success' => true,
        'matches' => $matches,
        'match_count' => count($matches),
        'text' => $text,
        'terms_searched' => $terms
    ]);

} catch (Exception $e) {
    error_log("OCR Error in ocr_text.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
