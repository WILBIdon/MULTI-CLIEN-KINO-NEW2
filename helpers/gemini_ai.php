<?php
/**
 * Integración con Google Gemini AI
 *
 * Proporciona funcionalidades de IA para:
 * - Extracción inteligente de datos de documentos
 * - Chat contextual con documentos
 * - Análisis y sugerencias automáticas
 *
 * Requiere API Key de Gemini: https://aistudio.google.com/app/apikey
 */

// Configuración de Gemini (se podría mover a config.php o variables de entorno)
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', 'gemini-2.0-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

/**
 * Verifica si la API de Gemini está configurada.
 *
 * @return bool True si hay API key configurada.
 */
function is_gemini_configured(): bool
{
    return GEMINI_API_KEY !== '';
}

/**
 * Llama a la API de Gemini con un prompt.
 *
 * @param string $prompt El prompt a enviar.
 * @param array $context Contexto adicional (opcional).
 * @return array Respuesta de la API.
 */
function call_gemini(string $prompt, array $context = []): array
{
    if (!is_gemini_configured()) {
        return [
            'success' => false,
            'error' => 'API de Gemini no configurada. Configure GEMINI_API_KEY.',
            'response' => null
        ];
    }

    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
        ]
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 30
        ]
    ];

    $contextStream = stream_context_create($options);

    try {
        $response = @file_get_contents($url, false, $contextStream);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'Error de conexión con Gemini API',
                'response' => null
            ];
        }

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error']['message'] ?? 'Error desconocido de Gemini',
                'response' => null
            ];
        }

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'success' => true,
            'response' => $text,
            'raw' => $result
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'response' => null
        ];
    }
}

/**
 * Extrae datos estructurados de texto de documento usando Gemini.
 *
 * @param string $documentText Texto del documento.
 * @param string $documentType Tipo de documento.
 * @return array Datos extraídos.
 */
function ai_extract_document_data(string $documentText, string $documentType = 'documento'): array
{
    // Limitar texto para no exceder tokens
    $maxChars = 8000;
    if (strlen($documentText) > $maxChars) {
        $documentText = substr($documentText, 0, $maxChars) . '...';
    }

    $prompt = <<<PROMPT
Analiza el siguiente texto extraído de un documento de tipo "$documentType" y extrae la información estructurada.

Responde SOLO con un JSON válido con el siguiente formato:
{
    "numero_documento": "número o referencia del documento",
    "fecha": "fecha en formato YYYY-MM-DD si se encuentra",
    "proveedor": "nombre del proveedor o emisor",
    "codigos": ["lista", "de", "códigos", "de", "productos"],
    "valor_total": "valor total si aplica",
    "items": [
        {"codigo": "ABC123", "descripcion": "descripción", "cantidad": 1, "valor": 100}
    ],
    "notas": "observaciones importantes"
}

Si un campo no se encuentra, usar null.

TEXTO DEL DOCUMENTO:
$documentText
PROMPT;

    $result = call_gemini($prompt);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'],
            'data' => null
        ];
    }

    // Intentar parsear el JSON de la respuesta
    $responseText = $result['response'];

    // Extraer JSON si viene con markdown
    if (preg_match('/```json?\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
        $responseText = $matches[1];
    }

    $data = json_decode($responseText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => true,
            'warning' => 'La respuesta no es JSON válido, retornando texto',
            'data' => null,
            'raw_response' => $result['response']
        ];
    }

    return [
        'success' => true,
        'data' => $data
    ];
}

/**
 * Chat contextual con el contenido de documentos.
 *
 * @param string $question Pregunta del usuario.
 * @param array $documentContext Contexto de documentos relevantes.
 * @return array Respuesta del chat.
 */
function ai_chat_with_context(string $question, array $documentContext): array
{
    $contextText = '';
    foreach ($documentContext as $doc) {
        $contextText .= "--- Documento: {$doc['tipo']} #{$doc['numero']} ---\n";
        $contextText .= "Fecha: {$doc['fecha']}\n";
        if (!empty($doc['codigos'])) {
            $contextText .= "Códigos: " . implode(', ', $doc['codigos']) . "\n";
        }
        $contextText .= "\n";
    }

    $prompt = <<<PROMPT
Eres un asistente experto en gestión documental y trazabilidad de importaciones.

CONTEXTO DE DOCUMENTOS:
$contextText

PREGUNTA DEL USUARIO:
$question

Responde de forma clara, concisa y profesional. Si la información no está disponible en el contexto, indícalo.
PROMPT;

    $result = call_gemini($prompt);

    return [
        'success' => $result['success'],
        'answer' => $result['response'] ?? '',
        'error' => $result['error'] ?? null
    ];
}

/**
 * Analiza discrepancias entre documentos vinculados.
 *
 * @param array $doc1 Datos del primer documento.
 * @param array $doc2 Datos del segundo documento.
 * @return array Análisis de discrepancias.
 */
function ai_analyze_discrepancies(array $doc1, array $doc2): array
{
    $prompt = <<<PROMPT
Analiza las discrepancias entre estos dos documentos de importación:

DOCUMENTO 1 ({$doc1['tipo']} #{$doc1['numero']}):
Códigos: {$doc1['codigos']}
Fecha: {$doc1['fecha']}

DOCUMENTO 2 ({$doc2['tipo']} #{$doc2['numero']}):
Códigos: {$doc2['codigos']}
Fecha: {$doc2['fecha']}

Identifica:
1. Códigos que están en Doc1 pero no en Doc2
2. Códigos que están en Doc2 pero no en Doc1
3. Posibles errores o inconsistencias
4. Recomendaciones

Responde en formato estructurado.
PROMPT;

    return call_gemini($prompt);
}

/**
 * Sugiere categorización automática de un documento.
 *
 * @param string $documentText Texto del documento.
 * @return array Sugerencia de tipo y metadatos.
 */
function ai_suggest_document_type(string $documentText): array
{
    $maxChars = 3000;
    if (strlen($documentText) > $maxChars) {
        $documentText = substr($documentText, 0, $maxChars) . '...';
    }

    $prompt = <<<PROMPT
Analiza el siguiente texto de un documento y determina qué tipo de documento es.

Tipos posibles:
- manifiesto: Documento de carga marítima/aérea
- declaracion: Declaración aduanera
- factura: Factura comercial
- packing_list: Lista de empaque
- certificado: Certificado de origen u otro
- reporte: Reporte o tabla de datos
- otro: Otro tipo

Responde con JSON: {"tipo": "tipo_sugerido", "confianza": 0.95, "razon": "explicación breve"}

TEXTO:
$documentText
PROMPT;

    $result = call_gemini($prompt);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error']];
    }

    $responseText = $result['response'];
    if (preg_match('/```json?\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
        $responseText = $matches[1];
    }

    $data = json_decode($responseText, true);

    return [
        'success' => true,
        'suggestion' => $data
    ];
}
