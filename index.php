<?php
/**
 * Gestor de Documentos - KINO TRACE
 *
 * Main document management interface with tabs:
 * - Buscar: Intelligent search
 * - Subir: Upload documents
 * - Consultar: List all documents
 * - Búsqueda por Código: Single code search
 */
session_start();
// Prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/search_engine.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$stats = get_search_stats($db);

// For sidebar
$currentModule = 'gestor';
$baseUrl = './';
$pageTitle = 'Gestor de Documentos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Documentos - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>


<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/includes/header.php'; ?>

            <div class="page-content">
                <!-- Stats Bar -->
                <div class="stats-grid" style="margin-bottom: 1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['total_documents']) ?></div>
                            <div class="stat-label">Documentos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['unique_codes']) ?></div>
                            <div class="stat-label">Códigos Únicos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['validated_codes']) ?></div>
                            <div class="stat-label">Validados</div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="card">
                    <div class="tabs" id="mainTabs">
                        <button class="tab active" data-tab="voraz">🎯 Búsqueda Voraz</button>
                        <button class="tab" data-tab="subir">Subir</button>
                        <button class="tab" data-tab="consultar">Consultar</button>
                        <button class="tab" data-tab="codigo">Búsqueda por Código</button>
                    </div>

                    <!-- Tab: Buscar -->
                    <!-- Tab: Búsqueda Voraz -->
                    <div class="tab-content active" id="tab-voraz">
                        <h3 style="margin-bottom: 1rem;">🎯 Búsqueda Voraz Inteligente</h3>
                        <p class="text-muted mb-4">Pega un bloque de texto con códigos. El sistema extraerá
                            automáticamente la primera columna y buscará esos códigos.</p>

                        <div class="form-group">
                            <label class="form-label">Texto con códigos (se extraerá la primera columna)</label>
                            <textarea class="form-textarea" id="bulkInput" rows="10" placeholder="Pega aquí tu texto. Ejemplo:

COD001    Descripción del producto 1
COD002    Otra descripción aquí
COD003    Más productos...

Se extraerán solo los códigos de la izquierda."></textarea>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" class="btn btn-primary" onclick="processBulkSearch()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Extraer y Buscar Códigos
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearBulkSearch()">Limpiar</button>
                        </div>

                        <div id="bulkLoading" class="loading hidden">
                            <div class="spinner"></div>
                            <p>Extrayendo códigos y buscando...</p>
                        </div>

                        <div id="extractedCodesPreview" class="hidden mt-4">
                            <div class="summary-box">
                                <h4 style="margin-bottom: 0.75rem;">📋 Códigos Extraídos</h4>
                                <div id="extractedCodesList" class="codes-list"></div>
                            </div>
                        </div>

                        <div id="bulkResults" class="hidden mt-4">
                            <div id="bulkSummary"></div>
                            <div id="bulkDocumentList" class="results-list"></div>
                        </div>
                    </div>

                    <!-- Tab: Subir -->
                    <div class="tab-content" id="tab-subir">
                        <h3 style="margin-bottom: 1rem;">Subir Documento</h3>

                        <form id="uploadForm">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label">Tipo de documento</label>
                                    <select class="form-select" name="tipo" id="docTipo" required>
                                        <option value="manifiesto">Manifiesto</option>
                                        <option value="declaracion">Declaración</option>
                                        <option value="factura">Factura</option>
                                        <option value="documento">Otro</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nombre de documento</label>
                                    <input type="text" class="form-input" name="numero" id="docNumero"
                                        placeholder="Ej: Manifiesto Enero 2024" required>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div class="form-group">
                                    <label class="form-label">Fecha</label>
                                    <input type="date" class="form-input" name="fecha" id="docFecha"
                                        value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Proveedor (opcional)</label>
                                    <input type="text" class="form-input" name="proveedor" id="docProveedor"
                                        placeholder="Nombre del proveedor">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Archivo PDF</label>
                                <div class="upload-zone" id="uploadZone">
                                    <div class="upload-zone-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                        </svg>
                                    </div>
                                    <p>Arrastra un archivo aquí o haz clic para seleccionar</p>
                                    <p class="text-muted" style="font-size: 0.75rem;">PDF, máximo 10MB</p>
                                    <input type="file" id="fileInput" name="file" accept=".pdf" style="display: none;">
                                </div>
                                <p id="fileName" class="hidden file-selected mt-2"></p>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Códigos (uno por línea)</label>
                                <textarea class="form-textarea" name="codes" id="docCodes" rows="4"
                                    placeholder="Ingresa los códigos asociados..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" id="uploadBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                Subir Documento
                            </button>
                        </form>
                    </div>

                    <!-- Tab: Consultar -->
                    <div class="tab-content" id="tab-consultar">
                        <div class="flex justify-between items-center mb-4">
                            <h3>Lista de Documentos</h3>
                            <div class="flex gap-2">
                                <select class="form-select" id="filterTipo" style="width: auto;">
                                    <option value="">Todos los tipos</option>
                                    <option value="manifiesto">Manifiestos</option>
                                    <option value="declaracion">Declaraciones</option>
                                    <option value="factura">Facturas</option>
                                    <option value="documento">Documentos</option>
                                </select>
                                <button class="btn btn-secondary" onclick="downloadCSV()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    CSV
                                </button>
                            </div>
                        </div>

                        <!-- Búsqueda Full-Text en PDFs -->
                        <div class="summary-box"
                            style="margin-bottom: 1rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(59, 130, 246, 0.1));">
                            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <input type="text" class="form-input" id="fulltextSearch"
                                        placeholder="🔍 Buscar palabras en PDFs y nombres de documentos..."
                                        style="width: 100%;">
                                </div>
                                <button class="btn btn-primary" onclick="searchFulltext()" id="fulltextBtn">
                                    Buscar en Contenido
                                </button>
                                <button class="btn btn-secondary" onclick="reindexDocuments()" id="reindexBtn"
                                    title="Indexar PDFs sin texto extraído">
                                    🔄 Indexar Pendientes
                                </button>
                            </div>
                            <div id="indexStatus"
                                style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-muted);"></div>
                        </div>

                        <!-- Resultados de búsqueda full-text -->
                        <div id="fulltextResults" class="hidden">
                            <div class="summary-box" style="margin-bottom: 1rem;">
                                <span id="fulltextSummary"></span>
                                <button class="btn btn-secondary" style="float: right; padding: 0.25rem 0.5rem;"
                                    onclick="clearFulltext()">✕ Limpiar</button>
                            </div>
                            <div id="fulltextList" class="results-list"></div>
                        </div>

                        <div id="documentsLoading" class="loading">
                            <div class="spinner"></div>
                            <p>Cargando documentos...</p>
                        </div>

                        <div id="documentsTable" class="hidden">
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Nombre</th>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Códigos</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="documentsTbody"></tbody>
                                </table>
                            </div>
                            <div id="pagination" class="flex justify-between items-center mt-4"></div>
                        </div>
                    </div>

                    <!-- Tab: Búsqueda por Código -->
                    <div class="tab-content" id="tab-codigo">
                        <h3 style="margin-bottom: 1rem;">Búsqueda por Código</h3>
                        <p class="text-muted mb-4">Busca un código específico con autocompletado.</p>

                        <div class="form-group" style="position: relative; max-width: 400px;">
                            <input type="text" class="form-input" id="singleCodeInput"
                                placeholder="Escribe un código...">
                            <div id="suggestions" class="suggestions-dropdown hidden"></div>
                        </div>

                        <div id="singleCodeResults" class="hidden mt-4">
                            <h4 class="mb-3">Documentos encontrados:</h4>
                            <div id="singleCodeList" class="results-list"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = 'api.php';
        const clientCode = '<?= $code ?>';
        let currentPage = 1;

        // ============ Tabs ============
        document.querySelectorAll('#mainTabs .tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('#mainTabs .tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');

                if (tab.dataset.tab === 'consultar') {
                    loadDocuments();
                }
            });
        });

        // Function to programmatically switch tabs
        function switchTab(tabName) {
            document.querySelectorAll('#mainTabs .tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            const targetTab = document.querySelector(`#mainTabs .tab[data-tab="${tabName}"]`);
            if (targetTab) {
                targetTab.classList.add('active');
            }

            const targetContent = document.getElementById('tab-' + tabName);
            if (targetContent) {
                targetContent.classList.add('active');
            }

            if (tabName === 'consultar') {
                loadDocuments();
            }
        }



        // ============ Upload Tab ============
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');

        uploadZone.addEventListener('click', () => fileInput.click());
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });
        uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showFileName();
            }
        });

        fileInput.addEventListener('change', showFileName);

        function showFileName() {
            if (fileInput.files.length) {
                document.getElementById('fileName').textContent = '✓ ' + fileInput.files[0].name;
                document.getElementById('fileName').classList.remove('hidden');
            }
        }

        function resetUploadForm() {
            const form = document.getElementById('uploadForm');
            const uploadZone = document.getElementById('uploadZone');
            const fileNameDisplay = document.getElementById('fileName');

            // Reset form
            form.reset();

            // Clear edit mode flags
            delete form.dataset.editId;
            delete form.dataset.currentFile;

            // Reset upload zone
            uploadZone.querySelector('p').textContent = 'Arrastra un archivo PDF o haz clic para seleccionar';
            uploadZone.style.borderColor = '';
            uploadZone.style.background = '';

            // Reset file name display
            fileNameDisplay.classList.add('hidden');
            fileNameDisplay.style.color = '';

            // Reset title and button
            document.querySelector('#tab-subir h3').textContent = 'Subir Documento';
            document.getElementById('uploadBtn').innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Subir Documento
            `;
        }

        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const form = e.target;
            const isEditMode = !!form.dataset.editId;

            // In edit mode, file is optional; in create mode, it's required
            if (!isEditMode && !fileInput.files.length) {
                alert('Selecciona un archivo PDF');
                return;
            }

            const btn = document.getElementById('uploadBtn');
            btn.disabled = true;
            btn.textContent = isEditMode ? 'Guardando...' : 'Subiendo...';

            const formData = new FormData();
            formData.append('action', isEditMode ? 'update' : 'upload');
            formData.append('tipo', document.getElementById('docTipo').value);
            formData.append('numero', document.getElementById('docNumero').value);
            formData.append('fecha', document.getElementById('docFecha').value);
            formData.append('proveedor', document.getElementById('docProveedor').value);
            formData.append('codes', document.getElementById('docCodes').value);

            // Add document ID if editing
            if (isEditMode) {
                formData.append('id', form.dataset.editId);
                formData.append('current_file', form.dataset.currentFile);
            }

            // Add file only if a new one was selected
            if (fileInput.files.length) {
                formData.append('file', fileInput.files[0]);
            }

            try {
                const response = await fetch(apiUrl, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    alert(isEditMode ? 'Documento actualizado correctamente' : 'Documento subido correctamente');
                    resetUploadForm();

                    // Switch to Consultar tab and reload documents
                    switchTab('consultar');
                    loadDocuments();
                } else {
                    alert('Error: ' + (result.error || 'Error desconocido'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg> Subir Documento`;
            }
        });

        // ============ Consultar Tab ============
        async function loadDocuments(page = 1, tipo = '') {
            document.getElementById('documentsLoading').classList.remove('hidden');
            document.getElementById('documentsTable').classList.add('hidden');

            try {
                const params = new URLSearchParams({
                    action: 'list',
                    page: page,
                    per_page: 20,
                    tipo: tipo
                });

                const response = await fetch(apiUrl + '?' + params);
                const result = await response.json();

                document.getElementById('documentsLoading').classList.add('hidden');
                document.getElementById('documentsTable').classList.remove('hidden');

                renderDocumentsTable(result);
            } catch (error) {
                document.getElementById('documentsLoading').classList.add('hidden');
                alert('Error: ' + error.message);
            }
        }

        function renderDocumentsTable(result) {
            const tbody = document.getElementById('documentsTbody');

            if (!result.data || result.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No hay documentos</td></tr>';
                return;
            }

            tbody.innerHTML = result.data.map(doc => `
                <tr>
                    <td><span class="badge badge-primary">${doc.tipo.toUpperCase()}</span></td>
                    <td>${doc.numero}</td>
                    <td>${doc.fecha}</td>
                    <td>${doc.proveedor || '-'}</td>
                    <td><span class="code-tag">${doc.codes.length}</span></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="modules/resaltar/viewer.php?doc=${doc.id}" class="btn btn-secondary btn-icon" title="Ver documento">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </a>
                            <button class="btn btn-secondary btn-icon" title="Editar" onclick="editDoc(${doc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button class="btn btn-secondary btn-icon" title="Eliminar" onclick="deleteDoc(${doc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');

            // Pagination
            const pagination = document.getElementById('pagination');
            pagination.innerHTML = `
                <span class="text-muted">Página ${result.page} de ${result.last_page}</span>
                <div class="flex gap-2">
                    <button class="btn btn-secondary" ${result.page <= 1 ? 'disabled' : ''} onclick="loadDocuments(${result.page - 1})">Anterior</button>
                    <button class="btn btn-secondary" ${result.page >= result.last_page ? 'disabled' : ''} onclick="loadDocuments(${result.page + 1})">Siguiente</button>
                </div>
            `;
        }

        document.getElementById('filterTipo').addEventListener('change', (e) => {
            loadDocuments(1, e.target.value);
        });

        async function editDoc(id) {
            try {
                // Get document details using correct API action
                const response = await fetch(apiUrl + '?action=get&id=' + id);
                const doc = await response.json();

                if (!doc || doc.error) {
                    alert('Error al cargar documento: ' + (doc.error || 'No encontrado'));
                    return;
                }

                // Switch to Subir tab
                switchTab('subir');

                // Fill form with document data
                document.getElementById('docTipo').value = doc.tipo;
                document.getElementById('docNumero').value = doc.numero;
                document.getElementById('docFecha').value = doc.fecha;
                document.getElementById('docProveedor').value = doc.proveedor || '';
                // Convert codes array to newline-separated text
                document.getElementById('docCodes').value = (doc.codes || []).join('\n');

                // Show current PDF filename and make upload optional
                const uploadZone = document.getElementById('uploadZone');
                const fileNameDisplay = document.getElementById('fileName');

                if (doc.ruta_archivo) {
                    fileNameDisplay.textContent = `📄 PDF actual: ${doc.ruta_archivo}`;
                    fileNameDisplay.classList.remove('hidden');
                    fileNameDisplay.style.color = 'var(--accent-success)';

                    // Update upload zone text
                    uploadZone.querySelector('p').textContent = 'PDF actual cargado. Arrastra uno nuevo solo si deseas reemplazarlo';
                    uploadZone.style.borderColor = 'var(--accent-success)';
                    uploadZone.style.background = 'rgba(16, 185, 129, 0.05)';
                }

                // Update form title and button
                document.querySelector('#tab-subir h3').textContent = 'Editar Documento';
                document.getElementById('uploadBtn').innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Guardar Cambios
                `;

                // Store doc ID and current file path for update
                const form = document.getElementById('uploadForm');
                form.dataset.editId = id;
                form.dataset.currentFile = doc.ruta_archivo || '';


            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function deleteDoc(id) {
            if (!confirm('¿Eliminar este documento?')) return;

            try {
                const response = await fetch(apiUrl + '?action=delete&id=' + id);
                const result = await response.json();

                if (result.success) {
                    loadDocuments();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function downloadCSV() {
            // Simple CSV export
            const tipo = document.getElementById('filterTipo').value;
            window.open(apiUrl + '?action=export_csv&tipo=' + tipo, '_blank');
        }

        // ============ Full-Text Search in PDFs ============
        const fulltextInput = document.getElementById('fulltextSearch');

        fulltextInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') searchFulltext();
        });

        async function searchFulltext() {
            const query = fulltextInput.value.trim();
            if (query.length < 3) {
                alert('Ingresa al menos 3 caracteres');
                return;
            }



            // Validación eliminada a petición del usuario
            // Ahora permite buscar cualquier término (incluyendo números/códigos)

            // Validación eliminada a petición del usuario
            // Ahora permite buscar cualquier término (incluyendo números/códigos)

            const btn = document.getElementById('fulltextBtn');
            btn.disabled = true;
            btn.textContent = 'Buscando...';

            try {
                const response = await fetch(`${apiUrl}?action=fulltext_search&query=${encodeURIComponent(query)}`);
                const result = await response.json();

                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';

                if (result.error) {
                    alert(result.error);
                    return;
                }

                showFulltextResults(result);
            } catch (error) {
                btn.disabled = false;
                btn.textContent = 'Buscar en Contenido';
                alert('Error: ' + error.message);
            }
        }

        function showFulltextResults(result) {
            document.getElementById('fulltextResults').classList.remove('hidden');
            document.getElementById('documentsTable').classList.add('hidden');
            document.getElementById('documentsLoading').classList.add('hidden');

            document.getElementById('fulltextSummary').innerHTML =
                `<strong>${result.count}</strong> documento(s) contienen "<strong>${result.query}</strong>"`;

            if (result.results.length === 0) {
                document.getElementById('fulltextList').innerHTML =
                    '<p class="text-muted">No se encontraron coincidencias. Prueba indexar los documentos primero.</p>';
                return;
            }

            let html = '';
            for (const doc of result.results) {
                let pdfUrl = '';
                if (doc.ruta_archivo) {
                    pdfUrl = doc.ruta_archivo.includes('/')
                        ? `../../clients/${clientCode}/uploads/${doc.ruta_archivo}`
                        : `../../clients/${clientCode}/uploads/${doc.tipo}/${doc.ruta_archivo}`;
                }

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha} · ${doc.occurrences} coincidencia(s)</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        <!-- Snippet oculto para usuario final 
                        ${doc.snippet ? `<div class="result-meta" style="margin-top: 0.5rem; font-style: italic; background: rgba(255,235,59,0.1); padding: 0.5rem; border-radius: 4px;">"${doc.snippet}"</div>` : ''}
                        -->
                        <div class="result-actions" style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(result.query)}" 
                               class="btn btn-primary btn-sm" target="_blank">
                                👁️ Ver Documento
                            </a>
                            
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary btn-sm" style="white-space: nowrap;">📄 Original</a>` : ''}

                            <button onclick="editDoc(${doc.id})" class="btn btn-sm" style="background: #f59e0b; color: white;" title="Editar">
                                ✏️
                            </button>

                            <button onclick="deleteDoc(${doc.id})" class="btn btn-sm" style="background: #ef4444; color: white;" title="Eliminar">
                                🗑️
                            </button>
                        </div>
                    </div>
                `;
            }
            document.getElementById('fulltextList').innerHTML = html;
        }

        function clearFulltext() {
            document.getElementById('fulltextResults').classList.add('hidden');
            document.getElementById('documentsTable').classList.remove('hidden');
            fulltextInput.value = '';
            loadDocuments();
        }

        let isIndexing = false;
        let totalIndexedSession = 0;

        async function reindexDocuments() {
            if (isIndexing) return;
            isIndexing = true;

            const btn = document.getElementById('reindexBtn');
            const status = document.getElementById('indexStatus');

            btn.disabled = true;
            btn.innerHTML = '⏳ Indexando...';
            totalIndexedSession = 0;

            // First call to get initial pending count
            let pending = 999;
            let batchNum = 0;

            while (pending > 0) {
                batchNum++;
                status.innerHTML = `🔄 Procesando lote #${batchNum}... (Indexados: ${totalIndexedSession})`;

                try {
                    const response = await fetch(`${apiUrl}?action=reindex_documents&batch=10`);
                    const result = await response.json();

                    if (!result.success) {
                        status.innerHTML = `❌ Error: ${result.error || 'Error desconocido'}`;
                        break;
                    }

                    totalIndexedSession += result.indexed;
                    pending = result.pending;

                    status.innerHTML = `✅ Indexados: ${totalIndexedSession}, Pendientes: ${pending}`;

                    if (result.errors && result.errors.length > 0) {
                        console.log('Errores de indexación:', result.errors);
                    }

                    // If nothing was indexed but still pending, files are missing
                    if (result.indexed === 0 && pending > 0) {
                        status.innerHTML += ` <span style="color: var(--warning);">(${pending} archivos no encontrados)</span>`;
                        break;
                    }
                } catch (error) {
                    status.innerHTML = `❌ Error de red: ${error.message}`;
                    break;
                }
            }

            if (pending === 0) {
                status.innerHTML = `✅ ¡Completado! ${totalIndexedSession} documentos indexados`;
            }

            btn.disabled = false;
            btn.innerHTML = '🔄 Indexar Pendientes';
            isIndexing = false;
        }

        // ============ Single Code Search Tab ============
        let debounceTimer;
        const singleCodeInput = document.getElementById('singleCodeInput');
        const suggestionsDiv = document.getElementById('suggestions');

        singleCodeInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            const term = e.target.value.trim();

            if (term.length < 2) {
                suggestionsDiv.classList.add('hidden');
                return;
            }

            debounceTimer = setTimeout(async () => {
                try {
                    const response = await fetch(`${apiUrl}?action=suggest&term=${encodeURIComponent(term)}`);
                    const suggestions = await response.json();

                    if (suggestions.length > 0) {
                        suggestionsDiv.innerHTML = suggestions.map(s =>
                            `<div class="suggestion-item" onclick="selectCode('${s}')">${s}</div>`
                        ).join('');
                        suggestionsDiv.classList.remove('hidden');
                    } else {
                        suggestionsDiv.classList.add('hidden');
                    }
                } catch (e) {
                    suggestionsDiv.classList.add('hidden');
                }
            }, 300);
        });

        singleCodeInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchSingleCode(singleCodeInput.value.trim());
            }
        });

        async function selectCode(code) {
            singleCodeInput.value = code;
            suggestionsDiv.classList.add('hidden');
            searchSingleCode(code);
        }

        async function searchSingleCode(code) {
            if (!code) return;

            try {
                const response = await fetch(`${apiUrl}?action=search_by_code&code=${encodeURIComponent(code)}`);
                const result = await response.json();

                document.getElementById('singleCodeResults').classList.remove('hidden');

                if (!result.documents || result.documents.length === 0) {
                    document.getElementById('singleCodeList').innerHTML = '<p class="text-muted">No se encontraron documentos con este código.</p>';
                    return;
                }

                document.getElementById('singleCodeList').innerHTML = result.documents.map(doc => {
                    // Construir ruta del PDF correctamente
                    let pdfUrl = '';
                    if (doc.ruta_archivo) {
                        if (doc.ruta_archivo.includes('/')) {
                            pdfUrl = `../../clients/${clientCode}/uploads/${doc.ruta_archivo}`;
                        } else {
                            pdfUrl = `../../clients/${clientCode}/uploads/${doc.tipo}/${doc.ruta_archivo}`;
                        }
                    }

                    return `
                        <div class="result-card">
                            <div class="result-header">
                                <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                                <span class="result-meta">${doc.fecha}</span>
                            </div>
                            <div class="result-title">${doc.numero}</div>
                            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(result.query)}" class="btn btn-primary" style="padding: 0.5rem 1rem;">👁️ Ver Documento</a>
                                ${pdfUrl ? `<a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(code)}" class="btn btn-success" style="padding: 0.5rem 1rem; background: #038802;">🖍️ Resaltar</a>` : ''}
                                ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 Ver PDF</a>` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        document.addEventListener('click', (e) => {
            if (!suggestionsDiv.contains(e.target) && e.target !== singleCodeInput) {
                suggestionsDiv.classList.add('hidden');
            }
        });

        // ============ Toggle Codes Display ============
        function toggleCodes(docId) {
            const codesDiv = document.getElementById('codes-' + docId);
            const icon = document.getElementById('icon-' + docId);

            if (codesDiv.style.display === 'none') {
                codesDiv.style.display = 'block';
                icon.textContent = '▼';
            } else {
                codesDiv.style.display = 'none';
                icon.textContent = '▶';
            }
        }



        // ============ Delete Document ============
        async function confirmDelete(docId, docNumero) {
            if (!confirm(`¿Estás seguro de eliminar el documento "${docNumero}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }

            try {
                const response = await fetch(`${apiUrl}?action=delete\u0026id=${docId}`, {
                    method: 'POST'
                });
                const result = await response.json();

                if (result.error) {
                    alert('Error: ' + result.error);
                    return;
                }

                alert('✅ Documento eliminado correctamente');

                // Reload documents list
                loadDocuments();
            } catch (error) {
                alert('Error al eliminar: ' + error.message);
            }
        }

        // ============ Búsqueda Voraz Inteligente ============

        function extractFirstColumn(text) {
            const lines = text.trim().split('\n');
            const codes = [];

            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed === '') continue;

                let code = '';
                if (trimmed.includes('\t')) {
                    code = trimmed.split('\t')[0].trim();
                } else if (trimmed.match(/\s{2,}/)) {
                    code = trimmed.split(/\s{2,}/)[0].trim();
                } else {
                    code = trimmed.split(/\s+/)[0].trim();
                }

                if (code.length > 0) codes.push(code);
            }

            return [...new Set(codes)];
        }

        async function processBulkSearch() {
            const input = document.getElementById('bulkInput').value;
            if (!input.trim()) {
                alert('Por favor pega el texto con códigos');
                return;
            }

            const extractedCodes = extractFirstColumn(input);
            if (extractedCodes.length === 0) {
                alert('No se pudieron extraer códigos del texto pegado');
                return;
            }

            document.getElementById('extractedCodesList').innerHTML = extractedCodes.map(c =>
                `<span class="code-tag">${c}</span>`
            ).join('');
            document.getElementById('extractedCodesPreview').classList.remove('hidden');
            document.getElementById('bulkLoading').classList.remove('hidden');

            try {
                const formData = new FormData();
                formData.append('codes', extractedCodes.join('\n'));

                const response = await fetch(`${apiUrl}?action=search`, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                document.getElementById('bulkLoading').classList.add('hidden');
                if (result.error) {
                    alert(result.error);
                    return;
                }
                showBulkResults(result, extractedCodes);
            } catch (error) {
                document.getElementById('bulkLoading').classList.add('hidden');
                alert('Error: ' + error.message);
            }
        }

        function showBulkResults(result, searchedCodes) {
            document.getElementById('bulkResults').classList.remove('hidden');
            const coveredCount = result.total_covered || 0;
            const notFound = result.not_found || [];

            let summaryHtml = `
                <div class="summary-box${notFound.length > 0 ? ' warning' : ''}">
                    <strong>${coveredCount}/${searchedCodes.length}</strong> códigos encontrados en 
                    <strong>${result.documents?.length || 0}</strong> documento(s)
            `;

            // Llamado de atención para códigos no encontrados
            if (notFound.length > 0) {
                summaryHtml += `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(239,68,68,0.1); border-left: 4px solid #ef4444; border-radius: var(--radius-md);">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <span style="font-size: 1.5rem;">⚠️</span>
                            <strong style="color: #dc2626;">ATENCIÓN: ${notFound.length} código(s) no encontrado(s)</strong>
                        </div>
                        <div class="codes-list">
                            ${notFound.map(c => `<span class="code-tag" style="background: rgba(239,68,68,0.15); color: #dc2626; border: 1px solid #ef4444; font-weight: 600;">${c}</span>`).join('')}
                        </div>
                        <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #991b1b;">Estos códigos no existen en ningún documento. Verifica que estén correctamente escritos.</p>
                    </div>
                `;
            }

            summaryHtml += `</div>`;
            document.getElementById('bulkSummary').innerHTML = summaryHtml;

            if (!result.documents || result.documents.length === 0) {
                document.getElementById('bulkDocumentList').innerHTML = '<p class="text-muted">No se encontraron documentos.</p>';
                return;
            }

            let html = '';
            for (const doc of result.documents) {
                let pdfUrl = doc.ruta_archivo ? `clients/${clientCode}/uploads/${doc.ruta_archivo.includes('/') ? doc.ruta_archivo : doc.tipo + '/' + doc.ruta_archivo}` : '';
                const firstCode = (doc.matched_codes && doc.matched_codes[0]) || '';
                const allCodes = doc.matched_codes || doc.codes || [];

                // Use the first searched code that matches this document
                const searchedCode = searchedCodes.find(sc => allCodes.includes(sc)) || firstCode;

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha}</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        <div class="codes-list" style="margin-top: 0.75rem;">
                            ${allCodes.map(c => `<span class="code-tag">${c}</span>`).join('')}
                        </div>
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            ${pdfUrl ? `
                                <a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(searchedCode)}" 
                                   class="btn btn-success" style="padding: 0.5rem 1rem; background: #038802;" target="_blank">
                                    🖍️ Resaltar "${searchedCode}"
                                </a>
                                <a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">
                                    📄 Original
                                </a>
                            ` : ''}
                        </div>
                    </div>
                `;
            }

            document.getElementById('bulkDocumentList').innerHTML = html;
        }

        function clearBulkSearch() {
            document.getElementById('bulkInput').value = '';
            document.getElementById('extractedCodesPreview').classList.add('hidden');
            document.getElementById('bulkResults').classList.add('hidden');
        }
    </script>
</body>

</html>