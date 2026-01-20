<?php
/**
 * Sidebar Navigation Component
 * 
 * Collapsible sidebar with navigation menu.
 * Uses flat, single-tone SVG icons for minimalist look.
 */

// Get client info for branding
$clientCode = $_SESSION['client_code'] ?? 'guest';
$clientName = 'KINO TRACE';

// Try to get client name from central DB if available
if (isset($centralDb)) {
    $stmt = $centralDb->prepare('SELECT nombre, titulo FROM control_clientes WHERE codigo = ? LIMIT 1');
    $stmt->execute([$clientCode]);
    $clientInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($clientInfo) {
        $clientName = $clientInfo['titulo'] ?: $clientInfo['nombre'];
    }
}

// Current module for active state
$currentModule = $currentModule ?? '';
?>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <!-- Header / Brand -->
    <div class="sidebar-header">
        <div class="sidebar-logo">K</div>
        <div class="sidebar-brand">
            <h1>
                <?= htmlspecialchars($clientName) ?>
            </h1>
            <span>Gestión Documental</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <!-- Main Tools -->
        <div class="nav-section">
            <div class="nav-section-title">Herramientas</div>

            <a href="<?= $baseUrl ?? '' ?>modules/busqueda/"
                class="nav-item <?= $currentModule === 'gestor' ? 'active' : '' ?>" data-tooltip="Gestor Doc">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />
                    </svg>
                </span>
                <span class="nav-label">Gestor Doc</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/resaltar/"
                class="nav-item <?= $currentModule === 'resaltar' ? 'active' : '' ?>" data-tooltip="Resaltar Doc">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                    </svg>
                </span>
                <span class="nav-label">Resaltar Doc</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/indexar/?auto=1" class="nav-item" data-tooltip="Indexar PDFs"
                style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(217, 119, 6, 0.15));">
                <span class="nav-icon" style="color: #f59e0b;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </span>
                <span class="nav-label">Indexar PDFs</span>
            </a>
        </div>

        <!-- Documents -->
        <div class="nav-section">
            <div class="nav-section-title">Documentos</div>

            <a href="<?= $baseUrl ?? '' ?>modules/manifiestos/"
                class="nav-item <?= $currentModule === 'manifiestos' ? 'active' : '' ?>" data-tooltip="Manifiestos">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                    </svg>
                </span>
                <span class="nav-label">Manifiestos</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/declaraciones/"
                class="nav-item <?= $currentModule === 'declaraciones' ? 'active' : '' ?>" data-tooltip="Declaraciones">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </span>
                <span class="nav-label">Declaraciones</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/subir/"
                class="nav-item <?= $currentModule === 'subir' ? 'active' : '' ?>" data-tooltip="Subir Doc">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                </span>
                <span class="nav-label">Subir Doc</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/importar/"
                class="nav-item <?= $currentModule === 'importar' ? 'active' : '' ?>" data-tooltip="Importar Data">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                </span>
                <span class="nav-label">Importar Data</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/excel_import/"
                class="nav-item <?= $currentModule === 'excel_import' ? 'active' : '' ?>" data-tooltip="Excel Import">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125" />
                    </svg>
                </span>
                <span class="nav-label">Excel Import</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/lote/"
                class="nav-item <?= $currentModule === 'lote' ? 'active' : '' ?>" data-tooltip="Subida Lote">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                </span>
                <span class="nav-label">Subida Lote</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/sincronizar/"
                class="nav-item <?= $currentModule === 'sincronizar' ? 'active' : '' ?>" data-tooltip="Sincronizar BD">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                    </svg>
                </span>
                <span class="nav-label">Sincronizar BD</span>
            </a>
        </div>

        <!-- Operations -->
        <div class="nav-section">
            <div class="nav-section-title">Operaciones</div>

            <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/vincular.php"
                class="nav-item <?= $currentModule === 'vincular' ? 'active' : '' ?>" data-tooltip="Vincular">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                    </svg>
                </span>
                <span class="nav-label">Vincular</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/validar.php"
                class="nav-item <?= $currentModule === 'validar' ? 'active' : '' ?>" data-tooltip="Validar">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <span class="nav-label">Validar</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>modules/recientes/"
                class="nav-item <?= $currentModule === 'recientes' ? 'active' : '' ?>" data-tooltip="Doc Recientes">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                <span class="nav-label">Doc Recientes</span>
            </a>

            <a href="<?= $baseUrl ?? '' ?>admin/backup.php"
                class="nav-item <?= $currentModule === 'backup' ? 'active' : '' ?>" data-tooltip="Backup">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                    </svg>
                </span>
                <span class="nav-label">Backup</span>
            </a>
        </div>
    </nav>

    <!-- Footer / User Card -->
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar">
                <?= strtoupper(substr($clientCode, 0, 1)) ?>
            </div>
            <div class="user-info">
                <div class="user-name">
                    <?= htmlspecialchars($clientCode) ?>
                </div>
                <div class="user-role">Cliente</div>
            </div>
        </div>
        <a href="<?= $baseUrl ?? '' ?>logout.php" class="nav-item mt-3" data-tooltip="Salir">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
            </span>
            <span class="nav-label">Salir</span>
        </a>
    </div>
</aside>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    }

    // Handle window resize
    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }
    });

    // Navigate to indexing tab and trigger re-index
    function goToIndexing(event) {
        event.preventDefault();
        const baseUrl = '<?= $baseUrl ?? '' ?>';
        window.location.href = baseUrl + 'modules/busqueda/#tab-consultar';
    }
</script>