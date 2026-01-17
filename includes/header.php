<?php
/**
 * Header Component
 * 
 * Top navigation bar with sidebar toggle, page title, and actions.
 */

$pageTitle = $pageTitle ?? 'Dashboard';
?>

<header class="main-header">
    <div class="header-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <h2 class="page-title">
            <?= htmlspecialchars($pageTitle) ?>
        </h2>
    </div>

    <div class="header-right">
        <button class="header-btn" title="Actualizar" onclick="location.reload()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
        </button>
    </div>
</header>