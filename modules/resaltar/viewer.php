<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 * * Optimized Version:
 * - NO server-side extraction (too slow on Windows without pdftotext).
 * - Client-side ONLY highlighting.
 * - Lazy Loading for pages.
 * - Radar Logic for auto-scroll.
 * - CSS Blend Modes for marker-style highlighting.
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

// Unificar códigos a resaltar
$termsToHighlight = [];

// 1. Añadir 'term' individual
if (!empty($searchTermInput)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_merge($termsToHighlight, $splitTerms);
    }
}

// 2. Procesar lista de 'codes'
if (!empty($codesInput)) {
    if (is_array($codesInput)) {
        $termsToHighlight = array_merge($termsToHighlight, $codesInput);
    } else {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes) {
            $termsToHighlight = array_merge($termsToHighlight, $splitCodes);
        }
    }
}

// Limpiar y deduplicar
$termsToHighlight = array_unique(array_filter(array_map('trim', $termsToHighlight)));
$searchTerm = implode(' ', $termsToHighlight); // Fallback

// ⭐ STRICT MODE & HITS LOGIC
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_GET['voraz_mode']) ? 'voraz_multi' : 'single');
$strictMode = isset($_GET['strict_mode']) && $_GET['strict_mode'] === 'true';

$hits = [];
$context = [];

if ($strictMode) {
    // 'term' son los HITS (Prioridad)
    if (!empty($searchTermInput)) {
        $hits = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    }

    // 'codes' son TODOS
    $allCodes = [];
    if (!empty($codesInput)) {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes) $allCodes = $splitCodes;
    }

    $hits = array_unique(array_filter(array_map('trim', $hits)));
    $allCodes = array_unique(array_filter(array_map('trim', $allCodes)));

    // Contexto = Todo lo que está en allCodes pero NO en hits
    $context = array_diff($allCodes, $hits);
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

// URLs
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
        /* --- ESTILOS VISOR --- */
        .viewer-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
            min-height: calc(100vh - 200px);
        }
        @media (max-width: 900px) {
            .viewer-container { grid-template-columns: 1fr; }
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
        .pdf-page-wrapper canvas { display: block; }

        /* --- CAPA DE TEXTO --- */
        .text-layer {
            position: absolute;
            left: 0; top: 0; right: 0; bottom: 0;
            overflow: hidden;
            opacity: 1;
            line-height: 1;
            mix-blend-mode: multiply; /* Permite que el resaltado se fusione con el canvas */
        }
        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
            cursor: text;
        }

        /* --- ESTILOS DE RESALTADO CORREGIDOS --- */
        .text-layer mark {
            /* 1. Ajuste de Área: Eliminar padding para que sea exacto al texto */
            padding: 0;
            margin: 0;
            border-radius: 0;
            
            /* 2. Color y Fusión: Estilo Marcador Real */
            color: transparent; /* El texto sigue transparente */
            mix-blend-mode: multiply; /* OSCURECE EL FONDO, NO LO TAPA */
        }

        /* Estilo para Coincidencias Principales (Hits) - Verde Intenso */
        .highlight-hit {
            background-color: #22c55e !important; /* Green-500: Verde fuerte */
            border-bottom: 1px solid #14532d; /* Pequeño borde para definición */
        }

        /* Estilo para Contexto - Verde Suave */
        .highlight-context {
            background-color: #86efac !important; /* Green-300 */
        }

        /* Fallback general si no hay clases específicas */
        mark {
            background-color: #4ade80;
            mix-blend-mode: multiply;
        }

        /* --- ESTILOS UI --- */
        .page-number {
            text-align: center; font-size: 0.875rem; color: var(--text-muted); margin-top: 0.5rem;
        }
        .doc-info { font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1rem; }
        .btn-print {
            width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.875rem; background: var(--accent-primary); color: white; border: none;
            border-radius: var(--radius-md); font-size: 0.875rem; font-weight: 600; cursor: pointer; margin-bottom: 0.75rem;
        }
        .btn-print:hover { background: var(--accent-primary-hover); }
        .voraz-navigation {
            display: flex; align-items: center; justify-content: center; gap: 20px; padding: 15px;
            background: #f8f9fa; border-bottom: 2px solid #667eea; margin-bottom: 10px; border-radius: var(--radius-md);
        }
        .nav-btn {
            padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;
        }
        .unified-download {
            text-align: center; padding: 15px; background: rgba(240, 147, 251, 0.1); border: 1px solid #f093fb;
            border-radius: var(--radius-md); margin-bottom: 1rem;
        }
        .btn-download-unified {
            padding: 12px 30px; background: white; color: #f5576c; text-decoration: none; border-radius: 8px; font-weight: 600; display: inline-block; border: 1px solid #f5576c;
        }

        /* Print Modal */
        .print-modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); z-index: 10000; align-items: center; justify-content: center;
        }
        .print-modal.active { display: flex; }
        .print-modal-content {
            background: var(--bg-primary); padding: 2rem; border-radius: var(--radius-lg); max-width: 500px; width: 90%;
        }
        .print-modal-buttons { display: flex; gap: 1rem; margin-top: 1.5rem; }
        
        @media print {
            .viewer-sidebar, .main-header, .app-footer { display: none !important; }
            .viewer-container { display: block !important; }
            .viewer-main { border: none !important; padding: 0 !important; }
            .pdf-page-wrapper { break-after: page; box-shadow: none !important; }
            /* Forzar impresión de background colors */
            .text-layer mark { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
                        <?php if ($mode === 'voraz_multi'): ?>
                            <div class="voraz-navigation">
                                <button onclick="navigateVorazDoc(-1)" class="nav-btn">◀</button>
                                <span id="doc-counter"><span id="current-doc">1</span>/<?= $totalDocs ?></span>
                                <button onclick="navigateVorazDoc(1)" class="nav-btn">▶</button>
                            </div>
                        <?php endif; ?>

                        <?php if ($mode === 'unified' && $downloadUrl): ?>
                            <div class="unified-download">
                                <a href="<?= htmlspecialchars($downloadUrl) ?>" download class="btn-download-unified">📥 Descargar Unificado</a>
                            </div>
                        <?php endif; ?>

                        <h3>📄 Documento</h3>
                        <div class="doc-info">
                            <p><strong>Tipo:</strong> <?= strtoupper($document['tipo']) ?></p>
                            <p><strong>Número:</strong> <?= htmlspecialchars($document['numero']) ?></p>
                            <p><strong>Fecha:</strong> <?= htmlspecialchars($document['fecha']) ?></p>
                        </div>

                        <div id="simpleStatus"></div>

                        <div style="margin-top: 1rem;">
                            <textarea readonly style="width: 100%; height: 100px; display:none;"><?= htmlspecialchars($searchTerm) ?></textarea>
                        </div>

                        <button class="btn-print" onclick="showPrintModal()">
                            🖨️ Imprimir Documento
                        </button>

                        <a href="<?= $pdfUrl ?>" download class="btn btn-secondary" style="width: 100%; text-align: center;">
                            📥 Descargar PDF
                        </a>
                    </div>

                    <div class="viewer-main">
                        <div id="pdfContainer" class="pdf-container">
                            <div class="loading-pages" style="padding: 3rem; text-align: center;">
                                <div class="spinner"></div>
                                <p>Iniciando visor inteligente...</p>
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
            <h3>📄 Imprimir Documento</h3>
            <p>Selecciona una opción de impresión:</p>
            <div class="print-modal-buttons">
                <button class="btn btn-primary" onclick="printFullDocument()">Todo el Documento</button>
                <button class="btn btn-secondary" onclick="closePrintModal()">Cancelar</button>
            </div>
        </div>
    </div>

    <script>
        // Configuración de PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // --- Configuración Global ---
        const viewerMode = '<?= $mode ?>';
        const isStrictMode = <?= $strictMode ? 'true' : 'false' ?>;
        
        // Listas de términos
        const rawHits = <?= json_encode(array_values($hits)) ?>;
        const rawContext = <?= json_encode(array_values($context)) ?>;
        
        const hits = rawHits.map(String).map(s => s.trim()).filter(s => s.length > 0);
        const context = rawContext.map(String).map(s => s.trim()).filter(s => s.length > 0);
        
        // Mapa maestro para control de estado
        let allTermsMap = new Map(); 
        [...hits, ...context].forEach(t => {
            allTermsMap.set(t.toLowerCase().replace(/[^a-z0-9]/g, ''), t);
        });

        // Estado del sistema
        let foundTermsSet = new Set();
        let hasScrolledToFirstMatch = false; 
        
        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;

        // --- UI: Actualizar Estado ---
        function updateStatusUI(scanning = false) {
            const statusDiv = document.getElementById('simpleStatus');
            if (!statusDiv) return;

            let missing = [];
            allTermsMap.forEach((originalTerm, normalizedKey) => {
                if (!foundTermsSet.has(normalizedKey)) {
                    missing.push(originalTerm);
                }
            });

            let html = '';
            if (missing.length === 0) {
                html = `
                    <div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; text-align:center; margin-bottom:1rem; border:1px solid #86efac;">
                        ✅ <strong>Completo:</strong> Todos los códigos encontrados.
                    </div>`;
            } else {
                const statusText = scanning ? 'Analizando documento...' : 'Búsqueda finalizada';
                html = `
                    <div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:1rem; border:1px solid #fecaca;">
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

                updateStatusUI(true);

                // 1. Crear Placeholders
                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    createPagePlaceholder(pageNum);
                }

                // 2. Iniciar Radar de Fondo
                runBackgroundRadar(numPages);

                // 3. Configurar Lazy Loading
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
                }, { root: null, rootMargin: '600px', threshold: 0.01 });

                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

            } catch (error) {
                console.error("Error PDF:", error);
                container.innerHTML = `<p style='color:red;'>Error crítico: ${error.message}</p>`;
            }
        }

        // --- RADAR SILENCIOSO (Detecta posición y scroll automático) ---
        async function runBackgroundRadar(totalDocs) {
            for (let i = 1; i <= totalDocs; i++) {
                try {
                    const page = await pdfDoc.getPage(i);
                    const textContent = await page.getTextContent();
                    const pageString = textContent.items.map(item => item.str).join('');
                    const cleanPageString = pageString.toLowerCase().replace(/[^a-z0-9]/g, '');

                    let foundOnThisPage = false;
                    for (let [key, original] of allTermsMap) {
                        if (cleanPageString.includes(key)) {
                            foundTermsSet.add(key);
                            foundOnThisPage = true;
                        }
                    }

                    updateStatusUI(true);

                    // AUTO SCROLL AL PRIMER HALLAZGO
                    if (foundOnThisPage && !hasScrolledToFirstMatch) {
                        hasScrolledToFirstMatch = true;
                        const pageEl = document.getElementById('page-' + i);
                        if (pageEl) {
                            pageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                    
                    // Pausa para no bloquear UI
                    if (i % 10 === 0) await new Promise(r => setTimeout(r, 10));

                } catch (e) { console.error("Error radar", e); }
            }
            updateStatusUI(false);
        }

        function createPagePlaceholder(pageNum) {
            const wrapper = document.createElement('div');
            wrapper.className = 'pdf-page-wrapper';
            wrapper.id = 'page-' + pageNum;
            wrapper.dataset.pageNum = pageNum;
            wrapper.style.minHeight = '800px'; 
            wrapper.style.position = 'relative';
            
            wrapper.innerHTML = `
                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:#9ca3af;">
                    Cargando Página ${pageNum}...
                </div>`;
            
            const containerDiv = document.createElement('div');
            containerDiv.style.marginBottom = "20px";
            containerDiv.appendChild(wrapper);
            
            const footer = document.createElement('div');
            footer.className = 'page-number';
            footer.textContent = `Página ${pageNum}`;
            containerDiv.appendChild(footer);

            container.appendChild(containerDiv);
        }

        // --- Renderizado Visual ---
        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                wrapper.innerHTML = '';
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';
                wrapper.style.minHeight = '';

                // Canvas
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                wrapper.appendChild(canvas);

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // Texto (Highlight)
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

                // Mark.js
                const instance = new Mark(textDiv);
                const options = {
                    element: "mark",
                    accuracy: "partially",
                    separateWordSearch: false
                };

                if (hits.length > 0) instance.mark(hits, { ...options, className: "highlight-hit" });
                if (context.length > 0) instance.mark(context, { ...options, className: "highlight-context" });

            } catch (err) { console.error(`Error render pag ${pageNum}:`, err); }
        }

        // Helpers
        function showPrintModal() { document.getElementById('printModal').classList.add('active'); }
        function closePrintModal() { document.getElementById('printModal').classList.remove('active'); }
        function printFullDocument() { window.print(); closePrintModal(); }

        // Voraz
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

        loadPDF();
    </script>
</body>
</html>