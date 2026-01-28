<?php

namespace Kino\Api;

use PDO;
use Exception;

class SystemController extends BaseController
{
    public function reindex($request)
    {
        set_time_limit(300);

        $forceAll = isset($request['force']);
        $batchSize = min(20, max(1, (int) ($request['batch'] ?? 10)));

        $offset = (int) ($request['offset'] ?? 0);

        if ($forceAll) {
            $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo 
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                ORDER BY id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$batchSize, $offset]);
        } else {
            $stmt = $this->db->prepare("
                SELECT id, ruta_archivo, tipo, datos_extraidos
                FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                ORDER BY id DESC
            ");
            $stmt->execute();

            $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $docs = [];
            foreach ($allDocs as $d) {
                $data = json_decode($d['datos_extraidos'] ?? '', true);
                $text = $data['text'] ?? '';
                $hasError = isset($data['error']);

                if (!$forceAll && $hasError) {
                    continue;
                }

                if (empty($text) || strlen($text) < 100) {
                    unset($d['datos_extraidos']);
                    $docs[] = $d;
                    if (count($docs) >= $batchSize)
                        break;
                }
            }
        }

        if ($forceAll) {
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $indexed = 0;
        $errors = [];

        $updateStmt = $this->db->prepare("UPDATE documentos SET datos_extraidos = ? WHERE id = ?");
        $uploadsDir = CLIENTS_DIR . "/{$this->clientCode}/uploads/";

        foreach ($docs as $doc) {
            $pdfPath = $uploadsDir . $doc['ruta_archivo'];

            if (!file_exists($pdfPath)) {
                $pdfPath = $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']);
            }

            if (!file_exists($pdfPath)) {
                $pdfPath = $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'];
            }

            if (!file_exists($pdfPath)) {
                $searchPattern = $uploadsDir . $doc['tipo'] . '/*' . basename($doc['ruta_archivo']);
                $matches = glob($searchPattern);
                if (!empty($matches)) {
                    $pdfPath = $matches[0];
                }
            }

            if (!file_exists($pdfPath)) {
                $triedPaths = [
                    $uploadsDir . $doc['ruta_archivo'],
                    $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']),
                    $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'],
                    "GLOB: " . ($searchPattern ?? 'N/A')
                ];
                $errors[] = "#{$doc['id']}: Archivo no encontrado";

                $errorData = json_encode([
                    'error' => 'Archivo no encontrado',
                    'paths_tried' => $triedPaths,
                    'timestamp' => time()
                ]);
                $updateStmt->execute([$errorData, $doc['id']]);

                continue;
            }

            try {
                $extractResult = extract_codes_from_pdf($pdfPath);
                if ($extractResult['success'] && !empty($extractResult['text'])) {
                    $datosExtraidos = [
                        'text' => substr($extractResult['text'], 0, 50000),
                        'auto_codes' => $extractResult['codes'],
                        'indexed_at' => date('Y-m-d H:i:s')
                    ];
                    $updateStmt->execute([json_encode($datosExtraidos, JSON_UNESCAPED_UNICODE), $doc['id']]);
                    $indexed++;
                } else {
                    $errors[] = "#{$doc['id']}: " . ($extractResult['error'] ?? 'Sin texto');
                }
            } catch (Exception $e) {
                $errors[] = "#{$doc['id']}: " . $e->getMessage();
            }
        }

        $allStmt = $this->db->query("SELECT datos_extraidos FROM documentos WHERE ruta_archivo LIKE '%.pdf'");
        $pending = 0;
        while ($row = $allStmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['datos_extraidos'] ?? '', true);
            if (empty($data['text']) || strlen($data['text'] ?? '') < 100) {
                $pending++;
            }
        }

        $this->jsonExit([
            'success' => true,
            'indexed' => $indexed,
            'errors' => $errors,
            'pending' => $pending,
            'message' => "Indexados: $indexed, Pendientes: $pending"
        ]);
    }

    public function diagnostic($request)
    {
        $diagnostics = [
            'pdftotext_available' => false,
            'pdftotext_path' => null,
            'smalot_available' => false,
            'native_php' => true,
            'test_result' => null,
            'sample_doc' => null
        ];

        // Global function presumed available
        $pdftotextPath = find_pdftotext();
        if ($pdftotextPath) {
            $diagnostics['pdftotext_available'] = true;
            $diagnostics['pdftotext_path'] = $pdftotextPath;
            $version = shell_exec("$pdftotextPath -v 2>&1");
            $diagnostics['pdftotext_version'] = trim(substr($version, 0, 100));
        }

        $parserPath = __DIR__ . '/../../vendor/autoload.php'; // Adjusted path
        if (file_exists($parserPath)) {
            require_once $parserPath;
            $diagnostics['smalot_available'] = class_exists('Smalot\PdfParser\Parser');
        }

        $testId = (int) ($request['doc_id'] ?? 0);
        if ($testId) {
            $stmt = $this->db->prepare("SELECT id, ruta_archivo, tipo FROM documentos WHERE id = ?");
            $stmt->execute([$testId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                $diagnostics['sample_doc'] = $doc['ruta_archivo'];
                $uploadsDir = CLIENTS_DIR . "/{$this->clientCode}/uploads/";

                $possiblePaths = [
                    $uploadsDir . $doc['ruta_archivo'],
                    $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'],
                    $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']),
                ];

                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $diagnostics['pdf_found'] = true;
                        $diagnostics['pdf_path'] = $path;
                        $diagnostics['pdf_size'] = filesize($path);

                        try {
                            $diagnostics['test_result'] = extract_codes_from_pdf($path);
                        } catch (Exception $e) {
                            $diagnostics['test_result'] = ['error' => $e->getMessage()];
                        }
                        break;
                    }
                }
            }
        }
        $this->jsonExit($diagnostics);
    }
}
