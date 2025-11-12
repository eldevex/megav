<?php
header('Content-Type: text/html; charset=utf-8');

// === НАСТРОЙКИ ===
$API_BASE = 'https://megav.app/servers-api/configs';
$PER_PAGE = 20;
$MAX_LOAD_ALL_PAGES = 20; // Сколько страниц грузить при "все сразу"

// Получаем параметры
$page = max(1, (int)($_GET['page'] ?? 1));
$country = $_GET['country'] ?? 'all';
$protocol = $_GET['protocol'] ?? 'all';
$action = $_GET['action'] ?? 'view'; // view | load_more | load_all | api

// Функция: запрос к API
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

    if ($httpCode !== 200) {
        return false;
    }

    return json_decode($response, true);
}

// Функция: получить данные страницы
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

// === Обработка действий ===
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
            if ($empty >= 3) break; // 3 пустые подряд — стоп
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

// === HTML-вывод ===
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
            button {
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
            <h1>Proxy Parser </h1>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <form method="GET" style="display:flex; gap:0.5rem; flex:1; min-width:200px;">
                    <select name="country" onchange="this.form.submit()">
<option value="all" <?= $country==='all'?'selected':'' ?>>Все халявные страны</option>
<option value="FR" <?= $country==='FR'?'selected':'' ?>>🇫🇷 Seine-Saint-Denis (196)</option>
<option value="MD" <?= $country==='MD'?'selected':'' ?>>🇲🇩 Chișinău Municipality (138)</option>
<option value="NL" <?= $country==='NL'?'selected':'' ?>>🇳🇱 North Holland (105)</option>
<option value="GB" <?= $country==='GB'?'selected':'' ?>>🇬🇧 Manchester (92)</option>
<option value="US" <?= $country==='US'?'selected':'' ?>>🇺🇸 Washington (86)</option>                        <option value="CY" <?= $country === 'CY' ? 'selected' : '' ?>>🇨🇾 Nicosia (85)</option>
                        <option value="HK" <?= $country === 'HK' ? 'selected' : '' ?>>🇭🇰 HK (45)</option>
                        <option value="DE" <?= $country === 'DE' ? 'selected' : '' ?>>🇩🇪 Saxony (30)</option>
                        <option value="BG" <?= $country === 'BG' ? 'selected' : '' ?>>🇧🇬 Sofia-Capital (26)</option>
                        <option value="CA" <?= $country === 'CA' ? 'selected' : '' ?>>🇨🇦 Quebec (9)</option>
                        <option value="SG" <?= $country === 'SG' ? 'selected' : '' ?>>🇸🇬 SG (8)</option>
                        <option value="TR" <?= $country === 'TR' ? 'selected' : '' ?>>🇹🇷 İzmir Province (8)</option>
                        <option value="LV" <?= $country === 'LV' ? 'selected' : '' ?>>🇱🇻 Rīga (8)</option>
                        <option value="JP" <?= $country === 'JP' ? 'selected' : '' ?>>🇯🇵 Tokyo (8)</option>
                        <option value="IN" <?= $country === 'IN' ? 'selected' : '' ?>>🇮🇳 Telangana (6)</option>
                        <option value="RU" <?= $country === 'RU' ? 'selected' : '' ?>>🇷🇺 Moscow (6)</option>
                        <option value="MY" <?= $country === 'MY' ? 'selected' : '' ?>>🇲🇾 Kuala Lumpur (5)</option>
                        <option value="TW" <?= $country === 'TW' ? 'selected' : '' ?>>🇹🇼 Taipei City (5)</option>
                        <option value="FI" <?= $country === 'FI' ? 'selected' : '' ?>>🇫🇮 Uusimaa (5)</option>
                        <option value="VN" <?= $country === 'VN' ? 'selected' : '' ?>>🇻🇳 Hanoi (4)</option>
                        <option value="BR" <?= $country === 'BR' ? 'selected' : '' ?>>🇧🇷 São Paulo (3)</option>
                        <option value="IT" <?= $country === 'IT' ? 'selected' : '' ?>>🇮🇹 Province of Milan (3)</option>
                        <option value="MA" <?= $country === 'MA' ? 'selected' : '' ?>>🇲🇦 Fes (3)</option>
                        <option value="EC" <?= $country === 'EC' ? 'selected' : '' ?>>🇪🇨 Pichincha (3)</option>
                        <option value="AE" <?= $country === 'AE' ? 'selected' : '' ?>>🇦🇪 Umm al Qaywayn (3)</option>
                        <option value="PL" <?= $country === 'PL' ? 'selected' : '' ?>>🇵🇱 Mazovia (3)</option>
                        <option value="TH" <?= $country === 'TH' ? 'selected' : '' ?>>🇹🇭 Bangkok (3)</option>
                        <option value="PR" <?= $country === 'PR' ? 'selected' : '' ?>>🇵🇷 PR (2)</option>
                        <option value="AR" <?= $country === 'AR' ? 'selected' : '' ?>>🇦🇷 Buenos Aires F.D. (2)</option>
                        <option value="BH" <?= $country === 'BH' ? 'selected' : '' ?>>🇧🇭 Manama (2)</option>
                        <option value="CR" <?= $country === 'CR' ? 'selected' : '' ?>>🇨🇷 Provincia de San José (2)</option>
                        <option value="DK" <?= $country === 'DK' ? 'selected' : '' ?>>🇩🇰 Capital Region (2)</option>
                        <option value="DZ" <?= $country === 'DZ' ? 'selected' : '' ?>>🇩🇿 Boumerdes (2)</option>
                        <option value="EG" <?= $country === 'EG' ? 'selected' : '' ?>>🇪🇬 Cairo Governorate (2)</option>
                        <option value="ES" <?= $country === 'ES' ? 'selected' : '' ?>>🇪🇸 Madrid (2)</option>
                        <option value="ID" <?= $country === 'ID' ? 'selected' : '' ?>>🇮🇩 Jakarta (2)</option>
                        <option value="KH" <?= $country === 'KH' ? 'selected' : '' ?>>🇰🇭 Phnom Penh (2)</option>
                        <option value="KR" <?= $country === 'KR' ? 'selected' : '' ?>>🇰🇷 Seoul (2)</option>
                        <option value="KZ" <?= $country === 'KZ' ? 'selected' : '' ?>>🇰🇿 Almaty (2)</option>
                        <option value="LT" <?= $country === 'LT' ? 'selected' : '' ?>>🇱🇹 Vilnius City Municipality (2)</option>
                        <option value="MK" <?= $country === 'MK' ? 'selected' : '' ?>>🇲🇰 MK (2)</option>
                        <option value="MT" <?= $country === 'MT' ? 'selected' : '' ?>>🇲🇹 Valletta (2)</option>
                        <option value="MX" <?= $country === 'MX' ? 'selected' : '' ?>>🇲🇽 Mexico City (2)</option>
                        <option value="NG" <?= $country === 'NG' ? 'selected' : '' ?>>🇳🇬 Lagos (2)</option>
                        <option value="PA" <?= $country === 'PA' ? 'selected' : '' ?>>🇵🇦 Provincia de Panamá (2)</option>
                        <option value="PE" <?= $country === 'PE' ? 'selected' : '' ?>>🇵🇪 Lima region (2)</option>
                        <option value="PT" <?= $country === 'PT' ? 'selected' : '' ?>>🇵🇹 Lisbon (2)</option>
                        <option value="SE" <?= $country === 'SE' ? 'selected' : '' ?>>🇸🇪 Stockholm County (2)</option>
                        <option value="SI" <?= $country === 'SI' ? 'selected' : '' ?>>🇸🇮 Ljubljana (2)</option>
                        <option value="ZA" <?= $country === 'ZA' ? 'selected' : '' ?>>🇿🇦 Gauteng (2)</option>
                        <option value="UA" <?= $country === 'UA' ? 'selected' : '' ?>>🇺🇦 Kyiv City (1)</option>
                        <option value="GT" <?= $country === 'GT' ? 'selected' : '' ?>>🇬🇹 Guatemala (1)</option>
                        <option value="GR" <?= $country === 'GR' ? 'selected' : '' ?>>🇬🇷 Central Macedonia (1)</option>
                        <option value="AT" <?= $country === 'AT' ? 'selected' : '' ?>>🇦🇹 Vienna (1)</option>
                        <option value="PY" <?= $country === 'PY' ? 'selected' : '' ?>>🇵🇾 Asunción (1)</option>
                        <option value="RO" <?= $country === 'RO' ? 'selected' : '' ?>>🇷🇴 București (1)</option>
                        <option value="EE" <?= $country === 'EE' ? 'selected' : '' ?>>🇪🇪 Tallinn (1)</option>
                        <option value="AM" <?= $country === 'AM' ? 'selected' : '' ?>>🇦🇲 AM (1)</option>
                        <option value="CZ" <?= $country === 'CZ' ? 'selected' : '' ?>>🇨🇿 Prague (1)</option>
                        <option value="LU" <?= $country === 'LU' ? 'selected' : '' ?>>🇱🇺 Luxembourg (1)</option>
                        <option value="ME" <?= $country === 'ME' ? 'selected' : '' ?>>🇲🇪 ME (1)</option>
                        <option value="XK" <?= $country === 'XK' ? 'selected' : '' ?>>🇽🇰 XK (1)</option>
                        <option value="SK" <?= $country === 'SK' ? 'selected' : '' ?>>🇸🇰 Bratislava Region (1)</option>
                        <option value="CL" <?= $country === 'CL' ? 'selected' : '' ?>>🇨🇱 Santiago Metropolitan (1)</option>
                        <option value="IL" <?= $country === 'IL' ? 'selected' : '' ?>>🇮🇱 Central District (1)</option>
                        <option value="BO" <?= $country === 'BO' ? 'selected' : '' ?>>🇧🇴 La Paz Department (1)</option>
                        <option value="HU" <?= $country === 'HU' ? 'selected' : '' ?>>🇭🇺 Budapest (1)</option>
                        <option value="NO" <?= $country === 'NO' ? 'selected' : '' ?>>🇳🇴 Oslo County (1)</option>
                        <option value="AU" <?= $country === 'AU' ? 'selected' : '' ?>>🇦🇺 New South Wales (1)</option>
                    </select>
                    <select name="protocol" onchange="this.form.submit()">
                        <option value="all">Все протоколы</option>
                        <option value="vless" <?= $protocol === 'vless' ? 'selected' : '' ?>>VLESS</option>
                        <option value="vmess" <?= $protocol === 'vmess' ? 'selected' : '' ?>>VMESS</option>
                        <option value="trojan" <?= $protocol === 'trojan' ? 'selected' : '' ?>>TROJAN</option>
                        <option value="shadowsocks" <?= $protocol === 'shadowsocks' ? 'selected' : '' ?>>SHADOWSOCKS</option>
                    </select>
                    <input type="hidden" name="page" value="1">
                </form>
                <button onclick="toggleTheme()">Переключить тему</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="stats"><?= $stats ?></div>

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

    <script>
        const toggleTheme = () => {
            const isDark = document.body.getAttribute('data-theme') === 'dark';
            document.body.setAttribute('data-theme', isDark ? 'light' : 'dark');
            localStorage.setItem('theme', isDark ? 'light' : 'dark');
        };

        // Загрузка темы
        const saved = localStorage.getItem('theme') || 'dark';
        document.body.setAttribute('data-theme', saved);

        const buildUrl = (action, page) => {
            const params = new URLSearchParams({
                country: '<?= $country ?>',
                protocol: '<?= $protocol ?>',
                action: action
            });
            if (page) params.set('page', page);
            return '?' + params.toString();
        };

        const loadMore = () => {
            const loading = document.getElementById('loading');
            loading.style.display = 'block';
            fetch(buildUrl('load_more', <?= $page + 1 ?>))
                .then(r => r.text())
                .then(html => {
                    document.body.innerHTML = html;
                });
        };

        const loadAll = () => {
            const loading = document.getElementById('loading');
            loading.style.display = 'block';
            loading.textContent = 'Загрузка всех страниц...';
            fetch(buildUrl('load_all', <?= $page ?>))
                .then(r => r.text())
                .then(html => {
                    document.body.innerHTML = html;
                });
        };

        const copyAll = () => {
            const text = document.getElementById('configs').innerText;
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const orig = btn.textContent;
                btn.textContent = 'Скопировано!';
                btn.style.background = 'var(--success)';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.background = '';
                }, 2000);
            });
        };
    </script>
</body>

</html>