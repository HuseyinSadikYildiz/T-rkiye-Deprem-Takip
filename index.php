<?php
// Database configuration
define('DB_HOST', 'sql200.infinityfree.com');
define('DB_USER', 'if0_41279922');
define('DB_PASS', 'Neizlesem');
define('DB_NAME', 'if0_41279922_deprem_db');

// Scraper settings
define('KANDILLI_URL', 'http://www.koeri.boun.edu.tr/scripts/lst0.asp');
define('AUTO_REFRESH_INTERVAL', 30000); // 30 seconds

// Database Connection Setup
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    
    // Create table automatically if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS earthquakes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        time TIME NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        depth DECIMAL(5,2) NOT NULL,
        magnitude DECIMAL(3,1) NOT NULL,
        location VARCHAR(255) NOT NULL,
        status VARCHAR(100),
        hash CHAR(64) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;";
    
    $pdo->exec($sql);

} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Scraper Logic
function runKandilliScraper($pdo) {
    $url = KANDILLI_URL;
    $content = @file_get_contents($url);
    if ($content === false) return 0;
    
    if (preg_match('/<pre>(.*?)<\/pre>/s', $content, $matches)) {
        $lines = explode("\n", $matches[1]);
        $count = 0;
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO earthquakes (date, time, latitude, longitude, depth, magnitude, location, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || !preg_match('/^\d{4}\.\d{2}\.\d{2}/', $line)) continue;
            
            $date      = substr($line, 0, 10);
            $time      = substr($line, 11, 8);
            $lat       = substr($line, 21, 7);
            $lng       = substr($line, 30, 7);
            $depth     = substr($line, 45, 4);
            $ml        = (float)substr($line, 60, 3);
            $location  = trim(substr($line, 71, 50));
            
            $hash = hash('sha256', $date . $time . $location . $lat . $lng);
            
            $stmt->execute([
                str_replace('.', '-', $date),
                $time,
                (float)$lat,
                (float)$lng,
                (float)$depth,
                $ml,
                $location,
                $hash
            ]);
            $count++;
        }
        return $count;
    }
    return 0;
}

// API Endpoint (JSON response when ?action=get_data is called)
if (isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Fetch new data from Kandilli via internal function
    runKandilliScraper($pdo);
    
    // Get the latest 500 earthquakes to provide enough data for filters
    $sql = "SELECT id, date, time, latitude, longitude, depth, magnitude, location FROM earthquakes ORDER BY date DESC, time DESC LIMIT 500";
    $stmt = $pdo->query($sql);
    $earthquakes = $stmt->fetchAll();
    
    echo json_encode([
        "success" => true,
        "last_update" => date('Y-m-d H:i:s'),
        "data" => $earthquakes
    ]);
    exit; // Halt execution so HTML isn't outputted
}
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Türkiye Deprem Takip Platformu - Kandilli Verileri</title>
    
    <!-- Meta Tags -->
    <meta name="description" content="Kandilli Rasathanesi verileriyle Türkiye'de gerçekleşen son depremleri anlık olarak takip edin.">
    <meta name="author" content="Antigravity">
    
    <!-- External Fonts (Outfit) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    
    <!-- Leaflet JS & CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <!-- Inline Styles (Originally assets/css/style.css) -->
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: #161b22;
            --border-color: #30363d;
            --text-primary: #c9d1d9;
            --text-secondary: #8b949e;
            --accent-color: #58a6ff;
            --mag-low: #2ea043;
            --mag-med: #d29922;
            --mag-high: #f85149;
            --font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            font-family: var(--font-family);
            overflow: hidden; /* Main content handles scroll */
        }

        .app-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        header {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
        }

        .logo h1 {
            font-size: 1.25rem;
            font-weight: 700;
            background: linear-gradient(90deg, #58a6ff, #bc8cff);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .status-indicator {
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pulse {
            width: 8px;
            height: 8px;
            background: #3fb950;
            border-radius: 50%;
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 0.8; }
            50% { transform: scale(1.2); opacity: 0.4; }
            100% { transform: scale(0.8); opacity: 0.8; }
        }

        main {
            flex: 1;
            display: grid;
            grid-template-columns: 350px 1fr;
            overflow: hidden;
        }

        @media (max-width: 992px) {
            main {
                grid-template-columns: 1fr;
                grid-template-rows: 40vh 1fr;
            }
        }

        /* Sidebar List */
        #list-container {
            background-color: var(--card-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .list-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
        }

        /* Filters Section */
        .filters-wrapper {
            padding: 1.25rem;
            background: linear-gradient(180deg, #1c2128 0%, #161b22 100%);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
        }

        .search-box input {
            width: 100%;
            background: rgba(13, 17, 23, 0.8);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.6rem 1rem;
            border-radius: 8px;
            outline: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.9rem;
            backdrop-filter: blur(4px);
        }

        .search-box input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.15);
            background: var(--bg-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .filter-group {
            position: relative;
        }

        .filter-group select {
            width: 100%;
            background: rgba(13, 17, 23, 0.8);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.6rem 2.5rem 0.6rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            outline: none;
            cursor: pointer;
            appearance: none; /* Remove native arrow */
            transition: all 0.2s;
            backdrop-filter: blur(4px);
        }

        /* Custom Select Arrow */
        .filter-group::after {
            content: "";
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 10px;
            height: 10px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%238b949e'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-size: contain;
            background-repeat: no-repeat;
            pointer-events: none;
            transition: transform 0.2s;
        }

        .filter-group:focus-within::after {
            transform: translateY(-50%) rotate(180deg);
        }

        .filter-group select:hover {
            background: #1c2128;
            border-color: #484f58;
        }

        .filter-group select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(88, 166, 255, 0.15);
        }

        .filter-group select option {
            background: #161b22;
            color: var(--text-primary);
            padding: 10px;
        }

        .active-filters-info {
            display: none;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 1.25rem;
            background: rgba(88, 166, 255, 0.08);
            border-bottom: 1px solid rgba(88, 166, 255, 0.2);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--accent-color);
        }

        .clear-filters-btn {
            background: rgba(248, 81, 73, 0.1);
            border: 1px solid rgba(248, 81, 73, 0.4);
            color: #f85149;
            padding: 4px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 700;
            transition: all 0.2s;
        }

        .clear-filters-btn:hover {
            background: #f85149;
            color: white;
            transform: scale(1.05);
        }

        .eq-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        .eq-card {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            transition: transform 0.2s, background 0.2s;
            cursor: pointer;
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .eq-card:hover {
            background: #1c2128;
            transform: translateY(-2px);
        }

        .mag-badge {
            min-width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: #fff;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .mag-low { background-color: var(--mag-low); }
        .mag-med { background-color: var(--mag-med); }
        .mag-high { background-color: var(--mag-high); }

        .eq-info {
            flex: 1;
        }

        .eq-location {
            font-weight: 600;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .eq-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
            display: flex;
            gap: 0.75rem;
        }

        /* Map Section */
        #map {
            width: 100%;
            height: 100%;
            background-color: var(--bg-color);
        }

        /* Leaflet Dark Mode Patch */
        .leaflet-container {
            background: #2b2b2b !important;
        }

        .leaflet-tile-pane {
            filter: invert(100%) hue-rotate(180deg) brightness(95%) contrast(90%);
        }

        .leaflet-popup-content-wrapper, .leaflet-popup-tip {
            background-color: var(--card-bg) !important;
            color: var(--text-primary) !important;
            border: 1px solid var(--border-color);
        }

        .leaflet-control-zoom-in, .leaflet-control-zoom-out {
            background-color: var(--card-bg) !important;
            color: var(--text-primary) !important;
            border: 1px solid var(--border-color) !important;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }
        ::-webkit-scrollbar-thumb {
            background: #30363d;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #484f58;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <header>
            <div class="logo">
                <h1>TÜRKİYE DEPREM TAKİP</h1>
            </div>
            <div class="status-indicator">
                <div class="pulse"></div>
                <span id="status-msg">CANLI VERİ</span>
                <span id="last-update" style="margin-left: 10px; font-weight: 600;">--:--:--</span>
            </div>
        </header>

        <!-- Main Dashboard -->
        <main>
            <!-- Earthquake List Dashboard -->
            <section id="list-container">
                <div class="list-header">
                    Son 500 Deprem (Kandilli)
                </div>

                <!-- Advanced Filters -->
                <div class="filters-wrapper">
                    <div class="search-box">
                        <input type="text" id="search-input" placeholder="Konum ara (ör: Marmara)...">
                    </div>
                    <div class="filter-grid">
                        <div class="filter-group">
                            <select id="province-filter">
                                <option value="">Tüm İller</option>
                                <option value="ADANA">Adana</option>
                                <option value="ADIYAMAN">Adıyaman</option>
                                <option value="AFYON">Afyonkarahisar</option>
                                <option value="AGRI">Ağrı</option>
                                <option value="AKSARAY">Aksaray</option>
                                <option value="AMASYA">Amasya</option>
                                <option value="ANKARA">Ankara</option>
                                <option value="ANTALYA">Antalya</option>
                                <option value="ARDAHAN">Ardahan</option>
                                <option value="ARTVIN">Artvin</option>
                                <option value="AYDIN">Aydın</option>
                                <option value="BALIKESIR">Balıkesir</option>
                                <option value="BARTIN">Bartın</option>
                                <option value="BATMAN">Batman</option>
                                <option value="BAYBURT">Bayburt</option>
                                <option value="BILECIK">Bilecik</option>
                                <option value="BINGOL">Bingöl</option>
                                <option value="BITLIS">Bitlis</option>
                                <option value="BOLU">Bolu</option>
                                <option value="BURDUR">Burdur</option>
                                <option value="BURSA">Bursa</option>
                                <option value="CANAKKALE">Çanakkale</option>
                                <option value="CANKIRI">Çankırı</option>
                                <option value="CORUM">Çorum</option>
                                <option value="DENIZLI">Denizli</option>
                                <option value="DIYARBAKIR">Diyarbakır</option>
                                <option value="DUZCE">Düzce</option>
                                <option value="EDIRNE">Edirne</option>
                                <option value="ELAZIG">Elazığ</option>
                                <option value="ERZINCAN">Erzincan</option>
                                <option value="ERZURUM">Erzurum</option>
                                <option value="ESKISEHIR">Eskişehir</option>
                                <option value="GAZIANTEP">Gaziantep</option>
                                <option value="GIRESUN">Giresun</option>
                                <option value="GUMUSHANE">Gümüşhane</option>
                                <option value="HAKKARI">Hakkari</option>
                                <option value="HATAY">Hatay</option>
                                <option value="IGDIR">Iğdır</option>
                                <option value="ISPARTA">Isparta</option>
                                <option value="ISTANBUL">İstanbul</option>
                                <option value="IZMIR">İzmir</option>
                                <option value="KAHRAMANMARAS">K.Maraş</option>
                                <option value="KARABUK">Karabük</option>
                                <option value="KARAMAN">Karaman</option>
                                <option value="KARS">Kars</option>
                                <option value="KASTAMONU">Kastamonu</option>
                                <option value="KAYSERI">Kayseri</option>
                                <option value="KILIS">Kilis</option>
                                <option value="KIRIKKALE">Kırıkkale</option>
                                <option value="KIRKLARELI">Kırklareli</option>
                                <option value="KIRSEHIR">Kırşehir</option>
                                <option value="KOCAELI">Kocaeli</option>
                                <option value="KONYA">Konya</option>
                                <option value="KUTAHYA">Kütahya</option>
                                <option value="MALATYA">Malatya</option>
                                <option value="MANISA">Manisa</option>
                                <option value="MARDIN">Mardin</option>
                                <option value="MERSIN">Mersin</option>
                                <option value="MUGLA">Muğla</option>
                                <option value="MUS">Muş</option>
                                <option value="NEVSEHIR">Nevşehir</option>
                                <option value="NIGDE">Niğde</option>
                                <option value="ORDU">Ordu</option>
                                <option value="OSMANIYE">Osmaniye</option>
                                <option value="RIZE">Rize</option>
                                <option value="SAKARYA">Sakarya</option>
                                <option value="SAMSUN">Samsun</option>
                                <option value="SIIRT">Siirt</option>
                                <option value="SINOP">Sinop</option>
                                <option value="SIVAS">Sivas</option>
                                <option value="SANLIURFA">Şanlıurfa</option>
                                <option value="SIRNAK">Şırnak</option>
                                <option value="TEKIRDAG">Tekirdağ</option>
                                <option value="TOKAT">Tokat</option>
                                <option value="TRABZON">Trabzon</option>
                                <option value="TUNCELI">Tunceli</option>
                                <option value="USAK">Uşak</option>
                                <option value="VAN">Van</option>
                                <option value="YALOVA">Yalova</option>
                                <option value="YOZGAT">Yozgat</option>
                                <option value="ZONGULDAK">Zonguldak</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="time-filter">
                                <option value="all">Tüm Zamanlar</option>
                                <option value="1">Son 1 Saat</option>
                                <option value="6">Son 6 Saat</option>
                                <option value="24">Son 24 Saat</option>
                                <option value="168">Son 7 Gün</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="mag-sort">
                                <option value="desc">Büyükten Küçüğe</option>
                                <option value="asc">Küçükten Büyüğe</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Active Filter Indicator -->
                <div id="active-filters-bar" class="active-filters-info">
                    <span id="filter-count">0 filtre aktif</span>
                    <button id="clear-filters" class="clear-filters-btn">Temizle</button>
                </div>

                <div class="eq-list">
                    <!-- Dynamic Earthquake Cards -->
                    <div style="padding: 20px; text-align: center; color: var(--text-secondary);">
                        Veriler yükleniyor...
                    </div>
                </div>
            </section>

            <!-- Interactive Map -->
            <section id="map-container">
                <div id="map"></div>
            </section>
        </main>
    </div>

    <!-- Inline Script (Originally assets/js/app.js) -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // API and State
            const API_URL = '?action=get_data';
            let mapMarkers = [];
            let earthquakeData = []; // Raw data from API
            let filteredData = [];   // Data currently shown
            let map;
            
            // UI Selectors
            const listContainer = document.querySelector('.eq-list');
            const updateTimeEl = document.getElementById('last-update');
            const statusMsgEl = document.getElementById('status-msg');
            
            // Filter Selectors
            const searchInput = document.getElementById('search-input');
            const provinceFilter = document.getElementById('province-filter');
            const timeFilter = document.getElementById('time-filter');
            const magSort = document.getElementById('mag-sort');
            const activeFiltersBar = document.getElementById('active-filters-bar');
            const filterCountEl = document.getElementById('filter-count');
            const clearFiltersBtn = document.getElementById('clear-filters');
            
            // Initialize Map
            function initMap() {
                // Turkey Bounds
                const southWest = L.latLng(33.0, 22.0); 
                const northEast = L.latLng(45.0, 48.0);
                const bounds = L.latLngBounds(southWest, northEast);

                map = L.map('map', {
                    maxBounds: bounds,
                    maxBoundsViscosity: 1.0,
                    minZoom: 5
                }).setView([39.0, 35.5], 6);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    minZoom: 5,
                    attribution: '© OpenStreetMap contributors'
                }).addTo(map);
                
                fetchData();
                setupEventListeners();
            }
            
            // Fetch Data
            async function fetchData() {
                statusMsgEl.innerText = 'Güncelleniyor...';
                try {
                    const response = await fetch(API_URL);
                    const result = await response.json();
                    
                    if (result.success) {
                        earthquakeData = result.data;
                        updateTimeEl.innerText = result.last_update.split(' ')[1];
                        applyFilters(); // Apply current filters to new data
                        statusMsgEl.innerText = 'Canlı Veri';
                    }
                } catch (error) {
                    console.error('Fetch Error:', error);
                    statusMsgEl.innerText = 'Hata: Bağlantı Kesildi';
                }
            }

            // Filter Logic
            function applyFilters() {
                const query = searchInput.value.toLowerCase();
                const province = provinceFilter.value;
                const hours = timeFilter.value;
                const sort = magSort.value;
                
                const now = new Date();
                let activeCount = 0;
                if (query) activeCount++;
                if (province) activeCount++;
                if (hours !== 'all') activeCount++;

                // Filter
                filteredData = earthquakeData.filter(eq => {
                    // 1. Search filter
                    const matchesSearch = eq.location.toLowerCase().includes(query);
                    
                    // 2. Province filter
                    const matchesProvince = !province || eq.location.toUpperCase().includes(province);
                    
                    // 3. Time filter
                    let matchesTime = true;
                    if (hours !== 'all') {
                        const eqDate = new Date(`${eq.date} ${eq.time}`);
                        const diffHours = (now - eqDate) / (1000 * 60 * 60);
                        matchesTime = diffHours <= parseInt(hours);
                    }
                    
                    return matchesSearch && matchesProvince && matchesTime;
                });

                // Sort
                filteredData.sort((a, b) => {
                    const magA = parseFloat(a.magnitude);
                    const magB = parseFloat(b.magnitude);
                    return sort === 'desc' ? magB - magA : magA - magB;
                });

                // Update UI
                updateActiveFilterBar(activeCount);
                renderEarthquakes();
            }

            function updateActiveFilterBar(count) {
                if (count > 0) {
                    activeFiltersBar.style.display = 'flex';
                    filterCountEl.innerText = `${count} filtre aktif`;
                } else {
                    activeFiltersBar.style.display = 'none';
                }
            }

            function setupEventListeners() {
                [searchInput, provinceFilter, timeFilter, magSort].forEach(el => {
                    el.addEventListener('input', applyFilters);
                });

                clearFiltersBtn.addEventListener('click', () => {
                    searchInput.value = '';
                    provinceFilter.value = '';
                    timeFilter.value = 'all';
                    magSort.value = 'desc';
                    applyFilters();
                });
            }
            
            // Helper colors
            function getMagColor(mag) {
                if (mag >= 5.0) return '#f85149';
                if (mag >= 3.0) return '#d29922';
                return '#2ea043';
            }
            
            // Helper class names
            function getMagClass(mag) {
                if (mag >= 5.0) return 'mag-high';
                if (mag >= 3.0) return 'mag-med';
                return 'mag-low';
            }
            
            // Render UI
            function renderEarthquakes() {
                listContainer.innerHTML = '';
                mapMarkers.forEach(m => map.removeLayer(m));
                mapMarkers = [];
                
                if (filteredData.length === 0) {
                    listContainer.innerHTML = '<div style="padding: 2.5rem; text-align: center; color: var(--text-secondary);">Eşleşen deprem bulunamadı.</div>';
                    return;
                }

                filteredData.forEach((eq, index) => {
                    const card = document.createElement('div');
                    card.className = 'eq-card';
                    card.innerHTML = `
                        <div class="mag-badge ${getMagClass(eq.magnitude)}">
                            ${eq.magnitude}
                        </div>
                        <div class="eq-info">
                            <div class="eq-location">${eq.location}</div>
                            <div class="eq-meta">
                                <span>🕒 ${eq.time}</span>
                                <span>📏 ${eq.depth} km</span>
                            </div>
                        </div>
                    `;
                    
                    card.onclick = () => {
                        map.setView([eq.latitude, eq.longitude], 10);
                        const marker = mapMarkers[index];
                        if (marker) marker.openPopup();
                        if (window.innerWidth < 992) {
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        }
                    };
                    
                    listContainer.appendChild(card);
                    
                    const color = getMagColor(eq.magnitude);
                    const radius = Math.pow(eq.magnitude, 1.8) * 1500;
                    
                    const marker = L.circle([eq.latitude, eq.longitude], {
                        color: color,
                        fillColor: color,
                        fillOpacity: 0.5,
                        radius: radius
                    }).addTo(map);
                    
                    marker.bindPopup(`
                        <div class="popup-content">
                            <strong>Büyüklük: ${eq.magnitude}</strong><br>
                            Konum: ${eq.location}<br>
                            Derinlik: ${eq.depth} km<br>
                            Tarih: ${eq.date} ${eq.time}
                        </div>
                    `);
                    
                    mapMarkers.push(marker);
                });
            }
            
            initMap();
            setInterval(fetchData, 30000); // 30 seconds
        });
    </script>
</body>
</html>
