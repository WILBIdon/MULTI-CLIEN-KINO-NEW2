<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

// Get parameters
$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
$searchTermInput = isset($_GET['term']) ? trim($_GET['term']) : '';
$codesInput = isset($_GET['codes']) ? $_GET['codes'] : '';
$fileParam = isset($_GET['file']) ? $_GET['file'] : '';

// --- LÓGICA DE PROCESAMIENTO DE TÉRMINOS ---
$termsToHighlight = [];

if (!empty($searchTermInput)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_merge($termsToHighlight, $splitTerms);
    }
}

$codesInputStr = '';
if (!empty($codesInput)) {
    if (is_array($codesInput)) {
        $termsToHighlight = array_merge($termsToHighlight, $codesInput);
        $codesInputStr = implode(',', $codesInput);
    } else {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes) {
            $termsToHighlight = array_merge($termsToHighlight, $splitCodes);
        }
        $codesInputStr = $codesInput;
    }
}

$termsToHighlight = array_unique(array_filter(array_map('trim', $termsToHighlight)));
$searchTerm = implode(' ', $termsToHighlight);

$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_GET['voraz_mode']) ? 'voraz_multi' : 'single');
$strictMode = isset($_GET['strict_mode']) && $_GET['strict_mode'] === 'true';

$hits = [];
$context = [];

if ($strictMode) {
    if (!empty($searchTermInput)) {
        $hits = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    }
    $allCodes = [];
    if (!empty($codesInput)) {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes)
            $allCodes = $splitCodes;
    }
    $hits = array_unique(array_filter(array_map('trim', $hits)));
    $allCodes = array_unique(array_filter(array_map('trim', $allCodes)));
    $context = array_diff($allCodes, $hits);
} else {
    $hits = [];
    $context = $termsToHighlight;
}

// --- GESTIÓN DE DOCUMENTO ---
$totalDocs = isset($_GET['total']) ? (int) $_GET['total'] : 1;
$downloadUrl = isset($_GET['download']) ? $_GET['download'] : '';

if ($documentId <= 0 && empty($fileParam)) {
    die('ID de documento inválido o archivo no especificado');
}

$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$pdfPath = null;
$document = [];

if ($documentId > 0) {
    $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document)
        die('Documento no encontrado');
    $pdfPath = resolve_pdf_path($clientCode, $document);
} else {
    $pdfPath = $uploadsDir . $fileParam;
    $document = [
        'id' => 0,
        'tipo' => ($mode === 'unified' ? 'Resumen' : 'PDF'),
        'numero' => ($mode === 'unified' ? 'PDF Unificado' : basename($fileParam)),
        'fecha' => date('Y-m-d'),
        'ruta_archivo' => $fileParam
    ];
}

if (!$pdfPath || !file_exists($pdfPath)) {
    die("Archivo PDF no encontrado.");
}

$relativePath = str_replace($uploadsDir, '', $pdfPath);
$baseUrl = '../../';
$pdfUrl = $baseUrl . 'clients/' . $clientCode . '/uploads/' . $relativePath;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visor Resaltado - <?= htmlspecialchars($document['numero']) ?></title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mark.js/dist/mark.min.js"></script>
    <style>
        /* --- ESTILOS DE PANTALLA --- */
        .viewer-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
            min-height: calc(100vh - 200px);
        }

        .viewer-sidebar {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            height: fit-content;
            position: sticky;
            top: 80px;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
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
            min-height: 500px;
        }

        .pdf-page-wrapper {
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: white;
            margin-bottom: 1rem;
        }

        .text-layer {
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            opacity: 1;
            line-height: 1;
            mix-blend-mode: multiply;
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
            cursor: text;
        }

        .highlight-hit {
            background-color: rgba(34, 197, 94, 0.5) !important;
            border-bottom: 2px solid #15803d;
        }

        .highlight-context {
            background-color: rgba(134, 239, 172, 0.4) !important;
        }

        .btn-print {
            width: 100%;
            padding: 0.8rem;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 0.75rem;
        }

        .print-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .print-modal.active {
            display: flex;
        }

        .print-modal-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        /* --- SOLUCIÓN DEFINITIVA DE IMPRESIÓN --- */
        @media print {
            @page {
                margin: 0 !important;
                size: auto;
            }

            /* Elimina el spinner (bola), sidebar, botones y encabezados */
            nav,
            .main-header,
            .sidebar,
            .viewer-sidebar,
            .app-footer,
            .print-modal,
            .page-number,
            .voraz-navigation,
            .doc-info,
            #simpleStatus,
            .search-form,
            .btn-print,
            .btn-secondary,
            .loading-pages,
            .spinner {
                display: none !important;
                height: 0 !important;
                visibility: hidden !important;
            }

            /* Resetea contenedores para evitar márgenes y hojas blancas iniciales */
            body,
            html,
            .dashboard-container,
            .main-content,
            .page-content,
            .viewer-container,
            .viewer-main,
            #pdfContainer {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                position: static !important;
                background: white !important;
            }

            /* Oculta esqueletos de páginas no cargadas (evita hojas blancas intermedias) */
            .pdf-page-wrapper:not([data-rendered="true"]) {
                display: none !important;
            }

            .page-outer-wrapper {
                display: block !important;
                position: relative !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-after: always !important;
                break-after: page !important;
            }

            .page-outer-wrapper:last-child {
                page-break-after: avoid !important;
            }

            .pdf-page-wrapper {
                margin: 0 auto !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid !important;
                width: 100% !important;
            }

            canvas {
                width: 100% !important;
                height: auto !important;
                display: block !important;
            }

            .text-layer {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        .page-outer-wrapper {
            margin-bottom: 2rem;
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
                    <div class="viewer-sidebar">
                        <h3>📄 Documento</h3>
                        <div class="doc-info">
                            <p><strong>Número:</strong> <?= htmlspecialchars($document['numero']) ?></p>
                        </div>
                        <div class="search-form">
                            <form method="GET">
                                <input type="hidden" name="doc" value="<?= $documentId ?>">
                                <input type="hidden" name="codes" value="<?= htmlspecialchars($codesInputStr) ?>">
                                <textarea name="term"
                                    rows="8"><?= htmlspecialchars(implode("\n", $termsToHighlight)) ?></textarea>
                                <button type="submit" class="btn btn-primary"
                                    style="width: 100%; margin-top: 0.5rem;">🔄 Actualizar</button>
                            </form>
                        </div>
                        <div id="simpleStatus" style="margin-top:15px;"></div>
                        <hr>
                        <button class="btn-print" onclick="showPrintModal()">🖨️ Imprimir</button>
                    </div>
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

    <div class="print-modal" id="printModal">
        <div class="print-modal-content">
            <h3>🖨️ Imprimir</h3>
            <button class="btn btn-primary" onclick="printFullDocument()">Todo el Documento</button>
            <button class="btn btn-secondary" onclick="closePrintModal()">Cancelar</button>
        </div>
    </div>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const hits = <?= json_encode(array_values($hits)) ?>;
        const context = <?= json_encode(array_values($context)) ?>;
        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;

        async function loadPDF() {
            pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
            container.innerHTML = '';
            for (let i = 1; i <= pdfDoc.numPages; i++) createPagePlaceholder(i);
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !entry.target.dataset.rendered) {
                        renderPage(parseInt(entry.target.dataset.pageNum), entry.target);
                    }
                });
            }, { rootMargin: '600px' });
            document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));
        }

        function createPagePlaceholder(pageNum) {
            const div = document.createElement('div');
            div.className = "page-outer-wrapper";
            div.innerHTML = `<div id="page-${pageNum}" class="pdf-page-wrapper" data-page-num="${pageNum}" style="min-height:800px;"></div>`;
            container.appendChild(div);
        }

        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';
                
                const canvas = document.createElement('canvas');
                canvas.height = viewport.height; canvas.width = viewport.width;
                wrapper.appendChild(canvas);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

                const textDiv = document.createElement('div');
                textDiv.className = 'text-layer';
                wrapper.appendChild(textDiv);
                const textContent = await page.getTextContent();
                await pdfjsLib.renderTextLayer({ textContent, container: textDiv, viewport, textDivs: [] }).promise;

                const instance = new Mark(textDiv);
                if (hits.length) instance.mark(hits, { className: "highlight-hit" });
                if (context.length) instance.mark(context, { className: "highlight-context" });

                // --- MARCA DE FINALIZACIÓN PARA IMPRESIÓN ---
                wrapper.setAttribute('data-rendered', 'true');
                wrapper.style.minHeight = "auto"; 
            } catch (err) { console.error(err); }
        }

        function showPrintModal() { document.getElementById('printModal').classList.add('active'); }
        function closePrintModal() { document.getElementById('printModal').classList.remove('active'); }
        function printFullDocument() { window.print(); closePrintModal(); }
        loadPDF();
    </script>
</body>

</html>