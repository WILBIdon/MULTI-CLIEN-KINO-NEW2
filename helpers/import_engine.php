<?php
/**
 * Motor de Importación de Datos
 *
 * Permite importar datos desde diferentes formatos:
 * - CSV (valores separados por comas)
 * - SQL (sentencias INSERT)
 * - Excel (xlsx) - requiere librería adicional
 *
 * Valida y mapea columnas antes de insertar en la base de datos.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/tenant.php';

/**
 * Procesa un archivo CSV y devuelve los datos como array.
 *
 * @param string $filePath Ruta al archivo CSV.
 * @param string $delimiter Delimitador (por defecto coma).
 * @return array Datos parseados con headers y filas.
 */
function parse_csv(string $filePath, string $delimiter = ','): array
{
    if (!file_exists($filePath)) {
        throw new Exception("Archivo no encontrado: $filePath");
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new Exception("No se puede abrir el archivo");
    }

    // Detectar BOM UTF-8
    $bom = fread($handle, 3);
    if ($bom !== "\xef\xbb\xbf") {
        rewind($handle);
    }

    // Primera línea como headers
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        throw new Exception("Archivo vacío o formato inválido");
    }

    // Limpiar headers
    $headers = array_map(function ($h) {
        return trim(mb_strtolower($h));
    }, $headers);

    $rows = [];
    $lineNumber = 2;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === count($headers)) {
            $rows[] = array_combine($headers, $row);
        }
        $lineNumber++;
    }

    fclose($handle);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'count' => count($rows)
    ];
}

/**
 * Parsea un archivo SQL y extrae sentencias INSERT.
 *
 * @param string $filePath Ruta al archivo SQL.
 * @return array Datos extraídos de las sentencias INSERT.
 */
function parse_sql_inserts(string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new Exception("Archivo no encontrado: $filePath");
    }

    $content = file_get_contents($filePath);

    // Buscar sentencias INSERT
    $pattern = '/INSERT\s+INTO\s+[`"]?(\w+)[`"]?\s*\(([^)]+)\)\s*VALUES\s*(.+?);/is';
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        return ['tables' => [], 'total_rows' => 0];
    }

    $tables = [];
    $totalRows = 0;

    foreach ($matches as $match) {
        $tableName = $match[1];
        $columns = array_map(function ($c) {
            return trim($c, " `\"'\t\n\r");
        }, explode(',', $match[2]));

        $valuesRaw = $match[3];

        // Parsear valores (puede haber múltiples filas)
        preg_match_all('/\(([^)]+)\)/', $valuesRaw, $valueMatches);

        $rows = [];
        foreach ($valueMatches[1] as $valueSet) {
            // Parsear valores individuales
            preg_match_all("/'([^']*)'|\"([^\"]*)\"|(\d+\.?\d*)|NULL/i", $valueSet, $vals);

            $rowValues = [];
            foreach ($vals[0] as $v) {
                $v = trim($v);
                if (strtoupper($v) === 'NULL') {
                    $rowValues[] = null;
                } elseif (preg_match('/^[\'"](.*)[\'"]\s*$/', $v, $m)) {
                    $rowValues[] = $m[1];
                } else {
                    $rowValues[] = is_numeric($v) ? $v + 0 : $v;
                }
            }

            if (count($rowValues) === count($columns)) {
                $rows[] = array_combine($columns, $rowValues);
                $totalRows++;
            }
        }

        if (!isset($tables[$tableName])) {
            $tables[$tableName] = [
                'columns' => $columns,
                'rows' => []
            ];
        }

        $tables[$tableName]['rows'] = array_merge($tables[$tableName]['rows'], $rows);
    }

    return [
        'tables' => $tables,
        'total_rows' => $totalRows
    ];
}

/**
 * Mapea columnas de origen a columnas de destino.
 *
 * @param array $sourceColumns Columnas del archivo importado.
 * @return array Mapeo sugerido.
 */
function suggest_column_mapping(array $sourceColumns): array
{
    $targetColumns = [
        'tipo' => ['tipo', 'type', 'document_type', 'doc_type'],
        'numero' => ['numero', 'number', 'num', 'document_number', 'doc_num', 'id'],
        'fecha' => ['fecha', 'date', 'fecha_doc', 'document_date'],
        'proveedor' => ['proveedor', 'provider', 'supplier', 'vendor'],
        'codigo' => ['codigo', 'code', 'product_code', 'item_code', 'sku'],
        'descripcion' => ['descripcion', 'description', 'desc', 'nombre', 'name'],
        'cantidad' => ['cantidad', 'quantity', 'qty', 'cant'],
        'valor' => ['valor', 'value', 'price', 'precio', 'amount']
    ];

    $mapping = [];

    foreach ($sourceColumns as $sourceCol) {
        $sourceLower = strtolower(trim($sourceCol));

        foreach ($targetColumns as $target => $variations) {
            if (in_array($sourceLower, $variations)) {
                $mapping[$sourceCol] = $target;
                break;
            }
        }

        if (!isset($mapping[$sourceCol])) {
            $mapping[$sourceCol] = null; // No mapping found
        }
    }

    return $mapping;
}

/**
 * Importa datos a la base de datos del cliente.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param array $rows Filas a importar.
 * @param array $mapping Mapeo de columnas.
 * @param string $importType Tipo de importación (documentos o códigos).
 * @return array Resultado de la importación.
 */
function import_to_database(PDO $db, array $rows, array $mapping, string $importType = 'documentos'): array
{
    $imported = 0;
    $errors = [];

    $db->beginTransaction();

    try {
        if ($importType === 'documentos') {
            $stmt = $db->prepare("
                INSERT INTO documentos (tipo, numero, fecha, proveedor)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $tipo = $row[$mapping['tipo'] ?? ''] ?? 'otro';
                $numero = $row[$mapping['numero'] ?? ''] ?? '';
                $fecha = $row[$mapping['fecha'] ?? ''] ?? date('Y-m-d');
                $proveedor = $row[$mapping['proveedor'] ?? ''] ?? '';

                if (empty($numero)) {
                    $errors[] = "Fila " . ($index + 1) . ": número vacío";
                    continue;
                }

                $stmt->execute([$tipo, $numero, $fecha, $proveedor]);
                $imported++;
            }

        } elseif ($importType === 'codigos') {
            // Primero crear documento contenedor
            $stmtDoc = $db->prepare("
                INSERT INTO documentos (tipo, numero, fecha, proveedor)
                VALUES ('importacion', ?, ?, 'Importación masiva')
            ");
            $stmtDoc->execute(['IMPORT_' . date('YmdHis'), date('Y-m-d')]);
            $docId = $db->lastInsertId();

            $stmtCode = $db->prepare("
                INSERT INTO codigos (documento_id, codigo, descripcion, cantidad, valor_unitario)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($rows as $index => $row) {
                $codigo = $row[$mapping['codigo'] ?? ''] ?? '';
                $descripcion = $row[$mapping['descripcion'] ?? ''] ?? '';
                $cantidad = $row[$mapping['cantidad'] ?? ''] ?? 1;
                $valor = $row[$mapping['valor'] ?? ''] ?? 0;

                if (empty($codigo)) {
                    $errors[] = "Fila " . ($index + 1) . ": código vacío";
                    continue;
                }

                $stmtCode->execute([$docId, $codigo, $descripcion, $cantidad, $valor]);
                $imported++;
            }
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'success' => true,
        'imported' => $imported,
        'errors' => $errors,
        'total_rows' => count($rows)
    ];
}

/**
 * Valida los datos antes de importar.
 *
 * @param array $rows Filas a validar.
 * @param array $mapping Mapeo de columnas.
 * @return array Errores encontrados.
 */
function validate_import_data(array $rows, array $mapping): array
{
    $errors = [];
    $warnings = [];

    foreach ($rows as $index => $row) {
        $lineNum = $index + 2; // +1 por 0-index, +1 por header

        // Validaciones básicas
        if (isset($mapping['fecha'])) {
            $fecha = $row[$mapping['fecha']] ?? '';
            if ($fecha && !strtotime($fecha)) {
                $warnings[] = "Línea $lineNum: formato de fecha inválido";
            }
        }

        if (isset($mapping['cantidad'])) {
            $cantidad = $row[$mapping['cantidad']] ?? '';
            if ($cantidad && !is_numeric($cantidad)) {
                $warnings[] = "Línea $lineNum: cantidad no numérica";
            }
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}
