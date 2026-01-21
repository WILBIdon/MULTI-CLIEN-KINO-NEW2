<?php
/**
 * KINO TRACE - Dashboard Principal
 * 
 * Landing page principal después del login.
 * Muestra el gestor de documentos con búsqueda inteligente.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/search_engine.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$stats = get_search_stats($db);

// Para sidebar
$currentModule = 'gestor';
$baseUrl = './';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* Dashboard Hero Section */
        .dashboard-hero {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .dashboard-hero h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .dashboard-hero p {
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Results styling */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .result-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            transition: all var(--transition-fast);
        }

        .result-card:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .result-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .result-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .codes-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .summary-box {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .summary-box.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-hero {
                padding: 1.5rem 1rem;
            }

            .dashboard-hero h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/includes/header.php'; ?>

            <div class="page-content">
                <!-- Hero Section -->
                <div class="dashboard-hero">
                    <h1>🔍 Gestor de Documentos - KINO TRACE</h1>
                    <p>Búsqueda inteligente de documentos por códigos. El sistema encuentra automáticamente todos los
                        documentos que contienen tus códigos.</p>
                </div>

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

                <!-- Search Interface -->
                <div class="card">
                    <h3 style="margin-bottom: 1rem;">🔍 Búsqueda Inteligente de Códigos</h3>
                    <p class="text-muted mb-4">Pega aquí tus códigos o bloque de texto. El sistema encontrará los
                        documentos que los contienen.</p>

                    <form id="searchForm">
                        <div class="form-group">
                            <textarea class="form-textarea" id="codesInput" rows="6" placeholder="ABC123
XYZ789
COD001
..."></textarea>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                Buscar
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearSearch()">Limpiar</button>
                        </div>
                    </form>

                    <div id="searchLoading" class="loading hidden">
                        <div class="spinner"></div>
                        <p>Buscando documentos...</p>
                    </div>

                    <div id="searchResults" class="hidden mt-4">
                        <div id="searchSummary"></div>
                        <div id="documentList" class="results-list"></div>
                    </div>
                </div>

                <!-- Quick Access Links -->
                <div class="card" style="margin-top: 1.5rem;">
                    <h4 style="margin-bottom: 1rem;">⚡ Accesos Rápidos</h4>
                    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <a href="modules/subir/" class="btn btn-secondary" style="justify-content: center;">
                            📤 Subir Documento
                        </a>
                        <a href="modules/excel_import/" class="btn btn-secondary" style="justify-content: center;">
                            📊 Importar Excel
                        </a>
                        <a href="modules/lote/" class="btn btn-secondary" style="justify-content: center;">
                            📦 Subida por Lote
                        </a>
                        <a href="modules/indexar/" class="btn btn-secondary" style="justify-content: center;">
                            🔄 Indexar PDFs
                        </a>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = 'api.php';
        const clientCode = '<?= $clientCode ?>';

        // ============ Search Form ============
        document.getElementById('searchForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const codes = document.getElementById('codesInput').value.trim();
            if (!codes) {
                alert('Ingresa al menos un código');
                return;
            }

            document.getElementById('searchLoading').classList.remove('hidden');
            document.getElementById('searchResults').classList.add('hidden');

            try {
                const formData = new FormData();
                formData.append('action', 'search');
                formData.append('codes', codes);

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                document.getElementById('searchLoading').classList.add('hidden');
                showSearchResults(result);
            } catch (error) {
                document.getElementById('searchLoading').classList.add('hidden');
                alert('Error en la búsqueda: ' + error.message);
            }
        });

        function showSearchResults(result) {
            document.getElementById('searchResults').classList.remove('hidden');

            const coveredCount = result.total_covered || 0;
            const totalSearched = result.total_searched || 0;
            const notFound = result.not_found || [];

            let summaryHtml = `
                <div class="summary-box${notFound.length > 0 ? ' warning' : ''}">
                    <strong>${coveredCount}/${totalSearched}</strong> códigos encontrados en 
                    <strong>${result.documents?.length || 0}</strong> documento(s)
                    ${notFound.length > 0 ? `
                        <div style="margin-top: 0.5rem;">
                            <span style="color: var(--accent-danger);">No encontrados:</span>
                            <div class="codes-list">
                                ${notFound.map(c => `<span class="code-tag" style="background: rgba(239,68,68,0.1); color: var(--accent-danger);">${c}</span>`).join('')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
            document.getElementById('searchSummary').innerHTML = summaryHtml;

            if (!result.documents || result.documents.length === 0) {
                document.getElementById('documentList').innerHTML = '<p class="text-muted">No se encontraron documentos.</p>';
                return;
            }

            let html = '';
            for (const doc of result.documents) {
                // Construir ruta del PDF
                let pdfUrl = '';
                if (doc.ruta_archivo) {
                    pdfUrl = doc.ruta_archivo.includes('/')
                        ? `clients/${clientCode}/uploads/${doc.ruta_archivo}`
                        : `clients/${clientCode}/uploads/${doc.tipo}/${doc.ruta_archivo}`;
                }

                // Primer código para resaltar
                const firstCode = (doc.matched_codes && doc.matched_codes[0]) || (doc.codes && doc.codes[0]) || '';

                html += `
                    <div class="result-card">
                        <div class="result-header">
                            <span class="badge badge-primary">${doc.tipo.toUpperCase()}</span>
                            <span class="result-meta">${doc.fecha}</span>
                        </div>
                        <div class="result-title">${doc.numero}</div>
                        <div class="result-meta">${doc.proveedor || ''}</div>
                        <div class="codes-list">
                            ${(doc.matched_codes || doc.codes || []).map(c => `<span class="code-tag">${c}</span>`).join('')}
                        </div>
                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            ${pdfUrl ? `<a href="modules/resaltar/viewer.php?doc=${doc.id}&term=${encodeURIComponent(firstCode)}" class="btn btn-primary" style="padding: 0.5rem 1rem;">👁️ Ver con Resaltado</a>` : ''}
                            <a href="modules/documento/view.php?id=${doc.id}" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📋 Ver Códigos</a>
                            ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="btn btn-secondary" style="padding: 0.5rem 1rem;">📄 PDF Original</a>` : ''}
                        </div>
                    </div>
                `;
            }
            document.getElementById('documentList').innerHTML = html;
        }

        function clearSearch() {
            document.getElementById('codesInput').value = '';
            document.getElementById('searchResults').classList.add('hidden');
        }
    </script>
</body>

</html>