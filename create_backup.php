<?php
/**
 * CREATE BACKUP - KINO TRACE
 * Genera un archivo ZIP compatible con restore_pro.php
 * Incluye base de datos y carpeta uploads del cliente.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';

$message = "";
$error = "";
$downloadFile = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientCode = $_POST['client_code'] ?? '';

    if (empty($clientCode)) {
        $error = "Debe ingresar un código de cliente.";
    } else {
        $clientPath = CLIENTS_DIR . '/' . sanitize_code($clientCode);

        if (!is_dir($clientPath)) {
            $error = "El cliente no existe.";
        } else {
            // Configurar ZIP
            $zipName = "backup_" . $clientCode . "_" . date('Y-m-d_H-i-s') . ".zip";
            $zipPath = sys_get_temp_dir() . '/' . $zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {

                // 1. Agregar Base de Datos
                if (file_exists($clientPath . '/data.db')) {
                    $zip->addFile($clientPath . '/data.db', 'data.db');
                } else {
                    $message .= "⚠️ Advertencia: No se encontró data.db. ";
                }

                // 2. Agregar Archivos (Uploads) recursivamente
                $uploadsDir = $clientPath . '/uploads';
                if (is_dir($uploadsDir)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($uploadsDir),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );

                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = 'uploads/' . substr($filePath, strlen($uploadsDir) + 1);
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                }

                $zip->close();

                // Preparar descarga
                if (file_exists($zipPath)) {
                    // Mover a una carpeta pública temporal para descarga o servir directo
                    // Para simplificar, serviremos directo con header en el mismo script si se pide
                    $downloadFile = $zipPath;
                    $message = "✅ Backup generado exitosamente.";
                } else {
                    $error = "Error al crear el archivo ZIP.";
                }

            } else {
                $error = "No se pudo iniciar el creador de ZIP.";
            }
        }
    }
}

// Servir descarga si existe
if ($downloadFile && empty($error)) {
    if (file_exists($downloadFile)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($downloadFile) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($downloadFile));
        readfile($downloadFile);
        unlink($downloadFile); // Borrar temporal después de enviar
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Crear Backup - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .container {
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-backup {
            background: #059669;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            width: 100%;
            font-size: 16px;
            cursor: pointer;
        }

        .btn-backup:hover {
            background: #047857;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body style="background: #f3f4f6;">
    <div class="container">
        <h2 style="text-align: center; color: #1f2937;">💾 Crear Respaldo Cliente</h2>
        <p style="text-align: center; color: #6b7280; margin-bottom: 20px;">
            Genera un ZIP compatible con <i>Restore Pro</i> que incluye la base de datos y todos los documentos.
        </p>

        <?php if ($error): ?>
            <div class="alert error">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Código del Cliente:</label>
                <input type="text" name="client_code" required placeholder="Ej: DEMO"
                    style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
            </div>
            <button type="submit" class="btn-backup">📦 Generar y Descargar ZIP</button>
        </form>

        <div style="margin-top: 20px; text-align: center;">
            <a href="index.php" style="color: #2563eb; text-decoration: none;">&larr; Volver al Inicio</a>
        </div>
    </div>
</body>

</html>