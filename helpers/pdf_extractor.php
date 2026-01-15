<?php
/**
 * Motor de Extracción de Códigos de PDF
 *
 * Extrae códigos de documentos PDF usando patrones configurables por el usuario.
 * El usuario define:
 * - Prefijo: donde empieza el código (ej: "Ref:", "Código:", etc)
 * - Terminador: donde termina el código (ej: "/", espacio, nueva línea)
 *
 * También soporta integración con IA (Gemini) para extracción inteligente.
 */

/**
 * Extrae texto de un archivo PDF usando pdftotext (poppler) o pdfparser.
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @return string Texto extraído del PDF.
 */
function extract_text_from_pdf(string $pdfPath): string
{
    if (!file_exists($pdfPath)) {
        throw new Exception("Archivo PDF no encontrado: $pdfPath");
    }

    // Intentar con pdftotext (más rápido y preciso si está instalado)
    $pdftotextPath = find_pdftotext();
    if ($pdftotextPath) {
        $escaped = escapeshellarg($pdfPath);
        $cmd = "$pdftotextPath -layout $escaped -";
        $output = shell_exec($cmd);
        if ($output !== null && trim($output) !== '') {
            return $output;
        }
    }

    // Fallback: usar Smalot\PdfParser si está disponible
    $parserPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($parserPath)) {
        require_once $parserPath;
        if (class_exists('Smalot\PdfParser\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            return $pdf->getText();
        }
    }

    // Si no hay ninguna opción, retornar vacío
    return '';
}

/**
 * Busca la ruta de pdftotext en el sistema.
 *
 * @return string|null Ruta al ejecutable o null si no se encuentra.
 */
function find_pdftotext(): ?string
{
    // Windows
    if (PHP_OS_FAMILY === 'Windows') {
        $possible = [
            'C:/Program Files/poppler/bin/pdftotext.exe',
            'C:/poppler/bin/pdftotext.exe',
            'pdftotext.exe'
        ];
        foreach ($possible as $path) {
            if (file_exists($path) || shell_exec("where $path 2>nul")) {
                return $path;
            }
        }
        return null;
    }

    // Linux/Mac
    $which = shell_exec('which pdftotext 2>/dev/null');
    return $which ? trim($which) : null;
}

/**
 * Extrae códigos del texto usando patrones personalizables.
 *
 * @param string $text Texto del PDF.
 * @param string $prefix Prefijo donde empieza el código (ej: "Ref:")
 * @param string $terminator Terminador del código (ej: "/" o "\s" para espacio)
 * @param int $minLength Longitud mínima del código.
 * @param int $maxLength Longitud máxima del código.
 * @return array Lista de códigos únicos encontrados.
 */
function extract_codes_with_pattern(
    string $text,
    string $prefix = '',
    string $terminator = '/',
    int $minLength = 4,
    int $maxLength = 50
): array {
    $codes = [];

    if ($prefix === '') {
        // Si no hay prefijo, buscar secuencias alfanuméricas largas
        // Típicos códigos de importación: números largos o combinaciones
        $pattern = '/\b([A-Z0-9]{' . $minLength . ',' . $maxLength . '})\b/i';
    } else {
        // Con prefijo: buscar "PREFIJO...TERMINADOR"
        $escapedPrefix = preg_quote($prefix, '/');
        $escapedTerminator = $terminator === '' ? '\s' : preg_quote($terminator, '/');
        $pattern = '/' . $escapedPrefix . '\s*([^' . $escapedTerminator . '\s]{' . $minLength . ',' . $maxLength . '})/i';
    }

    preg_match_all($pattern, $text, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $code) {
            $code = trim($code);
            // Filtrar códigos que sean solo números muy cortos o muy largos
            if (strlen($code) >= $minLength && strlen($code) <= $maxLength) {
                $codes[] = $code;
            }
        }
    }

    return array_values(array_unique($codes));
}

/**
 * Función completa: extrae texto del PDF y busca códigos con patrón.
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @param array $config Configuración del patrón.
 * @return array Resultado con texto y códigos encontrados.
 */
function extract_codes_from_pdf(string $pdfPath, array $config = []): array
{
    $prefix = $config['prefix'] ?? '';
    $terminator = $config['terminator'] ?? '/';
    $minLength = $config['min_length'] ?? 4;
    $maxLength = $config['max_length'] ?? 50;

    $text = extract_text_from_pdf($pdfPath);

    if (empty(trim($text))) {
        return [
            'success' => false,
            'error' => 'No se pudo extraer texto del PDF. Puede requerir OCR.',
            'text' => '',
            'codes' => []
        ];
    }

    $codes = extract_codes_with_pattern($text, $prefix, $terminator, $minLength, $maxLength);

    return [
        'success' => true,
        'text' => $text,
        'codes' => $codes,
        'count' => count($codes)
    ];
}

/**
 * Busca códigos específicos en el texto de un PDF.
 *
 * @param string $pdfPath Ruta al PDF.
 * @param array $searchCodes Códigos a buscar.
 * @return array Códigos encontrados y no encontrados.
 */
function search_codes_in_pdf(string $pdfPath, array $searchCodes): array
{
    $text = extract_text_from_pdf($pdfPath);
    $textUpper = strtoupper($text);

    $found = [];
    $notFound = [];

    foreach ($searchCodes as $code) {
        $code = trim($code);
        if ($code === '') continue;

        if (stripos($textUpper, strtoupper($code)) !== false) {
            $found[] = $code;
        } else {
            $notFound[] = $code;
        }
    }

    return [
        'found' => $found,
        'not_found' => $notFound,
        'total_searched' => count($searchCodes),
        'total_found' => count($found)
    ];
}

/**
 * Prepara datos para enviar a Gemini AI para extracción inteligente.
 * Esta función estructura el texto para que la IA lo procese.
 *
 * @param string $text Texto del PDF.
 * @param string $documentType Tipo de documento (manifiesto, factura, etc).
 * @return array Datos estructurados para enviar a la IA.
 */
function prepare_for_ai_extraction(string $text, string $documentType = 'documento'): array
{
    return [
        'document_type' => $documentType,
        'text_content' => $text,
        'text_length' => strlen($text),
        'prompt' => "Analiza el siguiente documento de tipo '$documentType' y extrae todos los códigos de importación, referencias de productos, y datos estructurados relevantes.",
        'expected_fields' => [
            'codes' => 'Lista de códigos de productos/importación',
            'date' => 'Fecha del documento',
            'provider' => 'Proveedor o emisor',
            'total' => 'Valor total si aplica',
            'items' => 'Lista de items con cantidad y descripción'
        ]
    ];
}
