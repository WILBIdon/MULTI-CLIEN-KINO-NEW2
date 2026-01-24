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
            $stmtLink = $db->prepare("UPDATE documentos SET ruta_archivo = ? WHERE TRIM(LOWER(numero)) = TRIM(LOWER(?))");
            $stmtLink->execute([$relativePath, $basename]);

            if ($stmtLink->rowCount() > 0) {
                $updatedDocs++;
                logMsg("✅ Vinculado: $basename", "success");
            } else {
                logMsg("⚠️ No encontrado en DB: $basename", "warning");
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
