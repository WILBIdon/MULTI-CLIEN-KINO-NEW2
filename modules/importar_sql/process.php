<?php
ob_start(); // Iniciar buffer para capturar salidas no deseadas
/**
 * Backend para Importación Avanzada
 */
header('Content-Type: application/json');
session_start();
set_time_limit(300); // 5 minutos máx
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/import_engine.php';

$response = [
    'success' => false,
    'logs' => [],
    'error' => null
];

function logMsg($msg, $type = 'info')
{
    global $response;
    $response['logs'][] = ['msg' => $msg, 'type' => $type];
}

try {
    if (!isset($_SESSION['client_code'])) {
        throw new Exception('Sesión no iniciada.');
    }

    $clientCode = $_SESSION['client_code'];
    $db = open_client_db($clientCode);

    // 0. Reset Logic
    if (isset($_POST['action']) && $_POST['action'] === 'reset') {
        try {
            $dbPath = client_db_path($clientCode);

            // 2. Cerrar conexión actual (importante para poder borrar archivo en Windows)
            $db = null;
            gc_collect_cycles();

            // 3. Borrar archivo físico (HARD RESET)
            if (file_exists($dbPath)) {
                if (!unlink($dbPath)) {
                    // Si falla unlink (candado), intentamos DELETE masivo como fallback
                    $db = open_client_db($clientCode);
                    $db->exec("DELETE FROM vinculos");
                    $db->exec("DELETE FROM codigos");
                    $db->exec("DELETE FROM documentos");
                    $msg = "⚠️ HARD RESET PARCIAL (Archivo bloqueado, se usó DELETE):\n- Tablas vaciadas.";
                } else {
                    $msg = "⚠️ HARD RESET COMPLETADO:\n- Archivo DB ($dbPath) eliminado.";
                }
            } else {
                $msg = "⚠️ DB No existía, se creará nueva.";
            }

            // 4. Recrear estructura limpia
            $db = open_client_db($clientCode);

            $db->exec(
                "CREATE TABLE IF NOT EXISTS documentos (\n"
                . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                . "    tipo TEXT NOT NULL,\n"
                . "    numero TEXT NOT NULL,\n"
                . "    fecha DATE NOT NULL,\n"
                . "    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,\n"
                . "    proveedor TEXT,\n"
                . "    naviera TEXT,\n"
                . "    peso_kg REAL,\n"
                . "    valor_usd REAL,\n"
                . "    ruta_archivo TEXT NOT NULL,\n"
                . "    original_path TEXT,\n"
                . "    hash_archivo TEXT,\n"
                . "    datos_extraidos TEXT,\n"
                . "    ai_confianza REAL,\n"
                . "    requiere_revision INTEGER DEFAULT 0,\n"
                . "    estado TEXT DEFAULT 'pendiente',\n"
                . "    notas TEXT\n"
                . ");\n"
                . "CREATE TABLE IF NOT EXISTS codigos (\n"
                . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                . "    documento_id INTEGER NOT NULL,\n"
                . "    codigo TEXT NOT NULL,\n"
                . "    descripcion TEXT,\n"
                . "    cantidad INTEGER,\n"
                . "    valor_unitario REAL,\n"
                . "    validado INTEGER DEFAULT 0,\n"
                . "    alerta TEXT,\n"
                . "    FOREIGN KEY(documento_id) REFERENCES documentos(id) ON DELETE CASCADE\n"
                . ");\n"
                . "CREATE TABLE IF NOT EXISTS vinculos (\n"
                . "    id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                . "    documento_origen_id INTEGER NOT NULL,\n"
                . "    documento_destino_id INTEGER NOT NULL,\n"
                . "    tipo_vinculo TEXT NOT NULL,\n"
                . "    codigos_coinciden INTEGER DEFAULT 0,\n"
                . "    codigos_faltan INTEGER DEFAULT 0,\n"
                . "    codigos_extra INTEGER DEFAULT 0,\n"
                . "    discrepancias TEXT,\n"
                . "    FOREIGN KEY(documento_origen_id) REFERENCES documentos(id) ON DELETE CASCADE,\n"
                . "    FOREIGN KEY(documento_destino_id) REFERENCES documentos(id) ON DELETE CASCADE\n"
                . ");"
            );

            ob_clean();
            echo json_encode(['success' => true, 'logs' => [['msg' => $msg . "\n- Estructura regenerada.", 'type' => 'success']]]);
            exit;
        } catch (Exception $e) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Error fatal en Hard Reset: ' . $e->getMessage()]);
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'debug_info') {
        $path = client_db_path($clientCode);
        $exists = file_exists($path) ? 'SI' : 'NO';
        $realPath = realpath($path);

        $docCount = $db->query("SELECT COUNT(*) FROM documentos")->fetchColumn();

        $info = "--- DIAGNÓSTICO ---\n";
        $info .= "Cliente: " . $clientCode . "\n";
        $info .= "DB Path Config: " . $path . "\n";
        $info .= "DB Existe: " . $exists . "\n";
        $info .= "DB RealPath: " . $realPath . "\n";
        $info .= "Docs en DB: " . $docCount . "\n";

        ob_clean();
        echo json_encode(['success' => true, 'logs' => [['msg' => $info, 'type' => 'info']]]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método inválido.');
    }

    // --- MIGRACIÓN AUTOMÁTICA DE ESQUEMA ---
    // Asegurar que la columna 'original_path' exista antes de insertar
    try {
        $cols = $db->query("PRAGMA table_info(documentos)")->fetchAll(PDO::FETCH_ASSOC);
        $hasOriginalPath = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'original_path') {
                $hasOriginalPath = true;
                break;
            }
        }
        if (!$hasOriginalPath) {
            $db->exec("ALTER TABLE documentos ADD COLUMN original_path TEXT");
            logMsg("🔧 Esquema actualizado: Se agregó columna 'original_path'.");
        }
    } catch (Exception $ex) {
        // Ignorar si falla, quizás la tabla no existe aún (se creará luego si es importación nueva)
    }

    // 1. Validar Archivos
    if (!isset($_FILES['sql_file']) || !isset($_FILES['zip_file'])) {
        throw new Exception('Faltan archivos SQL o ZIP.');
    }

    $sqlFile = $_FILES['sql_file'];
    $zipFile = $_FILES['zip_file'];

    if (pathinfo($sqlFile['name'], PATHINFO_EXTENSION) !== 'sql') {
        throw new Exception('El archivo 1 debe ser .sql');
    }
    if (pathinfo($zipFile['name'], PATHINFO_EXTENSION) !== 'zip') {
        throw new Exception('El archivo 2 debe ser .zip');
    }

    // 2. Procesar SQL
    logMsg("Analizando archivo SQL: {$sqlFile['name']}...");

    // Usamos el engine existente pero necesitamos ver qué tablas hay
    $sqlData = parse_sql_inserts($sqlFile['tmp_name']);
    $tables = $sqlData['tables'];

    if (empty($tables)) {
        throw new Exception('No se encontraron datos INSERT en el SQL.');
    }

    logMsg("Tablas encontradas: " . implode(', ', array_keys($tables)));

    // Identificar Tablas (Mapeo Inteligente)
    $tblDocs = null;
    $tblCodes = null;

    foreach ($tables as $name => $data) {
        // Detectar Documentos
        if (in_array('numero', $data['columns']) || strpos($name, 'doc') !== false) {
            $tblDocs = $name;
        }
        // Detectar Códigos
        if (in_array('code', $data['columns']) || in_array('codigo', $data['columns'])) {
            $tblCodes = $name;
        }
    }

    if (!$tblCodes) {
        // Fallback
        if (count($tables) === 1 && isset($tables['codes']))
            $tblCodes = 'codes';
    }

    $idMap = [];

    $db->beginTransaction();

    // -- IMPORTAR DOCUMENTOS --
    if ($tblDocs) {
        logMsg("Importando documentos desde tabla '$tblDocs'...");
        $docRows = $tables[$tblDocs]['rows'];
        $cols = $tables[$tblDocs]['columns'];

        $colMap = [
            'numero' => ['numero', 'number', 'doc_assigned_name', 'name'],
            'fecha' => ['fecha', 'date', 'created_at'],
            'tipo' => ['tipo', 'type'],
            'proveedor' => ['proveedor', 'supplier', 'vendor'],
            'path' => ['path', 'ruta', 'file_path', 'url']
        ];

        // Helper para encontrar valor
        $findVal = function ($row, $candidates, $cols) {
            foreach ($candidates as $cand) {
                if (isset($row[$cand]))
                    return $row[$cand];
            }
            return null;
        };

        $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, estado, ruta_archivo, original_path) VALUES (?, ?, ?, ?, 'pendiente', 'pending', ?)");

        foreach ($docRows as $row) {
            $numero = $row['numero'] ?? $row['number'] ?? $row['name'] ?? null;
            $fecha = $row['fecha'] ?? $row['date'] ?? date('Y-m-d');
            $tipo = $row['tipo'] ?? 'importado';
            $proveedor = $row['proveedor'] ?? '';

            // Buscar path original
            $originalPath = null;
            foreach ($colMap['path'] as $pCol) {
                if (isset($row[$pCol])) {
                    $originalPath = $row[$pCol];
                    break;
                }
            }

            $oldId = $row['id'] ?? null;

            if ($numero) {
                $stmtDoc->execute([$tipo, $numero, $fecha, $proveedor, $originalPath]);
                $newId = $db->lastInsertId();
                if ($oldId)
                    $idMap[$oldId] = $newId;
            }
        }
        logMsg("Se importaron " . count($docRows) . " documentos.");
    } else {
        logMsg("⚠️ No se detectó tabla de documentos en el SQL. Se intentará inferir si es necesario.", "warning");
    }

    // -- IMPORTAR CÓDIGOS --
    if ($tblCodes) {
        logMsg("Importando códigos desde tabla '$tblCodes'...");
        $codeRows = $tables[$tblCodes]['rows'];

        $stmtCode = $db->prepare("INSERT INTO codigos (documento_id, codigo, descripcion) VALUES (?, ?, ?)");

        $importedCodes = 0;
        foreach ($codeRows as $row) {
            $oldDocId = $row['document_id'] ?? null;
            $codigo = $row['code'] ?? $row['codigo'] ?? null;
            $desc = $row['description'] ?? $row['descripcion'] ?? '';

            if ($oldDocId && isset($idMap[$oldDocId])) {
                $targetDocId = $idMap[$oldDocId];
                if ($codigo) {
                    $stmtCode->execute([$targetDocId, $codigo, $desc]);
                    $importedCodes++;
                }
            }
        }
        logMsg("Se importaron $importedCodes códigos.");
    }

    $db->commit();

    // 3. Procesar ZIP
    logMsg("Procesando archivo ZIP...");
    $zip = new ZipArchive();
    if ($zip->open($zipFile['tmp_name']) === TRUE) {

        $uploadDir = CLIENTS_DIR . "/{$clientCode}/uploads/sql_import/";
        if (!file_exists($uploadDir))
            mkdir($uploadDir, 0777, true);

        $updatedDocs = 0;
        $unlinkedFiles = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'pdf')
                continue;

            // Extraer
            $targetPath = $uploadDir . basename($filename);
            copy("zip://" . $zipFile['tmp_name'] . "#" . $filename, $targetPath);
            $relativePath = 'sql_import/' . basename($filename);

            $basename = pathinfo($filename, PATHINFO_FILENAME);

            // 0. ESTRATEGIA SUPREMA: Match por 'original_path'
            $stmtPath = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE original_path = ? OR original_path LIKE ?");
            $stmtPath->execute([$relativePath, $filename, "%/$filename"]);

            if ($stmtPath->rowCount() > 0) {
                $updatedDocs++;
                logMsg("✅ Vinculado por PATH EXACTO: $filename", "success");
                continue;
            }

            // 1. Intento por nombre (Exacto)
            $stmtExact = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?)) AND ruta_archivo = 'pending'");
            $stmtExact->execute([$relativePath, $basename]);

            if ($stmtExact->rowCount() > 0) {
                $updatedDocs++;
                logMsg("✅ Vinculado (Exacto): $basename", "success");
                continue;
            }

            // Si falla, intentamos match por prefijo con separadores comunes
            // SQLite permite concatenación con ||
            // Buscamos documentos cuyo numero sea el inicio del nombre del archivo seguido de un separador
            $separators = ['_', '-', ' '];
            $linked = false;

            foreach ($separators as $sep) {
                // UPDATE documentos SET ruta_archivo = ... WHERE 'filename' LIKE numero || '_' || '%'
                $stmtPrefix = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE ? LIKE numero || ? || '%'");
                $stmtPrefix->execute([$relativePath, $basename, $sep]);

                if ($stmtPrefix->rowCount() > 0) {
                    $updatedDocs++;
                    logMsg("✅ Vinculado (Sufijo '$sep'): $basename", "success");
                    $linked = true;
                    break;
                }
            }

            if (!$linked) {
                // 3. ESTRATEGIA MAESTRA: Tokenización (Búsqueda Profunda)
                // El archivo puede tener el código en cualquier parte.
                // Ej: "1763056495_JUEGO TAILOR 032025001910624-3.pdf"
                // Tokens: ["1763056495", "JUEGO", "TAILOR", "032025001910624-3"]

                // Usamos DOS estrategias de tokenización combinadas:

                // Estrategia A: Conservar guiones (para códigos como "032025-3")
                // Solo separamos por espacios o guiones bajos
                $tokensA = preg_split('/[\s_]+/', $basename);

                // Estrategia B: Separar todo (para casos tipo "Factura-123")
                // Separamos por espacios, guiones bajos, guiones medios y puntos
                $tokensB = preg_split('/[\s_\-.]+/', $basename);

                // Combinar y limpiar
                $tokens = array_merge($tokensA ? $tokensA : [], $tokensB ? $tokensB : []);
                $tokens = array_unique($tokens);

                // Filtramos tokens muy cortos
                $tokens = array_filter($tokens, function ($t) {
                    return strlen($t) >= 4;
                }); // >= 4 permite años "2025" o codigos cortos "1611"

                foreach ($tokens as $token) {
                    $token = trim($token);

                    // FILTRO DE SEGURIDAD PARA EVITAR FALSOS POSITIVOS
                    // 1. Longitud mínima: 4 caracteres (evita "2025", "OCT", "123")
                    if (strlen($token) < 4)
                        continue;

                    // 2. [REMOVIDO] El usuario indicó que pueden haber códigos solo letras (aunque raros).
                    // Confiamos en que la coincidencia en DB sea suficiente filtro.
                    // if (!preg_match('/[0-9]/', $token)) continue; 

                    // A) ¿Es un numero de documento?
                    $stmtDoc = $db->prepare("SELECT id FROM documentos WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?)) LIMIT 1");
                    $stmtDoc->execute([$token]);
                    $docId = $stmtDoc->fetchColumn();

                    if (!$docId) {
                        // B) ¿Es un código?
                        $stmtCode = $db->prepare("SELECT documento_id FROM codigos WHERE TRIM(LOWER(codigo)) = TRIM(LOWER(?)) LIMIT 1");
                        $stmtCode->execute([$token]);
                        $docId = $stmtCode->fetchColumn();
                    }

                    if ($docId) {
                        // ¡Encontrado!
                        $stmtLink = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE id = ?");
                        $stmtLink->execute([$relativePath, $docId]);

                        if ($stmtLink->rowCount() > 0) {
                            $updatedDocs++;
                            logMsg("✅ Vinculado por TOKEN ($token): $basename", "success");
                            $linked = true;
                            break; // Dejar de buscar en este archivo
                        }
                    }
                }
            }

            if (!$linked) {
                // logMsg("⚠️ No encontrado en DB (Busqué tokens en: $basename)", "warning");
                $unlinkedFiles[] = $basename;
            }
        }
        $zip->close();

        // --- 4. AUTO-CREACIÓN DE DOCUMENTOS FALTANTES (NUEVO) ---
        // Si quedaron archivos sin vincular, los creamos como nuevos documentos.
        $createdDocs = 0;
        if (!empty($unlinkedFiles)) {
            $stmtCreate = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, estado, ruta_archivo, original_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
            // Prepare check statement
            $stmtCheck = $db->prepare("SELECT id FROM documentos WHERE original_path = ? LIMIT 1");

            foreach ($unlinkedFiles as $fileBaseName) {
                // Check if already exists (Prevent Duplicates)
                $stmtCheck->execute([$fileBaseName]);
                if ($stmtCheck->fetchColumn()) {
                    // Already exists, skip creation
                    continue;
                }

                // Derivar datos básicos del nombre del archivo
                // Ejemplo: "Factura-123.pdf" -> Numero: "Factura-123"
                $numero = pathinfo($fileBaseName, PATHINFO_FILENAME);
                $fecha = date('Y-m-d');
                $relativePath = 'sql_import/' . $fileBaseName;

                try {
                    $stmtCreate->execute(['generado_auto', $numero, $fecha, 'Importación Auto', 'procesado', $relativePath, $fileBaseName]);
                    $createdDocs++;
                    logMsg("✨ Documento creado autom.: $numero", "success");
                } catch (Exception $e) {
                    logMsg("❌ Error al crear documento auto ($numero): " . $e->getMessage(), "error");
                }
            }
            // Limpiamos la lista de 'unlinked' porque ya fueron tratados (ahora son 'created')
            $unlinkedFiles = [];
        }

        // --- GENERAR RESUMEN INTELIGENTE ---

        $summaryHtml = "<br><strong>📊 RESUMEN FINAL DE IMPORTACIÓN</strong><br>" . str_repeat("-", 40) . "<br>";

        if ($createdDocs > 0) {
            $summaryHtml .= "<br><span style='color: #60a5fa'>✨ <strong>$createdDocs Nuevos Documentos Creados</strong> (Estaban en ZIP pero no en SQL).</span><br>";
        }

        // 1. Archivos PDF no vinculados (Estaban en el ZIP pero no en la DB)
        if (!empty($unlinkedFiles)) {
            $unlinkedFiles = array_unique($unlinkedFiles);
            $count = count($unlinkedFiles);
            $summaryHtml .= "<br><span style='color: #fbbf24'>⚠️ <strong>$count Archivos PDF sin vincular</strong> (No existen en DB):</span><br>";
            $summaryHtml .= "<div style='font-size: 0.85em; color: #ccc; margin-left: 10px; max-height: 150px; overflow-y: auto;'>";
            $summaryHtml .= implode("<br>", $unlinkedFiles);
            $summaryHtml .= "</div>";
        } else {
            $summaryHtml .= "<br><span style='color: #34d399'>✅ Todos los archivos PDF del ZIP fueron vinculados.</span><br>";
        }

        // 2. Documentos Huérfanos en DB (Estaban en SQL pero no llegó su PDF)
        // Buscamos docs que sigan en estado 'pendiente' o con ruta 'importado'/'pending'
        $stmtOrphans = $db->query("
            SELECT d.numero, COUNT(c.id) as total_codes, GROUP_CONCAT(c.codigo) as codes_list
            FROM documentos d
            LEFT JOIN codigos c ON d.id = c.documento_id
            WHERE d.ruta_archivo IN ('pending', 'importado')
            GROUP BY d.id
        ");
        $orphanedDocs = $stmtOrphans->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($orphanedDocs)) {
            $countOrphans = count($orphanedDocs);
            $summaryHtml .= "<br><span style='color: #f87171'>❌ <strong>$countOrphans Documentos en DB sin PDF</strong> (Huérfanos):</span><br>";
            $summaryHtml .= "<div style='font-size: 0.85em; color: #ccc; margin-left: 10px; max-height: 200px; overflow-y: auto;'>";
            $summaryHtml .= "<table style='width:100%; text-align:left; border-collapse:collapse;'>";
            $summaryHtml .= "<tr><th style='border-bottom:1px solid #444'>Documento</th><th style='border-bottom:1px solid #444'>Códigos</th></tr>";

            foreach ($orphanedDocs as $row) {
                $codeList = $row['codes_list'] ? substr($row['codes_list'], 0, 50) . (strlen($row['codes_list']) > 50 ? '...' : '') : 'Sin códigos';
                $summaryHtml .= "<tr>";
                $summaryHtml .= "<td style='padding:2px 5px; color: #f87171'>" . htmlspecialchars($row['numero']) . "</td>";
                $summaryHtml .= "<td style='padding:2px 5px; color: #999'>" . htmlspecialchars($codeList) . " (" . $row['total_codes'] . ")</td>";
                $summaryHtml .= "</tr>";
            }
            $summaryHtml .= "</table></div>";
        } else {
            $summaryHtml .= "<br><span style='color: #34d399'>✅ No quedaron documentos huérfanos en la DB.</span><br>";
        }

        $summaryHtml .= "<br><i style='color:#888'>Fin del reporte.</i>";

        logMsg($summaryHtml);
        logMsg("Se procesaron " . ($updatedDocs + count($unlinkedFiles)) . " archivos en total.", "success");

    } else {
        throw new Exception("No se pudo abrir el ZIP.");
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
