
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

            // CSRF Token in Body (Fallback)
            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            formData.append('csrf_token', token);

            try {
                const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': token
                    }
                });
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
                            <button type="button" id="btn-codes-${doc.id}" class="btn btn-secondary btn-sm" title="Ver Códigos" onclick="toggleTableCodes(event, ${doc.id})">
                                Ver Códigos
                            </button>
                            ${doc.ruta_archivo ? `
                            <a href="modules/resaltar/viewer.php?doc=${doc.id}&codes=${encodeURIComponent(doc.codes.join(','))}" class="btn btn-primary btn-icon" title="Ver Documento y Resaltar" target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </a>` : ''}

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
                <tr id="codes-row-${doc.id}" class="hidden" style="background-color: var(--bg-tertiary);">
                    <td colspan="6">
                         <div style="padding: 1rem;">
                            <strong>Códigos vinculados:</strong>
                            <div class="codes-list" style="margin-top: 0.5rem; max-height: 400px; overflow-y: auto; display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; background: white; padding: 0.5rem; border: 1px solid #f0f0f0; border-radius: 4px;">
                                ${doc.codes.map(c => `<div style="font-family: inherit; font-size: 0.9rem; padding: 2px 0; color: #374151; width: 100%; display: block;">${c}</div>`).join('')}
                            </div>
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
                const pdfUrl = doc.ruta_archivo ? `modules/resaltar/download.php?doc=${doc.id}` : '';

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
                                ${pdfUrl ? `<button onclick="openHighlighter('modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(code)}')" class="btn btn-success" style="padding: 0.5rem 1rem; background: #038802;">🖍️ Resaltar</button>` : ''}
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



        function toggleTableCodes(e, docId) {
            if (e) e.preventDefault();
            const row = document.getElementById(`codes-row-${docId}`);
            const btn = document.getElementById(`btn-codes-${docId}`);

            if (row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                btn.textContent = 'Ocultar Códigos';
                btn.style.backgroundColor = '#d1d5db'; // Un gris más oscuro para indicar activo
                btn.style.color = '#1f2937';
            } else {
                row.classList.add('hidden');
                btn.textContent = 'Ver Códigos';
                btn.style.backgroundColor = '';
                btn.style.color = '';
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

        /**
         * Muestra resultados de búsqueda VORAZ (Limpio y reconstruido)
         */
        function showBulkResults(result, searchedCodes) {
            const resultsDiv = document.getElementById('bulkResults');

            if (!result.documents || result.documents.length === 0) {
                resultsDiv.innerHTML = '<div class="alert alert-info">No se encontraron documentos.</div>';
                resultsDiv.classList.remove('hidden');
                return;
            }

            const totalDocs = result.documents.length;

            // Header simplificado
            let html = `
                <div style="background: #eef2ff; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #c7d2fe;">
                    <h3 style="margin-top:0; color: #4338ca;">Resultados Voraz</h3>
                    <p style="margin:0; color: #374151;"><strong>${result.total_covered || 0}</strong> códigos encontrados en <strong>${totalDocs}</strong> documentos.</p>
                </div>
            `;

            <div style="text-align: center; margin-bottom: 2rem;">
                <!-- Botón: Generar PDF Unificado - BLUE -->
                <button onclick='voraz_generateUnifiedPDF(${escapeForJSON(result.documents)}, ${escapeForJSON(searchedCodes)})'
                    class="btn btn-primary"
                    style="padding: 0.75rem 1.5rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                    📄 Generar PDF Unificado
                </button>
            </div>

            // Advertencia de no encontrados
            if (result.not_found && result.not_found.length > 0) {
                html += `
                    <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                        <strong>No encontrados:</strong> ${result.not_found.join(', ')}
                    </div>
                `;
            }

            // Renderizar documentos (Estilo estándar)
            html += '<div style="display: grid; gap: 1rem;">';

            for (const doc of result.documents) {
                // Obtener TODOS los códigos para el resaltador
                // ⭐ Unir SOLO los códigos mostrados en la tarjeta (matched) para los badges
                const docCodes = doc.matched_codes || doc.codes || [];

                // ⭐ Para el botón "Resaltar", el usuario quiere ver TODOS los códigos del doc ("traer los 3 códigos").
                // Usamos all_codes si existe, si no, fallback a docCodes.
                const allCodesStr = doc.all_codes || docCodes.join(',');

                html += `
                    <div class="result-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; background: white; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div class="result-header" style="display: flex; justify-content: space-between; margin-bottom: 0.75rem;">
                            <span class="badge badge-primary">${(doc.tipo || 'DOC').toUpperCase()}</span>
                            <span class="result-meta" style="color: #6b7280; font-size: 0.875rem;">${doc.fecha || ''}</span>
                        </div>
                        
                        <div class="result-title" style="font-weight: 700; font-size: 1.1rem; color: #111827; margin-bottom: 0.5rem;">
                            ${doc.numero || 'Sin título'}
                        </div>
                        
                        <div style="background: #f9fafb; padding: 0.5rem; border-radius: 4px; border: 1px solid #f3f4f6; margin-bottom: 1rem;">
                            <small style="color: #6b7280; display: block; margin-bottom: 0.25rem;">Códigos encontrados:</small>
                            ${docCodes.map(c => `<span class="code-tag" style="display: inline-block; background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; margin-right: 4px; margin-bottom: 4px;">${c}</span>`).join('')}
                        </div>
                        
                        <div style="display: flex; gap: 0.75rem;">
                            <!-- Botón reconstruido para resaltar TODOS -->
                            <button onclick="voraz_highlightAllCodes('${doc.id}', '${escapeForAttr(doc.ruta_archivo)}', '${allCodesStr}', '${docCodes.join(',')}')" 
                                    class="btn btn-success" 
                                    style="background-color: #059669; border: none; color: white; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-size: 0.875rem; font-weight: 500;">
                                🖍️ Resaltar (${doc.all_codes ? doc.all_codes.split(',').length : docCodes.length})
                            </button>
                            
                            <a href="clients/${clientCode}/uploads/${doc.ruta_archivo}" target="_blank" 
                               class="btn btn-secondary"
                               style="background-color: white; border: 1px solid #d1d5db; color: #374151; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.875rem;">
                                📄 Original
                            </a>
                        </div>
                    </div>
                `;
            }

            html += '</div>';

            resultsDiv.innerHTML = html;
            resultsDiv.classList.remove('hidden');
        }

        // ========== FUNCIONES AUXILIARES (no afectan otras partes) ==========

        function escapeForAttr(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/'/g, '&#39;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function escapeForJSON(obj) {
            return JSON.stringify(obj)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'");
        }

        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Abre el viewer con TODOS los códigos del documento
         * NO afecta otros botones "Resaltar" de la app
         */
        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Abre el viewer con TODOS los códigos del documento
         * NO afecta otros botones "Resaltar" de la app
         */
        function voraz_highlightAllCodes(docId, filePath, codesStr, matchedCodesStr = '') {
            // Prioridad absoluta al ID (Soberanía del ID)
            // Si tenemos ID, no deberíamos depender de filePath para nada crítico,
            // pero lo enviamos por compatibilidad backward si el viewer lo requiere.

            console.log('🔍 VORAZ: Abriendo resaltador:', { docId, codesStr, matchedCodesStr });

            // Construir parámetros
            // 'term' = Códigos Encontrados (Hits) -> Naranja
            // 'codes' = Todos los códigos (Contexto) -> Verde (la diferencia se calcula en viewer)
            const params = new URLSearchParams({
                doc: docId,
                codes: codesStr,          // Contexto
                term: matchedCodesStr,    // Hits
                voraz_mode: 'true',
                strict_mode: 'true'       // Activar lógica de doble color
            });

            // Si por alguna razón no hay ID (caso raro), usamos file
            if (!docId && filePath) {
                params.append('file', filePath);
            }

            const url = `modules/resaltar/viewer.php?${params.toString()}`;
            window.open(url, '_blank');
        }

        /**
         * ⭐ FUNCIÓN EXCLUSIVA PARA BÚSQUEDA VORAZ
         * Genera PDF unificado con todos los documentos encontrados
         * NO afecta otras funciones de la app
         */
        async function voraz_generateUnifiedPDF(documents, allCodes) {
            console.log('🔍 VORAZ: Generando PDF unificado...', {
                documents: documents.length,
                codes: allCodes.length
            });

            // Validar datos
            if (!documents || documents.length === 0) {
                alert('❌ No hay documentos para unificar');
                return;
            }

            if (!allCodes || allCodes.length === 0) {
                alert('❌ No hay códigos para resaltar');
                return;
            }

            // Mostrar loading con ID único para voraz
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'voraz-unified-loading';
            loadingDiv.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                            background: rgba(0,0,0,0.85); display: flex; align-items: center; 
                            justify-content: center; z-index: 99999; flex-direction: column;">
                    <div style="background: white; padding: 40px 50px; border-radius: 15px; 
                                text-align: center; max-width: 450px; box-shadow: 0 10px 50px rgba(0,0,0,0.3);">
                        <div class="voraz-spinner" style="border: 5px solid #f3f3f3; 
                             border-top: 5px solid #667eea; border-radius: 50%; 
                             width: 60px; height: 60px; animation: spin 1s linear infinite;
                             margin: 0 auto 20px;"></div>
                        <h3 style="margin: 0 0 10px 0; color: #333; font-size: 20px;">
                            🔍 Generando PDF Unificado (Búsqueda Voraz)
                        </h3>
                        <p style="margin: 0 0 20px 0; color: #666;">
                            Procesando ${documents.length} documentos con ${allCodes.length} códigos
                        </p>
                        <div style="width: 100%; height: 25px; background: #eee; border-radius: 12px; 
                             overflow: hidden;">
                            <div id="voraz-progress-fill" style="width: 0%; height: 100%; 
                                 background: linear-gradient(90deg, #667eea, #764ba2); 
                                 transition: width 0.5s ease;"></div>
                        </div>
                        <p id="voraz-progress-text" style="margin-top: 10px; color: #999; font-size: 14px;">
                            Iniciando...
                        </p>
                    </div>
                </div>
                <style>
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>
            `;
            document.body.appendChild(loadingDiv);

            // Simular progreso
            let progress = 0;
            const progressEl = document.getElementById('voraz-progress-fill');
            const progressText = document.getElementById('voraz-progress-text');

            const progressInterval = setInterval(() => {
                progress += 5;
                if (progress <= 90) {
                    progressEl.style.width = progress + '%';
                    if (progress < 30) progressText.textContent = 'Cargando documentos...';
                    else if (progress < 60) progressText.textContent = 'Combinando PDFs...';
                    else progressText.textContent = 'Finalizando...';
                }
            }, 300);

            try {
                // Preparar datos
                const payload = {
                    documents: documents,
                    codes: allCodes,
                    mode: 'voraz' // ⭐ Identificador único
                };

                console.log('🔍 VORAZ: Enviando solicitud:', payload);

                // Llamar al backend
                const response = await fetch('modules/resaltar/generate_unified.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8',
                        'Accept': 'application/json',
                        'X-Voraz-Mode': 'true' // Header especial para identificar
                    },
                    body: JSON.stringify(payload)
                });

                console.log('🔍 VORAZ: Response status:', response.status);

                // Leer respuesta
                const responseText = await response.text();
                console.log('🔍 VORAZ: Response text:', responseText);

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('🔍 VORAZ: Error parseando JSON:', jsonError);
                    throw new Error(`Respuesta inválida del servidor:\n${responseText.substring(0, 300)}`);
                }

                console.log('🔍 VORAZ: Resultado parseado:', result);

                // Completar progreso
                clearInterval(progressInterval);
                progressEl.style.width = '100%';
                progressText.textContent = '¡Completado!';

                if (result.success) {
                    // Esperar para mostrar el 100%
                    await new Promise(resolve => setTimeout(resolve, 800));

                    // Abrir PDF unificado
                    const params = new URLSearchParams({
                        file: result.unified_pdf_path,
                        codes: allCodes.join(','),
                        mode: 'unified',
                        voraz_mode: 'true'
                    });

                    const url = `modules/resaltar/viewer.php?${params.toString()}`;
                    window.open(url, '_blank');

                    // Cerrar loading
                    document.body.removeChild(loadingDiv);

                    // Mensaje de éxito
                    alert(`✅ PDF Unificado generado exitosamente!\n\n` +
                        `📄 ${result.page_count} páginas totales\n` +
                        `📁 ${result.document_count} documentos combinados\n` +
                        `🔍 ${allCodes.length} códigos resaltados`);

                } else {
                    throw new Error(result.error || 'Error desconocido al generar PDF');
                }

            } catch (error) {
                clearInterval(progressInterval);
                console.error('🔍 VORAZ: Error completo:', error);

                alert(`❌ Error al generar PDF unificado:\n\n${error.message}\n\n` +
                    `Revisa la consola del navegador (F12) para más detalles.`);

            } finally {
                // Asegurar que se quite el loading
                const loadingElement = document.getElementById('voraz-unified-loading');
                if (loadingElement && loadingElement.parentNode) {
                    document.body.removeChild(loadingElement);
                }
            }
        }


        function clearBulkSearch() {
            document.getElementById('bulkInput').value = '';
            document.getElementById('extractedCodesPreview').classList.add('hidden');
            document.getElementById('bulkResults').classList.add('hidden');
        }
        // ============ Highlighter Modal Function ============
        function openHighlighter(url) {
            // Show modal
            const modal = document.getElementById('highlighterModal');
            modal.classList.remove('hidden');

            // Open in new tab
            window.open(url, '_blank');

            // Auto-hide modal after 3 seconds
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 3000);
        }

        /**
         * Abre el viewer con navegación entre múltiples documentos
         * Resalta TODOS los códigos buscados en cada documento
         */
        function voraz_openMultiViewer(documents, allCodes) {
            // Crear estructura de datos para el viewer

            const viewerData = {
                documents: documents.map(d => ({
                    ruta: d.ruta_archivo, // Usar ruta_archivo como espera el viewer
                    ...d
                })),
                searchCodes: allCodes,
                currentIndex: 0
            };

            // Guardar en sessionStorage para que el viewer los reciba
            sessionStorage.setItem('voraz_viewer_data', JSON.stringify(viewerData));

            // Abrir el viewer especial para búsqueda voraz
            const firstDoc = documents[0];
            const queryParams = new URLSearchParams({
                // El viewer espera 'doc' (ID) o 'file' (ruta). Usaremos doc ID si es posible para mantener consistencia,
                // pero la logica nueva del viewer usa 'file' para los siguientes. 
                // Usaremos la logica existente para el primero.
                doc: firstDoc.id,
                codes: allCodes.join(','),
                mode: 'voraz_multi',
                total: documents.length
            });

            openHighlighter(`modules/resaltar/viewer.php?${queryParams.toString()}`);
        }

        /**
         * Genera un PDF unificado combinando todos los documentos
         * y resalta todos los códigos buscados
         */

    