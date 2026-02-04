<?php
/**
 * Print-Optimized PDF Viewer with Highlighting
 * * Optimized Version:
 * - Client-side ONLY highlighting (Mark.js).
 * - Lazy Loading with Auto-Scroll Radar.
 * - Persistent Context Preservation (Hidden Inputs).
 * - Editable Search List.
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

// 1. Añadir 'term' individual (Input manual del textarea)
if (!empty($searchTermInput)) {
    $splitTerms = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    if ($splitTerms) {
        $termsToHighlight = array_merge($termsToHighlight, $splitTerms);
    }
}

// 2. Procesar lista de 'codes' (Contexto del sistema)
$codesInputStr = '';
if (!empty($codesInput)) {
    if (is_array($codesInput)) {
        $termsToHighlight = array_merge($termsToHighlight, $codesInput);
        // Aplanar para el input hidden y preservar en el siguiente submit
        $codesInputStr = implode(',', $codesInput);
    } else {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes) {
            $termsToHighlight = array_merge($termsToHighlight, $splitCodes);
        }
        $codesInputStr = $codesInput;
    }
}

// Limpiar y deduplicar para visualización general en el Textarea
$termsToHighlight = array_unique(array_filter(array_map('trim', $termsToHighlight)));
$searchTerm = implode(' ', $termsToHighlight);

// ⭐ STRICT MODE CONFIGURATION
$mode = isset($_GET['mode']) ? $_GET['mode'] : (isset($_GET['voraz_mode']) ? 'voraz_multi' : 'single');
$strictMode = isset($_GET['strict_mode']) && $_GET['strict_mode'] === 'true';

$hits = [];
$context = [];

if ($strictMode) {
    // Definición:
    // HITS (Naranja/Verde Fuerte) = Lo que el usuario escribe explícitamente en el Textarea ('term').
    // CONTEXT (Verde Suave) = Lo que viene de 'codes' pero NO está en el textarea.

    // 1. Parsear Hits (Manuales)
    if (!empty($searchTermInput)) {
        $hits = preg_split('/[\s,\t\n\r]+/', $searchTermInput, -1, PREG_SPLIT_NO_EMPTY);
    }

    // 2. Parsear Contexto (Sistema)
    $allCodes = [];
    if (!empty($codesInput)) {
        $splitCodes = preg_split('/[,;\t\n\r]+/', $codesInput, -1, PREG_SPLIT_NO_EMPTY);
        if ($splitCodes)
            $allCodes = $splitCodes;
    }

    $hits = array_unique(array_filter(array_map('trim', $hits)));
    $allCodes = array_unique(array_filter(array_map('trim', $allCodes)));

    // El contexto es todo lo automático, menos lo que ya estamos buscando manualmente
    $context = array_diff($allCodes, $hits);

} else {
    // Modo Clásico: Todo lo que está en la lista se busca con la misma prioridad
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
    $available = get_available_folders($clientCode);
    die("Archivo PDF no encontrado.<br>Ruta: " . htmlspecialchars($pdfPath ?? 'NULL'));
}

$relativePath = str_replace($uploadsDir, '', $pdfPath);
$baseUrl = '../../';
$pdfUrl = $baseUrl . 'clients/' . $clientCode . '/uploads/' . $relativePath;
$docIdForOcr = $documentId; // For OCR fallback
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
        /* --- LAYOUT --- */
        .viewer-container {
            display: grid;
            grid-template-columns: 300px 1fr;
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
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }

        .viewer-main {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        /* --- PDF LAYERS --- */
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
            mix-blend-mode: multiply;
            /* Fusión para que se vea el texto negro debajo */
        }

        .text-layer span {
            position: absolute;
            white-space: pre;
            color: transparent;
            cursor: text;
        }

        /* --- ESTILOS DE RESALTADO (TIPO MARCADOR) --- */
        .text-layer mark {
            padding: 0;
            margin: 0;
            border-radius: 0;
            color: transparent;
            mix-blend-mode: multiply;
        }

        /* Verde Fuerte (Hits Manuales) */
        .highlight-hit {
            background-color: rgba(34, 197, 94, 0.5) !important;
            border-bottom: 2px solid #15803d;
        }

        /* Verde Suave (Contexto Automático) */
        .highlight-context {
            background-color: rgba(134, 239, 172, 0.4) !important;
        }

        /* --- UI COMPONENTS --- */
        .page-number {
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        .doc-info {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .search-form textarea {
            width: 100%;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 0.5rem;
            font-family: monospace;
            font-size: 0.85rem;
            resize: vertical;
            background-color: #fff;
        }

        .btn-print {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.8rem;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 0.75rem;
        }

        .btn-print:hover {
            background: var(--accent-primary-hover);
        }

        .voraz-navigation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-bottom: 2px solid #667eea;
            margin-bottom: 15px;
            border-radius: var(--radius-md);
        }

        .nav-btn {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        /* --- PRINT MODAL --- */
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

        .print-modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 1.5rem;
        }

        @media print {

            /* Reset de márgenes del navegador */
            @page {
                margin: 0 !important;
                size: auto;
            }

            /* ELIMINACIÓN DEL CÍRCULO (SPINNER) Y UI: 
               Se oculta explícitamente el spinner y la interfaz para evitar basura visual */
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
            .loading-placeholder,
            .spinner {
                display: none !important;
                height: 0 !important;
                visibility: hidden !important;
            }

            /* FLUJO CONTINUO: Evita que contenedores con altura mínima generen hojas blancas */
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

            /* SOLUCIÓN A HOJAS BLANCAS INTERMEDIAS: 
               Solo se imprimen las páginas que JavaScript ya renderizó con éxito */
            .page-outer-wrapper.print-hide {
                display: none !important;
                height: 0 !important;
                overflow: hidden !important;
            }

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
                color: transparent !important;
                font-size: 0 !important;
            }

            .page-outer-wrapper:last-child {
                page-break-after: avoid !important;
            }

            .pdf-page-wrapper {
                margin: 0 auto !important;
                box-shadow: none !important;
                border: none !important;
                page-break-inside: avoid !important;
            }

            canvas {
                width: 100% !important;
                height: auto !important;
                display: block !important;
            }

            /* PRESERVAR RESALTADOS EN IMPRESIÓN */
            .text-layer {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .highlight-hit,
            .highlight-context {
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

                        <?php if ($mode === 'voraz_multi'): ?>
                            <div class="voraz-navigation">
                                <button onclick="navigateVorazDoc(-1)" class="nav-btn">◀</button>
                                <span id="doc-counter">Doc <span id="current-doc">1</span>/<?= $totalDocs ?></span>
                                <button onclick="navigateVorazDoc(1)" class="nav-btn">▶</button>
                            </div>
                        <?php endif; ?>

                        <?php if ($mode === 'unified' && $downloadUrl): ?>
                            <div style="margin-bottom:1rem; text-align:center;">
                                <a href="<?= htmlspecialchars($downloadUrl) ?>" download class="btn btn-secondary"
                                    style="width:100%;">📥 Descargar Unificado</a>
                            </div>
                        <?php endif; ?>

                        <h3>📄 Documento</h3>
                        <div class="doc-info">
                            <p><strong>Número:</strong> <?= htmlspecialchars($document['numero']) ?></p>
                            <p><strong>Tipo:</strong> <?= strtoupper($document['tipo']) ?></p>
                        </div>

                        <div class="search-form">
                            <form method="GET">
                                <input type="hidden" name="doc" value="<?= $documentId ?>">

                                <input type="hidden" name="codes" value="<?= htmlspecialchars($codesInputStr) ?>">

                                <?php if (isset($_GET['voraz_mode'])): ?>
                                    <input type="hidden" name="voraz_mode" value="true">
                                <?php endif; ?>
                                <?php if (isset($_GET['strict_mode'])): ?>
                                    <input type="hidden" name="strict_mode"
                                        value="<?= htmlspecialchars($_GET['strict_mode']) ?>">
                                <?php endif; ?>
                                <?php if (isset($_GET['file'])): ?>
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($_GET['file']) ?>">
                                <?php endif; ?>

                                <label
                                    style="font-size:0.85rem; font-weight:600; margin-bottom:5px; display:block;">Lista
                                    de Búsqueda (Editable):</label>
                                <textarea name="term" rows="8"
                                    placeholder="Escribe códigos aquí..."><?= htmlspecialchars(implode("\n", $termsToHighlight)) ?></textarea>

                                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                                    🔄 Actualizar / Buscar
                                </button>
                            </form>
                        </div>

                        <div id="simpleStatus" style="margin-top:15px;"></div>

                        <hr style="margin: 1.5rem 0; border-top:1px solid #eee;">

                        <button class="btn-print" onclick="printCleanDocument()">🖨️ Imprimir PDF</button>
                        <a href="<?= $pdfUrl ?>" download class="btn btn-secondary"
                            style="width: 100%; text-align: center; display:block; padding:0.8rem;">
                            📥 Descargar PDF
                        </a>
                    </div>

                    <div class="viewer-main">
                        <div id="pdfContainer" class="pdf-container">
                            <div class="loading-pages" style="text-align:center; padding:3rem;">
                                <div class="spinner"></div>
                                <p style="color:#6b7280; margin-top:10px;">Cargando documento inteligente...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>


    <!-- Modal de impresión eliminado - ahora se usa impresión directa -->

    <script>
        // Configuración PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // --- VARS GLOBALES ---
        const viewerMode = '<?= $mode ?>';
        const isStrictMode = <?= $strictMode ? 'true' : 'false' ?>;

        // Listas limpias para JS
        const hits = <?= json_encode(array_values($hits)) ?>.map(String).map(s => s.trim()).filter(s => s.length > 0);
        const context = <?= json_encode(array_values($context)) ?>.map(String).map(s => s.trim()).filter(s => s.length > 0);

        const pdfUrl = '<?= addslashes($pdfUrl) ?>';
        const container = document.getElementById('pdfContainer');
        const scale = 1.5;
        let pdfDoc = null;

        // Variables Radar y Scroll
        let hasScrolledToFirstMatch = false;

        // --- VORAZ NAV ---
        let vorazData = JSON.parse(sessionStorage.getItem('voraz_viewer_data') || 'null');
        let currentDocIndex = vorazData ? (vorazData.currentIndex || 0) : 0;
        if (vorazData && document.getElementById('current-doc')) {
            document.getElementById('current-doc').textContent = currentDocIndex + 1;
        }

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

        // --- CARGA DEL PDF ---
        async function loadPDF() {
            try {
                pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
                const numPages = pdfDoc.numPages;
                container.innerHTML = '';

                // Crear esqueletos de páginas
                for (let i = 1; i <= numPages; i++) createPagePlaceholder(i);

                // Configurar Lazy Load
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const pNum = parseInt(entry.target.dataset.pageNum);
                            if (!entry.target.dataset.rendered) {
                                renderPage(pNum, entry.target);
                                entry.target.dataset.rendered = 'true';
                            }
                        }
                    });
                }, { root: null, rootMargin: '600px', threshold: 0.05 });

                document.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));

                // Carga inicial forzada (primeras páginas) para UX rápido
                for (let i = 1; i <= Math.min(numPages, 3); i++) {
                    const el = document.getElementById('page-' + i);
                    if (el) { renderPage(i, el); el.dataset.rendered = 'true'; }
                }

                // INICIAR RADAR (Búsqueda silenciosa para status y scroll)
                scanAllPagesForSummary();

            } catch (err) {
                console.error("Error loadPDF:", err);
                container.innerHTML = `<p style='color:red; padding:20px;'>Error: ${err.message}</p>`;
            }
        }

        // --- RADAR DE FONDO (AUTO-SCROLL) ---
        async function scanAllPagesForSummary() {
            const statusDiv = document.getElementById('simpleStatus');
            if (!statusDiv) return;

            // Unir todo para el radar: Buscamos Hits y Contexto
            const termsToFind = [...hits, ...context];
            if (termsToFind.length === 0) {
                statusDiv.innerHTML = ''; return;
            }

            statusDiv.innerHTML = '<div style="color:#d97706; font-size:0.9em;">🔎 Analizando documento...</div>';

            // Mapa para control de "Faltantes"
            // Normalizamos keys para comparación (sin espacios, minuscula)
            let missingMap = new Map();
            termsToFind.forEach(t => missingMap.set(t.replace(/[^a-zA-Z0-9]/g, '').toLowerCase(), t));

            for (let i = 1; i <= pdfDoc.numPages; i++) {
                try {
                    const page = await pdfDoc.getPage(i);
                    const textContent = await page.getTextContent();
                    const hasText = textContent.items && textContent.items.length > 0;
                    
                    let pageHasMatch = false;
                    let cleanStr = '';
                    
                    if (hasText) {
                        // CAMINO 1: PDF con texto embebido
                        const rawStr = textContent.items.map(x => x.str).join('');
                        cleanStr = rawStr.replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
                    } else if (missingMap.size > 0) {
                        // CAMINO 2: PDF escaneado - usar OCR para el radar
                        try {
                            const docId = <?= $docIdForOcr ?>;
                            const termsArr = Array.from(missingMap.values());
                            const termsStr = encodeURIComponent(termsArr.join(','));
                            const ocrResp = await fetch(`ocr_text.php?doc=${docId}&page=${i}&terms=${termsStr}`);
                            const ocrResult = await ocrResp.json();
                            
                            if (ocrResult.success && ocrResult.text) {
                                cleanStr = ocrResult.text.replace(/[^a-zA-Z0-9]/g, '').toLowerCase();
                            }
                        } catch (ocrErr) {
                            console.warn('OCR radar error pg ' + i, ocrErr);
                        }
                    }

                    // Chequear coincidencias
                    for (let [key, original] of missingMap) {
                        if (cleanStr.includes(key)) {
                            missingMap.delete(key); // ¡Encontrado!
                            pageHasMatch = true;
                        }
                    }

                    // AUTO SCROLL al primer hallazgo relevante
                    if (pageHasMatch && !hasScrolledToFirstMatch) {
                        hasScrolledToFirstMatch = true;
                        const pEl = document.getElementById('page-' + i);
                        if (pEl) {
                            pEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }

                    // Pausa leve para no congelar UI en docs largos
                    if (i % 5 === 0) await new Promise(r => setTimeout(r, 10));

                } catch (e) { console.error("Radar error pg " + i, e); }
            }

            // Actualizar UI Final
            if (missingMap.size === 0) {
                statusDiv.innerHTML = `
                    <div style="background:#dcfce7; color:#166534; padding:10px; border-radius:6px; border:1px solid #86efac; font-size:0.9em;">
                        ✅ <strong>Completo:</strong> Todo encontrado.
                    </div>`;
            } else {
                const missingArr = Array.from(missingMap.values());
                statusDiv.innerHTML = `
                    <div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; border:1px solid #fecaca; font-size:0.9em;">
                        <strong>⚠️ Faltan (${missingArr.length}):</strong> ${missingArr.join(', ')}
                    </div>`;
            }
        }

        // --- RENDERIZADO VISUAL ---
        function createPagePlaceholder(pageNum) {
            const div = document.createElement('div');
            div.className = "page-outer-wrapper print-hide"; // Hidden by default for print
            div.innerHTML = `
                <div id="page-${pageNum}" class="pdf-page-wrapper" data-page-num="${pageNum}" style="min-height:800px; display:flex; align-items:center; justify-content:center;">
                    <span class="loading-placeholder" style="color:#999;">Cargando pág ${pageNum}...</span>
                </div>
                <div class="page-number">Página ${pageNum}</div>
            `;
            container.appendChild(div);
        }

        async function renderPage(pageNum, wrapper) {
            try {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale });

                wrapper.innerHTML = '';
                wrapper.style.display = 'block';
                wrapper.style.width = viewport.width + 'px';
                wrapper.style.height = viewport.height + 'px';

                // 1. Canvas
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                wrapper.appendChild(canvas);

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // 2. Capa de Texto
                const textDiv = document.createElement('div');
                textDiv.className = 'text-layer';
                textDiv.style.width = viewport.width + 'px';
                textDiv.style.height = viewport.height + 'px';
                wrapper.appendChild(textDiv);

                const textContent = await page.getTextContent();
                const hasText = textContent.items && textContent.items.length > 0;

                // MARCAR COMO RENDERIZADO (Vital para el CSS de impresión)
                wrapper.setAttribute('data-rendered', 'true');
                wrapper.style.minHeight = "auto";
                wrapper.style.color = "inherit";

                if (hasText) {
                    // ✅ CAMINO 1: PDF tiene texto embebido - usar Mark.js (LÓGICA ORIGINAL)
                    await pdfjsLib.renderTextLayer({
                        textContent: textContent,
                        container: textDiv,
                        viewport: viewport,
                        textDivs: []
                    }).promise;

                    // 3. Resaltado (Mark.js)
                    const instance = new Mark(textDiv);
                    const opts = { element: "mark", accuracy: "partially", separateWordSearch: false };

                    // Aplicar estilos diferenciados si es strict mode, o genéricos si no
                    if (isStrictMode) {
                        if (hits.length) instance.mark(hits, { ...opts, className: "highlight-hit" });
                        if (context.length) instance.mark(context, { ...opts, className: "highlight-context" });
                    } else {
                        const all = [...hits, ...context];
                        if (all.length) instance.mark(all, { ...opts, className: "highlight-hit" });
                    }
                } else {
                    // ✅ CAMINO 2: PDF escaneado (sin texto) - usar fallback OCR
                    const allTerms = [...hits, ...context];
                    if (allTerms.length > 0) {
                        console.log(`Página ${pageNum}: Sin texto embebido, usando fallback OCR...`);
                        await applyOcrHighlight(wrapper, textDiv, pageNum, allTerms);
                    }
                }

            } catch (err) { console.error("Render err pg " + pageNum, err); }
        }
        
        // Fallback OCR: solo se usa para documentos escaneados (sin texto embebido)
        async function applyOcrHighlight(wrapper, textDiv, pageNum, allTerms) {
            // Mostrar modal de "Analizando..."
            let loadingModal = document.getElementById('ocr-loading-modal');
            if (!loadingModal) {
                loadingModal = document.createElement('div');
                loadingModal.id = 'ocr-loading-modal';
                loadingModal.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(0,0,0,0.9);
                    color: white;
                    padding: 40px 60px;
                    border-radius: 20px;
                    z-index: 9999;
                    text-align: center;
                    font-family: system-ui, sans-serif;
                    box-shadow: 0 15px 50px rgba(0,0,0,0.6);
                `;
                loadingModal.innerHTML = `
                    <style>
                        @keyframes ocr-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                    </style>
                    <div style="width:60px;height:60px;border:4px solid rgba(255,255,255,0.2);border-top:4px solid #22c55e;border-radius:50%;animation:ocr-spin 1s linear infinite;margin:0 auto 20px;"></div>
                    <div style="font-size:18px;font-weight:bold;">Analizando documento...</div>
                    <div style="font-size:13px;opacity:0.7;margin-top:10px;">Extrayendo texto con OCR</div>
                `;
                document.body.appendChild(loadingModal);
            }
            
            try {
                const docId = <?= $docIdForOcr ?>;
                const termsStr = encodeURIComponent(allTerms.join(','));
                const response = await fetch(`ocr_text.php?doc=${docId}&page=${pageNum}&terms=${termsStr}`);
                const result = await response.json();
                
                // Ocultar modal
                if (loadingModal) loadingModal.remove();
                
                if (result.success && result.matches && result.matches.length > 0) {
                    // Solo mostrar badge pequeño con resultado
                    const ocrBadge = document.createElement('div');
                    ocrBadge.style.cssText = `
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: #16a34a;
                        color: white;
                        padding: 8px 15px;
                        border-radius: 8px;
                        z-index: 100;
                        font-family: system-ui, sans-serif;
                        font-size: 13px;
                        font-weight: bold;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                    `;
                    ocrBadge.innerHTML = `✅ OCR: ${result.match_count} encontrado(s)`;
                    wrapper.appendChild(ocrBadge);
                    
                    console.log(`OCR: ${result.match_count} coincidencias en página ${pageNum}`);
                }
            } catch (e) {
                // Ocultar modal en caso de error
                if (loadingModal) loadingModal.remove();
                console.warn(`OCR fallback error en página ${pageNum}:`, e);
            }
        }

        // --- NUEVA FUNCIÓN DE IMPRESIÓN LIMPIA ---
        async function printCleanDocument() {
            const statusDiv = document.getElementById('simpleStatus');
            const totalPages = pdfDoc ? pdfDoc.numPages : 0;

            if (totalPages === 0) {
                alert('El documento aún no se ha cargado completamente.');
                return;
            }

            // Mostrar progreso
            if (statusDiv) {
                statusDiv.innerHTML = `<div style="background:#fef3c7; color:#92400e; padding:10px; border-radius:6px; font-size:0.9em;">
                    🖨️ <strong>Preparando impresión...</strong> Por favor espera.
                </div>`;
            }

            // Recolectar imágenes de todas las páginas
            const pageImages = [];

            for (let pageNum = 1; pageNum <= totalPages; pageNum++) {
                // Actualizar progreso
                if (statusDiv) {
                    statusDiv.innerHTML = `<div style="background:#fef3c7; color:#92400e; padding:10px; border-radius:6px; font-size:0.9em;">
                        🖨️ <strong>Preparando página ${pageNum} de ${totalPages}...</strong>
                    </div>`;
                }

                try {
                    const page = await pdfDoc.getPage(pageNum);
                    // Escala alta para mejor calidad de impresión
                    const printScale = 2.5;
                    const viewport = page.getViewport({ scale: printScale });

                    // Crear canvas temporal
                    const tempCanvas = document.createElement('canvas');
                    const ctx = tempCanvas.getContext('2d');
                    tempCanvas.width = viewport.width;
                    tempCanvas.height = viewport.height;

                    // Fondo blanco explícito
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

                    // Renderizar página PDF
                    await page.render({
                        canvasContext: ctx,
                        viewport: viewport,
                        background: 'white'
                    }).promise;

                    // Obtener contenido de texto para resaltados
                    const textContent = await page.getTextContent();
                    const allTerms = [...hits, ...context];

                    // Dibujar resaltados sobre el canvas
                    if (allTerms.length > 0) {
                        ctx.globalAlpha = 0.55;
                        ctx.fillStyle = '#0d6939'; // Verde oscuro para escala de grises

                        for (const item of textContent.items) {
                            const itemText = item.str.toLowerCase();
                            for (const term of allTerms) {
                                if (term && itemText.includes(term.toLowerCase())) {
                                    // Calcular posición usando la matriz de transformación
                                    const tx = item.transform;
                                    const x = tx[4] * printScale;
                                    const y = viewport.height - (tx[5] * printScale);
                                    const width = (item.width || 50) * printScale;
                                    const height = (item.height || 12) * printScale;

                                    ctx.fillRect(x, y - height, width, height);
                         break; // Solo un resaltado por item
                                }
                            }
                        }
                        ctx.globalAlpha = 1.0;
                    }

                    // Convertir a imagen PNG de alta calidad
                    pageImages.push({
                        data: tempCanvas.toDataURL('image/png', 1.0),
                        width: viewport.width,
                        height: viewport.height
                    });

                } catch (e) {
                    console.error('Error preparando página', pageNum, e);
                }

                // Pequeña pausa para no bloquear el UI
                await new Promise(r => setTimeout(r, 20));
            }

            // Finalizar preparación
            if (statusDiv) {
                statusDiv.innerHTML = `<div style="background:#d1fae5; color:#065f46; padding:10px; border-radius:6px; font-size:0.9em;">
                    ✅ <strong>${pageImages.length} páginas listas.</strong> Abriendo ventana de impresión...
                </div>`;
            }

            // Crear ventana de impresión completamente limpia
            const printWindow = window.open('', '_blank', 'width=900,height=700');
            if (!printWindow) {
                alert('Por favor permite las ventanas emergentes para imprimir el documento.');
                if (statusDiv) statusDiv.innerHTML = '';
                return;
            }

            // Generar HTML limpio para impresión
            const pagesHTML = pageImages.map((img, i) =>
                `<div class="print-page">
                    <img src="${img.data}" alt="Página ${i + 1}">
                </div>`
            ).join('\n');

            printWindow.document.write(`<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Imprimir - ${document.title || 'Documento PDF'}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { 
            background: #fff; 
            width: 100%; 
            height: 100%;
        }
        .print-page {
            width: 100%;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #fff;
            overflow: hidden;
        }
        .print-page img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
        }
        @media screen {
            body { background: #e5e7eb; padding: 10px; }
            .print-page { 
                height: auto;
                margin-bottom: 10px; 
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                aspect-ratio: 8.5 / 11;
            }
        }
        @media print {
            @page { 
                margin: 0; 
                padding: 0;
                size: letter;
            }
            html, body { 
                background: #fff !important;
                width: 100% !important;
                height: 100% !important;
            }
            .print-page {
                width: 100vw;
                height: 100vh;
                page-break-after: always;
                page-break-inside: avoid;
                break-after: page;
                break-inside: avoid;
                margin: 0;
                padding: 0;
                box-shadow: none;
                border: none;
                overflow: hidden;
            }
            .print-page:last-of-type { 
                page-break-after: auto;
                break-after: auto;
            }
            .print-page img { 
                max-width: 100vw !important;
                max-height: 100vh !important;
                object-fit: contain !important;
            }
        }
    </style>
</head>
<body>
    ${pagesHTML}
    <script>
        // Auto-imprimir después de cargar todas las imágenes
        let imagesLoaded = 0;
        const images = document.querySelectorAll('img');
        const totalImages = images.length;
        
        if (totalImages === 0) {
            setTimeout(() => window.print(), 300);
        } else {
            images.forEach(img => {
                if (img.complete) {
                    imagesLoaded++;
                    if (imagesLoaded === totalImages) {
                        setTimeout(() => window.print(), 300);
                    }
                } else {
                    img.onload = img.onerror = () => {
                        imagesLoaded++;
                        if (imagesLoaded === totalImages) {
                            setTimeout(() => window.print(), 300);
                        }
                    };
                }
            });
        }
    <\/script>
</body>
</html>`);

            printWindow.document.close();

            // Limpiar mensaje de estado después de un tiempo
            setTimeout(() => {
                if (statusDiv) statusDiv.innerHTML = '';
            }, 4000);
        }

        // Start
        loadPDF();
    </script>
</body>

</html>