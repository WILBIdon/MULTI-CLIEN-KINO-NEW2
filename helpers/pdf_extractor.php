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

    if (!empty(trim($text))) {
        return $text;
    }

    // Método 4: OCR con Tesseract (para documentos escaneados)
    // Solo si los métodos anteriores fallaron o devolvieron muy poco texto
    if (function_exists('extract_with_ocr')) {
        $text = extract_with_ocr($pdfPath);
        if (!empty(trim($text))) {
            return $text;
        }
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
 * Extrae texto usando Tesseract OCR via pdftoppm (parte de poppler-utils)
 * Convierte primero a imagen y luego aplica OCR.
 */
function extract_with_ocr(string $pdfPath): string
{
    $tesseractPath = find_tesseract();
    if (!$tesseractPath) {
        return '';
    }

    // Verificar si tenemos pdftoppm para convertir a imagen
    $pdftoppmPath = find_pdftoppm();
    if (!$pdftoppmPath) {
        return '';
    }

    // Directorio temporal para imágenes
    $tempDir = sys_get_temp_dir() . '/ocr_' . uniqid();
    if (!mkdir($tempDir, 0777, true)) {
        return '';
    }

    $text = '';

    try {
        // 1. Convertir PDF a imágenes (TODAS las páginas para extracción completa)
        $escapedPdf = escapeshellarg($pdfPath);
        $escapedPrefix = escapeshellarg($tempDir . '/page');

        // Sin límite de páginas para extraer de TODO el documento
        $cmdConvert = "$pdftoppmPath -png -r 150 $escapedPdf $escapedPrefix";
        exec($cmdConvert);

        // 2. Procesar cada imagen con Tesseract
        $images = glob($tempDir . '/*.png');
        foreach ($images as $image) {
            $escapedImage = escapeshellarg($image);
            $escapedOut = escapeshellarg($image); // tesseract añade .txt

            // tesseract imagen salida -l spa
            $cmdOcr = "$tesseractPath $escapedImage $escapedOut -l spa";
            exec($cmdOcr);

            $txtFile = $image . '.txt';
            if (file_exists($txtFile)) {
                $text .= file_get_contents($txtFile) . "\n";
            }
        }

    } catch (Exception $e) {
        if (class_exists('Logger')) {
            Logger::error('OCR error', ['error' => $e->getMessage()]);
        }
    } finally {
        // Limpieza
        array_map('unlink', glob("$tempDir/*"));
        rmdir($tempDir);
    }

    return trim($text);
}

function find_tesseract(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        // En Windows requeriría instalación manual y añadir al PATH
        return shell_exec("where tesseract 2>nul") ? 'tesseract' : null;
    }
    $which = shell_exec('which tesseract 2>/dev/null');
    return $which ? trim($which) : null;
}

function find_pdftoppm(): ?string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return null; // Difícil en hosting windows sin configuración
    }
    $which = shell_exec('which pdftoppm 2>/dev/null');
    return $which ? trim($which) : null;
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
    int $minLength = 2,
    int $maxLength = 50
): array {
    $codes = [];

    if ($prefix === '') {
        // Si no hay prefijo, buscar secuencias alfanuméricas largas
        // Típicos códigos de importación: números largos o combinaciones
        $pattern = '/\b([A-Z0-9][A-Z0-9\-\.]{' . ($minLength - 1) . ',' . ($maxLength - 1) . '})\b/i';
    } else {
        // Con prefijo: buscar "PREFIJO...TERMINADOR"
        $escapedPrefix = preg_quote($prefix, '/');
        $escapedTerminator = $terminator === '' ? '\s' : preg_quote($terminator, '/');
        $pattern = '/' . $escapedPrefix . '\s*([^' . $escapedTerminator . '\s]{' . $minLength . ',' . $maxLength . '})/i';
    }

    preg_match_all($pattern, $text, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $code) {
            $cleanedCode = clean_extracted_code($code);
            // Filtrar códigos que sean solo números muy cortos o muy largos
            if (strlen($cleanedCode) >= $minLength && strlen($cleanedCode) <= $maxLength) {
                // Validar que no sea solo puntos, guiones o caracteres inválidos
                if (validate_code($cleanedCode)) {
                    $codes[] = $cleanedCode;
                }
            }
        }
    }

    // Eliminar duplicados de forma robusta (case-insensitive pero preservando original)
    $uniqueCodes = [];
    $seenLower = [];
    foreach ($codes as $code) {
        $lowerCode = strtolower($code);
        if (!in_array($lowerCode, $seenLower)) {
            $uniqueCodes[] = $code;
            $seenLower[] = $lowerCode;
        }
    }

    return array_values($uniqueCodes);
}

/**
 * Limpia un código extraído: elimina puntos finales, espacios extra, etc.
 */
function clean_extracted_code(string $code): string
{
    // Eliminar espacios al inicio y final
    $code = trim($code);

    // Eliminar puntos al final (puede haber varios)
    $code = rtrim($code, '.');

    // Eliminar comas al final
    $code = rtrim($code, ',');

    // Eliminar guiones al final (si quedaron solos)
    $code = rtrim($code, '-');

    // Eliminar espacios internos extra (convertir múltiples espacios a uno)
    $code = preg_replace('/\s+/', ' ', $code);

    // Si el código tiene espacios internos, podría ser erróneo - eliminar espacios
    // (códigos normalmente no tienen espacios)
    $code = str_replace(' ', '', $code);

    // Correcciones comunes de OCR (letras/números confundidos)
    // El usuario reportó específicamente: G confundida con 6, H con M.

    // Si parece un número pero tiene una G, cambiar a 6.
    // Ej: 123G45 -> 123645
    // Solo si la mayoría son números y no es una palabra válida.
    if (preg_match('/^\d*[Gg]\d*$/', $code) && strlen($code) > 2) {
        $code = str_replace(['G', 'g'], '6', $code);
    }

    // Si parece un número pero tiene H/M (casos específicos reportados)
    // Esto es más delicado, aplicamos si el contexto parece numérico
    // H -> M (o viceversa según reporte, "H con M")
    // Asumiremos corrección hacia números si aplica, pero H y M son letras.
    // Si el usuario dice "H con la M", puede ser visual. 
    // Como no son números, solo normalizamos si hay patrón claro.
    // De momento dejaremos la corrección G->6 que es la más clara de OCR numérico.

    return $code;
}

/**
 * Valida que un código sea válido (no solo caracteres especiales)
 */
function validate_code(string $code): bool
{
    // Debe contener al menos un caracter alfanumérico
    if (!preg_match('/[A-Z0-9]/i', $code)) {
        return false;
    }

    // No puede ser solo números de 1-3 dígitos (muy genérico)
    if (preg_match('/^\d{1,3}$/', $code)) {
        return false;
    }

    // No puede ser palabras comunes
    $commonWords = ['de', 'la', 'el', 'en', 'que', 'del', 'los', 'las', 'por', 'con', 'una', 'para'];
    if (in_array(strtolower($code), $commonWords)) {
        return false;
    }

    // No puede ser solo puntos, guiones o espacios
    if (preg_match('/^[\-\.\s]+$/', $code)) {
        return false;
    }

    return true;
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
        ],
        'instructions' => 'IMPORTANTE: Corrige errores comunes de OCR en los códigos. Si un código parece numérico pero tiene una "G", cámbiala por "6". Si ves confusión entre "H" y "M", usa el contexto para decidir cuál es correcta. Los códigos suelen tener al menos 2 caracteres. Ignora puntos o basura al final de los códigos.'
    ];
}
