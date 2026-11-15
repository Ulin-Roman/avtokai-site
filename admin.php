<?php
/* CODEX_FILENAME_GUARD_V1 */
if (\basename(__FILE__) !== 'admin.php') { \http_response_code(404); exit; }
/* CODEX_FILENAME_GUARD_END_V1 */
ini_set('display_errors', 0);
session_start();
error_reporting(E_ALL);

// ============================================
// КОНФИГУРАЦИЯ
// ============================================
require_once __DIR__ . '/config.php';



define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('SEO_FILE', __DIR__ . '/seo_data.json');

// ============================================
// ПОДКЛЮЧЕНИЕ К БД
// ============================================
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("UPDATE orders SET status = 'new' WHERE status IS NULL OR status = ''");
} catch(PDOException $e) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Сервис временно недоступен. Повторите попытку позже.']);
    exit;
}

// ============================================
// ФУНКЦИИ ДЛЯ РАБОТЫ С SEO
// ============================================
function getSeoData() {
    if (!file_exists(SEO_FILE)) {
        file_put_contents(SEO_FILE, json_encode([]));
        return [];
    }
    $content = file_get_contents(SEO_FILE);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveSeoData($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $json !== false && file_put_contents(SEO_FILE, $json, LOCK_EX) === strlen($json);
}

function transliterate($text) {
    $cyr = ['а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я','А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'];
    $lat = ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','h','ts','ch','sh','shch','','y','','e','yu','ya','A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O','P','R','S','T','U','F','H','Ts','Ch','Sh','Shch','','Y','','E','Yu','Ya'];
    $text = str_replace($cyr, $lat, $text);
    $text = preg_replace('/[^a-zA-Z0-9\-]/', '_', $text);
    $text = preg_replace('/_+/', '_', $text);
    return trim($text, '_');
}

// ============================================
// ЗАЩИТА ОТ БРУТФОРСА
// ============================================
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$attempts_file = __DIR__ . '/login_attempts.json';
$max_attempts = 10;
$block_time = 900;

$attempts = [];
if (file_exists($attempts_file)) {
    $attempts = json_decode(file_get_contents($attempts_file), true) ?: [];
}

foreach ($attempts as $key => $data) {
    if ($data['time'] < time() - 86400) {
        unset($attempts[$key]);
    }
}

$is_blocked = false;
if (isset($attempts[$ip])) {
    $ip_attempts = $attempts[$ip];
    if ($ip_attempts['count'] >= $max_attempts && $ip_attempts['time'] > time() - $block_time) {
        $is_blocked = true;
    }
}

// ============================================
// ОБРАБОТКА ЗАПРОСОВ
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // ---------- ЛОГИН ----------
    if ($_POST['action'] === 'login') {
        if ($is_blocked) {
            echo json_encode(['success' => false, 'message' => 'Слишком много попыток. Подождите 15 минут.']);
            exit;
        }
        
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_id'] = $admin['id'];
                
                if (isset($attempts[$ip])) {
                    unset($attempts[$ip]);
                    file_put_contents($attempts_file, json_encode($attempts));
                }
                
                echo json_encode(['success' => true]);
            } else {
                if (!isset($attempts[$ip])) {
                    $attempts[$ip] = ['count' => 1, 'time' => time()];
                } else {
                    $attempts[$ip]['count']++;
                    $attempts[$ip]['time'] = time();
                }
                file_put_contents($attempts_file, json_encode($attempts));
                echo json_encode(['success' => false, 'message' => 'Неверный логин или пароль']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
        }
        exit;
    }
    
    // ---------- ВЫХОД ----------
    if ($_POST['action'] === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ---------- ПРОВЕРКА АВТОРИЗАЦИИ ----------
    if ($_POST['action'] === 'check_auth') {
        $logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        echo json_encode(['logged_in' => $logged_in]);
        exit;
    }
    
    // ---------- ВСЕ ОСТАЛЬНЫЕ ДЕЙСТВИЯ - ТОЛЬКО ДЛЯ АВТОРИЗОВАННЫХ ----------
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Требуется авторизация']);
        exit;
    }
    
    // ---------- СПИСОК ВСЕХ ЗАЯВОК ----------
    if ($_POST['action'] === 'get_orders') {
        try {
            $sql = "SELECT o.*, 
                    (SELECT COUNT(*) FROM order_photos WHERE order_id = o.id) as photos_count
                    FROM orders o 
                    ORDER BY o.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as &$order) {
                if (empty($order['status'])) {
                    $order['status'] = 'new';
                }
            }
            
            echo json_encode($orders);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось загрузить заявки. Повторите попытку позже.']);
        }
        exit;
    }
    
    // ---------- ОДНА ЗАЯВКА С ФОТО ----------
    if ($_POST['action'] === 'get_order') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                if (empty($order['status'])) {
                    $order['status'] = 'new';
                }
                
                $photoStmt = $pdo->prepare("SELECT * FROM order_photos WHERE order_id = ? ORDER BY id ASC");
                $photoStmt->execute([$id]);
                $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $order['photos'] = $photos;
                $order['photos_count'] = count($photos);
                
                $statusMap = [
                    'new' => '🟡 Новая',
                    'processing' => '🟠 В работе',
                    'completed' => '🟢 Завершена',
                    'cancelled' => '🔴 Отменена'
                ];
                $order['status_text'] = $statusMap[$order['status']] ?? '🟡 Новая';
                
                $conditionMap = [
                    'perfect' => 'Отличное',
                    'good' => 'Хорошее',
                    'accident' => 'Битое/ДТП',
                    'not_driving' => 'Не на ходу',
                    'credit' => 'Кредитное'
                ];
                $order['condition_text'] = $conditionMap[$order['car_condition']] ?? $order['car_condition'] ?? 'Не указано';
            }
            
            echo json_encode($order ?: null);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось загрузить заявку. Повторите попытку позже.']);
        }
        exit;
    }
    
    // ---------- ОБНОВЛЕНИЕ СТАТУСА ----------
    if ($_POST['action'] === 'update_order') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'new';
            
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Не удалось обновить статус. Повторите попытку позже.']);
        }
        exit;
    }
    
    // ---------- УДАЛЕНИЕ ЗАЯВКИ ----------
    if ($_POST['action'] === 'delete_order') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            
            $photoStmt = $pdo->prepare("SELECT photo_path FROM order_photos WHERE order_id = ?");
            $photoStmt->execute([$id]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($photos as $photo) {
                if ($photo['photo_path']) {
                    $full_path = __DIR__ . '/' . $photo['photo_path'];
                    if (file_exists($full_path)) {
                        unlink($full_path);
                    }
                }
            }
            
            $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Не удалось удалить заявку. Повторите попытку позже.']);
        }
        exit;
    }
    
    // ---------- ПОЛУЧИТЬ ВСЕ SEO ТЕКСТЫ ----------
    if ($_POST['action'] === 'get_seo_list') {
        $data = getSeoData();
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
    
    // ---------- ПОЛУЧИТЬ ОДИН SEO ТЕКСТ ----------
    if ($_POST['action'] === 'get_seo_item') {
        $slug = $_POST['slug'] ?? '';
        $data = getSeoData();
        $item = $data[$slug] ?? null;
        echo json_encode(['success' => true, 'data' => $item]);
        exit;
    }
    
    // ---------- СОХРАНИТЬ SEO ТЕКСТ ----------
    if ($_POST['action'] === 'save_seo') {
        $slug = trim($_POST['slug'] ?? '');
        $station_name = trim($_POST['station_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $h1 = trim($_POST['h1'] ?? '');
        $text = trim($_POST['text'] ?? '');
        
        if (empty($slug)) {
            echo json_encode(['success' => false, 'message' => 'Укажите транскрипцию (slug)']);
            exit;
        }
        
        if (empty($station_name)) {
            echo json_encode(['success' => false, 'message' => 'Укажите название станции']);
            exit;
        }
        
        $data = getSeoData();
        
        $data[$slug] = [
            'station_name' => $station_name,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'h1' => $h1,
            'text' => $text,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if (!saveSeoData($data)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Не удалось сохранить SEO текст. Повторите попытку позже.']);
            exit;
        }
        
        echo json_encode(['success' => true, 'message' => 'SEO текст сохранён', 'slug' => $slug]);
        exit;
    }
    
    // ---------- УДАЛИТЬ SEO ТЕКСТ ----------
    if ($_POST['action'] === 'delete_seo') {
        $slug = $_POST['slug'] ?? '';
        $data = getSeoData();
        if (isset($data[$slug])) {
            unset($data[$slug]);
            if (!saveSeoData($data)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Не удалось удалить SEO текст. Повторите попытку позже.']);
                exit;
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Запись не найдена']);
        }
        exit;
    }
    
    // ---------- ГЕНЕРАЦИЯ ТРАНСКРИПЦИИ ----------
    if ($_POST['action'] === 'generate_slug') {
        $name = $_POST['name'] ?? '';
        $slug = transliterate($name);
        echo json_encode(['success' => true, 'slug' => $slug]);
        exit;
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Автокай — Админ-панель</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        
        /* ЭКРАН ВХОДА */
        .login-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #e53e3e; display: flex; align-items: center; justify-content: center; z-index: 2000; }
        .login-box { background: white; padding: 40px; border-radius: 16px; width: 100%; max-width: 400px; }
        .login-box h1 { color: #e53e3e; margin-bottom: 30px; text-align: center; }
        .login-box input { width: 100%; padding: 15px; border: 2px solid #e2e8f0; border-radius: 8px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 15px; background: #e53e3e; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .login-box button:hover { background: #c53030; }
        
        /* АДМИН ПАНЕЛЬ */
        .admin-screen { display: none; max-width: 1400px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: white; padding: 20px 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); flex-wrap: wrap; gap: 15px; }
        .header h1 { color: #e53e3e; font-size: 24px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        
        .logout-btn, .tab-btn { padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 10px; border: none; font-size: 14px; transition: all 0.3s ease; }
        .logout-btn { background: transparent; border: 2px solid #e53e3e; color: #e53e3e; }
        .logout-btn:hover { background: #e53e3e; color: white; }
        .tab-btn { background: #edf2f7; color: #4a5568; }
        .tab-btn:hover { background: #e2e8f0; }
        .tab-btn.active { background: #e53e3e; color: white; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* СТАТИСТИКА */
        .stats { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
        .stat-item { background: white; padding: 20px; border-radius: 12px; min-width: 150px; border: 1px solid #edf2f7; }
        .stat-label { color: #718096; font-size: 14px; margin-bottom: 5px; }
        .stat-number { font-size: 28px; font-weight: bold; color: #1a1a1a; }
        
        /* ТАБЛИЦА */
        .table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 16px 12px; background: #f8f9fa; color: #4a5568; font-size: 14px; border-bottom: 2px solid #e2e8f0; }
        td { padding: 16px 12px; border-bottom: 1px solid #edf2f7; }
        tr:hover td { background: #f8fafc; }
        
        .status { display: inline-block; padding: 6px 16px; border-radius: 50px; font-size: 13px; font-weight: 600; }
        .status-new { background: #cce5ff; color: #004085; }
        .status-processing { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .photo-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; background: #e53e3e; color: white; border-radius: 50px; font-size: 13px; border: none; cursor: pointer; }
        .photo-badge:hover { background: #c53030; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #4299e1; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn:hover { opacity: 0.9; }
        
        /* SEO */
        .seo-layout { display: grid; grid-template-columns: 350px 1fr; gap: 30px; }
        .seo-sidebar { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-height: 80vh; overflow-y: auto; }
        .seo-sidebar h3 { margin-bottom: 15px; color: #2d3748; }
        .seo-list-item { padding: 12px 15px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #edf2f7; transition: all 0.3s ease; }
        .seo-list-item:hover { background: #f8fafc; }
        .seo-list-item.active { background: #e53e3e; color: white; }
        .seo-list-item .delete-seo-btn { background: none; border: none; color: #999; cursor: pointer; padding: 5px; }
        .seo-list-item .delete-seo-btn:hover { color: #e53e3e; }
        .seo-list-item.active .delete-seo-btn { color: rgba(255,255,255,0.6); }
        .seo-list-item.active .delete-seo-btn:hover { color: white; }
        
        .seo-editor { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .seo-editor .form-group { margin-bottom: 20px; }
        .seo-editor label { display: block; font-weight: 600; margin-bottom: 5px; color: #2d3748; font-size: 14px; }
        .seo-editor label .required { color: #e53e3e; }
        .seo-editor input[type="text"], .seo-editor textarea { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; transition: border-color 0.3s ease; }
        .seo-editor input[type="text"]:focus, .seo-editor textarea:focus { border-color: #e53e3e; outline: none; box-shadow: 0 0 0 3px rgba(229,62,62,0.1); }
        .seo-editor textarea { resize: vertical; min-height: 150px; }
        .seo-editor .slug-preview { display: inline-block; background: #edf2f7; padding: 4px 12px; border-radius: 4px; font-size: 13px; color: #4a5568; margin-top: 5px; }
        .seo-editor .slug-preview span { color: #e53e3e; font-weight: 600; }
        .seo-editor .char-counter { text-align: right; font-size: 12px; color: #a0aec0; margin-top: 5px; }
        .seo-editor .char-counter.warning { color: #ecc94b; }
        .seo-editor .char-counter.danger { color: #e53e3e; }
        
        .seo-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
        .seo-empty { text-align: center; padding: 40px 20px; color: #a0aec0; }
        .seo-empty i { font-size: 48px; margin-bottom: 15px; }
        
        .seo-add-new { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .seo-add-new input { flex: 1; padding: 10px 15px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; min-width: 150px; }
        .seo-add-new button { padding: 10px 20px; background: #e53e3e; color: white; border: none; border-radius: 8px; cursor: pointer; white-space: nowrap; }
        .seo-add-new button:hover { background: #c53030; }
        
        /* МОДАЛКИ */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); align-items: center; justify-content: center; z-index: 3000; padding: 20px; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 16px; max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative; }
        .modal-close { position: absolute; top: 20px; right: 25px; font-size: 28px; cursor: pointer; color: #999; }
        .modal-close:hover { color: #e53e3e; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
        .info-item { background: #f8fafc; padding: 15px; border-radius: 8px; }
        .info-label { color: #718096; font-size: 12px; margin-bottom: 5px; }
        .info-value { font-weight: 600; font-size: 16px; }
        .photo-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; margin: 20px 0; }
        .photo-item { aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 2px solid #edf2f7; cursor: pointer; }
        .photo-item img { width: 100%; height: 100%; object-fit: cover; }
        .photo-item:hover { border-color: #e53e3e; }
        
        /* АДАПТИВ */
        @media (max-width: 992px) { .seo-layout { grid-template-columns: 1fr; } .seo-sidebar { max-height: 300px; } }
        @media (max-width: 768px) { body { padding: 10px; } .stats { flex-direction: column; } .stat-item { width: 100%; } .info-grid { grid-template-columns: 1fr; } .header { flex-direction: column; align-items: flex-start; } .header-actions { width: 100%; flex-direction: column; } .header-actions .tab-btn { width: 100%; justify-content: center; } .seo-add-new { flex-direction: column; } .seo-actions { flex-direction: column; } .seo-actions .btn { justify-content: center; } }
    </style>
</head>
<body>

<!-- ЭКРАН ВХОДА -->
<div id="loginScreen" class="login-screen">
    <div class="login-box">
        <h1>АВТОКАЙ</h1>
        <div id="loginError" style="background: #fed7d7; color: #c53030; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: none;"></div>
        <form id="loginForm">
            <input type="hidden" name="action" value="login">
            <input type="text" name="username" placeholder="Логин" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
    </div>
</div>

<!-- АДМИН ПАНЕЛЬ -->
<div id="adminScreen" class="admin-screen">
    <div class="header">
        <h1><i class="fas fa-file-alt" style="color: #e53e3e;"></i> Админ-панель</h1>
        <div class="header-actions">
            <button class="tab-btn active" onclick="switchTab('orders')"><i class="fas fa-list"></i> Заявки</button>
            <button class="tab-btn" onclick="switchTab('seo')"><i class="fas fa-robot"></i> SEO тексты</button>
            <button class="logout-btn" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Выйти</button>
        </div>
    </div>
    
    <!-- ВКЛАДКА ЗАЯВКИ -->
    <div id="tabOrders" class="tab-content active">
        <div class="stats" id="stats"></div>
        <div class="table-container">
            <table>
                <thead><tr><th>ID</th><th>Дата</th><th>Имя</th><th>Телефон</th><th>Авто</th><th>Фото</th><th>Статус</th><th>Действия</th></tr></thead>
                <tbody id="ordersTableBody"><tr><td colspan="8" style="text-align: center; padding: 50px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px; color: #e53e3e;"></i><p style="margin-top: 15px; color: #718096;">Загрузка заявок...</p></td></tr></tbody>
            </table>
        </div>
    </div>
    
    <!-- ВКЛАДКА SEO -->
    <div id="tabSeo" class="tab-content">
        <div class="seo-layout">
            <!-- Сайдбар -->
            <div class="seo-sidebar">
                <h3><i class="fas fa-list-ul"></i> Страницы</h3>
                <div class="seo-add-new">
                    <input type="text" id="newStationName" placeholder="Название станции" onkeyup="generateSlug(this.value)">
                    <button onclick="createNewSeo()"><i class="fas fa-plus"></i> Добавить</button>
                </div>
                <div style="font-size: 12px; color: #a0aec0; margin-bottom: 15px;">
                    Транскрипция: <span id="newSlugPreview">автоматически</span>
                </div>
                <div id="seoListContainer"><div class="seo-empty"><i class="fas fa-robot"></i><p>Нет SEO текстов</p></div></div>
            </div>
            
            <!-- Редактор -->
            <div class="seo-editor">
                <h3 id="seoEditorTitle"><i class="fas fa-edit"></i> Редактор SEO</h3>
                <form id="seoForm">
                    <input type="hidden" id="seoSlug" name="slug">
                    <div class="form-group">
                        <label>Название страницы <span class="required">*</span></label>
                        <input type="text" id="seoStationName" name="station_name" placeholder="Выкуп авто в Купчино" required>
                        <div style="font-size: 12px; color: #a0aec0; margin-top: 5px;">URL: /?spb=<span id="seoSlugPreview">выберите страницу</span></div>
                    </div>
                    <div class="form-group">
                        <label>Title <span class="required">*</span></label>
                        <input type="text" id="seoTitle" name="title" placeholder="Выкуп авто в Купчино | Санкт-Петербург" required>
                        <div class="char-counter" id="seoTitleCounter">0 / 70</div>
                    </div>
                    <div class="form-group">
                        <label>Description <span class="required">*</span></label>
                        <textarea id="seoDescription" name="description" rows="2" placeholder="Срочный выкуп автомобилей в Купчино. Деньги за 30 минут." required></textarea>
                        <div class="char-counter" id="seoDescCounter">0 / 160</div>
                    </div>
                    <div class="form-group">
                        <label>H1 <span class="required">*</span></label>
                        <input type="text" id="seoH1" name="h1" placeholder="Выкуп авто в Купчино" required>
                    </div>
                    <div class="form-group">
                        <label>Текст <span class="required">*</span></label>
                        <textarea id="seoText" name="text" rows="10" placeholder="Полный текст страницы..."></textarea>
                    </div>
                    <div class="seo-actions">
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Сохранить</button>
                        <button type="button" class="btn btn-danger" onclick="deleteCurrentSeo()"><i class="fas fa-trash"></i> Удалить</button>
                    </div>
                    <div id="seoMessage" style="margin-top: 15px; padding: 10px; border-radius: 8px; display: none;"></div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- МОДАЛКА ЗАЯВКИ -->
<div class="modal" id="orderModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <h2 style="margin-bottom: 20px;"><i class="fas fa-file-alt" style="color: #e53e3e;"></i> Заявка #<span id="modalOrderId"></span></h2>
        <div id="modalOrderDetails"></div>
        <div style="display: flex; gap: 10px; margin-top: 25px; flex-wrap: wrap;">
            <select id="orderStatusSelect" style="flex: 1; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; min-width: 150px;">
                <option value="new">🟡 Новая</option>
                <option value="processing">🟠 В работе</option>
                <option value="completed">🟢 Завершена</option>
                <option value="cancelled">🔴 Отменена</option>
            </select>
            <button class="btn btn-primary" onclick="updateStatus()"><i class="fas fa-save"></i> Сохранить</button>
            <button class="btn btn-danger" onclick="deleteCurrentOrder()"><i class="fas fa-trash"></i> Удалить</button>
        </div>
    </div>
</div>

<!-- МОДАЛКА ФОТО -->
<div class="modal" id="photoModal" onclick="this.classList.remove('active')">
    <div class="modal-content" style="max-width: 90%; text-align: center;" onclick="event.stopPropagation()">
        <span class="modal-close" onclick="document.getElementById('photoModal').classList.remove('active')">&times;</span>
        <img id="previewImage" src="" style="max-width: 100%; max-height: 80vh; border-radius: 8px;">
    </div>
</div>

<script>
// ============================================
// ПЕРЕМЕННЫЕ
// ============================================
let currentOrderId = null;
let orderRequestId = 0;
let ordersListRequestId = 0;
let statsRequestId = 0;
let adminSessionRequestId = 0;
let currentSeoSlug = null;
let seoDataList = [];
let seoListRequestId = 0;
let seoItemRequestId = 0;

// ============================================
// АВТОРИЗАЦИЯ
// ============================================
document.addEventListener('DOMContentLoaded', async function() {
    try {
        const formData = new FormData();
        formData.append('action', 'check_auth');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (result.logged_in) {
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('adminScreen').style.display = 'block';
            loadStats();
            loadOrders();
            loadSeoList();
        } else {
            document.getElementById('loginScreen').style.display = 'flex';
            document.getElementById('adminScreen').style.display = 'none';
        }
    } catch (error) { alert('Ошибка соединения'); }
});

// ============================================
// ПЕРЕКЛЮЧЕНИЕ ВКЛАДОК
// ============================================
function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(el => {
        if (el.textContent.toLowerCase().includes(tab === 'orders' ? 'заявки' : 'seo')) el.classList.add('active');
    });
    if (tab === 'seo') loadSeoList();
}

// ============================================
// ЛОГИН
// ============================================
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const errorEl = document.getElementById('loginError');
    const btn = this.querySelector('button');
    errorEl.style.display = 'none';
    btn.innerHTML = 'Вход...';
    btn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (result.success) location.reload();
        else { errorEl.textContent = result.message || 'Неверный логин или пароль'; errorEl.style.display = 'block'; }
    } catch (error) { errorEl.textContent = 'Ошибка соединения'; errorEl.style.display = 'block'; }
    finally { btn.innerHTML = 'Войти'; btn.disabled = false; }
});

// ============================================
// ВЫХОД
// ============================================
document.getElementById('logoutBtn').addEventListener('click', async function() {
    ++adminSessionRequestId;
    ++ordersListRequestId;
    ++statsRequestId;
    ++seoListRequestId;
    clearSeoForm();
    closeModal();
    const formData = new FormData();
    formData.append('action', 'logout');
    await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
    location.reload();
});

// ============================================
// СТАТИСТИКА
// ============================================
async function loadStats() {
    const requestId = ++statsRequestId;
    try {
        const formData = new FormData();
        formData.append('action', 'get_orders');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const orders = await response.json();
        if (requestId !== statsRequestId) return;
        if (!response.ok || !Array.isArray(orders)) throw new Error('Invalid orders response');
        if (Array.isArray(orders)) {
            const total = orders.length;
            const newOrders = orders.filter(o => o.status === 'new' || !o.status).length;
            const withPhoto = orders.filter(o => o.photos_count > 0).length;
            document.getElementById('stats').innerHTML = `
                <div class="stat-item"><div class="stat-label">Всего заявок</div><div class="stat-number">${total}</div></div>
                <div class="stat-item"><div class="stat-label">🟡 Новые</div><div class="stat-number">${newOrders}</div></div>
                <div class="stat-item"><div class="stat-label">📸 С фото</div><div class="stat-number">${withPhoto}</div></div>
            `;
        }
    } catch (error) {
        if (requestId !== statsRequestId) return;
        document.getElementById('stats').textContent = 'Ошибка загрузки статистики';
        console.error('Ошибка статистики:', error);
    }
}

// ============================================
// ЗАГРУЗКА ЗАЯВОК
// ============================================
async function loadOrders() {
    const requestId = ++ordersListRequestId;
    try {
        const formData = new FormData();
        formData.append('action', 'get_orders');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const orders = await response.json();
        if (requestId !== ordersListRequestId) return;
        if (!response.ok || !Array.isArray(orders)) throw new Error('Invalid orders response');
        let html = '';
        if (orders && orders.length > 0) {
            orders.forEach(order => {
                const status = order.status || 'new';
                const statusText = { 'new': '🟡 Новая', 'processing': '🟠 В работе', 'completed': '🟢 Завершена', 'cancelled': '🔴 Отменена' }[status] || '🟡 Новая';
                const photoBadge = order.photos_count > 0 ? `<button class="photo-badge" onclick="viewOrder(${order.id})"><i class="fas fa-camera"></i> ${order.photos_count}</button>` : '<span style="color: #a0aec0;">Нет</span>';
                html += `<tr>
                    <td><strong>#${order.id}</strong></td>
                    <td>${formatDate(order.created_at)}</td>
                    <td>${escapeHtml(order.name || '')}</td>
                    <td>${escapeHtml(order.phone || '')}</td>
                    <td>${escapeHtml(order.car_brand || '-')}</td>
                    <td>${photoBadge}</td>
                    <td><span class="status status-${status}">${statusText}</span></td>
                    <td>
                        <button class="btn btn-primary" onclick="viewOrder(${order.id})"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-danger" onclick="deleteOrder(${order.id})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            });
        } else {
            html = '<tr><td colspan="8" style="text-align: center; padding: 50px;"><i class="fas fa-inbox" style="font-size: 40px; color: #cbd5e0;"></i><p style="margin-top: 15px; color: #718096;">Нет заявок</p></td></tr>';
        }
        document.getElementById('ordersTableBody').innerHTML = html;
    } catch (error) {
        if (requestId !== ordersListRequestId) return;
        document.getElementById('ordersTableBody').innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 50px; color: #f56565;">Ошибка загрузки</td></tr>`;
    }
}

// ============================================
// ПРОСМОТР ЗАЯВКИ
// ============================================
async function viewOrder(id) {
    closeModal();
    const requestId = orderRequestId;
    try {
        const formData = new FormData();
        formData.append('action', 'get_order');
        formData.append('id', id);
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const order = await response.json();
        if (requestId !== orderRequestId) return;
        if (!response.ok) throw new Error('Order request failed');
        if (order === null) { alert('Заявка не найдена'); return; }
        if (!order || typeof order !== 'object' || Array.isArray(order) || order.error || !order.id) throw new Error('Invalid order response');
        currentOrderId = id;
        document.getElementById('modalOrderId').textContent = order.id;
        document.getElementById('orderStatusSelect').value = order.status || 'new';
        let photoHtml = '';
        if (order.photos && order.photos.length > 0) {
            photoHtml = '<h4 style="margin: 20px 0 10px;">Фото:</h4><div class="photo-gallery">';
            order.photos.forEach((photo, index) => {
                photoHtml += `<div class="photo-item" onclick="viewPhoto('${photo.photo_path}')"><img src="${photo.photo_path}" alt="Фото ${index + 1}"></div>`;
            });
            photoHtml += '</div>';
        }
        let html = `
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Имя</div><div class="info-value">${escapeHtml(order.name || 'Не указано')}</div></div>
                <div class="info-item"><div class="info-label">Телефон</div><div class="info-value">${escapeHtml(order.phone || 'Не указано')}</div></div>
                <div class="info-item"><div class="info-label">Автомобиль</div><div class="info-value">${escapeHtml(order.car_brand || 'Не указано')}</div></div>
                <div class="info-item"><div class="info-label">Год</div><div class="info-value">${escapeHtml(order.car_year || 'Не указан')}</div></div>
                <div class="info-item"><div class="info-label">Пробег</div><div class="info-value">${escapeHtml(order.car_mileage || 'Не указан')}</div></div>
                <div class="info-item"><div class="info-label">Состояние</div><div class="info-value">${escapeHtml(order.condition_text || 'Не указано')}</div></div>
            </div>
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 10px;"><div style="color: #4a5568;">Создано: ${formatDateFull(order.created_at)}</div></div>
            ${photoHtml}
        `;
        document.getElementById('modalOrderDetails').innerHTML = html;
        document.getElementById('orderModal').classList.add('active');
    } catch (error) { if (requestId === orderRequestId) alert('Ошибка загрузки заявки'); }
}

function viewPhoto(path) {
    document.getElementById('previewImage').src = path;
    document.getElementById('photoModal').classList.add('active');
}

// ============================================
// ОБНОВЛЕНИЕ СТАТУСА
// ============================================
async function updateStatus() {
    if (!currentOrderId) return;
    const orderId = currentOrderId;
    const requestId = orderRequestId;
    const sessionRequestId = adminSessionRequestId;
    const formData = new FormData();
    formData.append('action', 'update_order');
    formData.append('id', orderId);
    formData.append('status', document.getElementById('orderStatusSelect').value);
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (sessionRequestId !== adminSessionRequestId) return;
        if (!response.ok || result?.success !== true) throw new Error('Order update failed');
        if (requestId === orderRequestId && currentOrderId === orderId) { alert('Статус обновлен'); closeModal(); }
        loadStats();
        loadOrders();
    } catch (error) { if (sessionRequestId !== adminSessionRequestId) return; alert('Ошибка'); }
}

// ============================================
// УДАЛЕНИЕ ЗАЯВКИ
// ============================================
async function deleteOrder(id) {
    if (!confirm('Удалить заявку #' + id + '?')) return;
    const orderId = id;
    const requestId = orderRequestId;
    const sessionRequestId = adminSessionRequestId;
    const formData = new FormData();
    formData.append('action', 'delete_order');
    formData.append('id', orderId);
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (sessionRequestId !== adminSessionRequestId) return;
        if (!response.ok || result?.success !== true) throw new Error('Order deletion failed');
        if (requestId === orderRequestId && currentOrderId === orderId) { alert('Заявка удалена'); closeModal(); }
        loadStats();
        loadOrders();
    } catch (error) { if (sessionRequestId !== adminSessionRequestId) return; alert('Ошибка удаления'); }
}
async function deleteCurrentOrder() { if (currentOrderId) await deleteOrder(currentOrderId); }

function closeModal() {
    ++orderRequestId;
    document.getElementById('orderModal').classList.remove('active');
    currentOrderId = null;
}

// ============================================
// ============================================
// SEO ФУНКЦИИ
// ============================================
// ============================================

function generateSlug(name) {
    const preview = document.getElementById('newSlugPreview');
    if (!name.trim()) { preview.textContent = 'автоматически'; return; }
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=generate_slug&name=' + encodeURIComponent(name),
        credentials: 'same-origin'
    })
    .then(res => res.json())
    .then(result => { if (result.success) preview.textContent = result.slug; })
    .catch(() => {});
}

async function generateSlugFromName(name) {
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=generate_slug&name=' + encodeURIComponent(name),
            credentials: 'same-origin'
        });
        const result = await response.json();
        return result.success ? result.slug : null;
    } catch { return null; }
}

// ============================================
// ЗАГРУЗКА СПИСКА SEO
// ============================================
async function loadSeoList() {
    const requestId = ++seoListRequestId;
    try {
        const formData = new FormData();
        formData.append('action', 'get_seo_list');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (requestId !== seoListRequestId) return;
        if (!response.ok || result?.success !== true || !result.data || typeof result.data !== 'object') throw new Error('Invalid SEO list response');
        if (Object.values(result.data).some(item => !item || typeof item !== 'object' || Array.isArray(item))) throw new Error('Invalid SEO entry');
        if (result.success) {
            seoDataList = result.data || {};
            renderSeoList();
        }
    } catch (error) {
        if (requestId !== seoListRequestId) return;
        showSeoMessage('Не удалось загрузить список SEO. Повторите попытку позже.', 'error');
        console.error('Ошибка загрузки SEO:', error);
    }
}

function renderSeoList() {
    const container = document.getElementById('seoListContainer');
    const keys = Object.keys(seoDataList);
    if (keys.length === 0) {
        clearSeoForm();
        container.innerHTML = `<div class="seo-empty"><i class="fas fa-robot"></i><p>Нет SEO текстов</p></div>`;
        return;
    }
    let html = '';
    keys.forEach(slug => {
        const item = seoDataList[slug];
        const isActive = currentSeoSlug === slug;
        html += `<div class="seo-list-item ${isActive ? 'active' : ''}" data-seo-slug="${escapeHtml(slug)}">
            <span>${escapeHtml(item.station_name || slug)}</span>
            <button type="button" class="delete-seo-btn"><i class="fas fa-times"></i></button>
        </div>`;
    });
    container.innerHTML = html;
    container.querySelectorAll('.seo-list-item').forEach(item => {
        item.addEventListener('click', () => loadSeoItem(item.dataset.seoSlug));
        item.querySelector('.delete-seo-btn').addEventListener('click', event => {
            event.stopPropagation();
            deleteSeoItem(item.dataset.seoSlug);
        });
    });
    if (!currentSeoSlug || !seoDataList[currentSeoSlug]) {
        const firstSlug = keys[0];
        if (firstSlug) loadSeoItem(firstSlug);
        else clearSeoForm();
    }
}

// ============================================
// ЗАГРУЗКА ОДНОГО SEO
// ============================================
async function loadSeoItem(slug) {
    clearSeoForm();
    const requestId = seoItemRequestId;
    currentSeoSlug = slug;
    try {
        const formData = new FormData();
        formData.append('action', 'get_seo_item');
        formData.append('slug', slug);
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (requestId !== seoItemRequestId) return;
        if (!response.ok || result?.success !== true || !result.data || typeof result.data !== 'object' || Array.isArray(result.data)) throw new Error('Invalid SEO item response');
        if (result.success && result.data) {
            const data = result.data;
            document.getElementById('seoSlug').value = slug;
            document.getElementById('seoStationName').value = data.station_name || '';
            document.getElementById('seoTitle').value = data.title || '';
            document.getElementById('seoDescription').value = data.description || '';
            document.getElementById('seoH1').value = data.h1 || '';
            document.getElementById('seoText').value = data.text || '';
            document.getElementById('seoSlugPreview').textContent = encodeURIComponent(slug);
            document.getElementById('seoEditorTitle').innerHTML = `<i class="fas fa-edit"></i> ${escapeHtml(data.station_name || slug)}`;
            updateCharCounters();
            renderSeoList();
            document.getElementById('seoMessage').style.display = 'none';
        } else {
            clearSeoForm();
        }
    } catch (error) {
        if (requestId !== seoItemRequestId) return;
        clearSeoForm();
        showSeoMessage('Не удалось загрузить SEO текст. Повторите попытку позже.', 'error');
    }
}

function clearSeoForm() {
    ++seoItemRequestId;
    currentSeoSlug = null;
    document.querySelectorAll('.seo-list-item.active').forEach(item => item.classList.remove('active'));
    document.getElementById('seoSlug').value = '';
    document.getElementById('seoStationName').value = '';
    document.getElementById('seoTitle').value = '';
    document.getElementById('seoDescription').value = '';
    document.getElementById('seoH1').value = '';
    document.getElementById('seoText').value = '';
    document.getElementById('seoSlugPreview').textContent = 'выберите страницу';
    document.getElementById('seoEditorTitle').innerHTML = '<i class="fas fa-edit"></i> Выберите страницу';
    document.getElementById('seoMessage').style.display = 'none';
    updateCharCounters();
}

// ============================================
// СОЗДАНИЕ НОВОЙ СТРАНИЦЫ С ПОЛНЫМ ТЕКСТОМ
// ============================================
async function createNewSeo() {
    const nameInput = document.getElementById('newStationName');
    const name = nameInput.value.trim();
    if (!name) { alert('Введите название станции'); return; }
    
    const slug = await generateSlugFromName(name);
    if (!slug) { alert('Ошибка генерации транскрипции'); return; }
    if (seoDataList[slug]) { alert('Страница с такой транскрипцией уже существует'); return; }
    
    // ✅ ПОЛНЫЙ ТЕКСТ ДЛЯ НОВОЙ СТРАНИЦЫ
    const fullText = `<h2>Срочный выкуп автомобилей в ${name}</h2>

<p>Компания «Автокай» предлагает <strong>срочный выкуп автомобилей в ${name}</strong> по максимально выгодной цене. Мы готовы приехать к вам в любое время, провести бесплатную оценку и выплатить деньги в день обращения. Нам неважно состояние машины — мы выкупаем битые, кредитные, не на ходу, после ДТП и с пробегом.</p>

<p>Наши эксперты выезжают в любой район ${name}. Работаем 24/7 без выходных.</p>

<h3>Почему выгодно продать авто с нами?</h3>

<ul>
    <li><strong>Деньги сразу</strong> — наличными или переводом на карту в день сделки;</li>
    <li><strong>Бесплатный эвакуатор</strong> — приедем и заберём авто за наш счёт;</li>
    <li><strong>Оценка онлайн</strong> — пришлите фото в WhatsApp, узнайте цену за 5 минут;</li>
    <li><strong>Любое состояние</strong> — битые, кредитные, не на ходу, с пробегом;</li>
    <li><strong>Юридическая чистота</strong> — проверим авто, снимем с учёта сами;</li>
    <li><strong>Работаем 24/7</strong> — приедем в удобное время, даже ночью.</li>
</ul>

<h3>Какие автомобили мы выкупаем?</h3>

<ul>
    <li><strong>Битые авто после ДТП</strong> — даже если машина восстановлению не подлежит;</li>
    <li><strong>Кредитные автомобили</strong> — сами погасим остаток кредита, остаток отдадим вам;</li>
    <li><strong>Авто не на ходу</strong> — сломанные, после пожара, затопления;</li>
    <li><strong>Автомобили с пробегом</strong> — любые марки: Hyundai, Kia, Toyota, Nissan, Volkswagen, BMW, Mercedes, Audi, Ford, Chevrolet, Lada и другие;</li>
    <li><strong>Китайские авто</strong> — Haval, Geely, Chery, Exeed, Omoda и другие;</li>
    <li><strong>Авто без документов</strong> — поможем восстановить ПТС или СТС.</li>
</ul>

<h3>Как проходит сделка?</h3>

<ol>
    <li><strong>Вы оставляете заявку</strong> — звоните по телефону <a href="tel:+79320140348">8 (932) 014-03-48</a> или заполняете форму на сайте;</li>
    <li><strong>Оценка автомобиля</strong> — по фото онлайн или с выездом эксперта в любой район;</li>
    <li><strong>Осмотр и согласование цены</strong> — эксперт приезжает, осматривает авто за 15-20 минут и называет финальную цену;</li>
    <li><strong>Подписание договора и оплата</strong> — деньги сразу, наличными или на карту.</li>
</ol>

<h3>Цены на выкуп авто</h3>

<p>Мы даём до 95% рыночной стоимости автомобиля. Финальная цена зависит от состояния, пробега, комплектации и спроса на модель. Мы называем честную цену без скрытых комиссий.</p>

<h3>Часто задаваемые вопросы</h3>

<h4>Можно ли продать авто, если оно в кредите?</h4>
<p>Да, мы специализируемся на выкупе кредитных автомобилей. Мы сами связываемся с банком, выплачиваем остаток кредита, остаток суммы отдаём вам.</p>

<h4>Что делать, если авто не на ходу?</h4>
<p>Мы пригоним бесплатный эвакуатор в любой район. Выезд эксперта тоже бесплатный.</p>

<h4>Нужна ли диагностика перед продажей?</h4>
<p>Нет. Мы оцениваем автомобиль по внешнему состоянию и предоставленной информации.</p>

<h3>Свяжитесь с нами прямо сейчас</h3>

<p>Не откладывайте продажу авто на завтра. Звоните по телефону <strong><a href="tel:+79320140348">8 (932) 014-03-48</a></strong> или напишите в Telegram/WhatsApp.</p>

<p>«Автокай» — ваш надёжный партнёр по выкупу авто в ${name} и по всему Санкт-Петербургу.</p>`;

    const formData = new FormData();
    formData.append('action', 'save_seo');
    formData.append('slug', slug);
    formData.append('station_name', name);
    formData.append('title', `Выкуп авто в ${name} | Срочный выкуп автомобилей | Автокай СПб`);
    formData.append('description', `Срочный выкуп автомобилей в ${name}. Деньги сразу за 30 минут. Бесплатный эвакуатор. Любое состояние — битые, кредитные, не на ходу. Оценка онлайн. Звоните!`);
    formData.append('h1', `Выкуп авто в ${name} — деньги за 30 минут`);
    formData.append('text', fullText);
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (result.success) {
            nameInput.value = '';
            document.getElementById('newSlugPreview').textContent = 'автоматически';
            await loadSeoList();
            loadSeoItem(slug);
            showSeoMessage('✅ Страница создана с полным текстом!', 'success');
        } else {
            showSeoMessage('❌ Ошибка: ' + result.message, 'error');
        }
    } catch (error) {
        showSeoMessage('❌ Ошибка соединения', 'error');
    }
}

// ============================================
// СОХРАНЕНИЕ SEO (ОБНОВЛЕНИЕ)
// ============================================
document.getElementById('seoForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const slug = document.getElementById('seoSlug').value;
    if (!slug) {
        showSeoMessage('❌ Ошибка: не выбрана страница', 'error');
        return;
    }
    
    const formData = new FormData(this);
    formData.append('action', 'save_seo');
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (result.success) {
            showSeoMessage('✅ SEO текст сохранён!', 'success');
            loadSeoList(); // Обновляем список
        } else {
            showSeoMessage('❌ Ошибка: ' + result.message, 'error');
        }
    } catch (error) {
        showSeoMessage('❌ Ошибка соединения', 'error');
    }
});

// ============================================
// УДАЛЕНИЕ SEO
// ============================================
async function deleteSeoItem(slug) {
    if (!confirm(`Удалить страницу "${slug}"?`)) return;
    try {
        const formData = new FormData();
        formData.append('action', 'delete_seo');
        formData.append('slug', slug);
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (result.success) {
            if (currentSeoSlug === slug) { currentSeoSlug = null; clearSeoForm(); }
            loadSeoList();
            showSeoMessage('Страница удалена', 'success');
        } else { alert('Ошибка удаления'); }
    } catch (error) { alert('Ошибка удаления'); }
}
async function deleteCurrentSeo() {
    const slug = document.getElementById('seoSlug').value;
    if (slug) await deleteSeoItem(slug);
}

// ============================================
// СООБЩЕНИЯ
// ============================================
function showSeoMessage(text, type) {
    const el = document.getElementById('seoMessage');
    el.textContent = text;
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#d4edda' : '#f8d7da';
    el.style.color = type === 'success' ? '#155724' : '#721c24';
    el.style.border = '1px solid ' + (type === 'success' ? '#c3e6cb' : '#f5c6cb');
    setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// ============================================
// СЧЁТЧИКИ СИМВОЛОВ
// ============================================
document.getElementById('seoTitle').addEventListener('input', updateCharCounters);
document.getElementById('seoDescription').addEventListener('input', updateCharCounters);

function updateCharCounters() {
    updateCounter('seoTitleCounter', document.getElementById('seoTitle').value.length, 70);
    updateCounter('seoDescCounter', document.getElementById('seoDescription').value.length, 160);
}

function updateCounter(id, current, max) {
    const el = document.getElementById(id);
    el.textContent = current + ' / ' + max;
    el.className = 'char-counter' + (current > max ? ' danger' : current > max * 0.9 ? ' warning' : '');
}

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function formatDateFull(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
</script>

</body>
</html>