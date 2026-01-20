<?php
/**
 * Excel/CSV Import Module - KINO TRACE
 * 
 * Allows importing data from Excel (.xlsx) or CSV files to:
 * - Match file names with existing documents
 * - Add codes to matched documents
 * - Generate import reports
 * 
 * Supports CSV and basic XLSX parsing without external libraries.
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

// For sidebar
$currentModule = 'excel_import';
$baseUrl = '../../';
$pageTitle = 'Importar Excel';

$results = [];
$error = '';
$success = '';
$stats = ['matched' => 0, 'not_found' => 0, 'codes_added' => 0];

/**
 * Parse CSV file
 */
function parseCSV(string $filePath): array
{
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle, 0, ',');
        if ($headers) {
            // Normalize headers
            $headers = array_map(function ($h) {
                return strtolower(trim($h));
            }, $headers);

            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                if (count($data) >= count($headers)) {
                    $rows[] = array_combine($headers, array_slice($data, 0, count($headers)));
                }
            }
        }
        fclose($handle);
    }
    return $rows;
}

/**
 * Parse XLSX file (basic XML parsing without external libraries)
 * Only works with simple XLSX files
 */
function parseXLSX(string $filePath): array
{
    $rows = [];

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $rows;
    }

    // Read shared strings
    $sharedStrings = [];
    $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($stringsXml) {
        $xml = @simplexml_load_string($stringsXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string) $si->t;
            }
        }
    }

    // Read first sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml) {
        $xml = @simplexml_load_string($sheetXml);
        if ($xml && isset($xml->sheetData)) {
            $allRows = [];
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $value = '';
                    $type = (string) $cell['t'];

                    if ($type === 's') {
                        // Shared string
                        $index = (int) $cell->v;
                        $value = $sharedStrings[$index] ?? '';
                    } else {
                        $value = (string) $cell->v;
                    }

                    $rowData[] = $value;
                }
                $allRows[] = $rowData;
            }

            if (!empty($allRows)) {
                // First row as headers
                $headers = array_map(function ($h) {
                    return strtolower(trim($h));
                }, $allRows[0]);

                // Rest as data
                for ($i = 1; $i < count($allRows); $i++) {
                    if (count($allRows[$i]) >= count($headers)) {
                        $rows[] = array_combine($headers, array_slice($allRows[$i], 0, count($headers)));
                    }
                }
            }
        }
    }

    $zip->close();
    return $rows;
}

/**
 * Parse uploaded file (CSV or XLSX)
 */
function parseUploadedFile(array $file): array
{
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        return parseCSV($file['tmp_name']);
    } elseif ($ext === 'xlsx') {
        return parseXLSX($file['tmp_name']);
    }

    return [];
}

/**
 * Find document by name/number
 */
function findDocumentByName(PDO $db, string $name): ?array
{
    $name = trim($name);
    if (empty($name)) {
        return null;
    }

    // Remove file extension if present
    $nameClean = preg_replace('/\.(pdf|xlsx?|csv)$/i', '', $name);

    // Try exact match first
    $stmt = $db->prepare('SELECT * FROM documentos WHERE numero = ? OR numero LIKE ? LIMIT 1');
    $stmt->execute([$name, '%' . $nameClean . '%']);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        return $doc;
    }

    // Try partial match
    $stmt = $db->prepare('SELECT * FROM documentos WHERE numero LIKE ? OR ruta_archivo LIKE ? LIMIT 1');
    $stmt->execute(['%' . $nameClean . '%', '%' . $nameClean . '%']);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Process upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx'])) {
            $error = 'Formato no soportado. Use archivos .csv o .xlsx';
        } else {
            $data = parseUploadedFile($file);

            if (empty($data)) {
                $error = 'No se pudo leer el archivo o está vacío';
            } else {
                // Get column names from first row
                $columns = array_keys($data[0]);

                // Find the file/document name column
                $nameColumn = null;
                $codeColumn = null;

                foreach ($columns as $col) {
                    $colLower = strtolower($col);
                    if (in_array($colLower, ['archivo', 'nombre', 'documento', 'file', 'name', 'numero'])) {
                        $nameColumn = $col;
                    }
                    if (in_array($colLower, ['codigo', 'code', 'código', 'codigos', 'codes'])) {
                        $codeColumn = $col;
                    }
                }

                if (!$nameColumn) {
                    $error = 'No se encontró columna de nombre/archivo. Columnas detectadas: ' . implode(', ', $columns);
                } else {
                    // Process each row
                    $stmtInsertCode = $db->prepare('INSERT OR IGNORE INTO codigos (documento_id, codigo) VALUES (?, ?)');

                    foreach ($data as $row) {
                        $fileName = $row[$nameColumn] ?? '';
                        $code = $codeColumn ? ($row[$codeColumn] ?? '') : '';

                        $doc = findDocumentByName($db, $fileName);

                        $result = [
                            'file_name' => $fileName,
                            'code' => $code,
                            'matched' => false,
                            'doc_id' => null,
                            'doc_numero' => null
                        ];

                        if ($doc) {
                            $result['matched'] = true;
                            $result['doc_id'] = $doc['id'];
                            $result['doc_numero'] = $doc['numero'];
                            $stats['matched']++;

                            // Add code if present
                            if (!empty($code)) {
                                $stmtInsertCode->execute([$doc['id'], $code]);
                                $stats['codes_added']++;
                            }
                        } else {
                            $stats['not_found']++;
                        }

                        $results[] = $result;
                    }

                    if ($stats['matched'] > 0) {
                        $success = "✅ Importación completada: {$stats['matched']} documentos encontrados";
                        if ($stats['codes_added'] > 0) {
                            $success .= ", {$stats['codes_added']} códigos agregados";
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Excel - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        .import-hero {
            text-align: center;
            padding: 2.5rem 2rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%);
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
        }

        .import-hero h1 {
            margin-bottom: 0.5rem;
        }

        .import-hero p {
            color: var(--text-secondary);
            max-width: 500px;
            margin: 0 auto;
        }

        .upload-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 3rem 2rem;
            text-align: center;
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }

        .upload-zone:hover {
            border-color: var(--accent-primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .upload-zone.dragover {
            border-color: var(--accent-success);
            background: rgba(16, 185, 129, 0.1);
        }

        .upload-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .upload-zone input[type="file"] {
            display: none;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            background: var(--bg-secondary);
            padding: 1.25rem;
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .stat-item .value {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .stat-item .label {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .stat-item.success .value {
            color: var(--accent-success);
        }

        .stat-item.warning .value {
            color: var(--accent-warning);
        }

        .stat-item.info .value {
            color: var(--accent-primary);
        }

        .results-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .results-table th,
        .results-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-light);
        }

        .results-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
        }

        .scroll-table {
            max-height: 400px;
            overflow-y: auto;
        }

        .status-matched {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-not-found {
            background: rgba(239, 68, 68, 0.1);
            color: var(--accent-danger);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .format-info {
            background: var(--bg-tertiary);
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-top: 1rem;
        }

        .format-info h4 {
            margin-bottom: 0.5rem;
        }

        .format-info ul {
            margin: 0;
            padding-left: 1.25rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--accent-danger);
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="page-content">
                <?php if ($error): ?>
                    <div class="alert alert-error">⚠️
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($results)): ?>
                    <div class="import-hero">
                        <h1>📊 Importar desde Excel</h1>
                        <p>Sube un archivo Excel (.xlsx) o CSV para cruzar datos con tus documentos existentes.</p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-zone" id="uploadZone">
                            <div class="upload-icon">📁</div>
                            <p><strong>Arrastra tu archivo aquí</strong></p>
                            <p style="color: var(--text-muted); font-size: 0.875rem;">o haz clic para seleccionar</p>
                            <p style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.5rem;">
                                Formatos soportados: .xlsx, .csv
                            </p>
                            <input type="file" name="excel_file" id="fileInput" accept=".csv,.xlsx">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;" id="submitBtn" disabled>
                            📥 Procesar Archivo
                        </button>
                    </form>

                    <div class="format-info">
                        <h4>📋 Formato esperado del archivo</h4>
                        <ul>
                            <li>Primera fila debe contener los nombres de columnas</li>
                            <li>Columna <strong>"archivo"</strong> o <strong>"nombre"</strong>: nombre del documento a
                                buscar</li>
                            <li>Columna <strong>"codigo"</strong> (opcional): código a agregar al documento encontrado</li>
                            <li>Se buscarán coincidencias parciales por nombre</li>
                        </ul>
                    </div>

                <?php else: ?>
                    <h2 style="margin-bottom: 1rem;">📊 Resultados de la Importación</h2>

                    <div class="stats-row">
                        <div class="stat-item success">
                            <div class="value">
                                <?= $stats['matched'] ?>
                            </div>
                            <div class="label">Documentos Encontrados</div>
                        </div>
                        <div class="stat-item warning">
                            <div class="value">
                                <?= $stats['not_found'] ?>
                            </div>
                            <div class="label">No Encontrados</div>
                        </div>
                        <div class="stat-item info">
                            <div class="value">
                                <?= $stats['codes_added'] ?>
                            </div>
                            <div class="label">Códigos Agregados</div>
                        </div>
                    </div>

                    <div class="results-card">
                        <h3 style="margin-bottom: 1rem;">📋 Detalle</h3>
                        <div class="scroll-table">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>Archivo/Nombre</th>
                                        <th>Estado</th>
                                        <th>Documento Encontrado</th>
                                        <th>Código</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $r): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($r['file_name']) ?>
                                            </td>
                                            <td>
                                                <?php if ($r['matched']): ?>
                                                    <span class="status-matched">✓ Encontrado</span>
                                                <?php else: ?>
                                                    <span class="status-not-found">✗ No encontrado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($r['doc_numero']): ?>
                                                    <a href="../documento/view.php?id=<?= $r['doc_id'] ?>">
                                                        <?= htmlspecialchars($r['doc_numero']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($r['code'] ?: '-') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 1rem;">
                            <a href="index.php" class="btn btn-primary">📥 Nueva Importación</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php include __DIR__ . '/../../includes/footer.php'; ?>
        </main>
    </div>

    <script>
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');
        const submitBtn = document.getElementById('submitBtn');
        const form = document.getElementById('uploadForm');

        if (uploadZone) {
            uploadZone.addEventListener('click', () => fileInput.click());

            uploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadZone.classList.add('dragover');
            });

            uploadZone.addEventListener('dragleave', () => {
                uploadZone.classList.remove('dragover');
            });

            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileName();
                }
            });

            fileInput.addEventListener('change', updateFileName);

            function updateFileName() {
                if (fileInput.files.length > 0) {
                    const fileName = fileInput.files[0].name;
                    uploadZone.innerHTML = `
                        <div class="upload-icon">📄</div>
                        <p><strong>${fileName}</strong></p>
                        <p style="color: var(--accent-success); font-size: 0.875rem;">Archivo listo para procesar</p>
                    `;
                    submitBtn.disabled = false;
                }
            }
        }
    </script>
</body>

</html>