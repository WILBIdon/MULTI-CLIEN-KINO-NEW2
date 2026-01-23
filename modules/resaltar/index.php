<?php
/**
 * Resaltar Doc - PDF Text Highlighter
 *
 * Allows users to upload or select a PDF and highlight text chains
 * based on start and end patterns provided by the client.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

// Get available documents for dropdown
$documents = $db->query("
    SELECT id, tipo, numero, fecha, ruta_archivo
    FROM documentos
    WHERE ruta_archivo LIKE '%.pdf'
    ORDER BY id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// For sidebar
$currentModule = 'resaltar';
$baseUrl = '../../';
$pageTitle = 'Resaltar Documento';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resaltar Doc - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>


<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="highlighter-container">
                    <!-- Controls Panel -->
                    <div class="controls-panel">
                        <h3>Fuente del PDF</h3>

                        <div class="source-tabs">
                            <button class="source-tab active" data-source="upload">Subir PDF</button>
                            <button class="source-tab" data-source="existing">Doc Existente</button>
                        </div>

                        <!-- Upload option -->
                        <div id="uploadSource">
                            <div class="upload-area" id="uploadArea">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor"
                                    style="margin: 0 auto 0.5rem; display: block; color: var(--text-muted);">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                </svg>
                                <p style="font-size: 0.875rem; color: var(--text-secondary);">Arrastra un PDF o haz clic
                                </p>
                                <input type="file" id="pdfUpload" accept=".pdf" style="display: none;">
                            </div>
                            <p id="uploadedFileName" class="hidden"
                                style="font-size: 0.875rem; color: var(--accent-success);"></p>
                        </div>

                        <!-- Existing document option -->
                        <div id="existingSource" class="hidden">
                            <div class="form-group">
                                <select class="form-select" id="docSelect">
                                    <option value="">Seleccionar documento...</option>
                                    <?php foreach ($documents as $doc): ?>
                                        <option value="<?= htmlspecialchars($doc['ruta_archivo']) ?>"
                                            data-tipo="<?= htmlspecialchars($doc['tipo']) ?>"
                                            data-doc-id="<?= $doc['id'] ?>">
                                            📄 <?= htmlspecialchars($doc['numero']) ?> (<?= $doc['fecha'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <hr style="margin: 1rem 0; border: none; border-top: 1px solid var(--border-color);">

                        <h3>Patrón a Resaltar</h3>

                        <div class="form-group">
                            <label class="form-label">Texto inicial</label>
                            <input type="text" class="form-input" id="startPattern" placeholder="Ej: CODIGO:">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Texto final</label>
                            <input type="text" class="form-input" id="endPattern" placeholder="Ej: /END">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Color del resaltado</label>
                            <div class="color-picker">
                                <div class="color-option active" style="background: #fef08a;" data-color="#fef08a"
                                    title="Amarillo"></div>
                                <div class="color-option" style="background: #bbf7d0;" data-color="#bbf7d0"
                                    title="Verde"></div>
                                <div class="color-option" style="background: #bfdbfe;" data-color="#bfdbfe"
                                    title="Azul"></div>
                                <div class="color-option" style="background: #fecaca;" data-color="#fecaca"
                                    title="Rojo"></div>
                                <div class="color-option" style="background: #e9d5ff;" data-color="#e9d5ff"
                                    title="Morado"></div>
                            </div>
                        </div>

                        <button class="btn btn-primary" style="width: 100%;" id="addHighlightBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v16m8-8H4" />
                            </svg>
                            Añadir Resaltado
                        </button>

                        <hr style="margin: 1rem 0; border: none; border-top: 1px solid var(--border-color);">

                        <h3>Resaltados Activos</h3>
                        <div id="highlightsList">
                            <p class="text-muted" style="font-size: 0.875rem;">Sin resaltados definidos</p>
                        </div>

                        <button class="btn btn-secondary mt-4" style="width: 100%;" id="applyHighlightsBtn" disabled>
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                            </svg>
                            Aplicar Resaltados
                        </button>
                    </div>

                    <!-- PDF Viewer -->
                    <div class="pdf-viewer">
                        <div id="loadingPdf" class="loading hidden">
                            <div class="spinner"></div>
                            <p>Cargando PDF...</p>
                        </div>

                        <div id="pdfPlaceholder" class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                </svg>
                            </div>
                            <h4 class="empty-state-title">Selecciona un PDF</h4>
                            <p class="empty-state-text">Sube un archivo o selecciona un documento existente para
                                comenzar.</p>
                        </div>

                        <div id="pdfTextContainer" class="hidden">
                            <div class="flex justify-between items-center mb-4">
                                <h3>Texto Extraído</h3>
                                <span id="matchCount" class="badge badge-primary"></span>
                            </div>
                            <div id="pdfTextContent" class="pdf-text-content"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // Set PDF.js worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        let pdfText = '';
        let highlights = [];
        let selectedColor = '#fef08a';
        const clientCode = '<?= $code ?>';

        // Source tabs
        document.querySelectorAll('.source-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.source-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                if (tab.dataset.source === 'upload') {
                    document.getElementById('uploadSource').classList.remove('hidden');
                    document.getElementById('existingSource').classList.add('hidden');
                } else {
                    document.getElementById('uploadSource').classList.add('hidden');
                    document.getElementById('existingSource').classList.remove('hidden');
                }
            });
        });

        // Upload area
        const uploadArea = document.getElementById('uploadArea');
        const pdfUpload = document.getElementById('pdfUpload');

        uploadArea.addEventListener('click', () => pdfUpload.click());
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = 'var(--accent-primary)';
        });
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.style.borderColor = '';
        });
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '';
            if (e.dataTransfer.files.length) {
                pdfUpload.files = e.dataTransfer.files;
                loadPdfFromFile(pdfUpload.files[0]);
            }
        });

        pdfUpload.addEventListener('change', () => {
            if (pdfUpload.files.length) {
                loadPdfFromFile(pdfUpload.files[0]);
            }
        });

        // Load PDF from existing document
        document.getElementById('docSelect').addEventListener('change', async (e) => {
            const option = e.target.selectedOptions[0];
            const path = option.value;
            if (!path) return;

            const type = option.dataset.tipo || 'documento';

            // Logic to determine full path similar to index.php
            let fullPath;
            if (path.includes('/')) {
                fullPath = `../../clients/${clientCode}/uploads/${path}`;
            } else {
                fullPath = `../../clients/${clientCode}/uploads/${type}/${path}`;
            }

            loadPdfFromUrl(fullPath);
        });

        async function loadPdfFromFile(file) {
            document.getElementById('uploadedFileName').textContent = '✓ ' + file.name;
            document.getElementById('uploadedFileName').classList.remove('hidden');

            const arrayBuffer = await file.arrayBuffer();
            extractTextFromPdf(arrayBuffer);
        }

        async function loadPdfFromUrl(url) {
            document.getElementById('loadingPdf').classList.remove('hidden');
            document.getElementById('pdfPlaceholder').classList.add('hidden');

            try {
                const response = await fetch(url);
                const arrayBuffer = await response.arrayBuffer();
                extractTextFromPdf(arrayBuffer);
            } catch (error) {
                alert('Error al cargar el PDF: ' + error.message);
                document.getElementById('loadingPdf').classList.add('hidden');
                document.getElementById('pdfPlaceholder').classList.remove('hidden');
            }
        }

        async function extractTextFromPdf(data) {
            document.getElementById('loadingPdf').classList.remove('hidden');
            document.getElementById('pdfPlaceholder').classList.add('hidden');
            const container = document.getElementById('pdfTextContainer'); // Reutilizamos contenedor
            container.classList.remove('hidden');

            // Limpiar contenedor y usar id correcto para el contenido
            const contentDiv = document.getElementById('pdfTextContent');
            contentDiv.innerHTML = '';
            contentDiv.classList.remove('pdf-text-content'); // Quitar estilo monoespaciado para modo visual
            contentDiv.style.whiteSpace = 'normal'; // Resetear estilos de texto

            try {
                const pdf = await pdfjsLib.getDocument({ data }).promise;
                pdfText = ''; // Resetear texto global

                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);

                    // Crear wrapper para la página
                    const wrapper = document.createElement('div');
                    wrapper.className = 'pdf-page-wrapper';
                    wrapper.style.position = 'relative';
                    wrapper.style.marginBottom = '20px';
                    wrapper.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1)';
                    wrapper.style.border = '1px solid #e5e7eb';

                    // Crear Canvas
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');

                    // Ajustar escala para que se vea bien
                    const viewport = page.getViewport({ scale: 1.5 });
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    canvas.style.width = '100%';
                    canvas.style.height = 'auto';
                    canvas.style.display = 'block';

                    wrapper.appendChild(canvas);
                    contentDiv.appendChild(wrapper);

                    // Renderizar página
                    await page.render({
                        canvasContext: context,
                        viewport: viewport
                    }).promise;

                    // Extraer texto en background para la variable global (por si se necesita búsqueda futura)
                    const textContent = await page.getTextContent();
                    const pageText = textContent.items.map(item => item.str).join(' ');
                    pdfText += pageText + '\n\n';
                }

                document.getElementById('loadingPdf').classList.add('hidden');
                document.getElementById('applyHighlightsBtn').disabled = false;
                // Nota: El botón de aplicar resaltados necesitaría lógica visual avanzada para funcionar sobre Canvas
                // Por ahora mostramos el visual que es lo prioritario.

            } catch (error) {
                alert('Error al procesar PDF: ' + error.message);
                document.getElementById('loadingPdf').classList.add('hidden');
                document.getElementById('pdfPlaceholder').classList.remove('hidden');
                container.classList.add('hidden');
            }
        }

        // Color picker
        document.querySelectorAll('.color-option').forEach(option => {
            option.addEventListener('click', () => {
                document.querySelectorAll('.color-option').forEach(o => o.classList.remove('active'));
                option.classList.add('active');
                selectedColor = option.dataset.color;
            });
        });

        // Add highlight
        document.getElementById('addHighlightBtn').addEventListener('click', () => {
            const start = document.getElementById('startPattern').value.trim();
            const end = document.getElementById('endPattern').value.trim();

            if (!start) {
                alert('Ingresa al menos el texto inicial');
                return;
            }

            highlights.push({
                id: Date.now(),
                start: start,
                end: end,
                color: selectedColor
            });

            renderHighlightsList();
            document.getElementById('startPattern').value = '';
            document.getElementById('endPattern').value = '';
        });

        function renderHighlightsList() {
            const container = document.getElementById('highlightsList');

            if (highlights.length === 0) {
                container.innerHTML = '<p class="text-muted" style="font-size: 0.875rem;">Sin resaltados definidos</p>';
                return;
            }

            container.innerHTML = highlights.map(h => `
                <div class="highlight-tag">
                    <span class="color-dot" style="background: ${h.color};"></span>
                    <span style="flex: 1;">${escapeHtml(h.start)}${h.end ? ' ... ' + escapeHtml(h.end) : ''}</span>
                    <button onclick="removeHighlight(${h.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            `).join('');
        }

        function removeHighlight(id) {
            highlights = highlights.filter(h => h.id !== id);
            renderHighlightsList();
        }

        // ✨ SOLUCIÓN: Redirigir a viewer.php que funciona correctamente
        document.getElementById('applyHighlightsBtn').addEventListener('click', () => {
            if (highlights.length === 0) {
                alert('Agrega al menos un patrón de resaltado');
                return;
            }

            // Obtener el primer patrón (viewer.php solo soporta un término por ahora)
            const firstPattern = highlights[0].start;
            
            // Verificar si seleccionó un documento existente
            const docSelect = document.getElementById('docSelect');
            const selectedOption = docSelect?.selectedOptions[0];
            
            if (selectedOption && selectedOption.dataset.docId) {
                // ✅ Documento existente - redirigir a viewer.php
                const docId = selectedOption.dataset.docId;
                const redirectUrl = `viewer.php?doc=${docId}&term=${encodeURIComponent(firstPattern)}`;
                
                window.location.href = redirectUrl;
            } else {
                // ❌ No hay documento seleccionado o es upload
                alert(
                    '⚠️ Por favor selecciona un documento existente de la base de datos.\n\n' +
                    'El resaltador funciona mejor con documentos ya guardados.\n\n' +
                    'Pasos:\n' +
                    '1. Haz clic en "Doc Existente"\n' +
                    '2. Selecciona un documento del menú\n' +
                    '3. Define el patrón a resaltar\n' +
                    '4. Haz clic en "Aplicar Resaltados"'
                );
            }
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
    </script>
</body>

</html>