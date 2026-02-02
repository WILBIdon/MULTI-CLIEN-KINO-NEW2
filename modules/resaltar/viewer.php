<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 * 
 * Optimized Version:
 * - NO server-side extraction (too slow on Windows without pdftotext).
 * - Client-side ONLY highlighting.
 * - Lazy Loading for pages.
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
// Removed: require_once __DIR__ . '/../../helpers/pdf_extractor.php'; // Not needed anymore

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

// Unificar códigos a resaltar
$termsToHighlight = [];

// 1. Añadir 'term' individual (ahora soporta múltiples términos separados por espacio/enter)
if (!empty($searchTermInput)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_merge($termsToHighlight, $splitTerms);
    }
}

// 2. Procesar lista de 'codes' (comma, newline, tab separated)
if (!empty($codesInput)) {
    // Si es array (url query array), usalo directo. Si es string, split.
    if (is_array($codesInput)) {
        $termsToHighlight = array_merge($termsToHighlight, $codesInput);
    } else {
        // Soporta comas, saltos de línea, tabs, punto y coma (pero NO espacios, para soportar códigos con espacio)
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes) {
            $termsToHighlight = array_merge($termsToHighlight, $splitCodes);
        }
    }
}

// Limpiar y deducplicar
$termsToHighlight = array_unique(array_filter(array_map('trim', $termsToHighlight)));
$searchTerm = implode(' ', $termsToHighlight); // Fallback

// ⭐ NUEVA LÓGICA STRICT MODE
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_GET['voraz_mode']) ? 'voraz_multi' : 'single');
$strictMode = isset($_GET['strict_mode']) && $_GET['strict_mode'] === 'true';

$hits = [];
$context = [];

if ($strictMode) {
    // 'term' son los HITS (Naranja)
    // 'codes' son TODOS (Verde)
    // Entonces Contexto = Todos - Hits

    // Parsear hits desde 'term'
    if (!empty($searchTermInput)) {
        $hits = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    }

    // Parsear todos desde 'codes'
    $allCodes = [];
    if (!empty($codesInput)) {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes)
            $allCodes = $splitCodes;
    }

    $hits = array_unique(array_filter(array_map('trim', $hits)));
    $allCodes = array_unique(array_filter(array_map('trim', $allCodes)));

    // Contexto = Todo lo que está en allCodes pero NO en hits
    $context = array_diff($allCodes, $hits);

    // En strict mode, $termsToHighlight será la unión para propósitos generales,
    // pero pasaremos hits y context separados a JS.
} else {
    // Modo clásico: todo es igual
    $hits = [];
    $context = $termsToHighlight;
}
$totalDocs = isset($_GET['total']) ? (int) $_GET['total'] : 1;
$downloadUrl = isset($_GET['download']) ? $_GET['download'] : '';

if ($documentId <= 0 && empty($fileParam)) {
    die('ID de documento inválido o archivo no especificado');
}

$uploadsDir = CLIENTS_DIR . "/{$clientCode}/uploads/";
$pdfPath = null;
$document = [];

if ($documentId > 0) {
    // Get document info from DB
    $stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        die('Documento no encontrado');
    }

    // Use centralized robust path resolution
    $pdfPath = resolve_pdf_path($clientCode, $document);
} else {
    // Use file param directly (Unified or Manual Path)
    $pdfPath = $uploadsDir . $fileParam;

    // Mock document info
    $document = [
        'id' => 0,
        'tipo' => ($mode === 'unified' ? 'Resumen' : 'PDF'),
        'numero' => ($mode === 'unified' ? 'PDF Unificado' : basename($fileParam)),
        'fecha' => date('Y-m-d'),
        'ruta_archivo' => $fileParam
    ];
}

if (!$pdfPath || !file_exists($pdfPath)) {
    // Debug info if not found
    $available = get_available_folders($clientCode);
    $foldersStr = implode(', ', $available);
    die("Archivo PDF no encontrado. <br>Ruta intentada: " . htmlspecialchars($pdfPath ?? 'NULL') . "<br>Carpetas disponibles: [$foldersStr]");
}

// For sidebar
$currentModule = 'resaltar';
$baseUrl = '../../';
$pageTitle = 'Visor con Resaltado';

// La validación contra BD fue eliminada para confiar puramente en la extracción del PDF.
$validationWarning = '';

// OPTIMIZATION: REMOVED SERVER SIDE EXTRACTION
// We rely 100% on client side Mark.js
$pagesWithMatches = []; // Will be populated by JS
$matchCount = 0; // Will be populated by JS

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
            min-height: 500px;
            /* Ensure space for scrolling */
        }

        .pdf-page-wrapper {
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            background: white;
            margin-bottom: 1rem;
            /* Placeholder size until loaded */
            min-height: 800px;
            min-width: 600px;
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
            opacity: 1;
            line-height: 1;
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
        }

        .text-layer mark {
            background: rgba(50, 255, 50, 0.5);
            color: transparent;
            padding: 2px;
            border-radius: 2px;
            mix-blend-mode: multiply;
        }

        /* Highlight Styles */
        .highlight-hit {
            background-color: #ff9f43 !important;
            /* Orange for hits */
            box-shadow: 0 0 2px rgba(0, 0, 0, 0.5);
        }

        .highlight-context {
            background-color: rgba(50, 255, 50, 0.5) !important;
            /* Green for context */
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
                background: rgba(50, 255, 50, 0.5) !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        /* Print Modal */
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
            background: var(--bg-primary);
            padding: 2rem;
            border-radius: var(--radius-lg);
            max-width: 500px;
            width: 90%;
            box-shadow: var(--shadow-xl);
        }

        .print-modal h3 {
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .print-modal p {
            margin-bottom: 1.5rem;
            color: var(--text-secondary);
        }

        .print-modal-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .print-modal-buttons button {
            flex: 1;
            min-width: 120px;
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

        /* Voraz Navigation & Control Styles */
        .voraz-navigation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 2px solid #667eea;
            margin-bottom: 10px;
            border-radius: var(--radius-md);
        }

        .nav-btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .nav-btn:hover {
            background: #764ba2;
            transform: scale(1.05);
        }

        .nav-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        #doc-counter {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .unified-download {
            text-align: center;
            padding: 15px;
            background: rgba(240, 147, 251, 0.1);
            border: 1px solid #f093fb;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .btn-download-unified {
            padding: 12px 30px;
            background: white;
            color: #f5576c;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: inline-block;
            transition: all 0.3s;
            border: 1px solid #f5576c;
        }

        .btn-download-unified:hover {
            background: #f5576c;
            color: white;
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
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
                        <?php if ($mode === 'voraz_multi'): ?>
                            <div class="voraz-navigation">
                                <button onclick="navigateVorazDoc(-1)" id="btn-prev-doc" class="nav-btn">
                                    ◀
                                </button>
                                <span id="doc-counter"><span id="current-doc">1</span>/<?= $totalDocs ?></span>
                                <button onclick="navigateVorazDoc(1)" id="btn-next-doc" class="nav-btn">
                                    ▶
                            </div>
                        <?php endif; ?>

                        <!-- Match Navigation -->
                        <?php if ($strictMode || !empty($termsToHighlight)): ?>
                            <div class="voraz-navigation"
                                style="background: #ffffff; border-color: #ff9f43; flex-direction: column; gap: 10px;">
                                <h4 style="margin:0; font-size: 0.9rem; color: #d97706;">Navegación de Hallazgos</h4>
                                <div style="display: flex; gap: 10px; width: 100%;">
                                    <button onclick="jumpToMatch(-1)" class="nav-btn"
                                        style="flex: 1; background: #fbbf24; color: #78350f;">
                                        ⬆ Anterior
                                    </button>
                                    <button onclick="jumpToMatch(1)" class="nav-btn"
                                        style="flex: 1; background: #fbbf24; color: #78350f;">
                                        ⬇ Siguiente
                                    </button>
                                </div>
                                <div id="match-status" style="font-size: 0.8rem; text-align: center; color: #b45309;">
                                    Buscando...
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($mode === 'unified' && $downloadUrl): ?>
                            <div class="unified-download">
                                <a href="<?= htmlspecialchars($downloadUrl) ?>" download class="btn-download-unified">
                                    📥 Descargar Unificado
                                </a>
                            </div>
                        <?php endif; ?>

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
                                <?php if (isset($_GET['voraz_mode'])): ?>
                                    <input type="hidden" name="voraz_mode" value="true">
                                <?php endif; ?>
                                <?php if (isset($_GET['highlight_all'])): ?>
                                    <input type="hidden" name="highlight_all" value="true">
                                <?php endif; ?>
                                <textarea name="term" rows="6"
                                    style="width: 100%; border-radius: 6px; border: 1px solid #d1d5db; padding: 0.5rem; font-family: monospace;"
                                    placeholder="Buscar texto (uno por línea)..."><?= htmlspecialchars(implode("\n", $termsToHighlight)) ?></textarea>
                                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                                    🔍 Buscar y Resaltar
                                </button>
                            </form>
                        </div>

                        <?php if (!empty($searchTerm)): ?>
                            <div class="match-badge" id="matchBadge">
                                <span>⌛</span>
                                <span id="matchText">Calculando...</span>
                            </div>
                            <!-- Search Summary Container -->
                            <div id="searchSummary"
                                style="background: white; padding: 10px; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 0.85rem; max-height: 300px; overflow-y: auto;">
                                <p style="margin: 0 0 5px 0; font-weight: bold; color: #6b7280;">Procesando búsqueda...</p>
                                <div id="summaryList"></div>
                            </div>
                        <?php endif; ?>

                        <button class="btn-print" onclick="showPrintModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Imprimir Documento
                        </button>

                        <a href="<?= $pdfUrl ?>"
                            download="<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $document['numero'])) ?>.pdf"
                            class="btn btn-secondary" style="width: 100%; text-align: center;">
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

    <!-- Print Modal -->
    <div class="print-modal" id="printModal">
        <div class="print-modal-content">
            <h3>📄 Imprimir Documento</h3>
            <p>¿Qué deseas imprimir?</p>
            <div class="print-modal-buttons">
                <button class="btn btn-primary" onclick="printFullDocument()">
                    📄 Documento Completo
                </button>
                <button class="btn btn-success" style="background: #038802;" onclick="printHighlightedPages()">
                    🖍️ Solo Páginas Resaltadas
                </button>
                <button class="btn btn-secondary" onclick="closePrintModal()">
                    ✕ Cancelar
                </button>
            </div>
        </div>
    </div>

    <script>
        // Initialize PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // Variables Globales
        const viewerMode = '<?= $mode ?>';
        const isStrictMode = <?= $strictMode ? 'true' : 'false' ?>;
        const hits = <?= json_encode(array_values($hits)) ?>.map(String);
        const context = <?= json_encode(array_values($context)) ?>.map(String);

        let vorazData = null;
        let currentDocIndex = 0;

        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        // const searchTerm = '<?= addslashes($searchTerm) ?>'; // Deprecated in favor of hits/context

        let totalMatchesFound = 0;
        let pagesWithMatches = []; // List of page numbers with HITS
        let currentMatchIndex = -1; // Index in pagesWithMatches

        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;

        // --- Voraz Navigation ---
        if (viewerMode === 'voraz_multi') {
            const stored = sessionStorage.getItem('voraz_viewer_data');
            if (stored) {
                vorazData = JSON.parse(stored);
                currentDocIndex = vorazData.currentIndex || 0;
                const currentDocSpan = document.getElementById('current-doc');
                if (currentDocSpan) currentDocSpan.textContent = currentDocIndex + 1;
            }
        }

        function navigateVorazDoc(direction) {
            if (!vorazData) return;
            const newIndex = currentDocIndex + direction;
            if (newIndex < 0 || newIndex >= vorazData.documents.length) return;

            currentDocIndex = newIndex;
            vorazData.currentIndex = currentDocIndex;
            sessionStorage.setItem('voraz_viewer_data', JSON.stringify(vorazData));

            const doc = vorazData.documents[currentDocIndex];
            const params = new URLSearchParams(window.location.search);
            if (doc.id) params.set('doc', doc.id);
            if (doc.ruta_archivo) params.set('file', doc.ruta_archivo);
            window.location.search = params.toString();
        }

        // --- Render Logic with Lazy Load ---
        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;
                container.innerHTML = ''; // Clear loading spinner

                // Create placeholders for all pages immediately
                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    createPagePlaceholder(pageNum);
                }

                // Setup Intersection Observer for Lazy Loading
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const pageNum = parseInt(entry.target.dataset.pageNum);
                            // Render if not already rendered
                            if (!entry.target.dataset.rendered) {
                                renderPage(pageNum, entry.target);
                                entry.target.dataset.rendered = 'true';
                            }
                        }
                    });
                }, {
                    root: null,
                    rootMargin: '200px', // Pre-load 200px before appearing
                    threshold: 0.1
                });

                // Observe all page wrappers
                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

                // If it's a short document (e.g. < 5 pages), force render them all immediately to verify matches faster
                if (numPages <= 5) {
                    for (let i = 1; i <= numPages; i++) {
                        const el = document.getElementById('page-' + i);
                        if (el && !el.dataset.rendered) {
                            renderPage(i, el);
                            el.dataset.rendered = 'true';
                        }
                    }
                }

                // ⭐ INICIAR ESCANEO DE FONDO PARA RESUMEN
                scanAllPagesForSummary();

            } catch (error) {
                console.error("PDF Load Error:", error);
                container.innerHTML = `<p style='color:red;'>Error al cargar PDF: ${error.message}</p>`;
            }
        }

        // Función de escaneo en segundo plano para el reporte Found/Not Found
        async function scanAllPagesForSummary() {
            const summaryDiv = document.getElementById('searchSummary');
            if (!summaryDiv) return;

            // Obtener términos (PHP terms)
            // Obtener términos (PHP terms)
            const terms = <?= json_encode(array_values($termsToHighlight)) ?>.map(String);
            if (!terms || terms.length === 0) {
                summaryDiv.innerHTML = '<p class="text-muted">Sin términos de búsqueda.</p>';
                return;
            }

            const foundSet = new Set();
            const notFoundSet = new Set(terms);

            // Escanear página por página (solo texto, sin renderizar canvas)
            document.getElementById('summaryList').innerHTML = '<span style="color:orange">Analizando todo el PDF...</span>';

            for (let i = 1; i <= pdfDoc.numPages; i++) {
                try {
                    const textContent = await pdfDoc.getPage(i).getTextContent();
                    const pageText = textContent.items.map(s => s.str).join(' '); // Unir con espacios

                    // Chequear cada término pendiente (solo para reporte found/not found)
                    // Y detectar si la página tiene algún HIT
                    let pageHasHit = false;

                    // Chequear hits (prioridad)
                    hits.forEach(term => {
                        if (checkTermMatch(term, pageText)) {
                            foundSet.add(term);
                            pageHasHit = true;
                        }
                    });

                    if (pageHasHit) {
                        pagesWithMatches.push(i);
                    }

                    // Chequear contexto y actualizar notFoundSet
                    // (Si ya encontramos un termino en hits, ya está en foundSet, pero 
                    // si hay terminos en contexto que NO están en hits, chequearlos)
                    notFoundSet.forEach(term => {
                        if (checkTermMatch(term, pageText)) {
                            foundSet.add(term);
                            notFoundSet.delete(term);
                        }
                    });

                    // Si ya encontramos todos, parar temprano
                    if (notFoundSet.size === 0) break;

                } catch (e) {
                    console.error("Error scanning page " + i, e);
                }
            }

            // Actualizar UI Final
            let html = '';

            // Encontrados
            if (foundSet.size > 0) {
                html += `<div style="margin-bottom:8px;"><strong style="color:green">✅ Encontrados (${foundSet.size}):</strong><br>`;
                foundSet.forEach(t => html += `<span style="background:#dcfce7; color:#166534; padding:1px 4px; border-radius:3px; font-size:0.75rem; margin-right:2px; display:inline-block; margin-bottom:2px;">${t}</span> `);
                html += `</div>`;
            }

            // No encontrados
            if (notFoundSet.size > 0) {
                html += `<div><strong style="color:red">❌ No Encontrados (${notFoundSet.size}):</strong><br`;
                notFoundSet.forEach(t => html += `<span style="background:#fee2e2; color:#991b1b; padding:1px 4px; border-radius:3px; font-size:0.75rem; margin-right:2px; display:inline-block; margin-bottom:2px;">${t}</span> `);
                html += `</div>`;
            }

            if (foundSet.size === 0 && notFoundSet.size === 0) {
                html = '<p>Búsqueda vacía.</p>';
            }

            document.getElementById('summaryList').innerHTML = html;

            // Update Match Navigation
            const matchStatus = document.getElementById('match-status');
            if (matchStatus) {
                if (pagesWithMatches.length > 0) {
                    pagesWithMatches.sort((a, b) => a - b);
                    matchStatus.textContent = `${pagesWithMatches.length} páginas con hallazgos`;
                } else {
                    matchStatus.textContent = "Sin hallazgos";
                }
            }
        }

        function checkTermMatch(term, text) {
            if (!term || term.length < 2) return false;

            // 1. Intentar búsqueda exacta primero (más rápida)
            if (text.includes(term)) return true;

            // ⭐ Lógica Estricta vs Flexible
            if (isStrictMode) {
                // En modo estricto, solo permitimos diferencias de espacios trimming
                // O quizás una normalización muy suave (minusculas)
                return text.toLowerCase().includes(term.toLowerCase());
            }

            // 2. Búsqueda flexible "Limpia"
            const cleanTerm = term.replace(/[^a-z0-9]/gi, '');
            const cleanText = text.replace(/[^a-z0-9]/gi, '');

            return cleanText.includes(cleanTerm);
        }

        function jumpToMatch(direction) {
            if (pagesWithMatches.length === 0) {
                alert("No se encontraron coincidencias para navegar.");
                return;
            }

            // Encontrar la siguiente página con match relativa al scroll actual
            // O simplemente iterar el array pagesWithMatches

            currentMatchIndex += direction;

            if (currentMatchIndex < 0) currentMatchIndex = pagesWithMatches.length - 1;
            if (currentMatchIndex >= pagesWithMatches.length) currentMatchIndex = 0;

            const targetPage = pagesWithMatches[currentMatchIndex];
            const pageEl = document.getElementById('page-' + targetPage);

            if (pageEl) {
                pageEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('match-status').textContent = `Página ${targetPage} (${currentMatchIndex + 1}/${pagesWithMatches.length})`;
            }
        }

        function createPagePlaceholder(pageNum) {
            const pageContainer = document.createElement('div');

            const wrapper = document.createElement('div');
            wrapper.className = 'pdf-page-wrapper';
            wrapper.id = 'page-' + pageNum;
            wrapper.dataset.pageNum = pageNum;
            // Initial dummy size, will update on render
            wrapper.style.marginBottom = '20px';

            // Add skeleton loading effect
            wrapper.innerHTML = `
                <div style="display:flex; justify-content:center; align-items:center; height:100%; color:#999;">
                    Cargando página ${pageNum}...
                </div>`;

            const pageLabel = document.createElement('div');
            pageLabel.className = 'page-number';
            pageLabel.textContent = `Página ${pageNum}`;

            pageContainer.appendChild(wrapper);
            pageContainer.appendChild(pageLabel);
            container.appendChild(pageContainer);
        }

        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                wrapper.innerHTML = ''; // Clear placeholder
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';

                // Canvas
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                wrapper.appendChild(canvas);

                await page.render({
                    canvasContext: context,
                    viewport: viewport
                }).promise;

                // ⭐ Highlight logic
                const instance = new Mark(canvas.parentElement);

                // Opción 1: Strict Mode (Dual Color)
                if (isStrictMode) {
                    if (hits.length > 0) {
                        instance.mark(hits, {
                            element: "mark",
                            className: "highlight-hit", // Orange
                            accuracy: "partially",
                            separateWordSearch: false
                        });
                    }
                    if (context.length > 0) {
                        instance.mark(context, {
                            element: "mark",
                            className: "highlight-context", // Green
                            accuracy: "partially",
                            separateWordSearch: false
                        });
                    }
                } else {
                    // Opción 2: Modo Clásico (Todos Verde/Default)
                    const allParams = hits.concat(context);
                    if (allParams.length > 0) {
                        instance.mark(allParams, {
                            accuracy: "partially",
                            separateWordSearch: false
                        });
                    }
                }


                // Text Layer
                const textContent = await page.getTextContent();
                const textLayer = document.createElement('div');
                textLayer.className = 'text-layer';
                textLayer.style.width = viewport.width + 'px';
                textLayer.style.height = viewport.height + 'px';
                textLayer.style.setProperty('--scale-factor', scale);

                // Populate text layer logic (simplified version of previous loop)
                textContent.items.forEach(item => {
                    const span = document.createElement('span');
                    const tx = item.transform; // [sx, ky, kx, sy, tx, ty]
                    const fontHeight = Math.sqrt((tx[0] * tx[0]) + (tx[1] * tx[1]));
                    const scaledFontSize = fontHeight * scale;
                    const x = tx[4] * scale;
                    const y = viewport.height - (tx[5] * scale) - scaledFontSize;
                    const width = item.width * scale;

                    span.textContent = item.str;
                    span.style.left = x + 'px';
                    span.style.top = y + 'px';
                    span.style.fontSize = scaledFontSize + 'px';
                    span.style.fontFamily = item.fontName || 'sans-serif';
                    span.style.width = Math.ceil(width) + 'px';

                    textLayer.appendChild(span);
                });

                wrapper.appendChild(textLayer);



            } catch (err) {
                console.error(`Page ${pageNum} Render Error:`, err);
                wrapper.innerHTML = `<p style="color:red">Error rendering page ${pageNum}</p>`;
            }
        }



        // Start
        loadPDF();

        // --- Print Logic ---
        function showPrintModal() {
            document.getElementById('printModal').classList.add('active');
        }

        function closePrintModal() {
            document.getElementById('printModal').classList.remove('active');
        }

        function printFullDocument() {
            window.print();
            closePrintModal();
        }

        function printHighlightedPages() {
            // Basic print for now, as hiding pages dynamically in CSS print media 
            // without re-rendering is tricky with Lazy Loading (unrendered pages are empty).
            // Strategy: Force render matched pages if not rendered? 
            // For now, warn user if pages aren't loaded.
            alert('Imprimiendo: Asegúrate de haber visualizado las páginas resaltadas para que se carguen.');

            // Add a class that hides non-matched pages in print
            const style = document.createElement('style');
            style.id = 'print-filter-style';

            let css = '@media print { .pdf-page-wrapper { display: none; } ';
            if (pagesWithMatches.length > 0) {
                pagesWithMatches.forEach(p => {
                    css += `#page-${p} { display: block !important; } `;
                });
            } else {
                // Fallback if no matches found yet
                css += '.pdf-page-wrapper { display: block !important; }';
            }
            css += '}';

            document.head.appendChild(style);

            window.print();

            // Clean up
            setTimeout(() => document.head.removeChild(style), 1000);
            closePrintModal();
        }

    </script>
</body>

</html>