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
 * Extrae texto de un archivo PDF usando múltiples métodos.
 *
 * @param string $pdfPath Ruta al archivo PDF.
 * @return string Texto extraído del PDF.
 */
function extract_text_from_pdf(string $pdfPath): string
{
    if (!file_exists($pdfPath)) {
        if (class_exists('Logger')) {
            Logger::error('PDF file not found', ['path' => $pdfPath]);
        }
        throw new Exception("Archivo PDF no encontrado: $pdfPath");
    }

    $text = '';

    // Método 1: pdftotext (más preciso)
    $text = extract_with_pdftotext($pdfPath);
    if (!empty(trim($text))) {
        return $text;
    }

    // Método 2: Smalot\PdfParser (si está disponible)
    $text = extract_with_smalot($pdfPath);
    if (!empty(trim($text))) {
        return $text;
    }

    // Método 3: Extracción nativa PHP (básica, para PDFs simples)
    $text = extract_with_native_php($pdfPath);
    if (!empty(trim($text))) {
        return $text;
    }

    if (class_exists('Logger')) {
        Logger::warning('Failed to extract text from PDF', ['path' => $pdfPath]);
    }
    return '';
}

/**
 * Extrae texto usando pdftotext (poppler-utils)
 * 
 * @param string $pdfPath Ruta al PDF
 * @param int $timeoutSeconds Timeout en segundos (default: 30)
 * @return string Texto extraído o cadena vacía
 */
function extract_with_pdftotext(string $pdfPath, int $timeoutSeconds = 30): string
{
    $pdftotextPath = find_pdftotext();
    if (!$pdftotextPath) {
        if (class_exists('Logger')) {
            Logger::warning('pdftotext not available', ['path' => $pdfPath]);
        }
        return '';
    }

    $escaped = escapeshellarg($pdfPath);
    $cmd = "$pdftotextPath -layout -enc UTF-8 $escaped -";

    // Usar proc_open para capturar errores y controlar timeout
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        if (class_exists('Logger')) {
            Logger::error('Failed to start pdftotext process', ['command' => $cmd]);
        }
        return '';
    }

    fclose($pipes[0]);

    // Configurar pipes como no bloqueantes para timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $startTime = time();
    $output = '';
    $errors = '';

    // Leer con timeout
    while (time() - $startTime < $timeoutSeconds) {
        $output .= stream_get_contents($pipes[1]);
        $errors .= stream_get_contents($pipes[2]);

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        usleep(100000); // 100ms
    }

    // Si excedió timeout, terminar proceso
    if (time() - $startTime >= $timeoutSeconds) {
        proc_terminate($process, 9); // SIGKILL
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (class_exists('Logger')) {
            Logger::error('PDF extraction timeout', [
                'path' => $pdfPath,
                'timeout' => $timeoutSeconds
            ]);
        }
        return '';
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    if ($returnCode === 0 && !empty(trim($output))) {
        return $output;
    }

    if (!empty($errors) && class_exists('Logger')) {
        Logger::warning('pdftotext error output', [
            'path' => $pdfPath,
            'error' => substr($errors, 0, 500)
        ]);
    }

    return '';
}

/**
 * Extrae texto usando Smalot\PdfParser
 */
function extract_with_smalot(string $pdfPath): string
{
    $parserPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($parserPath)) {
        return '';
    }

    require_once $parserPath;
    if (!class_exists('Smalot\PdfParser\Parser')) {
        return '';
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        return $pdf->getText();
    } catch (Exception $e) {
        if (class_exists('Logger')) {
            Logger::error('Smalot parser error', [
                'path' => $pdfPath,
                'error' => $e->getMessage()
            ]);
        }
        return '';
    }
}

/**
 * Extracción nativa PHP - para PDFs con texto embebido simple
 * Lee el contenido binario y busca streams de texto
 */
function extract_with_native_php(string $pdfPath): string
{
    $content = file_get_contents($pdfPath);
    if ($content === false) {
        return '';
    }

    $text = '';

    // Buscar contenido entre BT y ET (Begin Text / End Text)
    if (preg_match_all('/BT\s*(.+?)\s*ET/s', $content, $matches)) {
        foreach ($matches[1] as $textBlock) {
            // Extraer texto entre paréntesis (Tj y TJ operadores)
            if (preg_match_all('/\(([^)]*)\)/', $textBlock, $textMatches)) {
                $text .= implode(' ', $textMatches[1]) . "\n";
            }
            // Extraer texto hexadecimal
            if (preg_match_all('/<([^>]+)>/', $textBlock, $hexMatches)) {
                foreach ($hexMatches[1] as $hex) {
                    $decoded = @hex2bin($hex);
                    if ($decoded) {
                        $text .= $decoded . ' ';
                    }
                }
            }
        }
    }

    // Limpiar caracteres no imprimibles
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF\n\r\t]/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
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
        if ($code === '')
            continue;

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
