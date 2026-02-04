<?php
/**
 * Módulo de Subida de Documentos con Extracción de Códigos
 *
 * Permite subir PDFs y extraer códigos automáticamente usando patrones
 * personalizables (prefijo/terminador) como en la aplicación anterior.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';
require_once __DIR__ . '/../../helpers/gemini_ai.php';
require_once __DIR__ . '/../../helpers/csrf_protection.php';

// Generar token CSRF para este módulo
$csrfToken = CsrfProtection::getToken();

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$geminiConfigured = is_gemini_configured();

$message = '';
$error = '';

// Procesar subida
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    try {
        $tipo = sanitize_code($_POST['tipo'] ?? 'documento');
        $numero = trim($_POST['numero'] ?? '');
        $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
        $proveedor = trim($_POST['proveedor'] ?? '');
        $codes = array_filter(array_map('trim', explode("\n", $_POST['codes'] ?? '')));

        if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Archivo no recibido correctamente');
        }

        // Crear directorio
        $clientDir = CLIENTS_DIR . '/' . $code;
        $uploadDir = $clientDir . '/uploads/' . $tipo;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Mover archivo
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $targetName = uniqid($tipo . '_', true) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $targetName;
        move_uploaded_file($_FILES['file']['tmp_name'], $targetPath);

        $hash = hash_file('sha256', $targetPath);

        // Datos extraídos
        $datosExtraidos = [];
        if (strtolower($ext) === 'pdf') {
            $extractResult = extract_codes_from_pdf($targetPath);
            if ($extractResult['success']) {
                $datosExtraidos = [
                    'text' => substr($extractResult['text'], 0, 10000),
                    'auto_codes' => $extractResult['codes']
                ];
            }
        }

        // Insertar documento
        $stmt = $db->prepare("
            INSERT INTO documentos (tipo, numero, fecha, proveedor, ruta_archivo, hash_archivo, datos_extraidos)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tipo,
            $numero,
            $fecha,
            $proveedor,
            $tipo . '/' . $targetName,
            $hash,
            json_encode($datosExtraidos)
        ]);

        $docId = $db->lastInsertId();

        // Insertar códigos
        if (!empty($codes)) {
            $insertCode = $db->prepare("INSERT INTO codigos (documento_id, codigo) VALUES (?, ?)");
            foreach (array_unique($codes) as $c) {
                $insertCode->execute([$docId, $c]);
            }
        }

        $message = "✅ Documento guardado exitosamente con " . count($codes) . " código(s)";

    } catch (Exception $e) {
        $error = "❌ Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir Documento - KINO TRACE</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f3f4f6;
            margin: 0;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 1rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .header h1 {
            margin: 0;
            color: #1f2937;
        }

        .nav-links a {
            margin-left: 1rem;
            color: #2563eb;
            text-decoration: none;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .card h2 {
            margin-top: 0;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #374151;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
        }

        .file-drop {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9fafb;
        }

        .file-drop:hover,
        .file-drop.dragover {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .file-drop .icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        .file-drop input {
            display: none;
        }

        .pattern-config {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 0.75rem;
            background: #f3f4f6;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .pattern-config label {
            font-size: 0.875rem;
        }

        .pattern-config input {
            padding: 0.5rem;
            font-size: 0.875rem;
        }

        .codes-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .codes-area {
                grid-template-columns: 1fr;
            }
        }

        .codes-box {
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
        }

        .codes-box h4 {
            margin-top: 0;
            color: #374151;
        }

        .codes-box textarea {
            width: 100%;
            height: 200px;
            font-family: monospace;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            resize: vertical;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-ai {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-ai:hover {
            opacity: 0.9;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .code-count {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }

        .message {
            background: #dcfce7;
            color: #166534;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
            color: #6b7280;
        }

        .spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #e5e7eb;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .ai-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header eliminado - la navegación está en el sidebar principal -->

        <?php if ($message): ?>
            <div class="message">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="action" value="save">

            <!-- Paso 1: Subir archivo -->
            <div class="card">
                <h2>📁 Paso 1: Seleccionar Archivo PDF</h2>

                <div class="file-drop" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <div class="icon">📄</div>
                    <p id="fileName">Arrastra un PDF aquí o haz clic para seleccionar</p>
                    <input type="file" name="file" id="fileInput" accept=".pdf" required>
                </div>

                <div class="pattern-config">
                    <div class="form-group" style="margin-bottom:0">
                        <label>Prefijo (Empieza en)</label>
                        <input type="text" id="prefix" placeholder="Ej: Ref:">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Terminador (Termina en)</label>
                        <input type="text" id="terminator" value="/" placeholder="Ej: / o espacio">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Long. Mínima</label>
                        <input type="number" id="minLength" value="4" min="1" max="100">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label>Long. Máxima</label>
                        <input type="number" id="maxLength" value="50" min="1" max="200">
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-primary" onclick="extractCodes()">
                        🔍 Extraer Códigos del PDF
                    </button>
                    <?php if ($geminiConfigured): ?>
                        <button type="button" class="btn btn-ai" onclick="aiExtract()">
                            🤖 Extracción con IA <span class="ai-badge">Gemini</span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="loading" id="extractLoading">
                    <div class="spinner"></div>
                    <p>Extrayendo códigos...</p>
                </div>
            </div>

            <!-- Paso 2: Códigos -->
            <div class="card">
                <h2>📋 Paso 2: Códigos Detectados
                    <span class="code-count" id="codeCount">0 códigos</span>
                </h2>

                <div class="codes-area">
                    <div class="codes-box">
                        <h4>✏️ Códigos a Guardar (editables)</h4>
                        <textarea name="codes" id="codesInput" placeholder="Los códigos aparecerán aquí después de extraerlos del PDF...
También puedes escribirlos manualmente (uno por línea)"></textarea>
                    </div>
                    <div class="codes-box">
                        <h4>📄 Texto Extraído del PDF</h4>
                        <textarea id="pdfText" readonly placeholder="El texto del PDF aparecerá aquí..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Paso 3: Metadatos -->
            <div class="card">
                <h2>📝 Paso 3: Información del Documento</h2>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de Documento</label>
                        <select name="tipo" id="tipoDoc" required>
                            <option value="manifiesto">📦 Manifiesto</option>
                            <option value="declaracion">📄 Declaración</option>
                            <option value="factura">💰 Factura</option>
                            <option value="reporte">📊 Reporte</option>
                            <option value="otro">📁 Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Número de Documento</label>
                        <input type="text" name="numero" id="numeroDoc" required placeholder="Ej: 12345">
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Proveedor</label>
                        <input type="text" name="proveedor" placeholder="Nombre del proveedor">
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-success">
                        💾 Guardar Documento con Códigos
                    </button>
                    <button type="reset" class="btn btn-secondary">Limpiar Todo</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        const apiUrl = '../../api.php';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const codesInput = document.getElementById('codesInput');
        const pdfText = document.getElementById('pdfText');
        const codeCount = document.getElementById('codeCount');

        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFileName();
            }
        });

        fileInput.addEventListener('change', updateFileName);

        function updateFileName() {
            if (fileInput.files.length) {
                document.getElementById('fileName').textContent = '📄 ' + fileInput.files[0].name;
            }
        }

        function updateCodeCount() {
            const codes = codesInput.value.split('\n').filter(c => c.trim());
            codeCount.textContent = codes.length + ' código(s)';
        }

        codesInput.addEventListener('input', updateCodeCount);

        async function extractCodes() {
            if (!fileInput.files.length) {
                alert('Primero selecciona un archivo PDF');
                return;
            }

            const loading = document.getElementById('extractLoading');
            loading.style.display = 'block';

            try {
                const formData = new FormData();
                formData.append('action', 'extract_codes');
                formData.append('file', fileInput.files[0]);
                formData.append('prefix', document.getElementById('prefix').value);
                formData.append('terminator', document.getElementById('terminator').value);
                formData.append('min_length', document.getElementById('minLength').value);
                formData.append('max_length', document.getElementById('maxLength').value);

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result = await response.json();

                loading.style.display = 'none';

                if (result.error) {
                    alert('Error: ' + result.error);
                    return;
                }

                if (result.codes && result.codes.length > 0) {
                    codesInput.value = result.codes.join('\n');
                    updateCodeCount();
                } else {
                    codesInput.value = '';
                    alert('No se encontraron códigos con el patrón especificado. Prueba ajustando el prefijo/terminador.');
                }

                if (result.text) {
                    pdfText.value = result.text;
                }

            } catch (error) {
                loading.style.display = 'none';
                alert('Error al extraer códigos: ' + error.message);
            }
        }

        async function aiExtract() {
            if (!fileInput.files.length) {
                alert('Primero selecciona un archivo PDF');
                return;
            }

            const loading = document.getElementById('extractLoading');
            loading.style.display = 'block';

            try {
                // Primero extraer texto
                const formData1 = new FormData();
                formData1.append('action', 'extract_codes');
                formData1.append('file', fileInput.files[0]);

                const response1 = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData1,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result1 = await response1.json();

                if (!result1.text) {
                    loading.style.display = 'none';
                    alert('No se pudo extraer texto del PDF');
                    return;
                }

                pdfText.value = result1.text;

                // Ahora enviar a IA
                const formData2 = new FormData();
                formData2.append('action', 'ai_extract');
                formData2.append('text', result1.text);
                formData2.append('document_type', document.getElementById('tipoDoc').value);

                const response2 = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData2,
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const result2 = await response2.json();

                loading.style.display = 'none';

                if (result2.error) {
                    alert('Error IA: ' + result2.error);
                    return;
                }

                if (result2.data) {
                    // Llenar campos con datos extraídos por IA
                    if (result2.data.numero_documento) {
                        document.getElementById('numeroDoc').value = result2.data.numero_documento;
                    }
                    if (result2.data.codigos && result2.data.codigos.length > 0) {
                        codesInput.value = result2.data.codigos.join('\n');
                        updateCodeCount();
                    }
                    alert('✅ IA extrajo los datos del documento');
                }

            } catch (error) {
                loading.style.display = 'none';
                alert('Error: ' + error.message);
            }
        }
    </script>
</body>

</html>