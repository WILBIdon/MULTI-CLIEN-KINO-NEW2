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
    json_exit(['error' => 'No autenticado', 'code' => 401]);
}

$clientCode = $_SESSION['client_code'];

try {
    $db = open_client_db($clientCode);
} catch (Exception $e) {
    json_exit(['error' => 'Error de base de datos', 'code' => 500]);
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
            $tipo = sanitize_code($_POST['tipo'] ?? 'documento');
            $numero = trim($_POST['numero'] ?? '');
            $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
            $proveedor = trim($_POST['proveedor'] ?? '');
            $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? '')));

            if (empty($_FILES['file']['tmp_name'])) {
                json_exit(['error' => 'Archivo no recibido']);
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

            // Buscar en datos_extraidos (texto pre-indexado)
            $stmt = $db->prepare("
                SELECT 
                    d.id, d.tipo, d.numero, d.fecha, d.proveedor, d.ruta_archivo,
                    d.datos_extraidos
                FROM documentos d
                WHERE d.datos_extraidos LIKE ?
                ORDER BY d.fecha DESC
                LIMIT ?
            ");
            $stmt->execute(['%' . $query . '%', $limit]);
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
                $stmt = $db->prepare("
                    SELECT id, ruta_archivo, tipo 
                    FROM documentos 
                    WHERE ruta_archivo LIKE '%.pdf'
                      AND (datos_extraidos IS NULL OR datos_extraidos = '' OR datos_extraidos = '[]')
                    LIMIT ?
                ");
                $stmt->execute([$batchSize]);
            }

            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    $errors[] = "#{$doc['id']}: Archivo no encontrado";
                    continue;
                }

                try {
                    $extractResult = extract_codes_from_pdf($pdfPath);
                    if ($extractResult['success']) {
                        $datosExtraidos = [
                            'text' => substr($extractResult['text'], 0, 50000), // Máximo 50KB de texto
                            'auto_codes' => $extractResult['codes'],
                            'indexed_at' => date('Y-m-d H:i:s')
                        ];
                        $updateStmt->execute([json_encode($datosExtraidos), $doc['id']]);
                        $indexed++;
                    } else {
                        $errors[] = "#{$doc['id']}: " . ($extractResult['error'] ?? 'Error de extracción');
                    }
                } catch (Exception $e) {
                    $errors[] = "#{$doc['id']}: " . $e->getMessage();
                }
            }

            // Contar pendientes
            $pending = (int) $db->query("
                SELECT COUNT(*) FROM documentos 
                WHERE ruta_archivo LIKE '%.pdf'
                  AND (datos_extraidos IS NULL OR datos_extraidos = '' OR datos_extraidos = '[]')
            ")->fetchColumn();

            json_exit([
                'success' => true,
                'indexed' => $indexed,
                'errors' => $errors,
                'pending' => $pending,
                'message' => "Indexados: $indexed, Pendientes: $pending"
            ]);

        default:
            json_exit(['error' => 'Acción inválida: ' . $action, 'code' => 400]);
    }
} catch (PDOException $e) {
    error_log('API Error: ' . $e->getMessage());
    json_exit(['error' => 'Error de base de datos', 'code' => 500]);
} catch (Exception $e) {
    json_exit(['error' => $e->getMessage(), 'code' => 500]);
}
