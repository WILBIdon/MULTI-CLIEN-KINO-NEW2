<?php
// modules/importar_masiva/index.php
session_start();
require_once '../../config.php';
require_once '../../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ../../login.php');
    exit;
}

$pageTitle = "Importación Masiva (CSV + ZIP)";
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">Importación Masiva (CSV / Excel)</h1>
        <a href="../../index.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Volver al Inicio
        </a>
    </div>

    <!-- Instructions Card -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-primary text-white">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-info-circle"></i> Instrucciones</h6>
        </div>
        <div class="card-body">
            <p>Sube un archivo <strong>CSV</strong> con los datos y un archivo <strong>ZIP</strong> con los PDFs
                originales.</p>
            <div class="alert alert-warning">
                <strong>Formato CSV Requerido:</strong><br>
                El archivo debe tener las siguientes columnas (el orden no importa, pero los nombres sí):
                <ul>
                    <li><code>nombre_pdf</code>: Nombre exacto del archivo PDF (ej: <code>1748..._KARDOO.pdf</code>).
                        <strong>Clave para enlazar.</strong></li>
                    <li><code>nombre_doc</code>: Numero o Nombre visible del documento (ej:
                        <code>KARDOO AGOSTO 2020A</code>).</li>
                    <li><code>fecha</code>: Fecha del documento (Formato: YYYY-MM-DD).</li>
                    <li><code>codigos</code>: Lista de códigos separados por comas (ej:
                        <code>K-353A, K-609, ...</code>).</li>
                </ul>
            </div>
            <p class="mb-0 text-muted small">Nota: Si usas Excel, guarda el archivo como "CSV (delimitado por comas)".
            </p>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="row">
        <div class="col-lg-6 mx-auto">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Cargar Archivos</h6>
                </div>
                <div class="card-body">
                    <form id="uploadForm" enctype="multipart/form-data">

                        <!-- CSV Input -->
                        <div class="form-group">
                            <label for="csv_file" class="font-weight-bold">1. Archivo de Datos (.csv)</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="csv_file" name="csv_file" accept=".csv"
                                    required>
                                <label class="custom-file-label" for="csv_file">Seleccionar CSV...</label>
                            </div>
                            <small class="form-text text-muted">Contiene nombres, fechas y códigos.</small>
                        </div>

                        <hr>

                        <!-- ZIP Input -->
                        <div class="form-group">
                            <label for="zip_file" class="font-weight-bold">2. Archivo de Documentos (.zip)</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" id="zip_file" name="zip_file"
                                    accept=".zip,.rar" required>
                                <label class="custom-file-label" for="zip_file">Seleccionar ZIP...</label>
                            </div>
                            <small class="form-text text-muted">Contiene los archivos PDF originales.</small>
                        </div>

                        <hr>

                        <!-- Action Buttons -->
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <button type="button" class="btn btn-danger btn-block" id="btnReset">
                                    <i class="fas fa-trash-alt"></i> Limpiar Todo (Reset)
                                </button>
                            </div>
                            <div class="col-md-6 mb-2">
                                <button type="submit" class="btn btn-success btn-block" id="btnImport">
                                    <i class="fas fa-upload"></i> Iniciar Importación
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Progress Bar -->
                    <div class="progress mt-4 d-none" id="progressBarContainer">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                            style="width: 0%" id="progressBar">0%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Console Output -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-dark text-white d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-terminal"></i> Consola de Salida</h6>
            <button class="btn btn-sm btn-outline-light"
                onclick="document.getElementById('consoleOutput').innerHTML = ''">Limpiar</button>
        </div>
        <div class="card-body bg-dark text-monospace p-0">
            <div id="consoleOutput"
                style="height: 300px; overflow-y: auto; color: #0f0; padding: 1rem; font-size: 0.85rem;">
                <span class="text-muted">// Esperando acción...</span>
            </div>
        </div>
    </div>

</div>

<script>
    // Helper for DOM elements
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => document.querySelectorAll(selector);

    // Custom File Input Label
    $$(".custom-file-input").forEach(input => {
        input.addEventListener("change", function () {
            var fileName = this.value.split("\\").pop();
            var label = this.nextElementSibling;
            label.classList.add("selected");
            label.innerHTML = fileName || 'Seleccionar archivo...';
        });
    });

    const consoleDiv = document.getElementById('consoleOutput');
    function log(msg, type = 'info') {
        const line = document.createElement('div');
        let color = '#fff'; // default
        let icon = '';

        if (type === 'error') { color = '#ff6b6b'; icon = '❌ '; }
        else if (type === 'success') { color = '#51cf66'; icon = '✅ '; }
        else if (type === 'warning') { color = '#fcc419'; icon = '⚠️ '; }
        else { color = '#339af0'; icon = 'ℹ️ '; } // info

        line.style.color = color;
        line.innerText = icon + msg;
        consoleDiv.appendChild(line);
        consoleDiv.scrollTop = consoleDiv.scrollHeight;
    }

    // Reset Action
    document.getElementById('btnReset').addEventListener('click', async function () {
        if (!confirm('¿ESTÁS SEGURO? Esto borrará TODA la base de datos actual.')) return;

        log('Iniciando Reset...', 'warning');

        try {
            const formData = new FormData();
            formData.append('action', 'reset');

            const response = await fetch('process.php', {
                method: 'POST',
                body: formData
            });

            const resp = await response.json();

            if (resp.success) {
                log('Base de datos reiniciada correctamente.', 'success');
            } else {
                log('Error al reiniciar: ' + (resp.error || 'Desconocido'), 'error');
            }
        } catch (error) {
            log('Error de red al intentar reiniciar: ' + error.message, 'error');
        }
    });

    // Import Action
    document.getElementById('uploadForm').addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('action', 'import');

        const progressBarContainer = document.getElementById('progressBarContainer');
        const progressBar = document.getElementById('progressBar');
        const btnImport = document.getElementById('btnImport');

        progressBarContainer.classList.remove('d-none');
        progressBar.style.width = '10%';
        progressBar.innerText = 'Subiendo...';
        btnImport.disabled = true;

        log('Iniciando subida de archivos... Espere por favor.', 'info');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'process.php', true);

        // Progress event
        xhr.upload.addEventListener("progress", function (evt) {
            if (evt.lengthComputable) {
                var percentComplete = (evt.loaded / evt.total) * 100;
                progressBar.style.width = percentComplete + '%';
                progressBar.innerText = Math.round(percentComplete) + '%';
            }
        }, false);

        // Completion event
        xhr.onload = function () {
            btnImport.disabled = false;

            if (xhr.status === 200) {
                try {
                    const resp = JSON.parse(xhr.responseText);

                    if (resp.logs && Array.isArray(resp.logs)) {
                        resp.logs.forEach(l => log(l.msg, l.type));
                    }

                    if (resp.success) {
                        progressBar.style.width = '100%';
                        progressBar.classList.add('bg-success');
                        progressBar.innerText = 'Completado';
                        log('Proceso finalizado correctamente.', 'success');
                    } else {
                        progressBar.classList.add('bg-danger');
                        progressBar.innerText = 'Error';
                        log('Error en proceso: ' + (resp.error || 'Verifique logs'), 'error');
                    }
                } catch (e) {
                    log('Error parseando respuesta del servidor: ' + xhr.responseText.substring(0, 100) + '...', 'error');
                    console.error('Raw response:', xhr.responseText);
                }
            } else {
                progressBar.classList.add('bg-danger');
                progressBar.innerText = 'Fallo HTTP';
                log('Error del servidor: ' + xhr.status + ' ' + xhr.statusText, 'error');
            }
        };

        // Error event
        xhr.onerror = function () {
            btnImport.disabled = false;
            progressBar.classList.add('bg-danger');
            progressBar.innerText = 'Fallo Red';
            log('Error de comunicación de red.', 'error');
        };

        xhr.send(formData);
    });
</script>

<?php require_once '../../includes/footer.php'; ?>