<?php
/**
 * Módulo de Indexación de PDFs
 * Permite indexar todos los documentos PDF pendientes para búsqueda full-text
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';

// Verify authentication
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$db = getDB();
$clientCode = $_SESSION['client_code'];
$baseUrl = '../../';
$pageTitle = 'Indexar Documentos';

// Contar documentos pendientes
$pendingCount = 0;
$totalPdfCount = 0;
$allDocsStmt = $db->query("SELECT datos_extraidos FROM documentos WHERE ruta_archivo LIKE '%.pdf'");
while ($row = $allDocsStmt->fetch(PDO::FETCH_ASSOC)) {
    $totalPdfCount++;
    $data = json_decode($row['datos_extraidos'] ?? '', true);
    if (empty($data['text']) || strlen($data['text'] ?? '') < 100) {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Indexar Documentos - KINO TRACE</title>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/styles.css">
    <style>
        .index-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .index-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--border-primary);
        }

        .index-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .index-header h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .index-header p {
            color: var(--text-secondary);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: var(--bg-tertiary);
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
        }

        .stat-box .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-primary);
        }

        .stat-box.pending .number {
            color: #f59e0b;
        }

        .stat-box.indexed .number {
            color: #10b981;
        }

        .stat-box .label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .action-area {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .btn-index {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s;
        }

        .btn-index:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }

        .btn-index:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .progress-section {
            display: none;
        }

        .progress-section.active {
            display: block;
        }

        .progress-bar-container {
            background: var(--bg-tertiary);
            border-radius: 8px;
            height: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #f59e0b, #10b981);
            transition: width 0.3s ease;
        }

        .progress-text {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .log-section {
            margin-top: 1.5rem;
            max-height: 200px;
            overflow-y: auto;
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 1rem;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .log-entry {
            padding: 0.25rem 0;
            border-bottom: 1px solid var(--border-primary);
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-entry.success {
            color: #10b981;
        }

        .log-entry.error {
            color: #ef4444;
        }

        .log-entry.info {
            color: var(--text-secondary);
        }

        .complete-message {
            display: none;
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-radius: 12px;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .complete-message.show {
            display: block;
        }

        .complete-message h3 {
            color: #10b981;
            margin-bottom: 0.5rem;
        }

        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
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
                <div class="index-container">
                    <div class="index-card">
                        <div class="index-header">
                            <h1>📄 Indexar Documentos PDF</h1>
                            <p>Extrae el texto de los PDFs para habilitar la búsqueda full-text</p>
                        </div>

                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="number">
                                    <?= $totalPdfCount ?>
                                </div>
                                <div class="label">Total PDFs</div>
                            </div>
                            <div class="stat-box indexed">
                                <div class="number" id="indexedCount">
                                    <?= $totalPdfCount - $pendingCount ?>
                                </div>
                                <div class="label">Indexados</div>
                            </div>
                            <div class="stat-box pending">
                                <div class="number" id="pendingCount">
                                    <?= $pendingCount ?>
                                </div>
                                <div class="label">Pendientes</div>
                            </div>
                        </div>

                        <div class="action-area" id="actionArea">
                            <?php if ($pendingCount > 0): ?>
                                <button class="btn-index" id="startBtn" onclick="startIndexing()">
                                    <span id="btnIcon">🚀</span>
                                    <span id="btnText">Iniciar Indexación</span>
                                </button>
                                <p style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.9rem;">
                                    Se procesarán
                                    <?= $pendingCount ?> documentos en lotes de 10
                                </p>
                            <?php else: ?>
                                <div class="complete-message show">
                                    <h3>✅ ¡Todos los documentos están indexados!</h3>
                                    <p>No hay documentos pendientes de indexar</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="progress-section" id="progressSection">
                            <div class="progress-bar-container">
                                <div class="progress-bar" id="progressBar"></div>
                            </div>
                            <div class="progress-text" id="progressText">Preparando...</div>

                            <div class="log-section" id="logSection"></div>
                        </div>

                        <div class="complete-message" id="completeMessage">
                            <h3>✅ ¡Indexación Completada!</h3>
                            <p id="completeSummary"></p>
                        </div>
                    </div>

                    <div style="margin-top: 1.5rem; text-align: center;">
                        <a href="../busqueda/#tab-consultar" class="btn btn-secondary" style="padding: 0.75rem 1.5rem;">
                            🔍 Ir a Búsqueda Full-Text
                        </a>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const apiUrl = '<?= $baseUrl ?>api.php';
        let totalPending = <?= $pendingCount ?>;
        let totalIndexed = 0;
        let isRunning = false;

        // Auto-start if URL has ?auto=1
        if (new URLSearchParams(window.location.search).get('auto') === '1' && totalPending > 0) {
            setTimeout(() => startIndexing(), 500);
        }

        function addLog(message, type = 'info') {
            const log = document.getElementById('logSection');
            const entry = document.createElement('div');
            entry.className = `log-entry ${type}`;
            entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            log.insertBefore(entry, log.firstChild);
        }

        async function startIndexing() {
            if (isRunning) return;
            isRunning = true;

            const btn = document.getElementById('startBtn');
            const progressSection = document.getElementById('progressSection');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const actionArea = document.getElementById('actionArea');

            btn.disabled = true;
            document.getElementById('btnIcon').innerHTML = '<div class="spinner"></div>';
            document.getElementById('btnText').textContent = 'Indexando...';

            progressSection.classList.add('active');
            addLog('Iniciando proceso de indexación...', 'info');

            let pending = totalPending;
            let batchNum = 0;

            while (pending > 0) {
                batchNum++;
                addLog(`Procesando lote #${batchNum}...`, 'info');

                try {
                    const response = await fetch(`${apiUrl}?action=reindex_documents&batch=10`);
                    const result = await response.json();

                    if (!result.success) {
                        addLog(`Error: ${result.error || 'Error desconocido'}`, 'error');
                        break;
                    }

                    totalIndexed += result.indexed;
                    pending = result.pending;

                    // Update UI
                    const percent = Math.round(((totalPending - pending) / totalPending) * 100);
                    progressBar.style.width = percent + '%';
                    progressText.textContent = `Indexados: ${totalIndexed} | Pendientes: ${pending} | ${percent}%`;

                    document.getElementById('indexedCount').textContent = <?= $totalPdfCount - $pendingCount ?> + totalIndexed;
                    document.getElementById('pendingCount').textContent = pending;

                    if (result.indexed > 0) {
                        addLog(`✓ Indexados ${result.indexed} documentos en este lote`, 'success');
                    }

                    if (result.errors && result.errors.length > 0) {
                        result.errors.forEach(err => addLog(`⚠ ${err}`, 'error'));
                    }

                    // Si no se indexó nada pero hay pendientes, son archivos faltantes
                    if (result.indexed === 0 && pending > 0) {
                        addLog(`⚠ ${pending} documentos no pudieron indexarse (archivos no encontrados)`, 'error');
                        break;
                    }

                } catch (error) {
                    addLog(`Error de red: ${error.message}`, 'error');
                    break;
                }
            }

            // Complete
            progressBar.style.width = '100%';

            if (pending === 0) {
                progressBar.style.background = '#10b981';
                addLog('✅ ¡Todos los documentos han sido indexados!', 'success');
                document.getElementById('completeMessage').classList.add('show');
                document.getElementById('completeSummary').textContent = `Se indexaron ${totalIndexed} documentos correctamente.`;
            } else {
                progressBar.style.background = '#f59e0b';
                addLog(`Proceso terminado. ${pending} documentos no pudieron ser indexados.`, 'info');
            }

            actionArea.style.display = 'none';
            isRunning = false;
        }
    </script>
</body>

</html>