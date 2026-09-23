<?php
/**
 * PREMIUM HOSPITAL SIMULATION 3D - ENHANCED VERSION
 * Theme: Orange (#E67E22) & White
 * Focus: Performance, Security, Responsiveness
 * Version: 2.0 - Optimized & Secured
 */

// Error Reporting - Disable in production
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session Management
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

// ============================================
// SECURITY FUNCTIONS
// ============================================

/**
 * Generate CSRF Token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF Token
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize Output (XSS Prevention)
 */
function sanitizeOutput($data) {
    if (is_array($data)) {
        return array_map('sanitizeOutput', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Rate Limiting for AJAX requests
 */
function checkRateLimit() {
    $key = 'ajax_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $limit = 60; // Max 60 requests
    $period = 60; // Per 60 seconds
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'start' => time()];
    }
    
    $data = &$_SESSION[$key];
    
    // Reset if period expired
    if (time() - $data['start'] > $period) {
        $data = ['count' => 0, 'start' => time()];
    }
    
    $data['count']++;
    
    if ($data['count'] > $limit) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please wait.']);
        exit;
    }
}

// ============================================
// AUTHORIZATION CHECK
// ============================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') { 
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    http_response_code(403);
    die("Unauthorized Access"); 
}

// ============================================
// AJAX DATA ENDPOINT - OPTIMIZED
// ============================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // Rate Limiting
    checkRateLimit();
    
    // CSRF Validation for AJAX
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? $_GET['csrf'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    try {
        // OPTIMIZED QUERY - Using JOINs instead of Subqueries
        $query = "
            SELECT 
                p.patient_id as id, 
                p.name,
                COALESCE(latest_visit.status, 'None') as v_stat,
                COALESCE(latest_test.status, 'None') as l_stat,
                COALESCE(latest_admission.status, 'None') as a_stat,
                COALESCE(recent_prescriptions.rx_cnt, 0) as rx_cnt
            FROM Patients p
            
            LEFT JOIN (
                SELECT 
                    patient_id, 
                    status,
                    ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY visit_date DESC) as rn
                FROM Visits
            ) latest_visit ON p.patient_id = latest_visit.patient_id AND latest_visit.rn = 1
            
            LEFT JOIN (
                SELECT 
                    patient_id, 
                    status,
                    ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY request_date DESC) as rn
                FROM Medical_Tests
            ) latest_test ON p.patient_id = latest_test.patient_id AND latest_test.rn = 1
            
            LEFT JOIN (
                SELECT 
                    patient_id, 
                    status,
                    ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY admission_date DESC) as rn
                FROM Admissions
            ) latest_admission ON p.patient_id = latest_admission.patient_id AND latest_admission.rn = 1
            
            LEFT JOIN (
                SELECT 
                    patient_id, 
                    COUNT(*) as rx_cnt
                FROM Prescriptions 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                GROUP BY patient_id
            ) recent_prescriptions ON p.patient_id = recent_prescriptions.patient_id
            
            ORDER BY p.patient_id DESC 
            LIMIT 100
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Location Priority System
        $locationPriorities = [
            'ward' => 1,
            'lab' => 2,
            'clinic' => 3,
            'pharmacy' => 4,
            'reception' => 5
        ];
        
        $results = [];
        foreach($raw as $row) {
            $possibleLocations = [];
            
            // Determine all possible locations
            if ($row['a_stat'] === 'Active') {
                $possibleLocations['ward'] = $locationPriorities['ward'];
            }
            if ($row['l_stat'] === 'Requested') {
                $possibleLocations['lab'] = $locationPriorities['lab'];
            }
            if ($row['v_stat'] === 'In Progress') {
                $possibleLocations['clinic'] = $locationPriorities['clinic'];
            }
            if ($row['rx_cnt'] > 0) {
                $possibleLocations['pharmacy'] = $locationPriorities['pharmacy'];
            }
            
            // Select highest priority location
            if (empty($possibleLocations)) {
                $loc = 'reception';
            } else {
                asort($possibleLocations);
                $loc = array_key_first($possibleLocations);
            }
            
            $results[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'], // Will be sanitized in JS
                'loc' => $loc
            ];
        }
        
        // Add ETag for caching
        $etag = md5(json_encode($results));
        header("ETag: $etag");
        
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
            http_response_code(304); // Not Modified
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $results,
            'timestamp' => time(),
            'count' => count($results)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error occurred'
        ]);
    } catch (Exception $e) {
        error_log("General Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'An error occurred'
        ]);
    }
    exit;
}

// ============================================
// MAIN PAGE RENDERING
// ============================================
require_once __DIR__ . '/../../includes/header.php';

$p_id = isset($_GET['patient_id']) ? filter_var($_GET['patient_id'], FILTER_VALIDATE_INT) : null;
$csrf_token = generateCSRFToken();

// Get total patients count
try {
    $total_in_db = $pdo->query("SELECT COUNT(*) FROM Patients")->fetchColumn();
} catch (Exception $e) {
    $total_in_db = 0;
}
?>

<!-- Navigation -->
<li><a href="index.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">الرئيسـية</a></li>
<li><a href="patients_report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'patients_report.php' ? 'active' : ''; ?>">تقرير المرضى الشامل</a></li>
<li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : ''; ?>">التحليلات والإحصائيات</a></li>
<li><a href="tracking.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'tracking.php' ? 'active' : ''; ?>">تتبع المرضى </a></li>
<li><a href="change_password.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'change_password.php' ? 'active' : ''; ?>">تغيير كلمة السر</a></li>
</ul>
<a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
</div>

<!-- MAIN DASHBOARD CONTENT -->
<div class="content" style="position: relative; padding: 0;">
    
    <div class="sim-container">
        
        <!-- PREMIUM HUD -->
        <div class="premium-hud">
            <div class="hud-left">
                <h3>نظام التتبع الجغرافي <span class="highlight">مستشفى البدر</span></h3>
                <div id="connection-status" style="font-size: 0.7rem; color: #666;">
                    إجمالي المرضى: <strong><?= $total_in_db ?></strong> | جاري التحميل...
                </div>
            </div>

            <div class="hud-center">
                <button onclick="tick()" class="btn-refresh" aria-label="تحديث البيانات">⟳ تحديث الآن</button>
                <button onclick="toggleDataLog()" class="btn-debug" aria-label="عرض البيانات">📊 البيانات</button>
            </div>
            
            <div class="hud-right">
                <div class="search-glass">
                    <input 
                        type="number" 
                        id="q_search" 
                        placeholder="ID..." 
                        value="<?= $p_id ?>"
                        aria-label="رقم المريض"
                        min="1"
                    >
                    <button onclick="doTrack()" aria-label="تتبع المريض">تتبع</button>
                </div>
            </div>
        </div>

        <!-- NOTIFICATION SYSTEM -->
        <div id="notification-container" class="notification-container"></div>

        <!-- DATA LOG PANEL -->
        <div id="data-panel" class="data-panel">
            <h4>البيانات المباشرة</h4>
            <div id="data-content"></div>
        </div>

        <!-- LOADING OVERLAY -->
        <div id="loading-overlay" class="loading-overlay">
            <div class="spinner"></div>
            <p>جاري التحميل...</p>
        </div>

        <!-- 3D WORLD AREA -->
        <div class="world-3d" id="world-3d">
            <div class="floor-grid"></div>
            
            <!-- ROOM BLOCKS - CENTERED GRID LAYOUT -->
            <div class="hospital-block reception" style="--w: 400px; --h: 400px; --x: -550px; --y: -550px; --z: 40px;">
                <div class="face top"></div><div class="face front"></div><div class="face right"></div>
                <div class="signage">🛋️ الاستقبال</div>
            </div>

            <div class="hospital-block clinics" style="--w: 500px; --h: 300px; --x: 100px; --y: -550px; --z: 60px;">
                <div class="face top"></div><div class="face front"></div><div class="face right"></div>
                <div class="signage">🩺 العيادات</div>
            </div>

            <div class="hospital-block labs" style="--w: 500px; --h: 300px; --x: 100px; --y: -100px; --z: 50px;">
                <div class="face top"></div><div class="face front"></div><div class="face right"></div>
                <div class="signage">🧪 المختبرات</div>
            </div>

            <div class="hospital-block wards" style="--w: 400px; --h: 450px; --x: -550px; --y: 50px; --z: 80px;">
                <div class="face top"></div><div class="face front"></div><div class="face right"></div>
                <div class="signage">🛌 رقود</div>
            </div>

            <div class="hospital-block pharmacy" style="--w: 500px; --h: 200px; --x: 100px; --y: 350px; --z: 30px;">
                <div class="face top"></div><div class="face front"></div><div class="face right"></div>
                <div class="signage">💊 الصيدلية</div>
            </div>

            <!-- AVATARS LAYER -->
            <div id="human-layer"></div>
        </div>

    </div>
</div>

<!-- AVATAR TEMPLATE -->
<template id="avatar-template">
    <div class="human-wrapper">
        <svg class="human-svg" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2C10.9 2 10 2.9 10 4s.9 2 2 2 2-.9 2-2-.9-2-2-2zm9 7h-6v13h-2v-6h-2v6H9V9H3V7h18v2z"/>
        </svg>
        <div class="p-label"></div>
    </div>
</template>

<style>
    :root {
        --hospital-orange: #E67E22;
        --hospital-white: #FFFFFF;
        --hospital-gray: #F8F9FA;
        --grid-line: rgba(230, 126, 34, 0.08);
        --shadow-color: rgba(0, 0, 0, 0.1);
    }

    .sim-container {
        width: 100%; 
        height: 100vh;
        background: radial-gradient(circle at center, #fff 0%, #eef2f3 100%);
        overflow: hidden; 
        font-family: 'Segoe UI', Arial, sans-serif;
        position: relative;
    }

    /* ============================================
       PREMIUM HUD STYLES
       ============================================ */
    .premium-hud {
        position: absolute; 
        top: 0; 
        left: 0; 
        right: 0; 
        height: 70px;
        background: rgba(255,255,255,0.95); 
        backdrop-filter: blur(10px);
        border-bottom: 2px solid var(--hospital-orange);
        display: flex; 
        align-items: center; 
        justify-content: space-between;
        padding: 0 30px; 
        z-index: 9999; 
        box-shadow: 0 5px 20px var(--shadow-color);
    }
    
    .hud-left h3 { 
        margin: 0; 
        font-size: 1rem; 
        color: #333; 
    }
    
    .hud-left .highlight { 
        color: var(--hospital-orange); 
        font-weight: 800; 
    }
    
    .btn-refresh {
        background: var(--hospital-orange);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 10px rgba(230, 126, 34, 0.3);
    }
    
    .btn-refresh:hover { 
        transform: scale(1.05); 
        background: #d35400; 
    }
    
    .btn-refresh:active {
        transform: scale(0.98);
    }

    .btn-debug {
        background: #34495e;
        color: white;
        border: none;
        padding: 10px 15px;
        border-radius: 8px;
        font-weight: bold;
        cursor: pointer;
        font-size: 0.8rem;
        margin-right: 10px;
        transition: all 0.3s ease;
    }
    
    .btn-debug:hover {
        background: #2c3e50;
    }

    .search-glass { 
        display: flex; 
        background: #fff; 
        border: 2px solid #e2e8f0; 
        border-radius: 12px; 
        overflow: hidden; 
    }
    
    .search-glass input { 
        border: none; 
        padding: 10px 15px; 
        width: 150px; 
        outline: none; 
        font-size: 0.9rem;
    }
    
    .search-glass button { 
        background: var(--hospital-orange); 
        color: white; 
        border: none; 
        padding: 0 20px; 
        font-weight: bold; 
        cursor: pointer;
        transition: background 0.3s ease;
    }
    
    .search-glass button:hover {
        background: #d35400;
    }

    /* ============================================
       NOTIFICATION SYSTEM
       ============================================ */
    .notification-container {
        position: fixed;
        top: 90px;
        right: 20px;
        z-index: 10001;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: 350px;
    }

    .notification {
        background: white;
        border-radius: 10px;
        padding: 15px 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        border-left: 4px solid;
        animation: slideIn 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .notification.success { border-left-color: #27ae60; }
    .notification.error { border-left-color: #e74c3c; }
    .notification.warning { border-left-color: #f39c12; }
    .notification.info { border-left-color: #3498db; }

    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* ============================================
       DATA PANEL
       ============================================ */
    .data-panel {
        display: none;
        position: absolute;
        top: 90px;
        right: 20px;
        width: 320px;
        max-height: 400px;
        background: rgba(255,255,255,0.98);
        z-index: 10000;
        border: 2px solid var(--hospital-orange);
        border-radius: 12px;
        overflow: auto;
        padding: 20px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.25);
    }

    .data-panel h4 {
        margin: 0 0 15px 0;
        color: var(--hospital-orange);
        font-size: 1.1rem;
        border-bottom: 2px solid var(--hospital-orange);
        padding-bottom: 10px;
    }

    .data-panel #data-content {
        font-size: 0.85rem;
        font-family: 'Courier New', monospace;
    }

    /* ============================================
       LOADING OVERLAY
       ============================================ */
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        z-index: 9998;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }

    .loading-overlay.active {
        display: flex;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid var(--hospital-orange);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* ============================================
       3D SCENE STYLES
       ============================================ */
    .world-3d {
        position: absolute; 
        top: 52%; 
        left: 50%; 
        width: 1px; 
        height: 1px;
        transform: rotateX(55deg) rotateZ(-45deg) scale(0.62);
        transform-style: preserve-3d;
        transition: transform 1s ease;
    }

    .floor-grid {
        position: absolute; 
        transform: translate(-50%, -50%);
        width: 3000px; 
        height: 3000px;
        background-image: 
            linear-gradient(var(--grid-line) 1px, transparent 1px),
            linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
        background-size: 50px 50px;
        transform-style: preserve-3d;
        z-index: -1;
    }

    /* ============================================
       ARCHITECTURAL BLOCKS
       ============================================ */
    .hospital-block {
        position: absolute;
        width: var(--w); 
        height: var(--h);
        transform: translate3d(var(--x), var(--y), 0);
        transform-style: preserve-3d;
    }
    
    .face { 
        position: absolute; 
        border: 1px solid rgba(255,255,255,0.2); 
    }
    
    .hospital-block .top {
        display: block;
        inset: 0; 
        background: rgba(255, 255, 255, 0.15);
        border: 2px solid rgba(230, 126, 34, 0.2);
        transform: translateZ(var(--z));
        transform-style: preserve-3d;
        backdrop-filter: blur(2px);
    }
    
    .hospital-block .front {
        width: var(--w); 
        height: var(--z); 
        top: 100%; 
        left: 0;
        transform: rotateX(-90deg); 
        transform-origin: top;
        background: linear-gradient(to bottom, var(--hospital-orange), #c0392b);
    }
    
    .hospital-block .right {
        width: var(--z); 
        height: var(--h); 
        top: 0; 
        left: 100%;
        transform: rotateY(90deg); 
        transform-origin: left;
        background: linear-gradient(to right, #d35400, #a04000);
    }

    /* ============================================
       FLOATING SIGNAGE
       ============================================ */
    .signage {
        position: absolute; 
        bottom: -40px; 
        left: 50%; 
        transform: rotateZ(45deg) rotateX(-55deg) translateX(-50%);
        background: white; 
        color: var(--hospital-orange); 
        border: 2px solid var(--hospital-orange);
        padding: 8px 25px; 
        border-radius: 30px; 
        font-weight: 800; 
        font-size: 1.1rem;
        white-space: nowrap; 
        box-shadow: 0 10px 20px var(--shadow-color);
        pointer-events: none; 
        z-index: 1000;
    }

    /* ============================================
       SVG HUMAN AVATARS
       ============================================ */
    #human-layer {
        position: absolute;
        top: 0; 
        left: 0;
        transform-style: preserve-3d;
        pointer-events: none;
        z-index: 1000;
    }

    .human-avatar {
        position: absolute;
        top: 0; 
        left: 0;
        transform-style: preserve-3d;
        transition: transform 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    
    .human-wrapper {
        transform: rotateZ(45deg) rotateX(-55deg) translateZ(20px);
        display: flex; 
        flex-direction: column; 
        align-items: center;
        width: 100px;
    }
    
    .human-svg { 
        width: 60px; 
        height: 90px; 
        fill: #FF5722;
        stroke: #fff; 
        stroke-width: 2px;
        filter: drop-shadow(0 20px 10px rgba(0,0,0,0.5));
        animation: bob 2s infinite ease-in-out alternate;
    }
    
    @keyframes bob {
        from { transform: translateY(0); }
        to { transform: translateY(-20px); }
    }

    .p-label {
        background: rgba(255, 255, 255, 0.95);
        color: #000;
        font-size: 0.85rem;
        font-weight: 800;
        padding: 4px 12px;
        border-radius: 6px;
        margin-top: 5px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        border: 2px solid var(--hospital-orange);
        white-space: nowrap;
        pointer-events: auto;
    }

    .human-avatar.is-searching .human-svg { 
        fill: #00E5FF;
        transform: scale(1.4);
    }

    /* ============================================
       RESPONSIVE DESIGN
       ============================================ */
    @media (max-width: 1024px) {
        .world-3d {
            transform: rotateX(60deg) rotateZ(-45deg) scale(0.45);
        }
        
        .premium-hud {
            flex-wrap: wrap;
            height: auto;
            padding: 15px;
        }
        
        .hud-left, .hud-center, .hud-right {
            flex: 1 1 100%;
            margin: 5px 0;
        }
    }

    @media (max-width: 768px) {
        .world-3d {
            transform: rotateX(65deg) rotateZ(-45deg) scale(0.35);
        }
        
        .data-panel {
            width: 90%;
            right: 5%;
        }
        
        .human-svg {
            width: 50px;
            height: 75px;
        }
        
        .p-label {
            font-size: 0.75rem;
            padding: 3px 8px;
        }
        
        .signage {
            font-size: 0.9rem;
            padding: 6px 15px;
        }
    }

    @media (max-width: 480px) {
        .world-3d {
            transform: rotateX(70deg) rotateZ(-45deg) scale(0.25);
        }
        
        .search-glass input {
            width: 100px;
        }
        
        .btn-refresh, .btn-debug {
            padding: 8px 12px;
            font-size: 0.8rem;
        }
    }

    /* ============================================
       ACCESSIBILITY
       ============================================ */
    @media (prefers-reduced-motion: reduce) {
        .human-svg {
            animation: none;
        }
        
        .human-avatar {
            transition: none;
        }
    }

    /* Focus styles for keyboard navigation */
    button:focus,
    input:focus {
        outline: 3px solid var(--hospital-orange);
        outline-offset: 2px;
    }
</style>

<script>
    // ============================================
    // CONFIGURATION
    // ============================================
    const CONFIG = {
        ROOMS: {
            reception: { x: -150, y: -700, z: 20 },
            clinic:    { x: 150,  y: -400, z: 20 },
            lab:       { x: -150,  y: 130, z: 20 },
            ward:      { x: -750, y: 175,  z: 20 },
            pharmacy:  { x: 350,  y: 450,  z: 20 }
        },
        REFRESH_INTERVAL: 5000, // 5 seconds
        MAX_RETRIES: 3,
        CSRF_TOKEN: '<?= $csrf_token ?>',
        TARGET_ID: '<?= $p_id ?>'
    };

    // ============================================
    // STATE MANAGEMENT
    // ============================================
    const AppState = {
        trackingCache: {},
        lastDataHash: null,
        errorCount: 0,
        isPageVisible: true,
        isLoading: false
    };

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    
    /**
     * Sanitize text content (XSS Prevention)
     */
    function sanitizeText(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Generate hash for data comparison
     */
    function generateHash(data) {
        return JSON.stringify(data);
    }

    /**
     * Show notification to user
     */
    function showNotification(message, type = 'info', duration = 3000) {
        const container = document.getElementById('notification-container');
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icons = {
            success: '✓',
            error: '✗',
            warning: '⚠',
            info: 'ℹ'
        };
        
        notification.innerHTML = `
            <span style="font-size: 1.5rem;">${icons[type] || icons.info}</span>
            <span>${sanitizeText(message)}</span>
        `;
        
        container.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideIn 0.3s ease reverse';
            setTimeout(() => notification.remove(), 300);
        }, duration);
    }

    /**
     * Show/hide loading overlay
     */
    function setLoading(isLoading) {
        AppState.isLoading = isLoading;
        const overlay = document.getElementById('loading-overlay');
        overlay.classList.toggle('active', isLoading);
    }

    /**
     * Update connection status
     */
    function updateConnectionStatus(count, isError = false) {
        const statusEl = document.getElementById('connection-status');
        const now = new Date().toLocaleTimeString('ar-SA');
        
        if (isError) {
            statusEl.innerHTML = `<span style="color: #e74c3c;">⚠ خطأ في الاتصال</span>`;
        } else {
            statusEl.innerHTML = `آخر تحديث: <strong>${now}</strong> | مريض: <strong>${count}</strong>`;
        }
    }

    // ============================================
    // AVATAR MANAGEMENT
    // ============================================
    
    /**
     * Create avatar element from template
     */
    function createAvatar(patient) {
        const template = document.getElementById('avatar-template');
        const clone = template.content.cloneNode(true);
        
        const wrapper = clone.querySelector('.human-wrapper');
        const label = clone.querySelector('.p-label');
        
        // Safely set patient name (XSS Prevention)
        label.textContent = patient.name;
        
        const div = document.createElement('div');
        div.id = 'h-' + patient.id;
        div.className = 'human-avatar';
        
        if (CONFIG.TARGET_ID == patient.id) {
            div.classList.add('is-searching');
        }
        
        div.appendChild(clone);
        
        return div;
    }

    /**
     * Update avatar position
     */
    function updateAvatarPosition(avatar, target, index) {
        // Grid positioning inside room
        const offsetX = (index % 4) * 60 - 100;
        const offsetY = Math.floor(index / 4) * 60 - 100;
        
        const x = target.x + offsetX;
        const y = target.y + offsetY;
        const z = target.z;
        
        avatar.style.transform = `translate3d(${x}px, ${y}px, ${z}px)`;
    }

    /**
     * Remove old avatars
     */
    function cleanupAvatars(activeIds) {
        Object.keys(AppState.trackingCache).forEach(id => {
            if (!activeIds.has(id)) {
                console.log('Removing avatar:', id);
                AppState.trackingCache[id].el.remove();
                delete AppState.trackingCache[id];
            }
        });
    }

    // ============================================
    // DATA FETCHING & PROCESSING
    // ============================================
    
    /**
     * Fetch tracking data from server
     */
    async function fetchTrackingData() {
        const response = await fetch(`tracking.php?ajax=1&csrf=${CONFIG.CSRF_TOKEN}`, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': CONFIG.CSRF_TOKEN,
                'Accept': 'application/json'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Unknown error');
        }
        
        return result.data;
    }

    /**
     * Update UI with tracking data
     */
    function updateTrackingUI(patients) {
        const layer = document.getElementById('human-layer');
        const dataContent = document.getElementById('data-content');
        const activeIds = new Set(patients.map(p => String(p.id)));
        
        // Update debug panel
        if (patients.length > 0) {
            dataContent.innerHTML = patients.map(p => `
                <div style="border-bottom:1px solid #eee; padding:5px; margin-bottom: 5px;">
                    <strong>ID:</strong> ${sanitizeText(String(p.id))} | 
                    <strong>${sanitizeText(p.name)}</strong> → 
                    <span style="color: var(--hospital-orange);">[${sanitizeText(p.loc)}]</span>
                </div>
            `).join('');
        } else {
            dataContent.innerHTML = '<center style="color: #999;">لا يوجد بيانات متوفرة</center>';
        }
        
        // Update/create avatars
        patients.forEach((patient, index) => {
            const pid = String(patient.id);
            const target = CONFIG.ROOMS[patient.loc] || CONFIG.ROOMS.reception;
            
            // Create avatar if doesn't exist
            if (!AppState.trackingCache[pid]) {
                console.log('Creating avatar for patient:', pid);
                const avatar = createAvatar(patient);
                layer.appendChild(avatar);
                AppState.trackingCache[pid] = { el: avatar };
            }
            
            // Update position
            updateAvatarPosition(AppState.trackingCache[pid].el, target, index);
        });
        
        // Cleanup removed patients
        cleanupAvatars(activeIds);
        
        // Update status
        updateConnectionStatus(patients.length, false);
    }

    // ============================================
    // MAIN TICK FUNCTION
    // ============================================
    
    /**
     * Main update function
     */
    async function tick() {
        // Skip if page is not visible
        if (!AppState.isPageVisible) {
            console.log('Page not visible, skipping update');
            return;
        }
        
        // Skip if already loading
        if (AppState.isLoading) {
            console.log('Already loading, skipping');
            return;
        }
        
        try {
            console.log('Fetching tracking data...');
            
            const patients = await fetchTrackingData();
            const currentHash = generateHash(patients);
            
            // Only update if data changed
            if (currentHash !== AppState.lastDataHash) {
                console.log('Data changed, updating UI');
                updateTrackingUI(patients);
                AppState.lastDataHash = currentHash;
            } else {
                console.log('No data changes detected');
            }
            
            // Reset error count on success
            AppState.errorCount = 0;
            
        } catch (error) {
            console.error('Tracking error:', error);
            AppState.errorCount++;
            
            updateConnectionStatus(0, true);
            
            // Show notification after multiple failures
            if (AppState.errorCount >= CONFIG.MAX_RETRIES) {
                showNotification(
                    'فشل الاتصال بالخادم. يرجى التحقق من الاتصال.',
                    'error',
                    5000
                );
            }
        }
    }

    // ============================================
    // USER ACTIONS
    // ============================================
    
    /**
     * Track specific patient
     */
    function doTrack() {
        const input = document.getElementById('q_search');
        const value = input.value.trim();
        
        if (!value) {
            showNotification('الرجاء إدخال رقم المريض', 'warning');
            input.focus();
            return;
        }
        
        const patientId = parseInt(value);
        
        if (isNaN(patientId) || patientId < 1) {
            showNotification('رقم المريض غير صحيح', 'error');
            input.focus();
            return;
        }
        
        window.location.href = `tracking.php?patient_id=${patientId}`;
    }

    /**
     * Toggle data log panel
     */
    function toggleDataLog() {
        const panel = document.getElementById('data-panel');
        const isVisible = panel.style.display !== 'none';
        panel.style.display = isVisible ? 'none' : 'block';
    }

    // ============================================
    // PAGE VISIBILITY API
    // ============================================
    
    document.addEventListener('visibilitychange', () => {
        AppState.isPageVisible = !document.hidden;
        
        if (AppState.isPageVisible) {
            console.log('Page visible, resuming updates');
            tick(); // Immediate update when page becomes visible
        } else {
            console.log('Page hidden, pausing updates');
        }
    });

    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================
    
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + R: Refresh
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            tick();
            showNotification('تم التحديث', 'success', 1500);
        }
        
        // Enter in search box: Track
        if (e.key === 'Enter' && document.activeElement.id === 'q_search') {
            e.preventDefault();
            doTrack();
        }
    });

    // ============================================
    // INITIALIZATION
    // ============================================
    
    // Auto-refresh interval
    setInterval(tick, CONFIG.REFRESH_INTERVAL);

    // Initial load
    window.addEventListener('load', () => {
        console.log('Application initialized');
        tick();
        showNotification('نظام التتبع جاهز', 'success', 2000);
    });

    // Handle errors globally
    window.addEventListener('error', (e) => {
        console.error('Global error:', e.error);
        showNotification('حدث خطأ غير متوقع', 'error');
    });

    // Handle unhandled promise rejections
    window.addEventListener('unhandledrejection', (e) => {
        console.error('Unhandled promise rejection:', e.reason);
        showNotification('حدث خطأ في معالجة البيانات', 'error');
    });
</script>

</body>
</html>