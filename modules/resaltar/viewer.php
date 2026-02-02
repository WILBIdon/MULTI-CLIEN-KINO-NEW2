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
        /* Highlight Styles - Adjusted for transparency and green-only preference */
        .highlight-hit {
            background-color: rgba(34, 197, 94, 0.4) !important; /* Green transparent (Tailwind green-500 equivalent) */
            border-bottom: 2px solid #166534; /* Darker green underline for emphasis */
            color: transparent;
        }

        .highlight-context {
            background-color: rgba(34, 197, 94, 0.2) !important; /* Lighter green transparent */
            color: transparent;
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
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Match Navigation -->


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
                            <!-- Simple Status Area -->
                            <div id="simpleStatus" style="margin-top: 10px; font-size: 0.9rem; font-weight: 600;"></div>
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
        // Configuración de PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // --- Configuración Global ---
        const viewerMode = '<?= $mode ?>';
        const isStrictMode = <?= $strictMode ? 'true' : 'false' ?>;
        
        // Listas de términos (Hits y Contexto)
        const rawHits = <?= json_encode(array_values($hits)) ?>;
        const rawContext = <?= json_encode(array_values($context)) ?>;
        
        // Limpieza básica para listas
        const hits = rawHits.map(String).map(s => s.trim()).filter(s => s.length > 0);
        const context = rawContext.map(String).map(s => s.trim()).filter(s => s.length > 0);
        
        // Mapa maestro para control de estado
        // Clave: Texto normalizado (sin espacios/signos), Valor: Texto original
        let allTermsMap = new Map(); 
        [...hits, ...context].forEach(t => {
            // Normalización agresiva para coincidir con lo que verá el buscador
            allTermsMap.set(t.toLowerCase().replace(/[^a-z0-9]/g, ''), t);
        });

        // Estado del sistema
        let foundTermsSet = new Set(); // Términos confirmados
        let hasScrolledToFirstMatch = false; // Flag para evitar saltos locos
        
        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;

        // --- UI: Actualizar Estado ---
        function updateStatusUI(scanning = false) {
            const statusDiv = document.getElementById('simpleStatus');
            if (!statusDiv) return;

            // Calcular faltantes
            let missing = [];
            allTermsMap.forEach((originalTerm, normalizedKey) => {
                if (!foundTermsSet.has(normalizedKey)) {
                    missing.push(originalTerm);
                }
            });

            let html = '';
            
            if (missing.length === 0) {
                html = `
                    <div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; text-align:center; margin-top:10px; border:1px solid #86efac;">
                        ✅ <strong>Completo:</strong> Todos los códigos encontrados.
                    </div>`;
            } else {
                const statusText = scanning ? 'Analizando documento...' : 'Búsqueda finalizada';
                html = `
                    <div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-top:10px; border:1px solid #fecaca;">
                        <div style="margin-bottom:5px; font-size:0.85em; text-transform:uppercase; opacity:0.8;">${statusText}</div>
                        <strong>⚠️ Faltan (${missing.length}):</strong> ${missing.join(', ')}
                    </div>`;
            }
            statusDiv.innerHTML = html;
        }

        // --- Carga Principal ---
        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;
                container.innerHTML = ''; 

                updateStatusUI(true); // Mostrar estado "Analizando"

                // 1. Crear estructuras vacías (Placeholders)
                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    createPagePlaceholder(pageNum);
                }

                // 2. Iniciar el "Radar de Fondo" (Busca texto sin renderizar imagen)
                // Esto es clave para encontrar la página antes de que el usuario baje
                runBackgroundRadar(numPages);

                // 3. Configurar Lazy Loading visual (para pintar cuando lleguemos)
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const pageNum = parseInt(entry.target.dataset.pageNum);
                            if (!entry.target.dataset.rendered) {
                                renderPage(pageNum, entry.target);
                                entry.target.dataset.rendered = 'true';
                            }
                        }
                    });
                }, { root: null, rootMargin: '400px', threshold: 0.05 });

                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

            } catch (error) {
                console.error("Error PDF:", error);
                container.innerHTML = `<p style='color:red;'>Error crítico: ${error.message}</p>`;
            }
        }

        // --- EL RADAR SILENCIOSO (Corrección del problema de Scroll) ---
        async function runBackgroundRadar(totalDocs) {
            // Recorremos todas las páginas buscando texto puro (muy rápido)
            for (let i = 1; i <= totalDocs; i++) {
                try {
                    const page = await pdfDoc.getPage(i);
                    const textContent = await page.getTextContent();
                    
                    // Unimos todo el texto de la página y limpiamos
                    const pageString = textContent.items.map(item => item.str).join('');
                    const cleanPageString = pageString.toLowerCase().replace(/[^a-z0-9]/g, '');

                    // Verificamos si hay coincidencias
                    let foundOnThisPage = false;
                    
                    for (let [key, original] of allTermsMap) {
                        // Si encontramos el término (y no estaba marcado ya)
                        if (cleanPageString.includes(key)) {
                            foundTermsSet.add(key);
                            foundOnThisPage = true;
                        }
                    }

                    // Actualizar UI progresivamente
                    updateStatusUI(true);

                    // ⭐ LA MAGIA: Si encontramos algo en esta página y es la primera vez...
                    // SCROLL AUTOMÁTICO HACIA ELLA
                    if (foundOnThisPage && !hasScrolledToFirstMatch) {
                        hasScrolledToFirstMatch = true;
                        const pageEl = document.getElementById('page-' + i);
                        if (pageEl) {
                            // Scroll suave hacia la página encontrada
                            pageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            // Esto forzará al IntersectionObserver a renderizarla visualmente
                            // y activará mark.js
                        }
                    }

                    // Pequeña pausa para no congelar el navegador en docs gigantes
                    if (i % 5 === 0) await new Promise(r => setTimeout(r, 10));

                } catch (e) {
                    console.error("Error radar pag " + i, e);
                }
            }
            updateStatusUI(false); // Finalizar estado
        }

        function createPagePlaceholder(pageNum) {
            const wrapper = document.createElement('div');
            wrapper.className = 'pdf-page-wrapper';
            wrapper.id = 'page-' + pageNum;
            wrapper.dataset.pageNum = pageNum;
            wrapper.style.minHeight = '800px'; // Altura estimada para que el scroll funcione
            wrapper.style.position = 'relative';
            
            // Loader simple visible
            wrapper.innerHTML = `
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:#9ca3af;">
                    Cargando Página ${pageNum}...
                </div>`;
            
            const containerDiv = document.createElement('div');
            containerDiv.style.marginBottom = "20px";
            containerDiv.appendChild(wrapper);
            
            // Número de página pie
            const footer = document.createElement('div');
            footer.className = 'page-number';
            footer.textContent = `Página ${pageNum}`;
            containerDiv.appendChild(footer);

            container.appendChild(containerDiv);
        }

        // --- Renderizado Visual (Pesado) ---
        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                wrapper.innerHTML = ''; // Limpiar loader
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';
                wrapper.style.minHeight = ''; // Quitar altura forzada

                // 1. Canvas (Imagen)
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                wrapper.appendChild(canvas);

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // 2. Capa de Texto (Para selección y Mark.js)
                const textDiv = document.createElement('div');
                textDiv.className = 'text-layer';
                textDiv.style.width = viewport.width + 'px';
                textDiv.style.height = viewport.height + 'px';
                wrapper.appendChild(textDiv);

                const textContent = await page.getTextContent();
                await pdfjsLib.renderTextLayer({
                    textContent: textContent,
                    container: textDiv,
                    viewport: viewport,
                    textDivs: []
                }).promise;

                // 3. Resaltado Visual
                const instance = new Mark(textDiv);
                const options = {
                    element: "mark",
                    accuracy: "partially",
                    separateWordSearch: false
                };

                // Aplicar colores
                if (hits.length > 0) {
                    instance.mark(hits, { ...options, className: "highlight-hit" });
                }
                if (context.length > 0) {
                    instance.mark(context, { ...options, className: "highlight-context" });
                }

            } catch (err) {
                console.error(`Error render pag ${pageNum}:`, err);
            }
        }

        // Funciones auxiliares UI
        function showPrintModal() { document.getElementById('printModal').classList.add('active'); }
        function closePrintModal() { document.getElementById('printModal').classList.remove('active'); }
        function printFullDocument() { window.print(); closePrintModal(); }

        // Navegación Voraz (Legacy)
        let vorazData = JSON.parse(sessionStorage.getItem('voraz_viewer_data') || 'null');
        let currentDocIndex = vorazData ? (vorazData.currentIndex || 0) : 0;
        function navigateVorazDoc(dir) {
            if (!vorazData) return;
            const newIndex = currentDocIndex + dir;
            if (newIndex >= 0 && newIndex < vorazData.documents.length) {
                vorazData.currentIndex = newIndex;
                sessionStorage.setItem('voraz_viewer_data', JSON.stringify(vorazData));
                const doc = vorazData.documents[newIndex];
                const p = new URLSearchParams(window.location.search);
                if (doc.id) p.set('doc', doc.id);
                if (doc.ruta_archivo) p.set('file', doc.ruta_archivo);
                window.location.search = p.toString();
            }
        }

        // Iniciar todo
        loadPDF();
    </script>
</body>

</html>