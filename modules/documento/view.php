<?php
/**
 * Vista de Documento - Muestra detalles y códigos enlazados
 * 
 * Vista genérica que funciona para cualquier tipo de documento.
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

// Para sidebar
$currentModule = 'recientes';
$baseUrl = '../../';
$pageTitle = 'Ver Documento';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ../recientes/');
    exit;
}

// Obtener documento
$stmt = $db->prepare('SELECT * FROM documentos WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    header('Location: ../recientes/');
    exit;
}

// Obtener códigos del documento
$stmtCodigos = $db->prepare('SELECT * FROM codigos WHERE documento_id = ? ORDER BY codigo');
$stmtCodigos->execute([$id]);
$codigos = $stmtCodigos->fetchAll(PDO::FETCH_ASSOC);

// Determinar la ruta del PDF
$pdfPath = null;
if (!empty($doc['ruta_archivo'])) {
    // Intentar múltiples rutas posibles
    $possiblePaths = [
        CLIENTS_DIR . "/{$clientCode}/uploads/{$doc['tipo']}/{$doc['ruta_archivo']}",
        CLIENTS_DIR . "/{$clientCode}/uploads/{$doc['ruta_archivo']}",
        CLIENTS_DIR . "/{$clientCode}/uploads/documento/{$doc['ruta_archivo']}",
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $pdfPath = str_replace(BASE_DIR, '../..', $path);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento:
        <?= htmlspecialchars($doc['numero']) ?> - KINO TRACE
    </title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .doc-title {
            flex: 1;
        }

        .doc-title h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.5rem;
        }

        .doc-meta {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .doc-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-card {
            background: var(--bg-secondary);
            padding: 1rem;
            border-radius: var(--radius-md);
        }

        .info-card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .info-card .value {
            font-weight: 600;
            font-size: 1.125rem;
        }

        .codes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.5rem;
            max-height: 400px;
            overflow-y: auto;
            padding: 0.5rem;
        }

        .code-item {
            background: var(--bg-secondary);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.875rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .code-item:hover {
            background: var(--accent-primary);
            color: white;
        }

        .pdf-viewer {
            width: 100%;
            height: 600px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
        }

        .actions-bar {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .search-codes {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            width: 100%;
            max-width: 300px;
            margin-bottom: 1rem;
        }

        .copied-toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--accent-success);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-md);
            display: none;
            z-index: 1000;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <div class="doc-header">
                    <div class="doc-title">
                        <h1>📄
                            <?= htmlspecialchars($doc['numero']) ?>
                        </h1>
                        <div class="doc-meta">
                            <span class="doc-meta-item">
                                <span class="badge badge-primary">
                                    <?= strtoupper($doc['tipo']) ?>
                                </span>
                            </span>
                            <span class="doc-meta-item">
                                📅
                                <?= htmlspecialchars($doc['fecha']) ?>
                            </span>
                            <?php if (!empty($doc['proveedor'])): ?>
                                <span class="doc-meta-item">
                                    🏢
                                    <?= htmlspecialchars($doc['proveedor']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="actions-bar">
                        <a href="../recientes/" class="btn btn-secondary">← Volver</a>
                        <?php if ($pdfPath): ?>
                            <a href="<?= htmlspecialchars($pdfPath) ?>" target="_blank" class="btn btn-primary">
                                📥 Descargar PDF
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="label">ID Documento</div>
                        <div class="value">#
                            <?= $doc['id'] ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="label">Códigos Enlazados</div>
                        <div class="value">
                            <?= count($codigos) ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="label">Fecha Creación</div>
                        <div class="value">
                            <?= date('d/m/Y', strtotime($doc['fecha_creacion'] ?? 'now')) ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="label">Archivo</div>
                        <div class="value" style="font-size: 0.75rem; word-break: break-all;">
                            <?= htmlspecialchars($doc['ruta_archivo'] ?: 'No disponible') ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($codigos)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">🏷️ Códigos Enlazados (
                                <?= count($codigos) ?>)
                            </h3>
                        </div>
                        <div style="padding: 0 1rem 1rem;">
                            <input type="text" id="searchCodes" class="search-codes" placeholder="🔍 Buscar código...">
                            <div class="codes-grid" id="codesGrid">
                                <?php foreach ($codigos as $codigo): ?>
                                    <div class="code-item" onclick="copyCode('<?= htmlspecialchars($codigo['codigo']) ?>')"
                                        title="Clic para copiar">
                                        <?= htmlspecialchars($codigo['codigo']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="empty-state">
                            <div class="empty-state-icon">🏷️</div>
                            <h4 class="empty-state-title">Sin códigos enlazados</h4>
                            <p class="empty-state-text">Este documento no tiene códigos asociados.
                                Use "Sincronizar BD" para enlazar códigos.</p>
                            <a href="../sincronizar/" class="btn btn-primary" style="margin-top: 1rem;">
                                🔗 Sincronizar BD
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($pdfPath): ?>
                    <div class="card" style="margin-top: 1.5rem;">
                        <div class="card-header">
                            <h3 class="card-title">📄 Vista Previa del PDF</h3>
                        </div>
                        <iframe src="<?= htmlspecialchars($pdfPath) ?>" class="pdf-viewer"></iframe>
                    </div>
                <?php endif; ?>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <div class="copied-toast" id="copiedToast">✓ Código copiado</div>

    <script>
        // Buscar códigos
        document.getElementById('searchCodes')?.addEventListener('input', function (e) {
            const search = e.target.value.toLowerCase();
            document.querySelectorAll('.code-item').forEach(item => {
                const code = item.textContent.toLowerCase();
                item.style.display = code.includes(search) ? '' : 'none';
            });
        });

        // Copiar código
        function copyCode(code) {
            navigator.clipboard.writeText(code).then(() => {
                const toast = document.getElementById('copiedToast');
                toast.textContent = '✓ ' + code + ' copiado';
                toast.style.display = 'block';
                setTimeout(() => toast.style.display = 'none', 2000);
            });
        }
    </script>
</body>

</html>