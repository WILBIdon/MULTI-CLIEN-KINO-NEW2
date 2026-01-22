<?php
/**
 * API Unificada para KINO-TRACE
 *
 * Proporciona endpoints para:
 * - Subida de documentos con extracción de códigos
 * - Búsqueda inteligente voraz
 * - CRUD de documentos
 * - Integración con IA (Gemini)
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/logger.php';
require_once __DIR__ . '/helpers/error_codes.php';
require_once __DIR__ . '/helpers/search_engine.php';
require_once __DIR__ . '/helpers/pdf_extractor.php';
require_once __DIR__ . '/helpers/gemini_ai.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Respuesta JSON y salida.
 */
function json_exit($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    send_error_response(api_error('AUTH_002'));
}

$clientCode = $_SESSION['client_code'];

try {
    $db = open_client_db($clientCode);
} catch (PDOException $e) {
    Logger::exception($e, ['client' => $clientCode]);
    send_error_response(api_error('DB_001', null, ['db_error' => $e->getMessage()]));
} catch (Exception $e) {
    Logger::exception($e, ['client' => $clientCode]);
    send_error_response(api_error('SYS_001'));
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ====================
        // EXTRACCIÓN DE CÓDIGOS DE PDF
        // ====================
        case 'extract_codes':
            if (empty($_FILES['file']['tmp_name'])) {
                json_exit(['error' => 'Archivo no recibido']);
            }

            $prefix = $_POST['prefix'] ?? '';
            $terminator = $_POST['terminator'] ?? '/';
            $minLength = (int) ($_POST['min_length'] ?? 4);
            $maxLength = (int) ($_POST['max_length'] ?? 50);

            $config = [
                'prefix' => $prefix,
                'terminator' => $terminator,
                'min_length' => $minLength,
                'max_length' => $maxLength
            ];

            $result = extract_codes_from_pdf($_FILES['file']['tmp_name'], $config);
            json_exit($result);

        // ====================
        // BUSCAR CÓDIGOS EN PDF SIN GUARDAR
        // ====================
        case 'search_in_pdf':
            if (empty($_FILES['file']['tmp_name'])) {
                json_exit(['error' => 'Archivo no recibido']);
            }

            $searchCodes = array_filter(
                array_map('trim', explode("\n", $_POST['codes'] ?? ''))
            );

            $result = search_codes_in_pdf($_FILES['file']['tmp_name'], $searchCodes);
            json_exit($result);

        // ====================
        // SUBIR DOCUMENTO CON CÓDIGOS
        // ====================
        case 'upload':
            // Validar campos requireridos
            $validationError = validate_required_fields($_POST, ['tipo', 'numero', 'fecha']);
            if ($validationError) {
                json_exit($validationError);
            }

            $tipo = sanitize_code($_POST['tipo']);
            $numero = trim($_POST['numero']);
            $fecha = trim($_POST['fecha']);
            $proveedor = trim($_POST['proveedor'] ?? '');
            $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? '')));

            // Validar archivo
            if (empty($_FILES['file']['tmp_name'])) {
                json_exit(api_error('FILE_005'));
            }

            $fileValidation = validate_file_type($_FILES['file']);
            if ($fileValidation) {
                json_exit($fileValidation);
            }

            $sizeValidation = validate_file_size($_FILES['file']);
            if ($sizeValidation) {
                json_exit($sizeValidation);
            }

            // Crear directorio de uploads
            $clientDir = CLIENTS_DIR . '/' . $clientCode;
            $uploadDir = $clientDir . '/uploads/' . $tipo;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Mover archivo
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $targetName = uniqid($tipo . '_', true) . '.' . $ext;
            $targetPath = $uploadDir . '/' . $targetName;
            move_uploaded_file($_FILES['file']['tmp_name'], $targetPath);

            // Hash del archivo
            $hash = hash_file('sha256', $targetPath);

            // Extraer texto si es PDF
            $datosExtraidos = [];
            if (strtolower($ext) === 'pdf') {
                $extractResult = extract_codes_from_pdf($targetPath);
                if ($extractResult['success']) {
                    $datosExtraidos = [
                        'text' => substr($extractResult['text'], 0, 10000),
                        'auto_codes' => $extractResult['codes']
                    ];
                }
            }

            // Insertar documento
            $stmt = $db->prepare("
                INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, hash_archivo, datos_extraidos)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $tipo,
                $numero,
                $fecha,
                $proveedor,
                $tipo . '/' . $targetName,
                $hash,
                json_encode($datosExtraidos)
            ]);

            $docId = $db->lastInsertId();

            // Insertar códigos
            if (!empty($codes)) {
                $insertCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
                foreach (array_unique($codes) as $code) {
                    $insertCode->execute([$docId, $code]);
                }
            }

            json_exit([
                'success' => true,
                'message' => 'Documento guardado',
                'document_id' => $docId,
                'codes_count' => count($codes)
            ]);

        // ====================
        // ACTUALIZAR DOCUMENTO
        // ====================
        case 'update':
            $id = (int) ($_POST['id'] ?? 0);
            $tipo = trim($_POST['tipo'] ?? '');
            $numero = trim($_POST['numero'] ?? '');
            $fecha = trim($_POST['fecha'] ?? '');
            $proveedor = trim($_POST['proveedor'] ?? '');
            $currentFile = trim($_POST['current_file'] ?? '');

            if (!$id || !$tipo || !$numero || !$fecha) {
                json_exit(['error' => 'Faltan campos requeridos']);
            }

            // Parse codes
            $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? '')));

            // Check if document exists
            $stmt = $db->prepare("SELECT id, ruta_archivo FROM documentos WHERE id = ?");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                json_exit(['error' => 'Documento no encontrado']);
            }

            $rutaArchivo = $doc['ruta_archivo'];
            $hash = null;
            $datosExtraidos = null;

            // Check if a new file was uploaded
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $clientDir = CLIENTS_DIR . '/' . $clientCode;
                $uploadDir = $clientDir . '/uploads/' . $tipo;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                // Delete old file if it exists and is different from the new location
                $oldFilePath = $clientDir . '/uploads/' . $doc['ruta_archivo'];
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }

                // Upload new file
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $targetName = uniqid($tipo . '_', true) . '.' . $ext;
                $targetPath = $uploadDir . '/' . $targetName;
                move_uploaded_file($_FILES['file']['tmp_name'], $targetPath);

                // Calculate hash and extract text
                $hash = hash_file('sha256', $targetPath);
                $rutaArchivo = $tipo . '/' . $targetName;

                if (strtolower($ext) === 'pdf') {
                    $extractResult = extract_codes_from_pdf($targetPath);
                    if ($extractResult['success']) {
                        $datosExtraidos = [
                            'text' => substr($extractResult['text'], 0, 10000),
                            'auto_codes' => $extractResult['codes']
                        ];
                    }
                }
            }

            // Update document
            if ($hash && $datosExtraidos) {
                // New file uploaded - update everything
                $stmt = $db->prepare("
                    UPDATE documentos 
                    SET tipo = ?, numero = ?, fecha = ?, proveedor = ?, 
                        ruta_archivo = ?, hash_archivo = ?, datos_extraidos = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $tipo,
                    $numero,
                    $fecha,
                    $proveedor,
                    $rutaArchivo,
                    $hash,
                    json_encode($datosExtraidos),
                    $id
                ]);
            } else {
                // No new file - update only metadata
                $stmt = $db->prepare("
                    UPDATE documentos 
                    SET tipo = ?, numero = ?, fecha = ?, proveedor = ?
                    WHERE id = ?
                ");
                $stmt->execute([$tipo, $numero, $fecha, $proveedor, $id]);
            }

            // Update codes - delete old codes and insert new ones
            $db->prepare("DELETE FROM codigos WHERE documento_id = ?")->execute([$id]);

            if (!empty($codes)) {
                $insertCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
                foreach (array_unique($codes) as $code) {
                    $insertCode->execute([$id, $code]);
                }
            }

            json_exit([
                'success' => true,
                'message' => 'Documento actualizado',
                'document_id' => $id,
                'codes_count' => count($codes)
            ]);

        // ====================
        // ELIMINAR DOCUMENTO
        // ====================
        case 'delete':
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

            if (!$id) {
                json_exit(api_error('VALIDATION_001', 'ID de documento requerido'));
            }

            // Get document info first
            $stmt = $db->prepare('SELECT ruta_archivo, tipo FROM documentos WHERE id = ?');
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                json_exit(api_error('DOC_001'));
            }

            // Delete physical file
            $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
            $filePath = $uploadsDir . $doc['ruta_archivo'];

            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete from database (cascade will delete codes)
            $stmt = $db->prepare('DELETE FROM documentos WHERE id = ?');
            $stmt->execute([$id]);

            Logger::info('Document deleted', [
                'doc_id' => $id,
                'file' => $doc['ruta_archivo']
            ]);

            json_exit([
                'success' => true,
                'message' => 'Documento eliminado correctamente'
            ]);

        // ====================
        // BÚSQUEDA VORAZ DE CÓDIGOS
        // ====================
        case 'search':
            $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? $_GET['codes'] ?? '')));

            if (empty($codes)) {
                json_exit(['error' => 'No se proporcionaron códigos']);
            }

            $result = greedy_search($db, $codes);
            json_exit($result);

        // ====================
        // BÚSQUEDA SIMPLE POR CÓDIGO
        // ====================
        case 'search_by_code':
            $code = trim($_REQUEST['code'] ?? '');
            $result = search_by_code($db, $code);
            json_exit(['documents' => $result]);

        // ====================
        // SUGERENCIAS MIENTRAS ESCRIBE
        // ====================
        case 'suggest':
            $term = trim($_GET['term'] ?? '');
            $suggestions = suggest_codes($db, $term, 10);
            json_exit($suggestions);

        // ====================
        // LISTAR DOCUMENTOS
        // ====================
        case 'list':
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = (int) ($_GET['per_page'] ?? 50);
            $tipo = $_GET['tipo'] ?? '';

            $where = '';
            $params = [];
            if ($tipo !== '') {
                $where = 'WHERE d.tipo = ?';
                $params[] = $tipo;
            }

            $total = (int) $db->query("SELECT COUNT(*) FROM documentos $where")->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $stmt = $db->prepare("
                SELECT
                    d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                    GROUP_CONCAT(c.codigo, '||') AS codigos
                FROM documentos d
                LEFT JOIN codigos c ON d.id = c.documento_id
                $where
                GROUP BY d.id
                ORDER BY d.fecha DESC
                LIMIT ? OFFSET ?
            ");

            $allParams = array_merge($params, [$perPage, $offset]);
            $stmt->execute($allParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $docs = array_map(function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'tipo' => $r['tipo'],
                    'numero' => $r['numero'],
                    'fecha' => $r['fecha'],
                    'proveedor' => $r['proveedor'],
                    'ruta_archivo' => $r['ruta_archivo'],
                    'codes' => $r['codigos'] ? array_filter(explode('||', $r['codigos'])) : []
                ];
            }, $rows);

            json_exit([
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) ceil($total / $perPage),
                'data' => $docs
            ]);

        // ====================
        // VER DOCUMENTO
        // ====================
        case 'get':
            $id = (int) ($_GET['id'] ?? 0);
            $stmt = $db->prepare("
                SELECT d.*, GROUP_CONCAT(c.codigo, '||') AS codigos
                FROM documentos d
                LEFT JOIN codigos c ON d.id = c.documento_id
                WHERE d.id = ?
                GROUP BY d.id
            ");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                json_exit(['error' => 'Documento no encontrado']);
            }

            $doc['codes'] = $doc['codigos'] ? array_filter(explode('||', $doc['codigos'])) : [];
            unset($doc['codigos']);

            json_exit($doc);

        // ====================
        // ELIMINAR DOCUMENTO
        // ====================
        case 'delete':
            $id = (int) ($_REQUEST['id'] ?? 0);
            if (!$id) {
                json_exit(['error' => 'ID inválido']);
            }

            // Obtener ruta del archivo
            $stmt = $db->prepare("SELECT ruta_archivo FROM documentos WHERE id = ?");
            $stmt->execute([$id]);
            $path = $stmt->fetchColumn();

            // Eliminar archivo
            if ($path) {
                $fullPath = CLIENTS_DIR . '/' . $clientCode . '/uploads/' . $path;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }

            // Eliminar de DB
            $db->prepare("DELETE FROM codigos WHERE documento_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM documentos WHERE id = ?")->execute([$id]);

            json_exit(['success' => true, 'message' => 'Documento eliminado']);

        // ====================
        // ESTADÍSTICAS
        // ====================
        case 'stats':
            $stats = get_search_stats($db);
            json_exit($stats);

        // ====================
        // GEMINI AI: Extracción inteligente
        // ====================
        case 'ai_extract':
            if (!is_gemini_configured()) {
                json_exit(['error' => 'Gemini AI no configurado. Configure GEMINI_API_KEY.']);
            }

            $documentId = (int) ($_POST['document_id'] ?? 0);
            $documentType = $_POST['document_type'] ?? 'documento';

            if ($documentId) {
                // Obtener texto del documento
                $stmt = $db->prepare("SELECT datos_extraidos FROM documentos WHERE id = ?");
                $stmt->execute([$documentId]);
                $data = json_decode($stmt->fetchColumn(), true);
                $text = $data['text'] ?? '';
            } else {
                $text = $_POST['text'] ?? '';
            }

            if (empty($text)) {
                json_exit(['error' => 'No hay texto para analizar']);
            }

            $result = ai_extract_document_data($text, $documentType);
            json_exit($result);

        // ====================
        // GEMINI AI: Chat con documentos
        // ====================
        case 'ai_chat':
            if (!is_gemini_configured()) {
                json_exit(['error' => 'Gemini AI no configurado']);
            }

            $question = trim($_POST['question'] ?? '');
            if (empty($question)) {
                json_exit(['error' => 'Pregunta vacía']);
            }

            // Obtener contexto de documentos recientes
            $context = $db->query("
                SELECT tipo, numero, fecha, proveedor
                FROM documentos
                ORDER BY fecha DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Agregar códigos al contexto
            foreach ($context as &$doc) {
                $stmt = $db->prepare("SELECT codigo FROM codigos WHERE documento_id = ?");
                $stmt->execute([$doc['id'] ?? 0]);
                $doc['codigos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $result = ai_chat_with_context($question, $context);
            json_exit($result);

        // ====================
        // SMART CHAT - Asistente que conoce la app
        // ====================
        case 'smart_chat':
            if (!is_gemini_configured()) {
                json_exit(['error' => 'Gemini AI no configurado. Configure GEMINI_API_KEY.']);
            }

            $question = trim($_POST['question'] ?? '');
            if (empty($question)) {
                json_exit(['error' => 'Pregunta vacía']);
            }

            $result = ai_smart_chat($db, $question, $clientCode);
            json_exit($result);

        // ====================
        // VERIFICAR ESTADO DE IA
        // ====================
        case 'ai_status':
            json_exit([
                'configured' => is_gemini_configured(),
                'model' => GEMINI_MODEL
            ]);

        // ====================
        // BÚSQUEDA FULL-TEXT EN CONTENIDO DE PDFs
        // ====================
        case 'fulltext_search':
            $query = trim($_REQUEST['query'] ?? '');
            $limit = min(100, max(1, (int) ($_REQUEST['limit'] ?? 50)));

            if (strlen($query) < 3) {
                json_exit(['error' => 'El término debe tener al menos 3 caracteres']);
            }

            // Buscar en datos_extraidos (texto) Y en numero (nombre del documento)
            $stmt = $db->prepare("
                SELECT 
                    d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                    d.datos_extraidos
                FROM documentos d
                WHERE d.datos_extraidos LIKE ? OR d.numero LIKE ?
                ORDER BY d.fecha DESC
                LIMIT ?
            ");
            $likeQuery = '%' . $query . '%';
            $stmt->execute([$likeQuery, $likeQuery, $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($rows as $r) {
                // Extraer snippet con contexto
                $data = json_decode($r['datos_extraidos'], true);
                $text = $data['text'] ?? '';
                $snippet = '';

                if (!empty($text)) {
                    $pos = stripos($text, $query);
                    if ($pos !== false) {
                        $start = max(0, $pos - 60);
                        $end = min(strlen($text), $pos + strlen($query) + 60);
                        $snippet = ($start > 0 ? '...' : '') .
                            substr($text, $start, $end - $start) .
                            ($end < strlen($text) ? '...' : '');
                        $snippet = preg_replace('/\s+/', ' ', trim($snippet));
                    }
                }

                // Contar ocurrencias
                $occurrences = substr_count(strtolower($text), strtolower($query));

                $results[] = [
                    'id' => (int) $r['id'],
                    'tipo' => $r['tipo'],
                    'numero' => $r['numero'],
                    'fecha' => $r['fecha'],
                    'proveedor' => $r['proveedor'],
                    'ruta_archivo' => $r['ruta_archivo'],
                    'snippet' => $snippet,
                    'occurrences' => $occurrences
                ];
            }

            // Ordenar por relevancia (más ocurrencias primero)
            usort($results, fn($a, $b) => $b['occurrences'] - $a['occurrences']);

            json_exit([
                'query' => $query,
                'count' => count($results),
                'results' => $results
            ]);

        // ====================
        // RE-INDEXAR DOCUMENTOS (extraer texto de PDFs)
        // ====================
        case 'reindex_documents':
            set_time_limit(300); // 5 minutos máximo

            $forceAll = isset($_REQUEST['force']);
            $batchSize = min(20, max(1, (int) ($_REQUEST['batch'] ?? 10)));

            // Obtener documentos sin texto indexado
            if ($forceAll) {
                $stmt = $db->prepare("
                    SELECT id, ruta_archivo, tipo 
                    FROM documentos 
                    WHERE ruta_archivo LIKE '%.pdf'
                    LIMIT ?
                ");
                $stmt->execute([$batchSize]);
            } else {
                // Buscar docs - seleccionar todos y filtrar en PHP por contenido real
                $stmt = $db->prepare("
                    SELECT id, ruta_archivo, tipo, datos_extraidos
                    FROM documentos 
                    WHERE ruta_archivo LIKE '%.pdf'
                    ORDER BY id DESC
                ");
                $stmt->execute();

                // Filtrar en PHP: solo docs sin texto real
                $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $docs = [];
                foreach ($allDocs as $d) {
                    $data = json_decode($d['datos_extraidos'] ?? '', true);
                    $text = $data['text'] ?? '';
                    $hasError = isset($data['error']);

                    // Skip if explicitly marked as error (unless forced)
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

            $updateStmt = $db->prepare("UPDATE documentos SET datos_extraidos = ? WHERE id = ?");
            $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

            foreach ($docs as $doc) {
                $pdfPath = $uploadsDir . $doc['ruta_archivo'];

                // Intentar múltiples rutas
                if (!file_exists($pdfPath)) {
                    $pdfPath = $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']);
                }

                if (!file_exists($pdfPath)) {
                    $pdfPath = $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'];
                }

                if (!file_exists($pdfPath)) {
                    // Estrategia 3: Búsqueda borrosa (fuzzy) - el archivo físico puede tener prefijos extra
                    // Ejemplo DB: archivo.pdf -> Real: 123456_archivo.pdf
                    // Buscamos en la carpeta del tipo del documento
                    $searchPattern = $uploadsDir . $doc['tipo'] . '/*' . basename($doc['ruta_archivo']);
                    $matches = glob($searchPattern);
                    if (!empty($matches)) {
                        $pdfPath = $matches[0];
                    }
                }

                if (!file_exists($pdfPath)) {
                    // Debug info
                    $triedPaths = [
                        $uploadsDir . $doc['ruta_archivo'],
                        $uploadsDir . $doc['tipo'] . '/' . basename($doc['ruta_archivo']),
                        $uploadsDir . $doc['tipo'] . '/' . $doc['ruta_archivo'],
                        "GLOB: " . ($searchPattern ?? 'N/A')
                    ];
                    $errors[] = "#{$doc['id']}: Archivo no encontrado. Intentado: " . implode(', ', $triedPaths);

                    // Marcar como error en DB para no reintentar infinitamente
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

            // Contar pendientes con lógica PHP
            $allStmt = $db->query("SELECT datos_extraidos FROM documentos WHERE ruta_archivo LIKE '%.pdf'");
            $pending = 0;
            while ($row = $allStmt->fetch(PDO::FETCH_ASSOC)) {
                $data = json_decode($row['datos_extraidos'] ?? '', true);
                if (empty($data['text']) || strlen($data['text'] ?? '') < 100) {
                    $pending++;
                }
            }

            json_exit([
                'success' => true,
                'indexed' => $indexed,
                'errors' => $errors,
                'pending' => $pending,
                'message' => "Indexados: $indexed, Pendientes: $pending"
            ]);

        // ====================
        // DIAGNÓSTICO DE EXTRACCIÓN PDF
        // ====================
        case 'pdf_diagnostic':
            $diagnostics = [
                'pdftotext_available' => false,
                'pdftotext_path' => null,
                'smalot_available' => false,
                'native_php' => true,
                'test_result' => null,
                'sample_doc' => null
            ];

            // Verificar pdftotext
            $pdftotextPath = find_pdftotext();
            if ($pdftotextPath) {
                $diagnostics['pdftotext_available'] = true;
                $diagnostics['pdftotext_path'] = $pdftotextPath;

                // Probar versión
                $version = shell_exec("$pdftotextPath -v 2>&1");
                $diagnostics['pdftotext_version'] = trim(substr($version, 0, 100));
            }

            // Verificar Smalot
            $parserPath = __DIR__ . '/vendor/autoload.php';
            if (file_exists($parserPath)) {
                require_once $parserPath;
                $diagnostics['smalot_available'] = class_exists('Smalot\PdfParser\Parser');
            }

            // Probar con un documento real
            $testId = (int) ($_REQUEST['doc_id'] ?? 0);
            if ($testId) {
                $stmt = $db->prepare("SELECT id, ruta_archivo, tipo FROM documentos WHERE id = ?");
                $stmt->execute([$testId]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($doc) {
                    $diagnostics['sample_doc'] = $doc['ruta_archivo'];
                    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

                    // Intentar encontrar el archivo
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

                            // Probar extracción
                            try {
                                $result = extract_codes_from_pdf($path);
                                $diagnostics['extraction_success'] = $result['success'];
                                $diagnostics['text_length'] = strlen($result['text'] ?? '');
                                $diagnostics['text_sample'] = substr($result['text'] ?? '', 0, 500);
                                $diagnostics['codes_found'] = count($result['codes'] ?? []);
                            } catch (Exception $e) {
                                $diagnostics['extraction_error'] = $e->getMessage();
                            }
                            break;
                        }
                    }

                    if (!isset($diagnostics['pdf_found'])) {
                        $diagnostics['pdf_found'] = false;
                        $diagnostics['paths_tried'] = $possiblePaths;
                    }
                }
            }

            json_exit($diagnostics);

        default:
            json_exit(['error' => 'Acción inválida: ' . $action, 'code' => 400]);
    }
} catch (PDOException $e) {
    error_log('API Error: ' . $e->getMessage());
    json_exit(['error' => 'Error de base de datos', 'code' => 500]);
} catch (Exception $e) {
    json_exit(['error' => $e->getMessage(), 'code' => 500]);
}
