<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 * Manteniendo funcionalidades de visor, resaltador y colores originales.
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);

$documentId = isset($_GET['doc']) ? (int) $_GET['doc'] : 0;
$searchTermInput = isset($_GET['term']) ? trim($_GET['term']) : '';
$codesInput = isset($_GET['codes']) ? $_GET['codes'] : '';
$fileParam = isset($_GET['file']) ? $_GET['file'] : '';

$termsToHighlight = [];
if (!empty($searchTermInput)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms)
        $termsToHighlight = array_merge($termsToHighlight, $splitTerms);
}

$codesInputStr = '';
if (!empty($codesInput)) {
    if (is_array($codesInput)) {
        $termsToHighlight = array_merge($termsToHighlight, $codesInput);
        $codesInputStr = implode(',', $codesInput);
    } else {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes)
            $termsToHighlight = array_merge($termsToHighlight, $splitCodes);
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
    if (!empty($searchTermInput))
        $hits = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
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

$pdfPath = null;
$document = [];
$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";

if ($documentId > 0) {
    $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document)
        die('Documento no encontrado');
    $pdfPath = resolve_pdf_path($clientCode, $document);
} else {
    $pdfPath = $uploadsDir . $fileParam;
    $document = ['id' => 0, 'tipo' => 'PDF', 'numero' => basename($fileParam)];
}

if (!$pdfPath || !file_exists($pdfPath))
    die("Archivo no encontrado.");

$relativePath = str_replace($uploadsDir, '', $pdfPath);
$pdfUrl = '../../clients/' . $clientCode . '/uploads/' . $relativePath;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Visor - <?= htmlspecialchars($document['numero']) ?></title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mark.js/dist/mark.min.js"></script>
    <style>
        .viewer-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.5rem;
        }

        .viewer-sidebar {
            background: var(--bg-secondary);
            padding: 1.25rem;
            position: sticky;
            top: 80px;
            height: fit-content;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
        }

        .viewer-main {
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
        }

        .pdf-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .pdf-page-wrapper {
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            mix-blend-mode: multiply;
        }

        .text-layer span {
            position: absolute;
            color: transparent;
            white-space: pre;
            cursor: text;
        }

        /* Conservando colores originales */
        .highlight-hit {
            background-color: rgba(34, 197, 94, 0.5) !important;
            border-bottom: 2px solid #15803d;
        }

        .highlight-context {
            background-color: rgba(134, 239, 172, 0.4) !important;
        }

        @media print {
            @page {
                margin: 0 !important;
                size: auto;
            }

            /* 1. Eliminar bola/spinner y UI */
            nav,
            header,
            aside,
            .sidebar,
            .main-header,
            .viewer-sidebar,
            .app-footer,
            .print-modal,
            .page-number,
            .voraz-navigation,
            .doc-info,
            #simpleStatus,
            .search-form,
            .spinner,
            .loading-pages {
                display: none !important;
                visibility: hidden !important;
            }

            /* 2. Limpieza de contenedores para evitar hojas blancas */
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

            /* 3. Ocultar páginas no renderizadas (Evita hojas blancas intermedias) */
            .pdf-page-wrapper:not([data-rendered="true"]) {
                display: none !important;
            }

            .page-outer-wrapper {
                display: block !important;
                page-break-after: always !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .pdf-page-wrapper {
                box-shadow: none !important;
                border: none !important;
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
                        <form method="GET">
                            <input type="hidden" name="doc" value="<?= $documentId ?>">
                            <textarea name="term" rows="8"
                                style="width:100%"><?= htmlspecialchars(implode("\n", $termsToHighlight)) ?></textarea>
                            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:10px;">🔄
                                Actualizar</button>
                        </form>
                        <button class="btn btn-primary" onclick="window.print()"
                            style="width:100%; margin-top:20px;">🖨️ Imprimir</button>
                    </div>
                    <div class="viewer-main">
                        <div id="pdfContainer" class="pdf-container">
                            <div class="loading-pages">
                                <div class="spinner"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        const hits = <?= json_encode(array_values($hits)) ?>;
        const context = <?= json_encode(array_values($context)) ?>;
        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const container = document.getElementById('pdfContainer');
        let pdfDoc = null;

        async function loadPDF() {
            pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
            container.innerHTML = '';
            for (let i = 1; i <= pdfDoc.numPages; i++) {
                const div = document.createElement('div');
                div.className = "page-outer-wrapper";
                div.innerHTML = `<div id="page-${i}" class="pdf-page-wrapper" data-page-num="${i}" style="min-height:800px;"></div>`;
                container.appendChild(div);
            }
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !entry.target.dataset.rendered) renderPage(parseInt(entry.target.dataset.pageNum), entry.target);
                });
            }, { rootMargin: '600px' });
            document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));
        }

        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale: 1.5 });
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

                // Marcar como renderizado para la impresora y resetear altura
                wrapper.setAttribute('data-rendered', 'true');
                wrapper.style.minHeight = "auto";
            } catch (err) { console.error(err); }
        }
        loadPDF();
    </script>
</body>

</html>