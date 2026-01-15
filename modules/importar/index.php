<?php
/**
 * Módulo de Importación de Bases de Datos
 *
 * Permite importar datos desde archivos CSV/SQL/Excel
 * con mapeo de columnas y preview antes de importar.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/import_engine.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

$message = '';
$error = '';
$previewData = null;
$mapping = null;

// Procesar importación final
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'preview') {
        try {
            if (empty($_FILES['file']['tmp_name'])) {
                throw new Exception('Archivo no recibido');
            }

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $delimiter = $_POST['delimiter'] ?? ',';
                $previewData = parse_csv($_FILES['file']['tmp_name'], $delimiter);
            } elseif ($ext === 'sql') {
                $previewData = parse_sql_inserts($_FILES['file']['tmp_name']);
            } else {
                throw new Exception('Formato no soportado. Use CSV o SQL.');
            }

            if (isset($previewData['headers'])) {
                $mapping = suggest_column_mapping($previewData['headers']);
            }

            // Guardar archivo temporalmente para importación posterior
            $tempPath = sys_get_temp_dir() . '/import_' . session_id() . '.' . $ext;
            move_uploaded_file($_FILES['file']['tmp_name'], $tempPath);
            $_SESSION['import_temp_file'] = $tempPath;
            $_SESSION['import_type'] = $ext;

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if ($_POST['action'] === 'import') {
        try {
            if (!isset($_SESSION['import_temp_file']) || !file_exists($_SESSION['import_temp_file'])) {
                throw new Exception('Primero suba un archivo para previsualizar');
            }

            $tempPath = $_SESSION['import_temp_file'];
            $ext = $_SESSION['import_type'];
            $importType = $_POST['import_type'] ?? 'codigos';

            // Parsear el archivo guardado
            if ($ext === 'csv') {
                $delimiter = $_POST['delimiter'] ?? ',';
                $data = parse_csv($tempPath, $delimiter);
                $rows = $data['rows'];
            } else {
                $data = parse_sql_inserts($tempPath);
                // Tomar primera tabla
                $firstTable = reset($data['tables']);
                $rows = $firstTable['rows'] ?? [];
            }

            // Obtener mapeo del formulario
            $columnMapping = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'map_') === 0 && $value !== '') {
                    $sourceCol = substr($key, 4);
                    $columnMapping[$value] = $sourceCol;
                }
            }

            // Importar
            $result = import_to_database($db, $rows, $columnMapping, $importType);

            $message = "✅ Importación completada: {$result['imported']} registros importados";

            if (!empty($result['errors'])) {
                $message .= " (" . count($result['errors']) . " errores)";
            }

            // Limpiar temporal
            @unlink($tempPath);
            unset($_SESSION['import_temp_file'], $_SESSION['import_type']);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Datos - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f3f4f6;
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .header h1 {
            margin: 0;
            color: #1f2937;
        }

        .nav-links a {
            margin-left: 1rem;
            color: #2563eb;
            text-decoration: none;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .card h2 {
            margin-top: 0;
            color: #1f2937;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
        }

        .file-upload {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            background: #f9fafb;
        }

        .file-upload:hover {
            border-color: #2563eb;
        }

        .file-upload input {
            display: none;
        }

        .format-options {
            display: flex;
            gap: 1rem;
            margin: 1rem 0;
        }

        .format-option {
            flex: 1;
            padding: 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .format-option:hover {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .format-option.selected {
            border-color: #2563eb;
            background: #dbeafe;
        }

        .format-option .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        table.preview {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        table.preview th,
        table.preview td {
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            text-align: left;
        }

        table.preview th {
            background: #f3f4f6;
        }

        table.preview tr:nth-child(even) {
            background: #f9fafb;
        }

        .mapping-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 1rem;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .mapping-row .arrow {
            color: #6b7280;
        }

        .message {
            background: #dcfce7;
            color: #166534;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>📥 Importar Datos</h1>
            <div class="nav-links">
                <a href="../trazabilidad/dashboard.php">🏠 Dashboard</a>
                <a href="../busqueda/">🔍 Buscar</a>
                <a href="../../logout.php">Salir</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Paso 1: Subir archivo -->
        <div class="card">
            <h2>📁 Paso 1: Seleccionar Archivo</h2>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="preview">

                <div class="format-options">
                    <div class="format-option" onclick="selectFormat('csv')">
                        <div class="icon">📊</div>
                        <strong>CSV</strong>
                        <p style="font-size:0.875rem;color:#6b7280;">Valores separados por comas</p>
                    </div>
                    <div class="format-option" onclick="selectFormat('sql')">
                        <div class="icon">🗄️</div>
                        <strong>SQL</strong>
                        <p style="font-size:0.875rem;color:#6b7280;">Sentencias INSERT</p>
                    </div>
                </div>

                <div class="file-upload" onclick="document.getElementById('fileInput').click()">
                    <div style="font-size:2rem;">📄</div>
                    <p id="fileName">Haz clic o arrastra un archivo CSV/SQL</p>
                    <input type="file" name="file" id="fileInput" accept=".csv,.sql,.txt" required>
                </div>

                <div class="form-group" style="margin-top:1rem;">
                    <label>Delimitador (para CSV)</label>
                    <select name="delimiter">
                        <option value=",">Coma (,)</option>
                        <option value=";">Punto y coma (;)</option>
                        <option value="	">Tabulador</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                    🔍 Previsualizar Datos
                </button>
            </form>
        </div>

        <?php if ($previewData): ?>
            <!-- Paso 2: Preview y Mapeo -->
            <div class="card">
                <h2>📋 Paso 2: Previsualizar y Mapear Columnas</h2>

                <?php if (isset($previewData['headers'])): ?>
                    <p><strong>
                            <?= count($previewData['rows']) ?>
                        </strong> filas encontradas</p>

                    <h4>Vista previa (primeras 5 filas):</h4>
                    <table class="preview">
                        <thead>
                            <tr>
                                <?php foreach ($previewData['headers'] as $h): ?>
                                    <th>
                                        <?= htmlspecialchars($h) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($previewData['rows'], 0, 5) as $row): ?>
                                <tr>
                                    <?php foreach ($previewData['headers'] as $h): ?>
                                        <td>
                                            <?= htmlspecialchars($row[$h] ?? '') ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form method="POST" style="margin-top:1.5rem;">
                        <input type="hidden" name="action" value="import">

                        <h4>Mapeo de columnas:</h4>
                        <p style="color:#6b7280;">Selecciona a qué campo corresponde cada columna del archivo</p>

                        <?php foreach ($previewData['headers'] as $h): ?>
                            <div class="mapping-row">
                                <span><strong>
                                        <?= htmlspecialchars($h) ?>
                                    </strong></span>
                                <span class="arrow">→</span>
                                <select name="map_<?= htmlspecialchars($h) ?>">
                                    <option value="">-- Ignorar --</option>
                                    <option value="codigo" <?= ($mapping[$h] ?? '') === 'codigo' ? 'selected' : '' ?>>Código</option>
                                    <option value="descripcion" <?= ($mapping[$h] ?? '') === 'descripcion' ? 'selected' : '' ?>
                                        >Descripción</option>
                                    <option value="cantidad" <?= ($mapping[$h] ?? '') === 'cantidad' ? 'selected' : '' ?>>Cantidad
                                    </option>
                                    <option value="valor" <?= ($mapping[$h] ?? '') === 'valor' ? 'selected' : '' ?>>Valor</option>
                                    <option value="fecha" <?= ($mapping[$h] ?? '') === 'fecha' ? 'selected' : '' ?>>Fecha</option>
                                    <option value="proveedor" <?= ($mapping[$h] ?? '') === 'proveedor' ? 'selected' : '' ?>>Proveedor
                                    </option>
                                    <option value="tipo" <?= ($mapping[$h] ?? '') === 'tipo' ? 'selected' : '' ?>>Tipo</option>
                                    <option value="numero" <?= ($mapping[$h] ?? '') === 'numero' ? 'selected' : '' ?>>Número Doc
                                    </option>
                                </select>
                            </div>
                        <?php endforeach; ?>

                        <div class="form-group" style="margin-top:1rem;">
                            <label>Tipo de importación</label>
                            <select name="import_type">
                                <option value="codigos">Códigos de productos</option>
                                <option value="documentos">Documentos</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success" style="margin-top:1rem;">
                            ✅ Importar
                            <?= count($previewData['rows']) ?> registros
                        </button>
                    </form>

                <?php elseif (isset($previewData['tables'])): ?>
                    <p><strong>
                            <?= $previewData['total_rows'] ?>
                        </strong> filas en
                        <?= count($previewData['tables']) ?> tabla(s)
                    </p>

                    <?php foreach ($previewData['tables'] as $tableName => $tableData): ?>
                        <h4>Tabla:
                            <?= htmlspecialchars($tableName) ?> (
                            <?= count($tableData['rows']) ?> filas)
                        </h4>
                        <table class="preview">
                            <thead>
                                <tr>
                                    <?php foreach ($tableData['columns'] as $col): ?>
                                        <th>
                                            <?= htmlspecialchars($col) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($tableData['rows'], 0, 5) as $row): ?>
                                    <tr>
                                        <?php foreach ($tableData['columns'] as $col): ?>
                                            <td>
                                                <?= htmlspecialchars($row[$col] ?? '') ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>

                    <form method="POST" style="margin-top:1.5rem;">
                        <input type="hidden" name="action" value="import">

                        <div class="form-group">
                            <label>Tipo de importación</label>
                            <select name="import_type">
                                <option value="codigos">Códigos de productos</option>
                                <option value="documentos">Documentos</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success">
                            ✅ Importar Datos
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.getElementById('fileInput').addEventListener('change', function () {
            document.getElementById('fileName').textContent = this.files[0]?.name || 'Selecciona un archivo';
        });

        function selectFormat(format) {
            document.querySelectorAll('.format-option').forEach(el => el.classList.remove('selected'));
            event.currentTarget.classList.add('selected');

            // Actualizar aceptación del input
            const input = document.getElementById('fileInput');
            if (format === 'csv') {
                input.accept = '.csv,.txt';
            } else if (format === 'sql') {
                input.accept = '.sql,.txt';
            }
        }
    </script>
</body>

</html>