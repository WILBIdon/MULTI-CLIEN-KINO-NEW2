<?php
/**
 * Sidebar Navigation Component - SIMPLIFIED
 * 
 * Solo 5 botones principales según boceto:
 * 1. Gestor/Doc
 * 2. Resaltador
 * 3. Documentos (expandible con tipos)
 * 4. Base D./Subida Masiva (expandible con funciones)
 * 5. Backup
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
            <h1><?= htmlspecialchars($clientName) ?></h1>
            <span>Gestión Documental</span>
        </div>
    </div>

    <!-- Navigation - SOLO 5 BOTONES PRINCIPALES -->
    <nav class="sidebar-nav">

        <!-- 1. GESTOR / DASHBOARD -->
        <a href="<?= $baseUrl ?? '' ?>modules/busqueda/"
            class="nav-item <?= $currentModule === 'gestor' ? 'active' : '' ?>">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
            </span>
            <span class="nav-label">Gestor Doc</span>
        </a>

        <!-- 2. RESALTADOR -->
        <a href="<?= $baseUrl ?? '' ?>modules/resaltar/"
            class="nav-item <?= $currentModule === 'resaltar' ? 'active' : '' ?>">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                </svg>
            </span>
            <span class="nav-label">Resaltador</span>
        </a>

        <!-- 3. DOCUMENTOS (Expandible) -->
        <div class="nav-item-expandable">
            <button class="nav-item nav-toggle" onclick="toggleSubmenu('documentos')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </span>
                <span class="nav-label">Documentos</span>
                <span class="nav-arrow">▾</span>
            </button>
            <div class="nav-submenu" id="submenu-documentos">
                <a href="<?= $baseUrl ?? '' ?>modules/manifiestos/" class="nav-subitem">
                    📦 Manifiestos
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/declaraciones/" class="nav-subitem">
                    📄 Declaraciones
                </a>
            </div>
        </div>

        <!-- 4. BASE DE DATOS / SUBIDA MASIVA (Expandible con TODAS las funciones) -->
        <div class="nav-item-expandable">
            <button class="nav-item nav-toggle" onclick="toggleSubmenu('database')">
                <span class="nav-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                    </svg>
                </span>
                <span class="nav-label">Base D. / Subida</span>
                <span class="nav-arrow">▾</span>
            </button>
            <div class="nav-submenu" id="submenu-database">
                <a href="<?= $baseUrl ?? '' ?>modules/subir/" class="nav-subitem">
                    📤 Subir Documento
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/excel_import/" class="nav-subitem">
                    📊 Importar Data Excel
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/importar/" class="nav-subitem">
                    📡 Importar Backup (SQL)
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/lote/" class="nav-subitem">
                    📦 Subida por Lote
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/sincronizar/" class="nav-subitem">
                    🔄 Sincronizar
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/vincular.php" class="nav-subitem">
                    🔗 Vincular
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/indexar/" class="nav-subitem">
                    🔍 Indexar
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/trazabilidad/validar.php" class="nav-subitem">
                    ✅ Validar
                </a>
                <a href="<?= $baseUrl ?? '' ?>modules/importar_sql/" class="nav-subitem">
                    🚀 Importación Av.
                </a>
            </div>
        </div>

        <!-- 5. BACKUP -->
        <a href="<?= $baseUrl ?? '' ?>admin/backup.php"
            class="nav-item <?= $currentModule === 'backup' ? 'active' : '' ?>">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
            </span>
            <span class="nav-label">Backup</span>
        </a>

    </nav>

    <!-- Footer / User Card -->
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?= strtoupper(substr($clientCode, 0, 1)) ?></div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($clientCode) ?></div>
                <div class="user-role">Cliente</div>
            </div>
        </div>
        <a href="<?= $baseUrl ?? '' ?>logout.php" class="nav-item logout-btn">
            <span class="nav-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
            </span>
            <span class="nav-label">Salir</span>
        </a>
    </div>
</aside>

<style>
    /* Estilos para menús expandibles */
    .nav-item-expandable {
        margin-bottom: 0.25rem;
    }

    .nav-toggle {
        width: 100%;
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
        position: relative;
    }

    .nav-toggle .nav-arrow {
        position: absolute;
        right: 1rem;
        transition: transform 0.2s ease;
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .nav-toggle.active .nav-arrow {
        transform: rotate(180deg);
    }

    .nav-submenu {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: var(--bg-tertiary);
        border-radius: var(--radius-md);
        margin: 0.25rem 0 0.5rem 0;
    }

    .nav-submenu.open {
        max-height: 500px;
        padding: 0.5rem 0;
    }

    .nav-subitem {
        display: block;
        padding: 0.75rem 1rem 0.75rem 3rem;
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.875rem;
        transition: all 0.2s ease;
        border-left: 3px solid transparent;
    }

    .nav-subitem:hover {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border-left-color: var(--accent-primary);
    }

    .logout-btn {
        margin-top: 0.5rem;
        border-top: 1px solid var(--border-color);
        padding-top: 0.75rem;
    }
</style>

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

    function toggleSubmenu(menuId) {
        const submenu = document.getElementById('submenu-' + menuId);
        const button = submenu.previousElementSibling;

        // Close all other submenus
        document.querySelectorAll('.nav-submenu').forEach(menu => {
            if (menu !== submenu) {
                menu.classList.remove('open');
                menu.previousElementSibling.classList.remove('active');
            }
        });

        // Toggle current submenu
        submenu.classList.toggle('open');
        button.classList.toggle('active');
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
</script>