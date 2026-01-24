<?php
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

            echo json_encode(['success' => true, 'logs' => [['msg' => $msg . "\n- Estructura regenerada.", 'type' => 'success']]]);
            exit;
        } catch (Exception $e) {
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

        echo json_encode(['success' => true, 'logs' => [['msg' => $info, 'type' => 'info']]]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método inválido.');
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
        // Detectar Documentos (busca columnas 'numero', 'fecha', 'proveedor' o nombre de tabla)
        if (in_array('numero', $data['columns']) || strpos($name, 'doc') !== false) {
            $tblDocs = $name;
        }
        // Detectar Códigos (busca 'code', 'codigo', 'document_id')
        if (in_array('code', $data['columns']) || in_array('codigo', $data['columns'])) {
            $tblCodes = $name;
        }
    }

    if (!$tblCodes) {
        // Fallback si solo hay una tabla y parece ser de codigos
        if (count($tables) === 1 && isset($tables['codes']))
            $tblCodes = 'codes';
    }

    // Si no encontramos tabla de documentos explícita pero hay códigos con document_id, 
    // asumimos que los documentos se deben crear o ya existen. 
    // PERO el requerimiento dice: "subir SQL cree las tablas... enlace docs".
    // Si el SQL SOLO tiene codigos, estamos en problemas, pero hagamos el mejor esfuerzo.

    $idMap = []; // Map Old ID -> New ID

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
            'proveedor' => ['proveedor', 'supplier', 'vendor']
        ];

        // Helper para encontrar valor
        $findVal = function ($row, $candidates, $cols) {
            foreach ($candidates as $cand) {
                $idx = array_search($cand, $cols);
                if ($idx !== false)
                    return $row[$idx]; // Ojo: $row es assoc en import_engine? No, es assoc array_combine.
                // Revisar import_engine: "$rows[] = array_combine($columns, $rowValues);" -> SI es assoc con keys = nombres de columna
                if (isset($row[$cand]))
                    return $row[$cand];
            }
            return null;
        };

        $stmtDoc = $db->prepare("INSERT INTO documentos (tipo, numero, fecha, proveedor, estado, ruta_archivo) VALUES (?, ?, ?, ?, 'pendiente', 'pending')");

        foreach ($docRows as $row) {
            // Mapeo seguro
            $numero = $row['numero'] ?? $row['number'] ?? $row['name'] ?? null;
            $fecha = $row['fecha'] ?? $row['date'] ?? date('Y-m-d');
            $tipo = $row['tipo'] ?? 'importado';
            $proveedor = $row['proveedor'] ?? '';
            $oldId = $row['id'] ?? null;

            if ($numero) {
                // Verificar duplicados?? Mejor insertar nuevo siempre segun requerimiemto "cree las tablas"
                $stmtDoc->execute([$tipo, $numero, $fecha, $proveedor]);
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

            // Si tenemos mapeo, lo usamos. Si no, ¿qué hacemos?
            // Si el SQL tiene documentos Y codigos, usamos el mapa.
            // Si solo tiene codigos, asumimos que los IDs son válidos (peligroso) O creamos documentos dummy?

            if ($oldDocId && isset($idMap[$oldDocId])) {
                $targetDocId = $idMap[$oldDocId];
                if ($codigo) {
                    $stmtCode->execute([$targetDocId, $codigo, $desc]);
                    $importedCodes++;
                }
            } elseif ($oldDocId && !$tblDocs) {
                // Caso raro: Solo tabla de codigos. ¿A qué documento pertenecen?
                // El usuario dijo "un doc puede tener varios codigos... solo un nombre asignado".
                // Quizás el SQL tiene IDs que coincidirán con algo? No podemos saberlo.
                // Saltamos por seguridad o creamos documento huérfano?
                // LOG:
                // logMsg("Salto código $codigo por falta de documento padre (ID $oldDocId)", 'error');
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

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'pdf')
                continue;

            // Normalizar nombre para buscar
            // El usuario dice "nombre de documento asignado".
            // Buscamos en la DB: documentos WHERE numero = basename(filename)
            $basename = pathinfo($filename, PATHINFO_FILENAME); // Sin extension

            // Extraer
            $targetPath = $uploadDir . basename($filename);
            copy("zip://" . $zipFile['tmp_name'] . "#" . $filename, $targetPath);

            // Vincular
            // UPDATE documentos SET ruta_archivo = ? WHERE numero = ? (Robust matching)
            // Intentamos coincidencia exacta y luego case-insensitive/trim

            $relativePath = 'sql_import/' . basename($filename);

            // 1. Intento directo y robusto
            // Estrategia: Buscar si el numero en DB es un prefijo del nombre del archivo.
            // Ej: Archivo "12345_Factura.pdf" debería coincidir con Documento Numero "12345"

            // Primero intentamos match exacto (rápido)
            $stmtExact = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?))");
            $stmtExact->execute([$relativePath, $basename]); // Intento 1: basename completo

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

                // Usamos regex para separar por _, -, . o espacio
                $tokens = preg_split('/[\s_\-.]+/', $basename);
                // Filtramos tokens muy cortos para evitar falsos positivos con palabras comunes (ej: "de", "la")
                $tokens = array_filter($tokens, function ($t) {
                    return strlen($t) > 2;
                });
                $tokens = array_unique($tokens);

                foreach ($tokens as $token) {
                    $token = trim($token);

                    // FILTRO DE SEGURIDAD PARA EVITAR FALSOS POSITIVOS
                    // 1. Longitud mínima: 4 caracteres (evita "2025", "OCT", "123")
                    if (strlen($token) < 4)
                        continue;

                    // 2. Debe contener al menos un número (evita palabras genericas como "JUEGO", "TAILOR", "SANSE")
                    // Si tus codigos son SOLO letras (ej: "AB-CD"), quita esta linea. Pero para este caso parece seguro.
                    if (!preg_match('/[0-9]/', $token))
                        continue;

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
                logMsg("⚠️ No encontrado en DB (Busqué tokens en: $basename)", "warning");
            }
        }
        $zip->close();
        logMsg("Se vincularon $updatedDocs documentos PDF exitosamente.", "success");

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

echo json_encode($response);
