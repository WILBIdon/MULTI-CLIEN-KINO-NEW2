<?php
/**
 * Motor de Búsqueda Inteligente Voraz
 *
 * Permite búsqueda rápida de códigos en todos los documentos del cliente.
 * Implementa algoritmo voraz para seleccionar documentos que cubran
 * la mayor cantidad de códigos buscados.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/tenant.php';

/**
 * Busca un código en todos los documentos y sus códigos asociados.
 *
 * @param PDO $db Conexión a la base de datos del cliente.
 * @param string $searchTerm Término de búsqueda.
 * @return array Documentos que contienen el código.
 */
function search_by_code(PDO $db, string $searchTerm): array
{
    $searchTerm = trim($searchTerm);
    if ($searchTerm === '') {
        return [];
    }

    $stmt = $db->prepare("
        SELECT DISTINCT
            d.id,
            d.tipo,
            d.numero,
            d.fecha,
            d.proveedor,
            d.ruta_archivo,
            c.codigo AS codigo_encontrado
        FROM documentos d
        JOIN codigos c ON d.id = c.documento_id
        WHERE UPPER(c.codigo) LIKE UPPER(?)
        ORDER BY d.fecha DESC
    ");

    $stmt->execute(['%' . $searchTerm . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Búsqueda voraz: dado un conjunto de códigos, encuentra el mínimo
 * conjunto de documentos que los contienen usando un algoritmo greedy.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param array $codes Lista de códigos a buscar.
 * @return array Documentos seleccionados y códigos cubiertos.
 */
function greedy_search(PDO $db, array $codes): array
{
    $codes = array_filter(array_map('trim', $codes));
    if (empty($codes)) {
        return ['documents' => [], 'covered' => [], 'not_found' => []];
    }

    // Construir condición para buscar cualquiera de los códigos
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $params = array_map('strtoupper', $codes);

    $stmt = $db->prepare("
        SELECT
            d.id,
            d.tipo,
            d.numero,
            d.fecha,
            d.proveedor,
            d.ruta_archivo,
            GROUP_CONCAT(c.codigo, '||') AS codigos
        FROM documentos d
        JOIN codigos c ON d.id = c.documento_id
        WHERE UPPER(c.codigo) IN ($placeholders)
        GROUP BY d.id
        ORDER BY d.fecha DESC
    ");

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convertir a estructura de documentos con array de códigos
    $documents = [];
    foreach ($rows as $row) {
        $docCodes = array_filter(explode('||', $row['codigos']));
        $documents[$row['id']] = [
            'id' => (int) $row['id'],
            'tipo' => $row['tipo'],
            'numero' => $row['numero'],
            'fecha' => $row['fecha'],
            'proveedor' => $row['proveedor'],
            'ruta_archivo' => $row['ruta_archivo'],
            'codes' => $docCodes
        ];
    }

    // Algoritmo voraz: seleccionar documentos que cubran más códigos
    $remaining = array_map('strtoupper', $codes);
    $selected = [];

    while (!empty($remaining) && !empty($documents)) {
        $best = null;
        $bestCover = [];

        foreach ($documents as $doc) {
            $docCodesUpper = array_map('strtoupper', $doc['codes']);
            $cover = array_intersect($docCodesUpper, $remaining);

            if ($best === null || count($cover) > count($bestCover)) {
                $best = $doc;
                $bestCover = $cover;
            } elseif (count($cover) === count($bestCover) && $doc['fecha'] > $best['fecha']) {
                // Si empatan, preferir el más reciente
                $best = $doc;
                $bestCover = $cover;
            }
        }

        if ($best === null || empty($bestCover)) {
            break;
        }

        $best['matched_codes'] = array_values($bestCover);
        $selected[] = $best;
        $remaining = array_diff($remaining, $bestCover);
        unset($documents[$best['id']]);
    }

    return [
        'documents' => $selected,
        'covered' => array_diff(array_map('strtoupper', $codes), $remaining),
        'not_found' => array_values($remaining),
        'total_searched' => count($codes),
        'total_covered' => count($codes) - count($remaining)
    ];
}

/**
 * Búsqueda full-text en texto extraído de documentos.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param string $query Texto a buscar.
 * @return array Documentos con coincidencias.
 */
function fulltext_search(PDO $db, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    // Buscar en datos_extraidos que contiene el JSON del texto
    $stmt = $db->prepare("
        SELECT
            d.id,
            d.tipo,
            d.numero,
            d.fecha,
            d.proveedor,
            d.ruta_archivo,
            d.datos_extraidos
        FROM documentos d
        WHERE d.datos_extraidos LIKE ?
        ORDER BY d.fecha DESC
        LIMIT 50
    ");

    $stmt->execute(['%' . $query . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Búsqueda avanzada dentro del contenido de PDFs.
 * Extrae texto de los PDFs en tiempo real y busca coincidencias.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param string $searchTerm Término a buscar dentro de los PDFs.
 * @param string $clientCode Código del cliente para ubicar los archivos.
 * @return array Documentos con coincidencias y snippets del texto.
 */
function search_in_pdf_content(PDO $db, string $searchTerm, string $clientCode): array
{
    require_once __DIR__ . '/pdf_extractor.php';

    $searchTerm = trim($searchTerm);
    if ($searchTerm === '' || strlen($searchTerm) < 3) {
        return [];
    }

    $results = [];
    $uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

    // Get all documents with PDF files
    $stmt = $db->query("
        SELECT id, tipo, numero, fecha, proveedor, ruta_archivo
        FROM documentos
        WHERE ruta_archivo LIKE '%.pdf'
        ORDER BY fecha DESC
        LIMIT 100
    ");

    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($documents as $doc) {
        $pdfPath = $uploadsDir . $doc['ruta_archivo'];

        if (!file_exists($pdfPath)) {
            continue;
        }

        try {
            $text = extract_text_from_pdf($pdfPath);

            if (empty($text)) {
                continue;
            }

            // Case-insensitive search
            $pos = stripos($text, $searchTerm);

            if ($pos !== false) {
                // Extract snippet around the match
                $snippetStart = max(0, $pos - 80);
                $snippetEnd = min(strlen($text), $pos + strlen($searchTerm) + 80);
                $snippet = substr($text, $snippetStart, $snippetEnd - $snippetStart);

                // Clean up snippet
                $snippet = preg_replace('/\s+/', ' ', $snippet);
                $snippet = trim($snippet);

                if ($snippetStart > 0) {
                    $snippet = '...' . $snippet;
                }
                if ($snippetEnd < strlen($text)) {
                    $snippet .= '...';
                }

                // Count total occurrences
                $occurrences = substr_count(strtolower($text), strtolower($searchTerm));

                $results[] = [
                    'id' => $doc['id'],
                    'tipo' => $doc['tipo'],
                    'numero' => $doc['numero'],
                    'fecha' => $doc['fecha'],
                    'proveedor' => $doc['proveedor'],
                    'ruta_archivo' => $doc['ruta_archivo'],
                    'snippet' => $snippet,
                    'occurrences' => $occurrences,
                    'search_term' => $searchTerm
                ];
            }
        } catch (Exception $e) {
            // Skip files that can't be processed
            continue;
        }
    }

    // Sort by number of occurrences (most relevant first)
    usort($results, function ($a, $b) {
        return $b['occurrences'] - $a['occurrences'];
    });

    return $results;
}

/**
 * Obtiene sugerencias de códigos mientras el usuario escribe.
 *
 * @param PDO $db Conexión a la base de datos.
 * @param string $term Término parcial.
 * @param int $limit Máximo de sugerencias.
 * @return array Lista de códigos que coinciden.
 */
function suggest_codes(PDO $db, string $term, int $limit = 10): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $stmt = $db->prepare("
        SELECT DISTINCT codigo
        FROM codigos
        WHERE codigo LIKE ?
        ORDER BY codigo ASC
        LIMIT ?
    ");

    $stmt->execute([$term . '%', $limit]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Obtiene estadísticas de búsqueda para el dashboard.
 *
 * @param PDO $db Conexión a la base de datos.
 * @return array Estadísticas de documentos y códigos.
 */
function get_search_stats(PDO $db): array
{
    return [
        'total_documents' => (int) $db->query("SELECT COUNT(*) FROM documentos")->fetchColumn(),
        'total_codes' => (int) $db->query("SELECT COUNT(*) FROM codigos")->fetchColumn(),
        'unique_codes' => (int) $db->query("SELECT COUNT(DISTINCT codigo) FROM codigos")->fetchColumn(),
        'validated_codes' => (int) $db->query("SELECT COUNT(*) FROM codigos WHERE validado = 1")->fetchColumn(),
        'documents_by_type' => $db->query("
            SELECT tipo, COUNT(*) as count
            FROM documentos
            GROUP BY tipo
        ")->fetchAll(PDO::FETCH_KEY_PAIR),
        'recent_documents' => $db->query("
            SELECT id, tipo, numero, fecha
            FROM documentos
            ORDER BY fecha DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC)
    ];
}
