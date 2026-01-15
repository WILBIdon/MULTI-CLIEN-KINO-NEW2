<?php
/**
 * Módulo de Búsqueda Inteligente
 *
 * Interfaz para búsqueda voraz de códigos en documentos.
 * Permite buscar múltiples códigos y encuentra el mínimo conjunto
 * de documentos que los contienen.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/search_engine.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);
$stats = get_search_stats($db);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda Inteligente - KINO TRACE</title>
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

        .nav-links a:hover {
            text-decoration: underline;
        }

        .stats-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .stat-box {
            flex: 1;
            min-width: 150px;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-box .number {
            font-size: 2rem;
            font-weight: bold;
            color: #2563eb;
        }

        .stat-box .label {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .search-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .search-section h2 {
            margin-top: 0;
            color: #1f2937;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem;
            font-size: 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: #2563eb;
        }

        textarea.codes-input {
            width: 100%;
            height: 150px;
            padding: 0.75rem;
            font-family: monospace;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            resize: vertical;
        }

        textarea.codes-input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .suggestions {
            position: absolute;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            width: 100%;
        }

        .suggestion-item {
            padding: 0.5rem 1rem;
            cursor: pointer;
        }

        .suggestion-item:hover {
            background: #f3f4f6;
        }

        .results-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .result-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }

        .result-card:hover {
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }

        .result-card .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .result-card .doc-type {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .result-card .doc-title {
            font-weight: 600;
            color: #1f2937;
            margin: 0.5rem 0;
        }

        .result-card .doc-meta {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .result-card .matched-codes {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }

        .code-tag {
            background: #dcfce7;
            color: #166534;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-family: monospace;
        }

        .code-tag.not-found {
            background: #fee2e2;
            color: #991b1b;
        }

        .summary-box {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .summary-box.warning {
            background: #fef3c7;
            border-color: #fbbf24;
        }

        .summary-box h4 {
            margin: 0 0 0.5rem 0;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e5e7eb;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .hidden {
            display: none;
        }

        @media (max-width: 768px) {
            .stats-bar {
                flex-direction: column;
            }

            .stat-box {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Búsqueda Inteligente</h1>
            <div class="nav-links">
                <a href="../trazabilidad/dashboard.php">🏠 Dashboard</a>
                <a href="../trazabilidad/vincular.php">🔗 Vincular</a>
                <a href="../../logout.php">Salir</a>
            </div>
        </div>

        <div class="stats-bar">
            <div class="stat-box">
                <div class="number">
                    <?= $stats['total_documents'] ?>
                </div>
                <div class="label">Documentos</div>
            </div>
            <div class="stat-box">
                <div class="number">
                    <?= $stats['unique_codes'] ?>
                </div>
                <div class="label">Códigos únicos</div>
            </div>
            <div class="stat-box">
                <div class="number">
                    <?= $stats['validated_codes'] ?>
                </div>
                <div class="label">Validados</div>
            </div>
        </div>

        <div class="search-section">
            <h2>🎯 Búsqueda Voraz de Códigos</h2>
            <p>Ingresa los códigos a buscar (uno por línea). El sistema encontrará los documentos que contienen estos
                códigos usando el algoritmo voraz.</p>

            <form id="searchForm">
                <textarea class="codes-input" id="codesInput" placeholder="ABC123
XYZ789
COD001
..."></textarea>
                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary">🔍 Buscar Códigos</button>
                    <button type="button" class="btn btn-secondary" onclick="clearSearch()">Limpiar</button>
                </div>
            </form>
        </div>

        <div class="search-section">
            <h2>⚡ Búsqueda Rápida</h2>
            <div style="position: relative;">
                <input type="text" class="search-input" id="quickSearch" placeholder="Escribe un código para buscar...">
                <div id="suggestions" class="suggestions hidden"></div>
            </div>
        </div>

        <div id="loading" class="loading hidden">
            <div class="spinner"></div>
            <p>Buscando documentos...</p>
        </div>

        <div id="results" class="results-section hidden">
            <h2>📋 Resultados</h2>
            <div id="summary"></div>
            <div id="documentList"></div>
        </div>
    </div>

    <script>
        const apiUrl = '../../api.php';

        document.getElementById('searchForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const codes = document.getElementById('codesInput').value.trim();
            if (!codes) {
                alert('Ingresa al menos un código');
                return;
            }

            showLoading();

            try {
                const formData = new FormData();
                formData.append('action', 'search');
                formData.append('codes', codes);

                const response = await fetch(apiUrl, { method: 'POST', body: formData });
                const result = await response.json();

                hideLoading();
                showResults(result);
            } catch (error) {
                hideLoading();
                alert('Error en la búsqueda: ' + error.message);
            }
        });

        function showLoading() {
            document.getElementById('loading').classList.remove('hidden');
            document.getElementById('results').classList.add('hidden');
        }

        function hideLoading() {
            document.getElementById('loading').classList.add('hidden');
        }

        function showResults(result) {
            const resultsDiv = document.getElementById('results');
            const summaryDiv = document.getElementById('summary');
            const listDiv = document.getElementById('documentList');

            resultsDiv.classList.remove('hidden');

            // Resumen
            const coveredCount = result.total_covered || 0;
            const totalSearched = result.total_searched || 0;
            const notFound = result.not_found || [];

            let summaryHtml = `
        <div class="summary-box${notFound.length > 0 ? ' warning' : ''}">
            <h4>📊 Resumen de Búsqueda</h4>
            <p><strong>${coveredCount}/${totalSearched}</strong> códigos encontrados en
               <strong>${result.documents?.length || 0}</strong> documento(s)</p>
            ${notFound.length > 0 ? `
                <p style="margin-top: 0.5rem;">❌ No encontrados:</p>
                <div class="matched-codes">
                    ${notFound.map(c => `<span class="code-tag not-found">${c}</span>`).join('')}
                </div>
            ` : ''}
        </div>
    `;
            summaryDiv.innerHTML = summaryHtml;

            // Lista de documentos
            if (!result.documents || result.documents.length === 0) {
                listDiv.innerHTML = '<p style="color: #6b7280;">No se encontraron documentos con los códigos especificados.</p>';
                return;
            }

            let html = '';
            for (const doc of result.documents) {
                html += `
            <div class="result-card">
                <div class="doc-header">
                    <span class="doc-type">${getTypeIcon(doc.tipo)} ${doc.tipo.toUpperCase()}</span>
                    <span class="doc-meta">${doc.fecha}</span>
                </div>
                <div class="doc-title">${doc.tipo} #${doc.numero}</div>
                <div class="doc-meta">
                    ${doc.proveedor ? `📦 ${doc.proveedor}` : ''}
                </div>
                <div class="matched-codes">
                    ${(doc.matched_codes || doc.codes || []).map(c =>
                    `<span class="code-tag">${c}</span>`
                ).join('')}
                </div>
                <div style="margin-top: 0.75rem;">
                    <a href="../${doc.tipo}/view.php?id=${doc.id}" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                        👁️ Ver documento
                    </a>
                </div>
            </div>
        `;
            }
            listDiv.innerHTML = html;
        }

        function getTypeIcon(tipo) {
            const icons = {
                'manifiesto': '📦',
                'declaracion': '📄',
                'factura': '💰',
                'reporte': '📊'
            };
            return icons[tipo] || '📁';
        }

        function clearSearch() {
            document.getElementById('codesInput').value = '';
            document.getElementById('results').classList.add('hidden');
        }

        // Búsqueda rápida con sugerencias
        let debounceTimer;
        const quickSearch = document.getElementById('quickSearch');
        const suggestionsDiv = document.getElementById('suggestions');

        quickSearch.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            const term = e.target.value.trim();

            if (term.length < 2) {
                suggestionsDiv.classList.add('hidden');
                return;
            }

            debounceTimer = setTimeout(async () => {
                try {
                    const response = await fetch(`${apiUrl}?action=suggest&term=${encodeURIComponent(term)}`);
                    const suggestions = await response.json();

                    if (suggestions.length > 0) {
                        suggestionsDiv.innerHTML = suggestions.map(s =>
                            `<div class="suggestion-item" onclick="selectSuggestion('${s}')">${s}</div>`
                        ).join('');
                        suggestionsDiv.classList.remove('hidden');
                    } else {
                        suggestionsDiv.classList.add('hidden');
                    }
                } catch (e) {
                    suggestionsDiv.classList.add('hidden');
                }
            }, 300);
        });

        function selectSuggestion(code) {
            quickSearch.value = code;
            suggestionsDiv.classList.add('hidden');
            // Trigger search
            document.getElementById('codesInput').value = code;
            document.getElementById('searchForm').dispatchEvent(new Event('submit'));
        }

        quickSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('codesInput').value = quickSearch.value;
                document.getElementById('searchForm').dispatchEvent(new Event('submit'));
            }
        });

        // Cerrar sugerencias al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (!suggestionsDiv.contains(e.target) && e.target !== quickSearch) {
                suggestionsDiv.classList.add('hidden');
            }
        });
    </script>
</body>

</html>