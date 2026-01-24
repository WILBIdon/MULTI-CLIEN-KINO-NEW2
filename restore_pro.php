<?php
/**
 * RESTORE PRO - KINO TRACE
 * Restauración profesional para grandes volúmenes de datos.
 */

// CONFIGURACIÓN DE ALTO RENDIMIENTO
set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('post_max_size', '1024M');
ini_set('upload_max_filesize', '1024M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

$mensaje = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigoCliente = $_POST['client_code'] ?? '';

    if (empty($codigoCliente)) {
        $error = "Falta el código del cliente.";
    } elseif (!isset($_FILES['backup_zip']) || $_FILES['backup_zip']['error'] !== UPLOAD_ERR_OK) {
        $error = "Error al subir el archivo ZIP. Código: " . $_FILES['backup_zip']['error'];
    } else {
        $zipPath = $_FILES['backup_zip']['tmp_name'];
        $zip = new ZipArchive;

        if ($zip->open($zipPath) === TRUE) {
            $rutaCliente = CLIENTS_DIR . '/' . sanitize_code($codigoCliente);
            $uploadsDir = $rutaCliente . '/uploads';

            // 1. Asegurar directorios
            if (!is_dir($uploadsDir))
                mkdir($uploadsDir, 0777, true);

            // 2. Extraer Archivos (Iterativo para control)
            $archivosExtraidos = 0;
            $archivosTotales = $zip->numFiles;

            for ($i = 0; $i < $archivosTotales; $i++) {
                $filename = $zip->getNameIndex($i);
                $fileinfo = pathinfo($filename);

                // Extraer solo si no es carpeta y es seguro
                if (substr($filename, -1) !== '/') {
                    // Si es la base de datos (data.db)
                    if (basename($filename) == 'data.db') {
                        copy("zip://" . $zipPath . "#" . $filename, $rutaCliente . '/data.db');
                    }
                    // Si es un archivo de uploads (PDFs, imágenes)
                    elseif (strpos($filename, 'uploads/') !== false) {
                        // Limpiar ruta para evitar 'uploads/uploads/...'
                        $relativePath = str_replace('uploads/', '', $filename);
                        $target = $uploadsDir . '/' . $relativePath;

                        // Crear subcarpetas si no existen
                        if (!is_dir(dirname($target)))
                            mkdir(dirname($target), 0777, true);

                        copy("zip://" . $zipPath . "#" . $filename, $target);
                        $archivosExtraidos++;
                    }
                }
            }

            $zip->close();

            // 3. Reparar Permisos y Estructura DB
            // Ejecutar optimización post-restauración
            if (file_exists(__DIR__ . '/optimize_db.php')) {
                require_once __DIR__ . '/optimize_db.php';
                if (function_exists('optimize_client_db')) {
                    optimize_client_db($codigoCliente);
                }
            }

            $mensaje = "✅ Restauración Completada: Se procesaron $archivosExtraidos de $archivosTotales archivos.";
        } else {
            $error = "No se pudo abrir el archivo ZIP.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Restaurador Pro</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-restore {
            background: #2563eb;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .danger {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body style="background: #f3f4f6;">
    <div class="container">
        <h2 style="text-align: center;">🔄 Restaurador de Backup Pro</h2>

        <?php if ($mensaje): ?>
            <div class="alert success">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert danger">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div style="margin-bottom: 15px;">
                <label>Código del Cliente (Carpeta destino):</label>
                <input type="text" name="client_code" required style="width: 100%; padding: 8px; margin-top: 5px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label>Archivo ZIP de Respaldo:</label>
                <input type="file" name="backup_zip" accept=".zip" required
                    style="width: 100%; padding: 8px; margin-top: 5px;">
                <small style="color: #666;">Asegúrate de que el PHP.ini permita archivos grandes.</small>
            </div>

            <button type="submit" class="btn-restore" onclick="this.innerHTML='⏳ Restaurando (No cierres)...';">
                Iniciar Restauración Completa
            </button>
        </form>
    </div>
</body>

</html>