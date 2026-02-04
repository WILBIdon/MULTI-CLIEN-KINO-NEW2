<?php
/**
 * OCR Text Extraction Endpoint for Scanned PDFs with Coordinates
 * 
 * SOLO se llama cuando PDF.js no encuentra texto en una página.
 * Usa Tesseract OCR con HOCR para extraer texto Y coordenadas.
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
    $pageNum = isset($_GET['page']) ? (int) $_GET['page'] : 1;

    // Parse terms to search
    $terms = array_filter(array_map('trim', explode(',', $termsParam)));

    if (empty($terms)) {
        echo json_encode(['success' => true, 'matches' => [], 'text' => '', 'highlights' => []]);
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

    // Usar nueva función con coordenadas
    if (function_exists('extract_with_ocr_coordinates')) {
        $ocrResult = extract_with_ocr_coordinates($pdfPath, $pageNum);
    } else {
        // Fallback a función anterior sin coordenadas
        $text = extract_text_from_pdf($pdfPath);
        $ocrResult = ['success' => !empty($text), 'text' => $text, 'words' => []];
    }

    if (!$ocrResult['success'] || empty($ocrResult['words'])) {
        // OCR falló o no hay palabras - intentar extracción simple
        $text = $ocrResult['text'] ?? extract_text_from_pdf($pdfPath);

        if (empty(trim($text))) {
            echo json_encode([
                'success' => true,
                'matches' => [],
                'highlights' => [],
                'text' => '',
                'message' => 'No se pudo extraer texto del documento (OCR no disponible)'
            ]);
            exit;
        }

        // Sin coordenadas, solo buscar coincidencias en texto
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
            'highlights' => [], // Sin coordenadas
            'text' => $text,
            'terms_searched' => $terms
        ]);
        exit;
    }

    // Tenemos palabras con coordenadas - buscar coincidencias
    $matches = [];
    $highlights = []; // Coordenadas para resaltar
    $text = $ocrResult['text'] ?? '';
    $imageWidth = $ocrResult['image_width'] ?? 0;
    $imageHeight = $ocrResult['image_height'] ?? 0;

    foreach ($ocrResult['words'] as $word) {
        $wordText = $word['text'];

        foreach ($terms as $term) {
            // Buscar coincidencia parcial (el término puede estar dentro de la palabra)
            if (mb_stripos($wordText, $term, 0, 'UTF-8') !== false) {
                $matches[] = [
                    'term' => $term,
                    'word' => $wordText,
                    'x' => $word['x'],
                    'y' => $word['y'],
                    'w' => $word['w'],
                    'h' => $word['h']
                ];

                $highlights[] = [
                    'x' => $word['x'],
                    'y' => $word['y'],
                    'w' => $word['w'],
                    'h' => $word['h'],
                    'term' => $term
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'matches' => $matches,
        'match_count' => count($matches),
        'highlights' => $highlights,
        'image_width' => $imageWidth,
        'image_height' => $imageHeight,
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
