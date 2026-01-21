<?php
/**
 * KINO TRACE - Dashboard Principal
 * 
 * Landing page principal después del login.
 * Muestra el gestor de documentos con búsqueda inteligente.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/tenant.php';
require_once __DIR__ . '/helpers/search_engine.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: login.php');
    exit;
}

$clientCode = $_SESSION['client_code'];
$db = open_client_db($clientCode);
$stats = get_search_stats($db);

// Para sidebar
$currentModule = 'gestor';
$baseUrl = './';
$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KINO TRACE</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        /* Dashboard Hero Section */
        .dashboard-hero {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .dashboard-hero h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .dashboard-hero p {
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Results styling */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .result-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            transition: all var(--transition-fast);
        }

        .result-card:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-md);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .result-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .result-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .codes-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .summary-box {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .summary-box.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-hero {
                padding: 1.5rem 1rem;
            }

            .dashboard-hero h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main-content">
            <?php include __DIR__ . '/includes/header.php'; ?>

            <div class="page-content">
                <!-- Hero Section -->
                <div class="dashboard-hero">
                    <h1>🔍 Gestor de Documentos - KINO TRACE</h1>
                    <p>Búsqueda inteligente de documentos por códigos. El sistema encuentra automáticamente todos los
                        documentos que contienen tus códigos.</p>
                </div>

                <!-- Stats Bar -->
                <div class="stats-grid" style="margin-bottom: 1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['total_documents']) ?></div>
                            <div class="stat-label">Documentos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['unique_codes']) ?></div>
                            <div class="stat-label">Códigos Únicos</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= number_format($stats['validated_codes']) ?></div>
                            <div class="stat-label">Validados</div>
                        </div>
                    </div>
                </div>


                <!-- Dashboard con Tarjetas de Acceso -->
                <div class="dashboard-grid">
                    <!-- Tarjeta: Gestor de Documentos -->
                    <a href="modules/busqueda/" class="dashboard-card primary-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                            </svg>
                        </div>
                        <h3>Gestor de Documentos</h3>
                        <p>Buscar, subir, consultar y gestionar todos tus documentos</p>
                        <span class="card-action">Abrir Gestor →</span>
                    </a>

                    <!-- Tarjeta: Resaltador PDF -->
                    <a href="modules/resaltar/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                            </svg>
                        </div>
                        <h3>Resaltador PDF</h3>
                        <p>Visualiza PDFs con términos resaltados automáticamente</p>
                        <span class="card-action">Abrir Visor →</span>
                    </a>

                    <!-- Tarjeta: Manifiestos -->
                    <a href="modules/manifiestos/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                            </svg>
                        </div>
                        <h3>Manifiestos</h3>
                        <p>Gestión de manifiestos de carga</p>
                        <span class="card-action">Ver Manifiestos →</span>
                    </a>

                    <!-- Tarjeta: Declaraciones -->
                    <a href="modules/declaraciones/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <h3>Declaraciones</h3>
                        <p>Gestión de declaraciones aduaneras</p>
                        <span class="card-action">Ver Declaraciones →</span>
                    </a>

                    <!-- Tarjeta: Subir Documento -->
                    <a href="modules/subir/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                        </div>
                        <h3>Subir Documento</h3>
                        <p>Subir nuevo documento PDF con códigos</p>
                        <span class="card-action">Subir →</span>
                    </a>

                    <!-- Tarjeta: Importar Excel -->
                    <a href="modules/excel_import/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125" />
                            </svg>
                        </div>
                        <h3>Importar Excel</h3>
                        <p>Importar datos desde Excel o CSV</p>
                        <span class="card-action">Importar →</span>
                    </a>

                    <!-- Tarjeta: Indexar PDFs -->
                    <a href="modules/indexar/" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                        </div>
                        <h3>Indexar PDFs</h3>
                        <p>Indexar documentos para búsqueda full-text</p>
                        <span class="card-action">Indexar →</span>
                    </a>

                    <!-- Tarjeta: Backup -->
                    <a href="admin/backup.php" class="dashboard-card">
                        <div class="card-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </div>
                        <h3>Backup</h3>
                        <p>Descargar respaldo completo de datos</p>
                        <span class="card-action">Crear Backup →</span>
                    </a>
                </div>
            </div>

            <?php include __DIR__ . '/includes/footer.php'; ?>
        </main>
    </div>

    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .dashboard-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .dashboard-card:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
        }

        .dashboard-card.primary-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(16, 185, 129, 0.1) 100%);
            border-color: var(--accent-primary);
        }

        .card-icon {
            width: 56px;
            height: 56px;
            background: var(--accent-primary);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            color: white;
        }

        .dashboard-card h3 {
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .dashboard-card p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .card-action {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--accent-primary);
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>

</html>