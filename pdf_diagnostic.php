<?php
/**
 * PDF Extraction Diagnostic Tool
 * This is a public endpoint to check if PDF extraction works on Railway
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/pdf_extractor.php';

header('Content-Type: application/json; charset=utf-8');

$diagnostics = [
    'php_version' => PHP_VERSION,
    'os' => PHP_OS,
    'pdftotext_available' => false,
    'pdftotext_path' => null,
    'smalot_available' => false,
    'native_php' => true,
    'test_extraction' => null
];

// Check pdftotext
$pdftotextPath = find_pdftotext();
if ($pdftotextPath) {
    $diagnostics['pdftotext_available'] = true;
    $diagnostics['pdftotext_path'] = $pdftotextPath;

    // Get version
    $version = shell_exec("$pdftotextPath -v 2>&1");
    $diagnostics['pdftotext_version'] = trim(substr($version ?? '', 0, 200));
}

// Check Smalot
$parserPath = __DIR__ . '/vendor/autoload.php';
$diagnostics['vendor_path'] = $parserPath;
$diagnostics['vendor_exists'] = file_exists($parserPath);

if (file_exists($parserPath)) {
    require_once $parserPath;
    $diagnostics['smalot_available'] = class_exists('Smalot\PdfParser\Parser');
}

// Check clients directory
$diagnostics['clients_dir'] = CLIENTS_DIR;
$diagnostics['clients_exists'] = is_dir(CLIENTS_DIR);

if (is_dir(CLIENTS_DIR)) {
    $clients = scandir(CLIENTS_DIR);
    $diagnostics['clients'] = array_values(array_diff($clients, ['.', '..']));
}

// Try to find and test a PDF
$testPdf = null;

// Look for a PDF in any client folder
if (!empty($diagnostics['clients'])) {
    foreach ($diagnostics['clients'] as $client) {
        $uploadsDir = CLIENTS_DIR . "/$client/uploads";
        if (is_dir($uploadsDir)) {
            // Search recursively for a PDF
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
                    $testPdf = $file->getPathname();
                    break 2;
                }
            }
        }
    }
}

if ($testPdf) {
    $diagnostics['test_pdf'] = $testPdf;
    $diagnostics['test_pdf_exists'] = file_exists($testPdf);
    $diagnostics['test_pdf_size'] = filesize($testPdf);

    try {
        $result = extract_codes_from_pdf($testPdf);
        $diagnostics['extraction_success'] = $result['success'];
        $diagnostics['text_length'] = strlen($result['text'] ?? '');
        $diagnostics['text_sample'] = substr($result['text'] ?? '', 0, 1000);
        $diagnostics['codes_count'] = count($result['codes'] ?? []);
        $diagnostics['error'] = $result['error'] ?? null;
    } catch (Exception $e) {
        $diagnostics['extraction_error'] = $e->getMessage();
    }
} else {
    $diagnostics['test_pdf'] = 'No PDF found in any client folder';
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
