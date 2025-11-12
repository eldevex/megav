<?php
header('Content-Type: text/html; charset=utf-8');

// === НАСТРОЙКИ ===
$API_BASE = 'https://megav.app/servers-api/configs';
$COUNTRIES_API = 'https://megav.app/servers-api/countries';
$PER_PAGE = 20;
$MAX_LOAD_ALL_PAGES = 20;

// Получаем параметры
$page = max(1, (int)($_GET['page'] ?? 1));
$country = $_GET['country'] ?? 'all';
$protocol = $_GET['protocol'] ?? 'all';
$action = $_GET['action'] ?? 'view'; // view | load_more | load_all | api

// === ФУНКЦИИ ===
function apiRequest($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ProxyParser/1.0)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return false;
    return json_decode($response, true);
}

function getCountries()
{
    global $COUNTRIES_API;
    $data = apiRequest($COUNTRIES_API);
    return ($data && is_array($data)) ? $data : [];
}

function getPage($page, $country, $protocol)
{
    global $API_BASE, $PER_PAGE;
    $params = http_build_query([
        'page' => $page,
        'per_page' => $PER_PAGE,
        'country' => $country !== 'all' ? $country : '',
        'protocol' => $protocol !== 'all' ? $protocol : ''
    ], '', '&');
    $url = "$API_BASE?$params";
    $data = apiRequest($url);
    if (!$data || !isset($data['configs'])) {
        return ['configs' => [], 'total_pages' => 1];
    }
    $working = array_filter($data['configs'], fn($c) => ($c['v2ray_status'] ?? '') === 'working');
    $urls = array_map(fn($c) => $c['config_url'], $working);
    return [
        'configs' => $urls,
        'total_pages' => $data['total_pages'] ?? 1,
        'total_working' => count($working)
    ];
}

// === ЗАГРУЗКА СТРАН ===
$countries = getCountries();
usort($countries, fn($a, $b) => ($b['server_count'] ?? 0) <=> ($a['server_count'] ?? 0));

// === ЛОГИКА ===
$allConfigs = [];
$error = '';
$stats = '';
$totalPages = 1;

if ($action === 'load_more' || $action === 'view') {
    $result = getPage($page, $country, $protocol);
    $allConfigs = $result['configs'];
    $totalPages = $result['total_pages'];
    $stats = "Страница $page из $totalPages | Рабочих: " . count($allConfigs);
} elseif ($action === 'load_all') {
    $loaded = 0;
    $empty = 0;
    $currentPage = $page;
    for ($i = $currentPage; $i < $currentPage + $MAX_LOAD_ALL_PAGES; $i++) {
        $result = getPage($i, $country, $protocol);
        if (empty($result['configs'])) {
            $empty++;
            if ($empty >= 3) break;
        } else {
            $allConfigs = array_merge($allConfigs, $result['configs']);
            $loaded++;
            $empty = 0;
        }
        usleep(200000); // 200 мс задержка
    }
    $totalPages = $result['total_pages'] ?? 1;
    $stats = "Загружено $loaded страниц | Всего конфигов: " . count($allConfigs);
} elseif ($action === 'api') {
    header('Content-Type: application/json');
    $result = getPage($page, $country, $protocol);
    echo json_encode([
        'page' => $page,
        'country' => $country,
        'protocol' => $protocol,
        'configs' => $result['configs'],
        'total_pages' => $result['total_pages'],
        'total_working' => count($result['configs'])
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proxy Parser (PHP)</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg: #1a1a1a;
            --sec: #2d2d2d;
            --text: #e0e0e0;
            --accent: #6366f1;
            --border: #404040;
            --success: #10b981;
        }

        [data-theme="light"] {
            --bg: #f5f5f5;
            --sec: #fff;
            --text: #1a1a1a;
            --accent: #6366f1;
            --border: #e0e0e0;
        }

        body {
            font-family: system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 1rem;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        select,
        button {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--sec);
            color: var(--text);
            font-size: 1rem;
            cursor: pointer;
        }

        select {
            min-width: 200px;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: #818cf8;
        }

        .configs {
            background: var(--sec);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        .stats {
            text-align: center;
            color: #b0b0b0;
            margin: 1rem 0;
            font-size: 0.9rem;
        }

        .error {
            background: #ef4444;
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            text-align: center;
        }

        .actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
            margin: 2rem 0;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #b0b0b0;
            display: none;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
            }

            select,
            .btn-primary {
                width: 100%;
            }

            .configs {
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body data-theme="dark">
    <div class="container">
        <div class="header">
            <h1>Proxy Parser</h1>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <form method="GET" style="display:flex; gap:0.5rem; flex:1; min-width:200px;" id="filterForm">
                    <select name="country" id="countrySelect">
                        <option value="all">Все халявные страны</option>
                    </select>
                    <select name="protocol" id="protocolSelect" onchange="handleProtocolChange()">
                        <option value="all" <?= $protocol === 'all' ? 'selected' : '' ?>>Все протоколы</option>
                        <option value="vless" <?= $protocol === 'vless' ? 'selected' : '' ?>>VLESS</option>
                        <option value="vmess" <?= $protocol === 'vmess' ? 'selected' : '' ?>>VMESS</option>
                        <option value="trojan" <?= $protocol === 'trojan' ? 'selected' : '' ?>>TROJAN</option>
                        <option value="shadowsocks" <?= $protocol === 'shadowsocks' ? 'selected' : '' ?>>SHADOWSOCKS</option>
                    </select>
                    <input type="hidden" name="page" value="1">
                    <input type="hidden" name="action" value="view">
                </form>
                <button id="themeToggle" onclick="toggleTheme()">🌙</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="stats"><?= htmlspecialchars($stats) ?></div>
        <div class="configs" id="configs">
            <?= htmlspecialchars(implode("\n\n", $allConfigs)) ?>
        </div>

        <div class="actions">
            <button class="btn-primary" onclick="copyAll()">Копировать все</button>
            <button class="btn-primary" onclick="loadMore()">Следующая страница</button>
            <button class="btn-primary" onclick="loadAll()">Все конфиги (<?= $MAX_LOAD_ALL_PAGES ?> стр)</button>
        </div>
        <div class="loading" id="loading">Загрузка...</div>
    </div>

    <!-- Скрытые данные для JS -->
    <script>
        window.countriesData = <?= json_encode($countries) ?>;
        window.currentCountry = '<?= $country ?>';
        window.currentProtocol = '<?= $protocol ?>';
    </script>

    <script>
        // Функция для флагов (JS-версия, работает идеально)
        function getFlagEmoji(code) {
            if (!code || code.length !== 2) return '';
            return code.toUpperCase().replace(/./g, char =>
                String.fromCodePoint(0x1F1E6 + char.charCodeAt(0) - 0x41)
            );
        }

        // Заполнение селектора стран
        function populateCountries() {
            const select = document.getElementById('countrySelect');
            select.innerHTML = '<option value="all">Все халявные страны</option>'; // Очистка

            if (!window.countriesData || !Array.isArray(window.countriesData)) {
                // Fallback: базовый список, если API недоступен
                const fallback = [{
                        code: 'FR',
                        name: 'France',
                        server_count: 204
                    },
                    {
                        code: 'MD',
                        name: 'Moldova',
                        server_count: 141
                    },
                    // Добавь другие, если нужно
                ];
                window.countriesData = fallback;
            }

            // Сортировка по server_count (убывание)
            window.countriesData.sort((a, b) => (b.server_count || 0) - (a.server_count || 0));

            window.countriesData.forEach(country => {
                if (!country.code || !country.name) return;
                const flag = getFlagEmoji(country.code);
                const option = document.createElement('option');
                option.value = country.code;
                option.textContent = `${flag} ${country.name} (${country.server_count})`;
                if (country.code === window.currentCountry) option.selected = true;
                select.appendChild(option);
            });

            // Обработчик смены страны
            select.onchange = handleCountryChange;
        }

        function handleCountryChange() {
            document.querySelector('input[name="action"]').value = 'view';
            document.getElementById('filterForm').submit();
        }

        function handleProtocolChange() {
            document.querySelector('input[name="action"]').value = 'view';
            document.getElementById('filterForm').submit();
        }

        // Тема
        const toggleTheme = () => {
            const body = document.body;
            const isDark = body.getAttribute('data-theme') === 'dark';
            const newTheme = isDark ? 'light' : 'dark';
            body.setAttribute('data-theme', newTheme);
            document.getElementById('themeToggle').textContent = newTheme === 'dark' ? '🌙' : '☀️';
            localStorage.setItem('theme', newTheme);
        };

        // Восстановление темы
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.body.setAttribute('data-theme', savedTheme);
        document.getElementById('themeToggle').textContent = savedTheme === 'dark' ? '🌙' : '☀️';

        const buildUrl = (action, page) => {
            const params = new URLSearchParams({
                country: window.currentCountry,
                protocol: window.currentProtocol,
                action: action
            });
            if (page) params.set('page', page);
            return '?' + params.toString();
        };

        const loadMore = () => {
            const loading = document.getElementById('loading');
            loading.style.display = 'block';
            window.currentCountry = document.getElementById('countrySelect').value;
            window.currentProtocol = document.getElementById('protocolSelect').value;
            fetch(buildUrl('load_more', <?= $page + 1 ?>))
                .then(r => r.text())
                .then(html => {
                    document.body.innerHTML = html;
                    // Перезаполним страны после замены HTML
                    setTimeout(populateCountries, 100);
                });
        };

        const loadAll = () => {
            const loading = document.getElementById('loading');
            loading.style.display = 'block';
            loading.textContent = 'Загрузка всех страниц...';
            window.currentCountry = document.getElementById('countrySelect').value;
            window.currentProtocol = document.getElementById('protocolSelect').value;
            fetch(buildUrl('load_all', <?= $page ?>))
                .then(r => r.text())
                .then(html => {
                    document.body.innerHTML = html;
                    setTimeout(populateCountries, 100);
                });
        };

        const copyAll = () => {
            const text = document.getElementById('configs').innerText;
            if (!text.trim()) {
                alert('Нет конфигов для копирования');
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const orig = btn.textContent;
                btn.textContent = 'Скопировано!';
                btn.style.background = 'var(--success)';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.background = '';
                }, 2000);
            }).catch(() => {
                alert('Ошибка копирования. Выделите текст вручную.');
            });
        };

        // Инициализация
        document.addEventListener('DOMContentLoaded', populateCountries);
    </script>
</body>

</html>