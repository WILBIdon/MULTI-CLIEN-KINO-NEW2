<?php
ob_start();
/**
 * process_final.php (adapted for Kino Trace context)
 * 
 * Implementación "Tal Cual" solicitada por el usuario (Step 832)
 * Adaptada mínimamente para funcionar dentro de la estructura de API/JSON del sistema.
 */
header('Content-Type: application/json');
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300);
ini_set('memory_limit', '512M');

// Shutdown handler for fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    $output = ob_get_clean();
    if ($error) {
        // Log panic info
        file_put_contents('debug_panic.txt', date('c') . " Error: " . print_r($error, true));
    }
});

require_once __DIR__ . '/../../config.php';
while (ob_get_level())
    ob_end_clean();
ob_start();

require_once __DIR__ . '/../../helpers/tenant.php';
while (ob_get_level())
    ob_end_clean();
ob_start();

require_once __DIR__ . '/../../helpers/import_engine.php';
while (ob_get_level())
    ob_end_clean();
ob_start();

// --- ADAPTACION: Estructura de Respuesta JSON GLOBAL ---
$response = [
    'success' => false,
    'logs' => [],
    'error' => null
];

// --- ADAPTACION: logMsg para JSON ---
function logMsg($msg, $type = "info")
{
    global $response;
    // Mapeo de tipos de log para el frontend (success, error, info, warning)
    $mappedType = $type;
    if ($type === 'warn')
        $mappedType = 'warning';

    $response['logs'][] = ['msg' => $msg, 'type' => $mappedType];
}

// --- CODIGO DEL USUARIO "TAL CUAL" (INICIO) ---

function normalizeKey($s)
{
    // 1) keep only filename (remove directories)
    $s = basename($s);

    // 2) remove extension
    $s = preg_replace('/\.[Pp][Dd][Ff]$/', '', $s);

    // 3) remove leading timestamp-like prefixes: "1748...._" or "1748....-"
    $s = preg_replace('/^\d{6,}[_\-\s]+/', '', $s);

    // 4) normalize spaces
    $s = str_replace(["\r", "\n", "\t"], " ", $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s);

    // 5) lowercase for stable compare
    $s = mb_strtolower($s, 'UTF-8');

    return $s;
}

function buildDocumentoIndex(PDO $db)
{
    // Build a PHP-side index for robust matching (no fragile SQL LIKE).
    // We index by:
    // - normalized numero
    // - normalized original_path (filename)
    // - normalized original_path without extension
    $idx = [];  // key => id

    $q = $db->query("SELECT id, numero, original_path, ruta_archivo FROM documentos");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $row['id'];

        if (!empty($row['numero'])) {
            $k = normalizeKey($row['numero']);
            if ($k !== "")
                $idx[$k] = $id;
        }

        if (!empty($row['original_path'])) {
            $k1 = normalizeKey($row['original_path']);
            if ($k1 !== "")
                $idx[$k1] = $id;

            // Also index without timestamp prefix if original_path includes it
            $k2 = normalizeKey(basename($row['original_path']));
            if ($k2 !== "")
                $idx[$k2] = $id;
        }
    }
    return $idx;
}

/**
 * Link a PDF file to a documento row by id (single source of truth).
 * Updates:
 * - ruta_archivo: relative path where we stored the extracted PDF
 * - original_path: store the ZIP original filename (with extension), NOT just base
 */
function linkById(PDO $db, $id, $relativePath, $fullFilename)
{
    $stmt = $db->prepare("UPDATE documentos
                          SET ruta_archivo = ?, original_path = ?
                          WHERE id = ?");
    $stmt->execute([$relativePath, $fullFilename, $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Process ZIP and link PDFs.
 * - $zipTmpPath: the uploaded ZIP tmp file
 * - $uploadDir: absolute directory where PDFs will be extracted
 * - $relativeBase: relative base used in DB (e.g. 'sql_import/')
 */
function processZipAndLink(PDO $db, $zipTmpPath, $uploadDir, $relativeBase = 'sql_import/')
{
    if (!is_dir($uploadDir))
        mkdir($uploadDir, 0777, true);

    $zip = new ZipArchive();
    if ($zip->open($zipTmpPath) !== TRUE) {
        throw new Exception("No se pudo abrir el ZIP.");
    }

    // Index existing documents once (fast)
    $idx = buildDocumentoIndex($db);

    // Track what document ids already got a PDF in this import run
    $linkedDocIds = [];
    $updatedDocs = 0;
    $createdDocs = 0;
    $duplicates = [];
    $unmatched = [];

    // Prepared statements for fast exact checks
    // (1) Exact match by original_path (case-insensitive)
    $stmtFindByPath = $db->prepare("SELECT id FROM documentos WHERE LOWER(original_path) = LOWER(?) LIMIT 1");
    // (2) Exact match by numero (case-insensitive)
    $stmtFindByNumero = $db->prepare("SELECT id FROM documentos WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?)) LIMIT 1");

    // Insert (best-effort) — no assumption about DB engine
    $stmtCreate = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, estado, ruta_archivo, original_path)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");

    // Local set to avoid creating same "new" doc twice in the same ZIP run
    $createdKeys = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);

        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'pdf')
            continue;

        $base = basename($filename);
        $targetPath = rtrim($uploadDir, "/") . "/" . $base;

        // Extract file
        $ok = copy("zip://" . $zipTmpPath . "#" . $filename, $targetPath);
        if (!$ok) {
            logMsg("❌ No se pudo extraer: $filename", "error");
            continue;
        }

        $relativePath = rtrim($relativeBase, "/") . "/" . $base;   // 'sql_import/xxx.pdf'
        $numero = pathinfo($base, PATHINFO_FILENAME);              // without .pdf

        // ---------- MATCH STEP 1: original_path exact ----------
        $stmtFindByPath->execute([$base]); // store only basename in DB by convention
        $id = $stmtFindByPath->fetchColumn();

        if (!$id) {
            // Also try full filename (if DB stored with folders)
            $stmtFindByPath->execute([$filename]);
            $id = $stmtFindByPath->fetchColumn();
        }

        if ($id) {
            $id = (int) $id;

            if (isset($linkedDocIds[$id])) {
                $duplicates[] = [$base, $id, "PATH"];
                continue;
            }

            if (linkById($db, $id, $relativePath, $base)) {
                $linkedDocIds[$id] = true;
                $updatedDocs++;
                logMsg("✅ Vinculado por PATH: $base (doc_id=$id)", "success");
                continue;
            }
        }

        // ---------- MATCH STEP 2: numero exact ----------
        $stmtFindByNumero->execute([$numero]);
        $id = $stmtFindByNumero->fetchColumn();
        if ($id) {
            $id = (int) $id;

            if (isset($linkedDocIds[$id])) {
                $duplicates[] = [$base, $id, "NUMERO"];
                continue;
            }

            if (linkById($db, $id, $relativePath, $base)) {
                $linkedDocIds[$id] = true;
                $updatedDocs++;
                logMsg("✅ Vinculado por NUMERO: $numero (doc_id=$id)", "success");
                continue;
            }
        }

        // ---------- MATCH STEP 3: normalized "semantic" key ----------
        // This is the key fix to link PDFs that include timestamps/prefixes
        $kFile = normalizeKey($base);     // removes timestamp prefix, lower, etc.
        if ($kFile !== "" && isset($idx[$kFile])) {
            $id = (int) $idx[$kFile];

            if (isset($linkedDocIds[$id])) {
                $duplicates[] = [$base, $id, "NORM"];
                continue;
            }

            if (linkById($db, $id, $relativePath, $base)) {
                $linkedDocIds[$id] = true;
                $updatedDocs++;
                logMsg("✅ Vinculado por NORMALIZACIÓN: $base (doc_id=$id)", "success");
                continue;
            }
        }

        // ---------- UNMATCHED: auto-link/self-heal or auto-create ----------
        // Self-heal: try to find doc by normalized numero (removing timestamp prefix)
        $numeroNorm = normalizeKey($numero);
        if ($numeroNorm !== "" && isset($idx[$numeroNorm])) {
            $id = (int) $idx[$numeroNorm];

            if (!isset($linkedDocIds[$id])) {
                if (linkById($db, $id, $relativePath, $base)) {
                    $linkedDocIds[$id] = true;
                    $updatedDocs++;
                    logMsg("🔗 Auto-Vinculado (Self-Healing): $base (doc_id=$id)", "success");
                    continue;
                }
            } else {
                $duplicates[] = [$base, $id, "SELFHEAL"];
                continue;
            }
        }

        // Auto-create (ONLY if truly new)
        // Deduplicate inside the same run by normalized key, not by raw filename
        $createKey = $kFile !== "" ? $kFile : normalizeKey($numero);
        if ($createKey !== "" && isset($createdKeys[$createKey])) {
            // same doc name repeated in ZIP (different timestamps) -> treat as duplicate file
            $duplicates[] = [$base, null, "CREATE_DEDUP"];
            continue;
        }
        $createdKeys[$createKey] = true;

        $fecha = date('Y-m-d');
        try {
            $stmtCreate->execute([
                'generado_auto',
                pathinfo($base, PATHINFO_FILENAME), // keep full filename (no ext) as numero
                $fecha,
                'Importación Auto',
                'procesado',
                $relativePath,
                $base  // store basename WITH extension to keep uniqueness stable
            ]);
            $createdDocs++;
            logMsg("✨ Documento creado autom.: $base", "success");
        } catch (Exception $e) {
            // If UNIQUE(original_path) exists, this prevents fatal crashes.
            // We log and continue.
            logMsg("⚠️ No se pudo crear (posible duplicado): $base | " . $e->getMessage(), "warn");
            $unmatched[] = $base;
        }
    }

    $zip->close();

    logMsg("\n📊 RESUMEN ZIP", "info");
    logMsg("----------------------------------------", "info");
    logMsg("✅ Documentos vinculados/actualizados: $updatedDocs", "info");
    logMsg("✨ Documentos creados: $createdDocs", "info");
    logMsg("♻️ PDFs duplicados (mismo documento): " . count($duplicates), "info");
    logMsg("❓ PDFs sin procesar por error: " . count($unmatched), "info");

    if (!empty($duplicates)) {
        logMsg("\n♻️ LISTA DE DUPLICADOS (se saltaron para no crear copias):", "info");
        foreach ($duplicates as $d) {
            $file = $d[0];
            $id = $d[1] === null ? "N/A" : $d[1];
            $why = $d[2];
            logMsg(" - $file => doc_id=$id ($why)", "info");
        }
    }

    if (!empty($unmatched)) {
        logMsg("\n❗ ARCHIVOS CON ERROR (revisar nombres/DB):", "warn");
        foreach ($unmatched as $f)
            logMsg(" - $f", "warn");
    }
}
// --- CODIGO DEL USUARIO "TAL CUAL" (FIN) ---


// --- INTEGRACION PRINCIPAL ---
try {
    if (!isset($_SESSION['client_code'])) {
        throw new Exception('Sesión no iniciada.');
    }

    $clientCode = $_SESSION['client_code'];
    $db = open_client_db($clientCode);

    // 0. Reset Logic (Standard)
    if (isset($_POST['action']) && $_POST['action'] === 'reset') {
        $dbPath = client_db_path($clientCode);
        $db = null;
        gc_collect_cycles();
        if (file_exists($dbPath))
            @unlink($dbPath); // Suppress warning if file blocked
        $db = open_client_db($clientCode); // Recreate
        // Definir esquema básico
        $db->exec("CREATE TABLE IF NOT EXISTS documentos (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT, numero TEXT, fecha DATE, fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP, proveedor TEXT, naviera TEXT, peso_kg REAL, valor_usd REAL, ruta_archivo TEXT NOT NULL, original_path TEXT, hash_archivo TEXT, datos_extraidos TEXT, ai_confianza REAL, requiere_revision INTEGER DEFAULT 0, estado TEXT DEFAULT 'pendiente', notas TEXT);");
        $db->exec("CREATE TABLE IF NOT EXISTS codigos (id INTEGER PRIMARY KEY AUTOINCREMENT, documento_id INTEGER NOT NULL, codigo TEXT NOT NULL, descripcion TEXT, cantidad INTEGER, valor_unitario REAL, validado INTEGER DEFAULT 0, alerta TEXT, FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE);");
        $db->exec("CREATE TABLE IF NOT EXISTS vinculos (id INTEGER PRIMARY KEY AUTOINCREMENT, documento_origen_id INTEGER NOT NULL, documento_destino_id INTEGER NOT NULL, tipo_vinculo TEXT NOT NULL, codigos_coinciden INTEGER DEFAULT 0, codigos_faltan INTEGER DEFAULT 0, codigos_extra INTEGER DEFAULT 0, discrepancias TEXT, FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE, FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE);");

        ob_clean(); // Ensure no previous output
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'logs' => [['msg' => "Hard Reset Realizado + Estructura Regenerada", 'type' => 'success']]]);
        ob_end_flush();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método inválido.');
    }

    // 1. Asegurar Columna y Poblar (Pre-requisito)
    try {
        $cols = $db->query("PRAGMA table_info(documentos)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('original_path', $cols)) {
            $db->exec("ALTER TABLE documentos ADD COLUMN original_path TEXT");
        }
    } catch (Exception $e) {
    }


    // 2. Procesar SQL (Se mantiene para poblar DB)
    if (isset($_FILES['sql_file']) && pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION) === 'sql') {
        $sqlFile = $_FILES['sql_file'];
        logMsg("Procesando SQL: " . $sqlFile['name']);

        $sqlData = parse_sql_inserts($sqlFile['tmp_name']); // Helper existente
        $tables = $sqlData['tables'];

        $db->beginTransaction();

        // Mapa de IDs: [OLD_ID => NEW_ID]
        $idMap = [];
        // Mapa de Números: [NORMALIZED_NUMERO => NEW_ID] (Nuevo Fallback)
        $numeroMap = [];

        // 1. IMPORTAR DOCUMENTOS
        // Buscamos la tabla con varios nombres posibles
        $docTableName = null;
        if (isset($tables['documentos']))
            $docTableName = 'documentos';
        elseif (isset($tables['documents']))
            $docTableName = 'documents';

        if ($docTableName) {
            $data = $tables[$docTableName];
            // Preparar statement genérico
            $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, naviera, peso_kg, valor_usd, ruta_archivo, original_path, estado) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            logMsg("Importando documentos desde '$docTableName'...", "info");

            // --- DIAGNOSTICO: Loguear columnas encontradas en el primer row ---
            if (!empty($data['rows'])) {
                $firstRowKeys = array_keys($data['rows'][0]);
                logMsg("🔎 Columnas documentos (RAW): " . implode(", ", $firstRowKeys), "info");
            }

            foreach ($data['rows'] as $row) {
                // Normalizar keys del row a minúsculas
                $rowLower = array_change_key_case($row, CASE_LOWER);

                // --- MAPEO ROBUSTO DE ID ---
                $oldId = $rowLower['id'] ?? $rowLower['_id'] ?? $rowLower['uid'] ?? null;

                // --- MAPEO DE COLUMNAS IMPLICITAS ---
                $tipo = $rowLower['tipo'] ?? 'importado';
                $numero = $rowLower['numero'] ?? $rowLower['number'] ?? $rowLower['name'] ?? 'S/N';
                $fecha = $rowLower['fecha'] ?? $rowLower['date'] ?? date('Y-m-d');
                $proveedor = $rowLower['proveedor'] ?? $rowLower['provider'] ?? '';
                $naviera = $rowLower['naviera'] ?? '';
                $peso = $rowLower['peso_kg'] ?? 0;
                $valor = $rowLower['valor_usd'] ?? 0;

                // --- MAPEO AGRESIVO DE ORIGINAL PATH ---
                $origPath = null;
                $possiblePathKeys = ['original_path', 'ruta_archivo', 'ruta', 'path', 'file_path', 'file', 'archivo', 'filename', 'nombre_archivo', 'url', 'uri', 'src'];

                foreach ($possiblePathKeys as $key) {
                    if (!empty($rowLower[$key])) {
                        $origPath = $rowLower[$key];
                        break;
                    }
                }

                $ruta = 'pending';
                $estado = $rowLower['estado'] ?? 'pendiente';

                try {
                    $stmtDoc->execute([$tipo, $numero, $fecha, $proveedor, $naviera, $peso, $valor, $ruta, $origPath, $estado]);
                    $newId = $db->lastInsertId();

                    if ($oldId) {
                        $idMap[$oldId] = $newId;
                    }
                    // Guardar mapa por numero tambien (normalizado)
                    $normNum = normalizeKey($numero);
                    if ($normNum !== "") {
                        $numeroMap[$normNum] = $newId;
                    }

                    // --- NUEVO: Extraer códigos de codigos_extraidos (IMPORTACION INCRUSTADA) ---
                    $rawCodes = $rowLower['codigos_extraidos'] ?? $rowLower['codigos'] ?? null;
                    if (!empty($rawCodes)) {
                        $codeList = [];

                        // 1. Intentar decode JSON
                        $decoded = json_decode($rawCodes, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $codeList = $decoded;
                        } else {
                            // 2. Intentar separar por comas, pipes o espacios
                            $codeList = preg_split('/[;,|]/', $rawCodes);
                        }

                        // DIAGNOSTICO: Loguear qué encontramos
                        if (count($codeList) > 0) {
                            logMsg("🛠️ Extrayendo " . count($codeList) . " códigos para doc '$numero' (Raw len: " . strlen($rawCodes) . ")", "info");
                        }

                        // Preparar insert de códigos (si no se preparó antes)
                        if (!isset($stmtCode)) {
                            $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo, descripcion, cantidad, valor_unitario, validado, alerta) 
                                                       VALUES (?, ?, ?, 0, 0, 0, NULL)");
                        }

                        foreach ($codeList as $codeStr) {
                            $codeStr = trim($codeStr);
                            // Limpiar comillas extras si quedaron
                            $codeStr = trim($codeStr, '"\'');

                            if ($codeStr === '' || strlen($codeStr) < 2)
                                continue; // Skip empty or very short junk

                            try {
                                $stmtCode->execute([$newId, $codeStr, 'Importado de Columna', 0, 0, 0, NULL]);
                            } catch (Exception $e) { /* Ignore duplicates */
                            }
                        }
                    }

                } catch (Exception $e) {
                    logMsg("Error importando doc '$numero': " . $e->getMessage(), "warning");
                }
            }
            logMsg("Documentos importados. Mapeados " . count($idMap) . " IDs y " . count($numeroMap) . " Números.", "success");
        }

        // 2. IMPORTAR CÓDIGOS
        if (isset($tables['codigos'])) {
            $data = $tables['codigos'];
            $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo, descripcion, cantidad, valor_unitario, validado, alerta) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?)");

            $codesCount = 0;

            // --- DIAGNOSTICO ---
            if (!empty($data['rows'])) {
                $firstCodeKeys = array_keys($data['rows'][0]);
                logMsg("🔎 Columnas codigos (RAW): " . implode(", ", $firstCodeKeys), "info");
            }

            foreach ($data['rows'] as $row) {
                $rowLower = array_change_key_case($row, CASE_LOWER);

                // --- ESTRATEGIA 1: ID ---
                $oldDocId = null;
                $possibleFkKeys = ['documento_id', 'document_id', 'doc_id', 'id_documento', 'id_doc', 'ref_id', 'parent_id', 'documento', 'doc'];
                foreach ($possibleFkKeys as $fkKey) {
                    if (isset($rowLower[$fkKey])) {
                        $oldDocId = $rowLower[$fkKey];
                        break;
                    }
                }

                $finalDocId = null;

                // Intento 1: Por ID exacto
                if ($oldDocId && isset($idMap[$oldDocId])) {
                    $finalDocId = $idMap[$oldDocId];
                }

                // Intento 2: Por Número de Documento (Fallback)
                if (!$finalDocId) {
                    $possibleNumKeys = ['documento_numero', 'numero_documento', 'numero', 'doc_number', 'parent_number'];
                    foreach ($possibleNumKeys as $numKey) {
                        if (!empty($rowLower[$numKey])) {
                            $rawNum = $rowLower[$numKey];
                            $normRaw = normalizeKey($rawNum);
                            if (isset($numeroMap[$normRaw])) {
                                $finalDocId = $numeroMap[$normRaw];
                                break;
                            }
                        }
                    }
                }

                // Solo insertar si encontramos padre
                if ($finalDocId) {
                    try {
                        $stmtCode->execute([
                            $finalDocId,
                            $rowLower['codigo'] ?? $rowLower['code'] ?? 'UNKNOWN',
                            $rowLower['descripcion'] ?? $rowLower['description'] ?? $rowLower['desc'] ?? '',
                            $rowLower['cantidad'] ?? $rowLower['quantity'] ?? $rowLower['qty'] ?? 0,
                            $rowLower['valor_unitario'] ?? $rowLower['valor'] ?? $rowLower['price'] ?? 0,
                            $rowLower['validado'] ?? 0,
                            $rowLower['alerta'] ?? null
                        ]);
                        $codesCount++;
                    } catch (Exception $e) {
                    }
                }
            }
            logMsg("Códigos importados: $codesCount", "success");
        }

        // 3. IMPORTAR VÍNCULOS (Usando mapa de IDs y Mapeo Flexible)
        if (isset($tables['vinculos'])) {
            $data = $tables['vinculos'];
            $stmtLink = $db->prepare("INSERT INTO vinculos (documento_origen_id, documento_destino_id, tipo_vinculo, codigos_coinciden, discrepancias) 
                                      VALUES (?, ?, ?, ?, ?)");

            $linksCount = 0;
            foreach ($data['rows'] as $row) {
                $rowLower = array_change_key_case($row, CASE_LOWER);

                // --- MAPEO ROBUSTO DE FKs VINCULOS ---
                $oldOriginId = $rowLower['documento_origen_id'] ?? $rowLower['origen_id'] ?? $rowLower['source_id'] ?? null;
                $oldDestId = $rowLower['documento_destino_id'] ?? $rowLower['destino_id'] ?? $rowLower['target_id'] ?? null;

                if ($oldOriginId && isset($idMap[$oldOriginId]) && $oldDestId && isset($idMap[$oldDestId])) {
                    try {
                        $stmtLink->execute([
                            $idMap[$oldOriginId],
                            $idMap[$oldDestId],
                            $rowLower['tipo_vinculo'] ?? 'manual',
                            $rowLower['codigos_coinciden'] ?? 0,
                            $rowLower['discrepancias'] ?? null
                        ]);
                        $linksCount++;
                    } catch (Exception $e) {
                    }
                }
            }
            logMsg("Vínculos restaurados: $linksCount", "success");
        }

        $db->commit();
    }

    // 3. Procesar ZIP usando FUNCION DEL USUARIO
    if (isset($_FILES['zip_file'])) {
        $zipTmpPath = $_FILES['zip_file']['tmp_name'];
        $uploadDir = CLIENTS_DIR . "/{$clientCode}/uploads/sql_import/";

        // LLAMADA A LA FUNCIÓN DEL USUARIO
        processZipAndLink($db, $zipTmpPath, $uploadDir, 'sql_import');
    }

    $response['success'] = true;

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    $response['error'] = $e->getMessage();
    logMsg($e->getMessage(), 'error');
}

ob_clean();
echo json_encode($response);
ob_end_flush();
