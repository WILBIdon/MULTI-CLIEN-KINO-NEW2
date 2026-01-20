<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 * 
 * This viewer:
 * - Receives a document_id and search_term
 * - Extracts text from the PDF
 * - Identifies pages containing the search term
 * - Renders only those pages with highlighted text
 * - Provides print-optimized CSS
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/pdf_extractor.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// Get parameters
$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

if ($documentId <= 0) {
    die('ID de documento inválido');
}

// Get document info
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    die('Documento no encontrado');
}

$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$rutaArchivo = $document['ruta_archivo'];

// Build the PDF path - try multiple possible locations
$pdfPath = null;
$possiblePaths = [
    $uploadsDir . $rutaArchivo,
    $uploadsDir . $document['tipo'] . '/' . $rutaArchivo,
    $uploadsDir . $document['tipo'] . '/' . basename($rutaArchivo),
];

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $pdfPath = $path;
        break;
    }
}

if (!$pdfPath) {
    die('Archivo PDF no encontrado. Rutas probadas: ' . implode(', ', array_map('basename', $possiblePaths)));
}

// For sidebar
$currentModule = 'resaltar';
$baseUrl = '../../';
$pageTitle = 'Visor con Resaltado';

// Extract text if search term provided
$pagesWithMatches = [];
$fullText = '';

if (!empty($searchTerm)) {
    $fullText = extract_text_from_pdf($pdfPath);
    if (!empty($fullText)) {
        // Find all positions of the search term
        $pos = 0;
        while (($pos = stripos($fullText, $searchTerm, $pos)) !== false) {
            $pagesWithMatches[] = $pos;
            $pos += strlen($searchTerm);
        }
    }
}

$matchCount = count($pagesWithMatches);

// Build the PDF URL - extract the relative path from the found pdfPath
$relativePath = str_replace($uploadsDir, '', $pdfPath);
$pdfUrl = $baseUrl . 'clients/' . $clientCode . '/uploads/' . $relativePath;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor Resaltado -
        <?= htmlspecialchars($document['numero']) ?>
    </title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mark.js/dist/mark.min.js"></script>
    <style>
        .viewer-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            min-height: calc(100vh - 200px);
        }

        @media (max-width: 900px) {
            .viewer-container {
                grid-template-columns: 1fr;
            }
        }

        .viewer-sidebar {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            height: fit-content;
            position: sticky;
            top: 80px;
        }

        .viewer-sidebar h3 {
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .viewer-main {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        .pdf-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .pdf-page-wrapper {
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: white;
            margin-bottom: 1rem;
        }

        .pdf-page-wrapper canvas {
            display: block;
        }

        .text-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            opacity: 0.2;
            line-height: 1;
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
        }

        .text-layer mark {
            background: #ffeb3b !important;
            color: transparent;
            padding: 2px;
            border-radius: 2px;
        }

        .page-number {
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .match-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 235, 59, 0.2);
            border: 1px solid #ffeb3b;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .doc-info {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .doc-info p {
            margin: 0.25rem 0;
        }

        .btn-print {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 0.75rem;
        }

        .btn-print:hover {
            background: var(--accent-primary-hover);
        }

        .highlight-controls {
            background: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .highlight-controls label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        /* Print styles */
        @media print {

            .sidebar,
            .main-header,
            .viewer-sidebar,
            .app-footer,
            .ai-chat-widget {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .viewer-container {
                display: block !important;
            }

            .viewer-main {
                border: none !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            .pdf-page-wrapper {
                page-break-after: always;
                box-shadow: none !important;
            }

            .text-layer mark {
                background: #ffff00 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        .loading-pages {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .search-form {
            margin-bottom: 1rem;
        }

        .search-form input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="viewer-container">
                    <!-- Sidebar Controls -->
                    <div class="viewer-sidebar">
                        <h3>📄 Documento</h3>

                        <div class="doc-info">
                            <p><strong>Tipo:</strong>
                                <?= strtoupper($document['tipo']) ?>
                            </p>
                            <p><strong>Número:</strong>
                                <?= htmlspecialchars($document['numero']) ?>
                            </p>
                            <p><strong>Fecha:</strong>
                                <?= htmlspecialchars($document['fecha']) ?>
                            </p>
                        </div>

                        <div class="search-form">
                            <form method="GET">
                                <input type="hidden" name="doc" value="<?= $documentId ?>">
                                <input type="text" name="term" value="<?= htmlspecialchars($searchTerm) ?>"
                                    placeholder="Buscar texto...">
                                <button type="submit" class="btn btn-primary" style="width: 100%;">
                                    🔍 Buscar y Resaltar
                                </button>
                            </form>
                        </div>

                        <?php if (!empty($searchTerm)): ?>
                            <div class="match-badge">
                                <span>🎯</span>
                                <span>
                                    <?= $matchCount ?> coincidencia(s) de "
                                    <?= htmlspecialchars($searchTerm) ?>"
                                </span>
                            </div>
                        <?php endif; ?>

                        <button class="btn-print" onclick="window.print()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Imprimir Documento
                        </button>

                        <a href="<?= $pdfUrl ?>" download class="btn btn-secondary"
                            style="width: 100%; text-align: center;">
                            📥 Descargar PDF
                        </a>
                    </div>

                    <!-- PDF Viewer -->
                    <div class="viewer-main">
                        <div id="pdfContainer" class="pdf-container">
                            <div class="loading-pages">
                                <div class="spinner"></div>
                                <p>Cargando documento...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        // Initialize PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const searchTerm = '<?= addslashes($searchTerm) ?>';
        const container = document.getElementById('pdfContainer');

        async function renderPDF() {
            try {
                const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdf.numPages;

                container.innerHTML = '';

                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const scale = 1.5;
                    const viewport = page.getViewport({ scale });

                    // Create page wrapper
                    const wrapper = document.createElement('div');
                    wrapper.className = 'pdf-page-wrapper';
                    wrapper.style.width = viewport.width + 'px';
                    wrapper.style.height = viewport.height + 'px';

                    // Create canvas
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    wrapper.appendChild(canvas);

                    // Render PDF page
                    await page.render({
                        canvasContext: context,
                        viewport: viewport
                    }).promise;

                    // Create text layer for highlighting
                    const textContent = await page.getTextContent();
                    const textLayer = document.createElement('div');
                    textLayer.className = 'text-layer';
                    textLayer.style.width = viewport.width + 'px';
                    textLayer.style.height = viewport.height + 'px';

                    textContent.items.forEach(item => {
                        const span = document.createElement('span');
                        const transform = item.transform;
                        const fontSize = Math.sqrt(transform[0] * transform[0] + transform[1] * transform[1]);

                        span.textContent = item.str;
                        span.style.left = transform[4] + 'px';
                        span.style.top = (viewport.height - transform[5] - fontSize) + 'px';
                        span.style.fontSize = fontSize + 'px';
                        span.style.fontFamily = item.fontName || 'sans-serif';

                        textLayer.appendChild(span);
                    });

                    wrapper.appendChild(textLayer);

                    // Add page number
                    const pageLabel = document.createElement('div');
                    pageLabel.className = 'page-number';
                    pageLabel.textContent = `Página ${pageNum} de ${numPages}`;

                    const pageContainer = document.createElement('div');
                    pageContainer.appendChild(wrapper);
                    pageContainer.appendChild(pageLabel);
                    container.appendChild(pageContainer);

                    // Apply highlighting if search term exists
                    if (searchTerm) {
                        const marker = new Mark(textLayer);
                        marker.mark(searchTerm, {
                            separateWordSearch: false,
                            accuracy: 'partially',
                            caseSensitive: false
                        });
                    }
                }
            } catch (error) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">⚠️</div>
                        <h4 class="empty-state-title">Error al cargar PDF</h4>
                        <p class="empty-state-text">${error.message}</p>
                    </div>
                `;
            }
        }

        renderPDF();
    </script>
</body>

</html>