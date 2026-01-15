<?php
/**
 * Panel principal de trazabilidad para un cliente.
 *
 * Muestra estadísticas de documentos, códigos y vínculos,
 * con accesos rápidos a todos los módulos del sistema.
 * Incluye búsqueda rápida y chat con IA.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../helpers/tenant.php';
require_once __DIR__ . '/../../helpers/search_engine.php';
require_once __DIR__ . '/../../helpers/gemini_ai.php';

// Verificar autenticación
if (!isset($_SESSION['client_code'])) {
    header('Location: ../../login.php');
    exit;
}

$code = $_SESSION['client_code'];
$db = open_client_db($code);

// Obtener estadísticas
$stats = get_search_stats($db);
$docCount = $stats['total_documents'];
$codeCount = $stats['total_codes'];
$uniqueCodes = $stats['unique_codes'];
$validCount = $stats['validated_codes'];
$recentDocs = $stats['recent_documents'];
$docsByType = $stats['documents_by_type'];
$vincCount = (int) $db->query('SELECT COUNT(*) FROM vinculos')->fetchColumn();
$geminiConfigured = is_gemini_configured();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KINO TRACE</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            color: #1f2937;
            font-size: 1.75rem;
        }

        .header h1 span {
            color: #2563eb;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-badge {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-weight: 600;
        }

        .logout-btn {
            color: #6b7280;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .logout-btn:hover {
            background: #f3f4f6;
        }

        /* Quick search */
        .quick-search {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .quick-search input {
            flex: 1;
            min-width: 200px;
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
        }

        .quick-search input:focus {
            outline: none;
            border-color: #2563eb;
        }

        .quick-search button {
            padding: 0.75rem 1.5rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .quick-search button:hover {
            background: #1d4ed8;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .stat-card .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: #2563eb;
        }

        .stat-card .label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        /* Modules grid */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .module-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            text-decoration: none;
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        .module-card:hover {
            border-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
        }

        .module-card .icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .module-card h3 {
            color: #1f2937;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .module-card p {
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.4;
        }

        .module-card.highlight {
            background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%);
            border-color: #93c5fd;
        }

        .module-card.ai {
            background: linear-gradient(135deg, #f3e8ff 0%, #faf5ff 100%);
            border-color: #c4b5fd;
        }

        /* Recent docs */
        .recent-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .recent-section h2 {
            color: #1f2937;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }

        .recent-list {
            list-style: none;
        }

        .recent-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .recent-list li:last-child {
            border-bottom: none;
        }

        .doc-type-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* AI Chat mini */
        .ai-chat-mini {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
        }

        .ai-chat-mini h3 {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ai-chat-mini input {
            width: 100%;
            padding: 0.75rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        .ai-chat-mini button {
            padding: 0.5rem 1rem;
            background: white;
            color: #764ba2;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        .ai-chat-mini .response {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            display: none;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Dashboard <span>KINO TRACE</span></h1>
            <div class="user-info">
                <span class="user-badge">👤 <?= htmlspecialchars($code) ?></span>
                <?php if (!empty($_SESSION['is_admin'])): ?>
                    <a href="../../admin/panel.php" class="logout-btn">⚙️ Admin</a>
                <?php endif; ?>
                <a href="../../logout.php" class="logout-btn">Salir</a>
            </div>
        </div>

        <!-- Búsqueda rápida -->
        <div class="quick-search">
            <input type="text" id="quickSearchInput" placeholder="🔍 Buscar código rápido...">
            <button onclick="quickSearch()">Buscar</button>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">📄</div>
                <div class="number"><?= $docCount ?></div>
                <div class="label">Documentos</div>
            </div>
            <div class="stat-card">
                <div class="icon">🏷️</div>
                <div class="number"><?= $uniqueCodes ?></div>
                <div class="label">Códigos únicos</div>
            </div>
            <div class="stat-card">
                <div class="icon">✅</div>
                <div class="number"><?= $validCount ?>/<?= $codeCount ?></div>
                <div class="label">Validados</div>
            </div>
            <div class="stat-card">
                <div class="icon">🔗</div>
                <div class="number"><?= $vincCount ?></div>
                <div class="label">Vínculos</div>
            </div>
        </div>

        <!-- Chat IA -->
        <?php if ($geminiConfigured): ?>
            <div class="ai-chat-mini">
                <h3>🤖 Asistente IA (Gemini)</h3>
                <input type="text" id="aiQuestion" placeholder="Pregunta sobre tus documentos...">
                <button onclick="askAI()">Preguntar</button>
                <div class="response" id="aiResponse"></div>
            </div>
        <?php endif; ?>

        <!-- Módulos principales -->
        <div class="modules-grid">
            <a href="../busqueda/" class="module-card highlight">
                <div class="icon">🔍</div>
                <h3>Búsqueda Inteligente</h3>
                <p>Búsqueda voraz de códigos en todos los documentos</p>
            </a>
            <a href="../subir/" class="module-card highlight">
                <div class="icon">📤</div>
                <h3>Subir Documento</h3>
                <p>Sube PDFs y extrae códigos automáticamente</p>
            </a>
            <a href="../importar/" class="module-card">
                <div class="icon">📥</div>
                <h3>Importar Datos</h3>
                <p>Importa desde CSV, SQL o Excel</p>
            </a>
            <a href="../manifiestos/list.php" class="module-card">
                <div class="icon">📦</div>
                <h3>Manifiestos</h3>
                <p>Gestiona manifiestos de carga</p>
            </a>
            <a href="../declaraciones/list.php" class="module-card">
                <div class="icon">📜</div>
                <h3>Declaraciones</h3>
                <p>Declaraciones aduaneras</p>
            </a>
            <a href="vincular.php" class="module-card">
                <div class="icon">🔗</div>
                <h3>Vincular</h3>
                <p>Relaciona documentos entre sí</p>
            </a>
            <a href="validar.php" class="module-card">
                <div class="icon">✅</div>
                <h3>Validar Códigos</h3>
                <p>Valida y marca códigos</p>
            </a>
        </div>

        <!-- Documentos recientes -->
        <div class="recent-section">
            <h2>📋 Documentos Recientes</h2>
            <?php if (empty($recentDocs)): ?>
                <p style="color:#6b7280;">No hay documentos aún. <a href="../subir/">Sube tu primer documento</a></p>
            <?php else: ?>
                <ul class="recent-list">
                    <?php foreach ($recentDocs as $doc): ?>
                        <li>
                            <span>
                                <span class="doc-type-badge"><?= strtoupper($doc['tipo']) ?></span>
                                <strong style="margin-left:0.5rem;">#<?= htmlspecialchars($doc['numero']) ?></strong>
                            </span>
                            <span style="color:#6b7280;"><?= htmlspecialchars($doc['fecha']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function quickSearch() {
            const term = document.getElementById('quickSearchInput').value.trim();
            if (term) {
                window.location.href = '../busqueda/?q=' + encodeURIComponent(term);
            }
        }

        document.getElementById('quickSearchInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') quickSearch();
        });

        <?php if ($geminiConfigured): ?>
            async function askAI() {
                const question = document.getElementById('aiQuestion').value.trim();
                if (!question) return;

                const responseDiv = document.getElementById('aiResponse');
                responseDiv.style.display = 'block';
                responseDiv.textContent = 'Pensando...';

                try {
                    const formData = new FormData();
                    formData.append('action', 'ai_chat');
                    formData.append('question', question);

                    const response = await fetch('../../api.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.error) {
                        responseDiv.textContent = '❌ ' + result.error;
                    } else {
                        responseDiv.textContent = result.answer || 'Sin respuesta';
                    }
                } catch (e) {
                    responseDiv.textContent = '❌ Error: ' + e.message;
                }
            }

            document.getElementById('aiQuestion').addEventListener('keydown', function (e) {
                if (e.key === 'Enter') askAI();
            });
        <?php endif; ?>
    </script>
</body>

</html>