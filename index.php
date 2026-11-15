<?php
/* CODEX_FILENAME_GUARD_V1 */
if (\basename(__FILE__) !== 'index.php') { \http_response_code(404); exit; }
/* CODEX_FILENAME_GUARD_END_V1 */
// ============================================
// КОНФИГУРАЦИЯ
// ============================================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
ini_set('max_file_uploads', '20');

// Данные БД
require_once __DIR__ . '/config.php';



// Telegram - ВАШ ЛИЧНЫЙ CHAT_ID


// Контакты
define('SITE_PHONE', '89320140348');
define('SITE_PHONE_FORMATTED', '8 (932) 014-03-48');
define('SITE_WHATSAPP', 'https://wa.me/89320140348');
define('SITE_TELEGRAM', 'https://t.me/kailevZ');
define('SITE_EMAIL', 'info@avtokai.ru');
define('SITE_MAXIM', 'https://max.ru/u/f9LHodD0cOLDI3-n5e44BRKFa3eDm405koc5Wq5wysOI6zzTcEuGQwd5HWw');

// Папка для фото
define('UPLOAD_DIR', __DIR__ . '/uploads/');


// ============================================
// ПОДГРУЖАЕМ SEO ДАННЫЕ ИЗ JSON
// ============================================
$seoDataFile = __DIR__ . '/seo_data.json';
$seoData = [];

if (file_exists($seoDataFile)) {
    $content = file_get_contents($seoDataFile);
    $seoData = json_decode($content, true);
    if (!is_array($seoData)) $seoData = [];
}

// ============================================
// ОПРЕДЕЛЯЕМ СТАНЦИЮ ИЗ GET-ПАРАМЕТРА
// ============================================
if (isset($_GET['spb']) && !is_string($_GET['spb'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Некорректный параметр района.');
}
$currentStation = isset($_GET['spb']) ? trim($_GET['spb']) : '';
$currentSlug = $currentStation;

// Если есть параметр, ищем в данных
if (!empty($currentSlug) && isset($seoData[$currentSlug])) {
    $seo = $seoData[$currentSlug];
    $pageTitle = $seo['title'] ?? 'Автокай — Срочный выкуп авто в Санкт-Петербурге, деньги сразу за 30 минут';
    $pageDescription = $seo['description'] ?? 'Срочный выкуп автомобилей в СПб. Деньги за 30 минут. Любое состояние. Бесплатный эвакуатор. Оценка онлайн. Звоните 8 (932) 014-03-48!';
    $pageH1 = $seo['h1'] ?? 'ВЫКУПИМ АВТО ДО 95% РЫНОЧНОЙ ЦЕНЫ';
    $pageText = $seo['text'] ?? '';
    $stationName = $seo['station_name'] ?? $currentSlug;
    $isStationPage = true;
} else {
    // Страница по умолчанию (главная)
    $pageTitle = 'Автокай — Срочный выкуп авто в Санкт-Петербурге, деньги сразу за 30 минут';
    $pageDescription = 'Срочный выкуп автомобилей в СПб. Деньги за 30 минут. Любое состояние. Бесплатный эвакуатор. Оценка онлайн. Звоните 8 (932) 014-03-48!';
    $pageH1 = 'ВЫКУПИМ АВТО ДО 95% РЫНОЧНОЙ ЦЕНЫ';
    $pageText = '';
    $stationName = 'Санкт-Петербург';
    $isStationPage = false;
}

// Если станция не найдена в JSON, показываем главную
if (!empty($currentSlug) && !isset($seoData[$currentSlug])) {
    $pageTitle = 'Автокай — Срочный выкуп авто в Санкт-Петербурге, деньги сразу за 30 минут';
    $pageDescription = 'Срочный выкуп автомобилей в СПб. Деньги за 30 минут. Любое состояние. Бесплатный эвакуатор. Оценка онлайн. Звоните 8 (932) 014-03-48!';
    $pageH1 = 'ВЫКУПИМ АВТО ДО 95% РЫНОЧНОЙ ЦЕНЫ';
    $pageText = '';
    $stationName = 'Санкт-Петербург';
    $isStationPage = false;
}

// ============================================
// ПОДКЛЮЧЕНИЕ К БД
// ============================================
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ТАБЛИЦА ЗАЯВОК
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        car_brand VARCHAR(255),
        car_year VARCHAR(50),
        car_mileage VARCHAR(50),
        car_condition VARCHAR(50),
        status ENUM('new','processing','completed','cancelled') DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ТАБЛИЦА ДЛЯ ФОТО
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        photo_path VARCHAR(500) NOT NULL,
        photo_original_name VARCHAR(500),
        photo_size INT,
        telegram_photo_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ТАБЛИЦА АДМИНОВ
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    

} catch(PDOException $e) {
    $sqlState = strtoupper((string)$e->getCode());
    if (!preg_match('/^[0-9A-Z]{5}$/D', $sqlState)) $sqlState = 'unknown';
    error_log('database_initialization_failed sqlstate=' . $sqlState);
    http_response_code(503);
    $unavailableMessage = 'Сервис временно недоступен. Позвоните нам или попробуйте позже.';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'accepted' => false, 'message' => $unavailableMessage], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $unavailableMessage;
    }
    exit;
}

// ============================================
// ФУНКЦИИ ДЛЯ ФОТО
// ============================================
function compressImage($source, $destination, $maxWidth = 1600, $quality = 85) {
    $image = null;
    $newImage = null;
    try {
        $info = @getimagesize($source);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])
            || !is_int($info[0]) || !is_int($info[1])
            || $info[0] < 1 || $info[1] < 1
            || $info[0] > 30000 || $info[1] > 30000
            || !in_array($info['mime'], $allowedMimes, true)) return false;
        $width = $info[0];
        $height = $info[1];
        // Compare before multiplying, including on a 32-bit PHP build.
        if ($height > intdiv(80000000, $width)) return false;
        $maxWidth = (int)$maxWidth;
        if ($maxWidth < 1 || $maxWidth > 30000) return false;
        $targetWidth = min($width, $maxWidth);
        $targetHeight = $width > $maxWidth ? max(1, (int)floor($height * ($maxWidth / $width))) : $height;
        $sourcePixels = $width * $height;
        $targetPixels = $width > $maxWidth ? $targetWidth * $targetHeight : 0;
        // Budget GD/decode working memory before allocating either raster.
        // Keep 16 MiB for the request; estimate 6 bytes per source/resample pixel.
        $rawMemoryLimit = trim((string)ini_get('memory_limit'));
        if ($rawMemoryLimit !== '-1') {
            if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $rawMemoryLimit, $memoryParts)) return false;
            $memoryLimit = (int)$memoryParts[1];
            $memoryPower = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3][strtoupper($memoryParts[2])];
            for ($power = 0; $power < $memoryPower; $power++) {
                if ($memoryLimit > intdiv(PHP_INT_MAX, 1024)) { $memoryLimit = PHP_INT_MAX; break; }
                $memoryLimit *= 1024;
            }
            $available = $memoryLimit - memory_get_usage(true);
            $reserve = 16 * 1024 * 1024;
            if ($available <= $reserve) return false;
            $available -= $reserve;
            if ($sourcePixels > intdiv($available, 6)) return false;
            $available -= $sourcePixels * 6;
            if ($targetPixels > intdiv($available, 6)) return false;
        }
        switch ($info['mime']) {
            case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
            case 'image/png': $image = @imagecreatefrompng($source); break;
            case 'image/webp': $image = @imagecreatefromwebp($source); break;
            case 'image/gif': $image = @imagecreatefromgif($source); break;
        }
        if (!$image) return false;
        if ($info['mime'] === 'image/png') {
            if (!imagealphablending($image, false) || !imagesavealpha($image, true)) return false;
        }
        if ($width > $maxWidth) {
            $newImage = @imagecreatetruecolor($targetWidth, $targetHeight);
            if (!$newImage) return false;
            if ($info['mime'] === 'image/png') {
                if (!imagealphablending($newImage, false) || !imagesavealpha($newImage, true)) return false;
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                if ($transparent === false || !imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent)) return false;
            }
            if (!imagecopyresampled($newImage, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) return false;
            imagedestroy($image);
            $image = $newImage;
            $newImage = null;
        }
        switch ($info['mime']) {
            case 'image/jpeg': return @imagejpeg($image, $destination, $quality);
            case 'image/png': return @imagepng($image, $destination, 8);
            case 'image/webp': return @imagewebp($image, $destination, $quality);
            case 'image/gif': return @imagegif($image, $destination);
        }
        return false;
    } catch (\Throwable $error) {
        return false;
    } finally {
        if ($newImage !== null && $newImage !== false) imagedestroy($newImage);
        if ($image !== null && $image !== false) imagedestroy($image);
    }
}

function handlePhotoUpload($file, $orderId, $fileIndex = 0) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Ошибка загрузки'];
    }
    
    if ($file['size'] > 20 * 1024 * 1024) {
        return ['success' => false, 'error' => 'Фото больше 20MB'];
    }
    
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        return ['success' => false, 'error' => 'Только JPG, PNG, WEBP, GIF'];
    }
    
    if (!file_exists(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'order_' . $orderId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = UPLOAD_DIR . $filename;
    $webpath = 'uploads/' . $filename;
    
    if (!compressImage($file['tmp_name'], $filepath, 1600, 85)) {
        return ['success' => false, 'error' => 'Не удалось прочитать фотографию. Выберите исправный файл JPG, PNG, WEBP или GIF.'];
    }
    
    return [
        'success' => true,
        'path' => $webpath,
        'full_path' => $filepath,
        'size' => filesize($filepath),
        'original_name' => $file['name']
    ];
}

// ============================================
// ФУНКЦИИ TELEGRAM
// ============================================
// LEAD_DELIVERY_BEGIN
class LeadValidationException extends \RuntimeException {}

function telegramDeliveryResult($reason = 'transport_error') {
    return ['ok' => false, 'reason' => $reason, 'http_code' => 0,
        'curl_errno' => 0, 'error_code' => 0, 'photo_id' => null];
}

function performTelegramRequest($method, $fields) {
    $result = telegramDeliveryResult();
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') return $result;
    $ch = null;
    try {
        if (!in_array($method, ['sendMessage', 'sendPhoto'], true)) return $result;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $method === 'sendPhoto' ? $fields : http_build_query($fields));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $method === 'sendPhoto' ? 15 : 10);
        $response = curl_exec($ch);
        $result['curl_errno'] = max(0, (int)curl_errno($ch));
        $result['http_code'] = max(0, (int)curl_getinfo($ch, CURLINFO_HTTP_CODE));
        if ($response === false || $result['curl_errno'] !== 0) return $result;
        $data = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($data)) {
            $result['reason'] = 'invalid_json';
            return $result;
        }
        $result['error_code'] = isset($data['error_code']) && is_int($data['error_code']) ? max(0, $data['error_code']) : 0;
        if ($result['http_code'] !== 200) {
            $result['reason'] = 'http_error';
            return $result;
        }
        if (($data['ok'] ?? null) !== true) {
            $result['reason'] = 'telegram_rejected';
            return $result;
        }
        if ($method === 'sendPhoto') {
            $photoId = $data['result']['photo'][0]['file_id'] ?? null;
            if (!is_string($photoId) || $photoId === '' || strlen($photoId) > 512) {
                $result['reason'] = 'invalid_photo_response';
                return $result;
            }
            $result['photo_id'] = $photoId;
        }
        $result['ok'] = true;
        $result['reason'] = 'delivered';
        return $result;
    } catch (\Throwable $error) {
        $result['reason'] = 'transport_exception';
        return $result;
    } finally {
        if ($ch !== null && $ch !== false) curl_close($ch);
    }
}

function sendToTelegram($text) {
    return performTelegramRequest('sendMessage', ['chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
}

function sendPhotoToTelegram($filePath, $caption) {
    try {
        return performTelegramRequest('sendPhoto', ['chat_id' => TELEGRAM_CHAT_ID,
            'caption' => $caption, 'parse_mode' => 'HTML', 'photo' => new CURLFile($filePath)]);
    } catch (\Throwable $error) {
        return telegramDeliveryResult('transport_exception');
    }
}

// Diagnostics contain only internal IDs and bounded machine codes, never contacts,
// messages, paths, response bodies, bot credentials, or Telegram request URLs.
function logLeadDelivery($formType, $orderId, $stage, $result) {
    $reasons = ['accepted', 'delivered', 'transport_error', 'transport_exception',
        'invalid_json', 'http_error', 'telegram_rejected', 'invalid_photo_response',
        'storage_failed', 'post_acceptance_error', 'receipt_write_failed',
        'legacy_submission', 'receipt_payload_mismatch', 'photo_metadata_failed', 'notification_pending', 'audit_write_failed', 'upload_cleanup_failed'];
    $stages = ['accepted', 'message', 'first_photo', 'photo', 'text_fallback',
        'receipt', 'storage', 'post_acceptance', 'photo_metadata', 'delivery_complete', 'audit', 'cleanup'];
    $record = ['order_id' => max(0, (int)$orderId),
        'form_type' => in_array($formType, ['submit_order', 'submit_callback'], true) ? $formType : 'unknown',
        'stage' => in_array($stage, $stages, true) ? $stage : 'unknown',
        'reason' => in_array($result['reason'] ?? '', $reasons, true) ? $result['reason'] : 'unknown',
        'http_code' => min(599, max(0, (int)($result['http_code'] ?? 0))),
        'curl_errno' => min(9999, max(0, (int)($result['curl_errno'] ?? 0))),
        'error_code' => min(9999, max(0, (int)($result['error_code'] ?? 0))),
        'notification_pending' => (int)$orderId > 0 && (bool)($result['notification_pending'] ?? (($result['ok'] ?? false) !== true))];
    $line = '[avtokai-lead] ' . json_encode($record);
    try { error_log($line); }
    catch (\Throwable $error) { /* Logging cannot revoke an accepted request. */ }
    try {
        // Reuse the existing protected audit paths; no new public/runtime path.
        $auditPath = $formType === 'submit_callback' ? 'calls.log' : 'orders.log';
        @file_put_contents($auditPath, date('Y-m-d H:i:s') . ' | ' . $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $error) { /* The error-log sink remains independent. */ }
}

// Preserve the existing accepted-lead business audit. This is separate from the
// contact-free transport diagnostics above and runs only after a durable commit.
function writeAcceptedLeadAudit($formType, $orderId, $name, $phone, $desiredPrice = '', $fileCount = 0, $leadPage = '') {
    $page = $leadPage !== '' ? ' | Страница: ' . $leadPage : '';
    $line = $formType === 'submit_callback'
        ? date('Y-m-d H:i:s') . " | ЗВОНОК #{$orderId} | {$name} | {$phone}{$page}\n"
        : date('Y-m-d H:i:s') . " | ЗАЯВКА #{$orderId} | {$name} | {$phone} | Желаемая цена: {$desiredPrice} | Фото: {$fileCount}{$page}\n";
    try {
        $written = @file_put_contents($formType === 'submit_callback' ? 'calls.log' : 'orders.log', $line, FILE_APPEND | LOCK_EX);
        if ($written !== false) return;
    } catch (\Throwable $error) { /* The database has already accepted this lead. */ }
    logLeadDelivery($formType, $orderId, 'audit', ['reason' => 'audit_write_failed']);
}

function readLeadSubmissionId() {
    $value = $_POST['submission_id'] ?? null;
    // Previously opened pages remain usable; they have no receipt key.
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/', $value)) {
        throw new LeadValidationException('Обновите страницу перед отправкой формы.');
    }
    return $value;
}

function pruneLeadReceipts() {
    $receipts = $_SESSION['lead_receipts_v1'] ?? [];
    if (!is_array($receipts)) $receipts = [];
    $cutoff = time() - 86400;
    foreach ($receipts as $key => $receipt) {
        if (!is_array($receipt) || !isset($receipt['accepted_at'], $receipt['order_id'], $receipt['fingerprint'])
            || !is_int($receipt['accepted_at']) || $receipt['accepted_at'] < $cutoff
            || $receipt['accepted_at'] > time() || (int)$receipt['order_id'] < 1
            || !is_string($receipt['fingerprint']) || !preg_match('/\A[a-f0-9]{64}\z/', $receipt['fingerprint'])) {
            unset($receipts[$key]);
        }
    }
    uasort($receipts, static fn($left, $right) => $left['accepted_at'] <=> $right['accepted_at']);
    $_SESSION['lead_receipts_v1'] = array_slice($receipts, -50, null, true);
}

function leadSubmissionFingerprint($formType, $fields, $photos = []) {
    // The session secret makes low-entropy contact fields unsuitable for guessing
    // from the stored digest. Neither the digest nor its source enters logs.
    return hash_hmac('sha256', json_encode([$formType, $fields, $photos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $_SESSION['form_token']);
}

function findLeadReceipt($formType, $submissionId, $fingerprint) {
    pruneLeadReceipts();
    if ($submissionId === null) return null;
    $receipt = $_SESSION['lead_receipts_v1'][$formType . ':' . $submissionId] ?? null;
    if ($receipt !== null && !hash_equals($receipt['fingerprint'], $fingerprint)) {
        logLeadDelivery($formType, $receipt['order_id'], 'receipt', ['reason' => 'receipt_payload_mismatch']);
        throw new LeadValidationException('Предыдущая заявка уже принята. Для отправки новых данных обновите страницу.');
    }
    return $receipt;
}

function rememberLeadReceipt($formType, $submissionId, $fingerprint, $orderId, $notificationPending = true) {
    if ($submissionId !== null) {
        pruneLeadReceipts();
        $_SESSION['lead_receipts_v1'][$formType . ':' . $submissionId] = [
            'order_id' => (int)$orderId, 'fingerprint' => $fingerprint,
            'accepted_at' => time(), 'notification_pending' => (bool)$notificationPending];
        pruneLeadReceipts();
    } else {
        logLeadDelivery($formType, $orderId, 'receipt', ['reason' => 'legacy_submission']);
    }
    if (session_status() === PHP_SESSION_ACTIVE && !@session_write_close()) {
        logLeadDelivery($formType, $orderId, 'receipt', ['reason' => 'receipt_write_failed']);
    }
}

function updateLeadReceiptDelivery($formType, $submissionId, $notificationPending, $orderId) {
    if ($submissionId === null) return;
    if (session_status() !== PHP_SESSION_ACTIVE && !@session_start()) {
        logLeadDelivery($formType, $orderId, 'receipt', ['reason' => 'receipt_write_failed']);
        return;
    }
    $key = $formType . ':' . $submissionId;
    if (isset($_SESSION['lead_receipts_v1'][$key]) && (int)$_SESSION['lead_receipts_v1'][$key]['order_id'] === (int)$orderId) {
        $_SESSION['lead_receipts_v1'][$key]['notification_pending'] = (bool)$notificationPending;
    }
    if (!@session_write_close()) logLeadDelivery($formType, $orderId, 'receipt', ['reason' => 'receipt_write_failed']);
}

function respondAcceptedLead($orderId, $notificationPending, $duplicate = false, $submissionId = null) {
    echo json_encode(['success' => true, 'accepted' => true, 'order_id' => (int)$orderId,
        'submission_id' => $submissionId, 'duplicate' => (bool)$duplicate,
        'notification_pending' => (bool)$notificationPending, 'message' => 'Заявка принята']);
}

// Accept only bounded strings before passing form fields to SQL or Telegram.
function readFormText($key, $label, $maxLength) {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new LeadValidationException('Некорректное поле: ' . $label);
    }
    $value = trim($value);
    if (!preg_match('/\A[^\x00-\x1F\x7F]{0,' . $maxLength . '}\z/u', $value)) {
        throw new LeadValidationException($label . ': не более ' . $maxLength . ' символов, без служебных знаков.');
    }
    return $value;
}

function readFormName() {
    $name = readFormText('name', 'Имя', 100);
    if ($name === '') throw new LeadValidationException('Укажите ваше имя');
    if (!preg_match('/\A(?=.*\p{L})[\p{L}\p{M} \'\x{2019}\x{02BC}\-]+\z/u', $name)) {
        throw new LeadValidationException('Имя: используйте буквы, пробелы, дефис или апостроф, без цифр.');
    }
    return $name;
}

// Derive attribution from the actual form endpoint, never a submitted URL.
// Only these reviewed landing paths enter notifications and existing audit logs.
function campaignLeadPageUrl() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $slugs = ['vykup-avto', 'srochnyj-vykup-avto', 'skupka-avto',
        'prodat-avto', 'avtovykup', 'prodat-mashinu-bystro',
        'vykup-bityh-avto', 'vykup-kreditnyh-avto', 'vykup-neispravnyh-avto',
        'vykup-avto-posle-dtp', 'vykup-avto-s-probegom', 'vykup-staryh-avto'];
    foreach ($slugs as $slug) {
        if ($path === '/' . $slug || $path === '/' . $slug . '/') {
            return 'https://avtokai.ru/' . $slug . '/';
        }
    }
    return '';
}

// ============================================
// ОБРАБОТКА ЗАЯВОК
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Не удалось прочитать форму. Общий размер фотографий должен быть не больше 19 МБ.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // ========== ОБРАБОТКА ОСНОВНОЙ ФОРМЫ ==========
    if ($_POST['action'] === 'submit_order') {
        $acceptedOrderId = 0;
        $submissionId = null;
        $uploadedPhotos = [];
        try {
            // АНТИБОТ ПРОВЕРКА
            if (!empty($_POST['website'])) {
                throw new LeadValidationException('Обнаружен бот');
            }
            
            if (isset($_POST['form_time']) && is_numeric($_POST['form_time'])) {
                $timeSpent = time() - intval($_POST['form_time']);
                if ($timeSpent < 3) {
                    throw new LeadValidationException('Слишком быстро. Подтвердите, что вы не бот.');
                }
            }
            
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || $token === '' || !hash_equals($_SESSION['form_token'] ?? '', $token)) {
                throw new LeadValidationException('Недействительный токен. Обновите страницу.');
            }
            
            $name = readFormName();
            $phone = readFormText('phone', 'Телефон', 32);
            
            if (!preg_match('/^[78]\d{10}$/', preg_replace('/\D/', '', $phone))) {
                throw new LeadValidationException('Введите полный номер телефона: +7 и ещё 10 цифр.');
            }
            
            $carBrand = readFormText('car_brand', 'Марка и модель', 120);
            $carYear = readFormText('car_year', 'Год выпуска', 4);
            $carMileage = readFormText('car_mileage', 'Пробег', 7);
            $carCondition = readFormText('car_condition', 'Состояние авто', 20);
            $desiredPrice = readFormText('desired_price', 'Желаемая цена', 10);
            $maxCarYear = (int)date('Y') + 1;
            if ($carYear !== '' && (!preg_match('/\A[0-9]{4}\z/', $carYear) || (int)$carYear < 1886 || (int)$carYear > $maxCarYear)) {
                throw new LeadValidationException('Укажите год выпуска от 1886 до ' . $maxCarYear . '.');
            }
            if ($carMileage !== '' && !preg_match('/\A[0-9]{1,7}\z/', $carMileage)) {
                throw new LeadValidationException('Пробег: укажите целое число километров, не более 7 цифр.');
            }
            if ($desiredPrice !== '' && !preg_match('/\A[0-9]{1,10}\z/', $desiredPrice)) {
                throw new LeadValidationException('Желаемая цена: укажите целое число рублей, не более 10 цифр.');
            }
            $conditions = [
                'perfect' => 'Отличное',
                'good' => 'Хорошее',
                'accident' => 'Битое/ДТП',
                'not_driving' => 'Не на ходу',
                'credit' => 'Кредитное'
            ];
            if ($carCondition !== '' && !isset($conditions[$carCondition])) {
                throw new LeadValidationException('Выберите состояние автомобиля из списка.');
            }

            if (isset($_FILES['car_photos'])) {
                $photos = $_FILES['car_photos'];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
                    if (!isset($photos[$part]) || !is_array($photos[$part]) || !array_is_list($photos[$part])) {
                        throw new LeadValidationException('Некорректный список фотографий. Выберите фото заново.');
                    }
                }
                $photoCount = count($photos['name']);
                if ($photoCount > 20) throw new LeadValidationException('Можно загрузить не более 20 фотографий.');
                foreach (['type', 'tmp_name', 'error', 'size'] as $part) {
                    if (count($photos[$part]) !== $photoCount) throw new LeadValidationException('Некорректный список фотографий.');
                }
                $photoBytes = 0;
                for ($i = 0; $i < $photoCount; $i++) {
                    if (!is_string($photos['name'][$i]) || !is_string($photos['type'][$i]) || !is_string($photos['tmp_name'][$i]) || !is_int($photos['error'][$i]) || !is_int($photos['size'][$i]) || $photos['size'][$i] < 0) {
                        throw new LeadValidationException('Некорректный файл фотографии.');
                    }
                    if ($photos['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($photos['error'][$i] !== UPLOAD_ERR_OK) throw new LeadValidationException('Фото не загрузилось. Уменьшите размер или выберите файл заново.');
                    $photoBytes += $photos['size'][$i];
                }
                if ($photoBytes > 19 * 1024 * 1024) throw new LeadValidationException('Общий размер фотографий должен быть не больше 19 МБ.');
            }

            $submissionId = readLeadSubmissionId();
            $photoFingerprints = [];
            foreach (($_FILES['car_photos']['tmp_name'] ?? []) as $index => $temporaryPhoto) {
                if ($_FILES['car_photos']['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
                $photoDigest = @hash_file('sha256', $temporaryPhoto);
                if ($photoDigest === false) throw new LeadValidationException('Не удалось прочитать фотографию. Выберите файл заново.');
                $photoFingerprints[] = $photoDigest;
            }
            $fingerprint = leadSubmissionFingerprint('submit_order',
                [$name, $phone, $carBrand, $carYear, $carMileage, $carCondition, $desiredPrice], $photoFingerprints);
            $receipt = findLeadReceipt('submit_order', $submissionId, $fingerprint);
            if ($receipt !== null) {
                respondAcceptedLead($receipt['order_id'], $receipt['notification_pending'] ?? true, true, $submissionId);
                exit;
            }
            $pdo->beginTransaction();
            
            // Вставляем заявку
            $stmt = $pdo->prepare("INSERT INTO orders (name, phone, car_brand, car_year, car_mileage, car_condition, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, 'new')");
            $stmt->execute([
                $name, $phone,
                $carBrand, $carYear, $carMileage, $carCondition
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // Обработка фото
            $uploadedPhotos = [];
            $hasPhotos = false;
            $fileCount = 0;
            
            if (isset($_FILES['car_photos'])) {
                $files = $_FILES['car_photos'];
                $fileCount = count($files['name']);
                
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $files['name'][$i],
                            'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error' => $files['error'][$i],
                            'size' => $files['size'][$i]
                        ];
                        
                        $photoInfo = handlePhotoUpload($file, $orderId, $i);
                        
                        if (!$photoInfo['success']) {
                            throw new LeadValidationException($photoInfo['error']);
                        }
                        if ($photoInfo['success']) {
                            $uploadedPhotos[] = $photoInfo;
                            $photoStmt = $pdo->prepare("INSERT INTO order_photos 
                                (order_id, photo_path, photo_original_name, photo_size) 
                                VALUES (?, ?, ?, ?)");
                            $photoStmt->execute([
                                $orderId,
                                $photoInfo['path'],
                                $photoInfo['original_name'],
                                $photoInfo['size']
                            ]);
                            
                            $hasPhotos = true;
                        }
                    }
                }
            }

            // Durable acceptance comes first. Telegram is a notification channel,
            // and cannot roll back or revoke a saved lead.
            if (!$pdo->commit()) throw new \RuntimeException('Lead commit failed');
            $acceptedOrderId = (int)$orderId;
            rememberLeadReceipt('submit_order', $submissionId, $fingerprint, $acceptedOrderId);
            writeAcceptedLeadAudit('submit_order', $acceptedOrderId, $name, $phone, $desiredPrice, count($uploadedPhotos), campaignLeadPageUrl());
            logLeadDelivery('submit_order', $acceptedOrderId, 'accepted', ['reason' => 'accepted']);
            
            // Escape user text only for Telegram HTML; retain original database values.
            $telegramName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $telegramPhone = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // Формируем сообщение для Telegram
            $carInfo = [];
            if ($carBrand !== '') $carInfo[] = "🚗 Авто: " . htmlspecialchars($carBrand, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($carYear !== '') $carInfo[] = "📅 Год: " . htmlspecialchars($carYear, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($carMileage !== '') $carInfo[] = "📊 Пробег: " . htmlspecialchars($carMileage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($carCondition !== '') {
                $carInfo[] = "🔧 Состояние: " . $conditions[$carCondition];
            }
            
            if ($desiredPrice !== '') {
                $carInfo[] = "💰 Желаемая цена: " . number_format((int)$desiredPrice, 0, '.', ' ') . " ₽";
            }
            
            $carInfoText = !empty($carInfo) ? "\n" . implode("\n", $carInfo) : "";
            
            $msg = "🚀 <b>НОВАЯ ЗАЯВКА #{$orderId}</b>\n\n";
            $msg .= "👤 <b>Имя:</b> {$telegramName}\n";
            $msg .= "📞 <b>Телефон:</b> {$telegramPhone}";
            $msg .= $carInfoText;
            $leadPage = campaignLeadPageUrl();
            if ($leadPage !== '') $msg .= "\n🌐 <b>Страница:</b> " . $leadPage;
            
            $notificationPending = false;
            if ($hasPhotos) {
                $fileCount = count($uploadedPhotos);
                $msg .= "\n📸 <b>Фото:</b> {$fileCount} шт.";
                
                if (!empty($uploadedPhotos)) {
                    $firstPhoto = $uploadedPhotos[0];
                    $delivery = sendPhotoToTelegram($firstPhoto['full_path'], $msg);
                    $notificationPending = !$delivery['ok'];
                    logLeadDelivery('submit_order', $acceptedOrderId, 'first_photo', $delivery);
                    if ($delivery['ok']) {
                        try {
                            $pdo->prepare("UPDATE order_photos SET telegram_photo_id = ? WHERE order_id = ? AND photo_path = ?")
                                ->execute([$delivery['photo_id'], $orderId, $firstPhoto['path']]);
                        } catch (\Throwable $error) {
                            logLeadDelivery('submit_order', $acceptedOrderId, 'photo_metadata', ['reason' => 'photo_metadata_failed', 'notification_pending' => false]);
                        }
                    } else {
                        // Even when Telegram rejects a photo, deliver its lead text.
                        $fallback = sendToTelegram($msg);
                        logLeadDelivery('submit_order', $acceptedOrderId, 'text_fallback', $fallback);
                    }
                    
                    for ($i = 1; $i < count($uploadedPhotos); $i++) {
                        $delivery = sendPhotoToTelegram($uploadedPhotos[$i]['full_path'], "📸 Фото #" . ($i + 1) . " к заявке #{$orderId}");
                        $notificationPending = $notificationPending || !$delivery['ok'];
                        logLeadDelivery('submit_order', $acceptedOrderId, 'photo', $delivery);
                        if ($delivery['ok']) {
                            try {
                                $pdo->prepare("UPDATE order_photos SET telegram_photo_id = ? WHERE order_id = ? AND photo_path = ?")
                                    ->execute([$delivery['photo_id'], $orderId, $uploadedPhotos[$i]['path']]);
                            } catch (\Throwable $error) {
                                logLeadDelivery('submit_order', $acceptedOrderId, 'photo_metadata', ['reason' => 'photo_metadata_failed', 'notification_pending' => false]);
                            }
                        }
                    }
                }
            } else {
                $delivery = sendToTelegram($msg);
                $notificationPending = !$delivery['ok'];
                logLeadDelivery('submit_order', $acceptedOrderId, 'message', $delivery);
            }
            updateLeadReceiptDelivery('submit_order', $submissionId, $notificationPending, $acceptedOrderId);
            logLeadDelivery('submit_order', $acceptedOrderId, 'delivery_complete', ['reason' => $notificationPending ? 'notification_pending' : 'delivered', 'notification_pending' => $notificationPending]);
            respondAcceptedLead($acceptedOrderId, $notificationPending, false, $submissionId);
        } catch (\Throwable $e) {
            if ($acceptedOrderId > 0) {
                logLeadDelivery('submit_order', $acceptedOrderId, 'post_acceptance', ['reason' => 'post_acceptance_error']);
                respondAcceptedLead($acceptedOrderId, true, false, $submissionId);
                exit;
            }
            $rolledBack = false;
            try { if ($pdo->inTransaction()) $rolledBack = $pdo->rollBack(); }
            catch (\Throwable $rollbackError) {
                logLeadDelivery('submit_order', 0, 'storage', ['reason' => 'storage_failed']);
            }
            if ($rolledBack) {
                // Only files created by this rolled-back request; never committed uploads.
                foreach ($uploadedPhotos ?? [] as $photo) {
                    try { if (is_file($photo['full_path'])) @unlink($photo['full_path']); }
                    catch (\Throwable $cleanupError) {
                        logLeadDelivery('submit_order', 0, 'cleanup', ['reason' => 'upload_cleanup_failed']);
                    }
                }
            }
            if (!($e instanceof LeadValidationException)) logLeadDelivery('submit_order', 0, 'storage', ['reason' => 'storage_failed']);
            echo json_encode(['success' => false, 'accepted' => false,
                'message' => $e instanceof LeadValidationException ? $e->getMessage() : 'Не удалось сохранить заявку. Позвоните нам или попробуйте позже.']);
        }
        exit;
    }
    
    // ========== ОТДЕЛЬНАЯ ОБРАБОТКА ФОРМЫ ЗВОНКА ==========
    if ($_POST['action'] === 'submit_callback') {
        $acceptedOrderId = 0;
        $submissionId = null;
        try {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['form_token'] ?? '', $token) || $token === '') {
                throw new LeadValidationException('Недействительный токен. Обновите страницу.');
            }
            // АНТИБОТ ПРОВЕРКА
            if (!empty($_POST['website'])) {
                throw new LeadValidationException('Обнаружен бот');
            }
            
            $name = readFormName();
            $phone = readFormText('phone', 'Телефон', 32);
            
            if (!preg_match('/^[78]\d{10}$/', preg_replace('/\D/', '', $phone))) {
                throw new LeadValidationException('Введите полный номер телефона: +7 и ещё 10 цифр.');
            }

            $submissionId = readLeadSubmissionId();
            $fingerprint = leadSubmissionFingerprint('submit_callback', [$name, $phone]);
            $receipt = findLeadReceipt('submit_callback', $submissionId, $fingerprint);
            if ($receipt !== null) {
                respondAcceptedLead($receipt['order_id'], $receipt['notification_pending'] ?? true, true, $submissionId);
                exit;
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO orders (name, phone, car_brand, car_year, car_mileage, car_condition, status)
                VALUES (?, ?, ?, ?, ?, ?, 'new')")
                ->execute([$name, $phone, 'Обратный звонок', '', '', '']);
            $orderId = (int)$pdo->lastInsertId();
            if (!$pdo->commit()) throw new \RuntimeException('Lead commit failed');
            $acceptedOrderId = $orderId;
            rememberLeadReceipt('submit_callback', $submissionId, $fingerprint, $acceptedOrderId);
            writeAcceptedLeadAudit('submit_callback', $acceptedOrderId, $name, $phone, '', 0, campaignLeadPageUrl());
            logLeadDelivery('submit_callback', $acceptedOrderId, 'accepted', ['reason' => 'accepted']);
            
            $telegramName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $telegramPhone = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // Отправляем в Telegram
            $msg = "📞 <b>ЗАКАЗ ЗВОНКА #{$orderId}</b>\n\n";
            $msg .= "👤 <b>Имя:</b> {$telegramName}\n";
            $msg .= "📞 <b>Телефон:</b> {$telegramPhone}\n";
            $leadPage = campaignLeadPageUrl();
            if ($leadPage !== '') $msg .= "🌐 <b>Страница:</b> " . $leadPage . "\n";
            
            $delivery = sendToTelegram($msg);
            $notificationPending = !$delivery['ok'];
            logLeadDelivery('submit_callback', $acceptedOrderId, 'message', $delivery);
            updateLeadReceiptDelivery('submit_callback', $submissionId, $notificationPending, $acceptedOrderId);
            logLeadDelivery('submit_callback', $acceptedOrderId, 'delivery_complete', ['reason' => $notificationPending ? 'notification_pending' : 'delivered', 'notification_pending' => $notificationPending]);
            respondAcceptedLead($acceptedOrderId, $notificationPending, false, $submissionId);
        } catch (\Throwable $e) {
            if ($acceptedOrderId > 0) {
                logLeadDelivery('submit_callback', $acceptedOrderId, 'post_acceptance', ['reason' => 'post_acceptance_error']);
                respondAcceptedLead($acceptedOrderId, true, false, $submissionId);
                exit;
            }
            try { if ($pdo->inTransaction()) $pdo->rollBack(); }
            catch (\Throwable $rollbackError) {
                logLeadDelivery('submit_callback', 0, 'storage', ['reason' => 'storage_failed']);
            }
            if (!($e instanceof LeadValidationException)) logLeadDelivery('submit_callback', 0, 'storage', ['reason' => 'storage_failed']);
            echo json_encode(['success' => false, 'accepted' => false,
                'message' => $e instanceof LeadValidationException ? $e->getMessage() : 'Не удалось сохранить заявку. Позвоните нам или попробуйте позже.']);
        }
        exit;
    }
}

// LEAD_DELIVERY_END
// ============================================
// ГЕНЕРАЦИЯ ТОКЕНА ДЛЯ ФОРМЫ
// ============================================
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$form_time = time();
// Shared page catalog is separate from protected production settings and station data.
$serviceSlugs = ['vykup-bityh-avto', 'vykup-kreditnyh-avto', 'vykup-neispravnyh-avto',
    'vykup-avto-posle-dtp', 'vykup-avto-s-probegom', 'vykup-staryh-avto'];
try {
    $servicePages = json_decode(file_get_contents(__DIR__ . '/service-pages.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach ($serviceSlugs as $slug) {
        if (!isset($servicePages[$slug]['h1'], $servicePages[$slug]['title'], $servicePages[$slug]['description'])) {
            throw new RuntimeException('Incomplete service catalog');
        }
    }
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Страница временно недоступна. Пожалуйста, попробуйте позже.');
}
// Advertising landings share the existing components and application handlers.
// Their content stays server-rendered and separate from the fixed service catalog.
$campaignPages = [
    'vykup-avto' => [
        'hero_title' => 'Выкуп авто',
        'h1' => 'Выкуп авто в Санкт-Петербурге и Ленинградской области',
        'title' => 'Выкуп авто в СПб и Ленинградской области — любое состояние | Автокай',
        'description' => 'Выкуп авто в Санкт-Петербурге и Ленинградской области: целые, битые, кредитные и неисправные машины. Бесплатная оценка, эвакуатор и оформление за наш счёт.',
    ],
    'srochnyj-vykup-avto' => [
        'hero_title' => 'Срочный выкуп авто',
        'h1' => 'Срочный выкуп авто в Санкт-Петербурге и Ленинградской области',
        'title' => 'Срочный выкуп авто в СПб и Ленобласти — деньги за 30 минут | Автокай',
        'description' => 'Срочный выкуп авто в Санкт-Петербурге и Ленинградской области. Оценка по фото, выезд и расчёт без долгих объявлений. Бесплатное оформление и эвакуатор.',
    ],
    'skupka-avto' => [
        'hero_title' => 'Скупка автомобилей',
        'h1' => 'Скупка автомобилей в Санкт-Петербурге и Ленинградской области',
        'title' => 'Скупка автомобилей в СПб и Ленобласти — с пробегом и после ДТП | Автокай',
        'description' => 'Скупка автомобилей в Санкт-Петербурге и Ленинградской области: с пробегом, битых, неисправных и кредитных. Бесплатная оценка и выезд оценщика.',
    ],
    'prodat-avto' => [
        'hero_title' => 'Продать авто',
        'h1' => 'Продать авто в Санкт-Петербурге и Ленинградской области',
        'title' => 'Продать авто в СПб и Ленобласти без поиска покупателей | Автокай',
        'description' => 'Продайте авто в Санкт-Петербурге и Ленинградской области без самостоятельного поиска покупателей. Бесплатная оценка, согласование цены и оформление в Автокае.',
    ],
    'avtovykup' => [
        'hero_title' => 'Автовыкуп',
        'h1' => 'Автовыкуп в Санкт-Петербурге и Ленинградской области',
        'title' => 'Автовыкуп в СПб и Ленинградской области — оценка и оформление | Автокай',
        'description' => 'Автовыкуп в Санкт-Петербурге и Ленинградской области: профессиональная оценка, согласование стоимости и оформление сделки. Целые, битые и кредитные авто.',
    ],
    'prodat-mashinu-bystro' => [
        'hero_title' => 'Продать машину быстро',
        'h1' => 'Продать машину быстро в Санкт-Петербурге и Ленинградской области',
        'title' => 'Продать машину быстро в СПб и Ленобласти — без объявлений | Автокай',
        'description' => 'Поможем быстро продать машину в Санкт-Петербурге и Ленинградской области. Одна заявка, бесплатная оценка по фото, выезд и оформление за наш счёт.',
    ],
];
$campaignSlugs = array_keys($campaignPages);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$serviceSlug = trim($requestPath, '/');
$isServicePage = in_array($serviceSlug, $serviceSlugs, true)
    && in_array($requestPath, ['/' . $serviceSlug, '/' . $serviceSlug . '/'], true);
$isAutovykupPage = in_array($requestPath, ['/avtovykup-v-spb', '/avtovykup-v-spb/'], true);
$isCampaignPage = in_array($serviceSlug, $campaignSlugs, true)
    && in_array($requestPath, ['/' . $serviceSlug, '/' . $serviceSlug . '/'], true);
$campaignPage = $isCampaignPage ? $campaignPages[$serviceSlug] : null;
// Home, advertising landings, services and station pages share one photo and live form.
$isHomeHero = !$isStationPage && in_array($requestPath, ['/', '/index.php'], true);
$isStationHero = $isStationPage && in_array($requestPath, ['/', '/index.php'], true);
$isDesktopPhotoHero = $isCampaignPage || $isHomeHero || $isServicePage || $isStationHero;
$isPhoneHero = $isDesktopPhotoHero || $isAutovykupPage;
$isPhotoHero = $isDesktopPhotoHero;
$isOldCarHero = $isServicePage && $serviceSlug === 'vykup-staryh-avto';
$isBityhHero = $isServicePage && $serviceSlug === 'vykup-bityh-avto';
$isPortraitHero = $isPhoneHero;
$portraitHeroImageUrl = ($isBityhHero || ($isServicePage && $serviceSlug === 'vykup-avto-posle-dtp')) ? '/hero-vykup-bityh-mobile.webp?v=e75b29bfac55' : '/hero-avtovykup-cash.webp?v=71f9e049a4c0';
// Presentation copy for the shared phone hero; route and SEO metadata stay separate.
$phoneHeroOffers = [
    'avtovykup-v-spb' => [
        'title' => 'Автовыкуп',
        'intro' => 'Выкупаем авто с пробегом, повреждениями и неисправностями.',
    ],
    'vykup-avto' => [
        'intro' => 'Выкупаем целые, битые, кредитные и неисправные авто.',
    ],
    'srochnyj-vykup-avto' => [
        'title' => 'Срочный', 'followup' => 'Выкуп авто',
        'intro' => 'Выкупаем авто срочно и без объявлений. Оценка и выезд — бесплатно.',
    ],
    'skupka-avto' => [
        'title' => 'Скупка авто', 'variant' => 'compact',
        'intro' => 'Выкупаем авто с пробегом, после ДТП и не на ходу.',
    ],
    'prodat-avto' => [
        'variant' => 'compact',
        'intro' => 'Выкупаем ваш автомобиль напрямую — без объявлений и поиска покупателя.',
    ],
    'avtovykup' => [
        'intro' => 'Выкупаем целые, битые, кредитные и неисправные автомобили.',
    ],
    'prodat-mashinu-bystro' => [
        'title' => 'Продать машину', 'followup' => 'Быстро', 'variant' => 'long',
        'intro' => 'Выкупаем машину без объявлений и долгого поиска покупателя.',
    ],
];
$servicePhoneHeroOffers = [
    'vykup-bityh-avto' => ['title' => 'Выкуп битых', 'followup' => 'авто', 'variant' => 'compact', 'intro' => 'Выкупаем битые авто с любыми повреждениями.'],
    'vykup-kreditnyh-avto' => ['title' => 'Выкуп', 'followup' => 'кредитных авто', 'variant' => 'service-long', 'intro' => 'Выкупаем кредитные и залоговые авто.'],
    'vykup-neispravnyh-avto' => ['title' => 'Выкуп', 'followup' => 'неисправных авто', 'variant' => 'service-long', 'intro' => 'Выкупаем авто с неисправным двигателем или коробкой передач.'],
    'vykup-avto-posle-dtp' => ['title' => 'Выкуп авто', 'followup' => 'после ДТП', 'variant' => 'compact', 'intro' => 'Выкупаем авто после ДТП, даже если они не на ходу.'],
    'vykup-avto-s-probegom' => ['title' => 'Выкуп авто', 'followup' => 'с пробегом', 'variant' => 'compact', 'intro' => 'Выкупаем авто с любым пробегом, разных марок и годов выпуска.'],
    'vykup-staryh-avto' => ['title' => 'Выкуп старых', 'followup' => 'авто', 'variant' => 'compact', 'intro' => 'Выкупаем старые авто, включая неисправные и не на ходу.'],
];
foreach ($servicePhoneHeroOffers as $heroSlug => $heroOffer) {
    $phoneHeroOffers[$heroSlug] = $heroOffer + ['intro' => $servicePages[$heroSlug]['intro']];
}
$phoneHeroOffer = $isPhoneHero && !$isStationHero ? $phoneHeroOffers[$isHomeHero ? 'vykup-avto' : $serviceSlug] : null;
if ($isStationHero) {
    $stationHeroName = is_string($stationName) && trim($stationName) !== '' ? trim($stationName) : $currentSlug;
    $phoneHeroOffer = [
        'title' => 'Выкуп авто',
        'intro' => 'Выкупаем авто в любом состоянии. Оценка и выезд — бесплатно.',
    ];
}
// Desktop headline proportions follow the existing copy column, with a finite scale per title family.
$desktopHeroTitleScales = [
    'home' => '.1504',
    'vykup-avto' => '.1457',
    'srochnyj-vykup-avto' => '.1504',
    'skupka-avto' => '.1596',
    'prodat-avto' => '.1426',
    'avtovykup' => '.1562',
    'prodat-mashinu-bystro' => '.1419',
    'vykup-bityh-avto' => '.1470',
    'vykup-kreditnyh-avto' => '.1431',
    'vykup-neispravnyh-avto' => '.1400',
    'vykup-avto-posle-dtp' => '.1681',
    'vykup-avto-s-probegom' => '.1570',
    'vykup-staryh-avto' => '.1378',
    'avtovykup-v-spb' => '.0988',
    'metro' => '.1504',
];
$desktopHeroTitleKey = $isHomeHero ? 'home' : ($isStationHero ? 'metro' : ($serviceSlug ?? ''));
$desktopHeroTitleScale = $desktopHeroTitleScales[$desktopHeroTitleKey] ?? '.1504';
// Canonical service paths also work through the existing generic Apache rewrite.
// Redirect only safe navigation methods; keep POST bodies for the form handlers.
if (($isServicePage || $isAutovykupPage || $isCampaignPage) && $requestPath === '/' . $serviceSlug
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /' . $serviceSlug . '/' . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}
$service = $isServicePage ? $servicePages[$serviceSlug] : null;
$serviceHeroTitles = [
    'vykup-bityh-avto' => 'Выкуп битых авто',
    'vykup-kreditnyh-avto' => 'Выкуп кредитных авто',
    'vykup-neispravnyh-avto' => 'Выкуп неисправных авто',
    'vykup-avto-posle-dtp' => 'Выкуп авто после ДТП',
    'vykup-avto-s-probegom' => 'Выкуп авто с пробегом',
    'vykup-staryh-avto' => 'Выкуп старых авто',
];
if ($isCampaignPage) {
    $isStationPage = false;
    $pageTitle = $campaignPage['title'];
    $pageDescription = $campaignPage['description'];
    $pageH1 = $campaignPage['h1'];
    $pageText = '';
    $canonicalUrl = 'https://avtokai.ru/' . $serviceSlug . '/';
} elseif ($isServicePage) {
    $isStationPage = false;
    $pageTitle = $service['title'];
    $pageDescription = $service['description'];
    $pageH1 = $serviceHeroTitles[$serviceSlug];
    $pageText = '';
    $canonicalUrl = 'https://avtokai.ru/' . $serviceSlug . '/';
} elseif ($isAutovykupPage) {
    $isStationPage = false;
    $pageTitle = 'Автовыкуп в СПб и Ленобласти — до 95% цены | Автокай';
    $pageDescription = 'Автовыкуп в Санкт-Петербурге и Ленинградской области. До 95% рыночной цены. Бесплатная оценка автомобиля. Оставьте заявку на автовыкуп.';
    $pageH1 = 'АВТОВЫКУП В СПБ';
    $pageText = '';
    $canonicalUrl = 'https://avtokai.ru/avtovykup-v-spb/';
} elseif (in_array($requestPath, ['/', '/index.php'], true)) {
    if (!$isStationPage) {
        $pageTitle = 'Срочный выкуп авто в СПб и Ленобласти — до 95% цены | Автокай';
        $pageDescription = 'Срочный выкуп автомобилей в Санкт-Петербурге и Ленинградской области. До 95% рыночной цены. Бесплатная оценка автомобиля. Оставьте заявку на выкуп авто.';
        $pageH1 = 'ВЫКУП АВТО В СПБ ДО 95% РЫНОЧНОЙ ЦЕНЫ';
    }
    $canonicalUrl = 'https://avtokai.ru/' . ($isStationPage ? '?spb=' . rawurlencode($currentSlug) : '');
} else {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><html lang="ru"><meta charset="utf-8"><title>Страница не найдена | Автокай</title><h1>Страница не найдена</h1><a href="/">На главную Автокая</a></html>');
}
$homeAnchorPrefix = '';
function pageEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// WhatsApp Click to Chat requires the international country code, not domestic 8.
// Normalize only this known Russian URL pattern; keep the protected configuration intact.
$publicWhatsAppUrl = preg_replace_callback(
    '~\A(https://wa\.me/)8([0-9]{10})(?=[/?#]|\z)~',
    static fn($match) => $match[1] . '7' . $match[2],
    SITE_WHATSAPP
);

// Use official vestibule landmarks only for the exact reviewed stock articles.
$stationVisitLandmarks = [];
if ($isStationHero && is_string($pageText)) {
    $stationVisitGuides = [
        'Admiralteyskaya' => ['44b7e84d82a33a7b9e29ee9ad8990739a738d2b09673dc054879b576446f3aad', ['Малая Морская улица, д. 4/1']],
        'Akademicheskaya' => ['cecd49a3f6c04a8586e5de898546fadb16bb3de305f041358c56ecc2fe280325', ['проспект Науки, д. 19, корп. 1']],
        'Avtovo' => ['1909ce3437b7bd536e807d5bfe9bfd1783734bb10ef5bba11e96b4f4a235c1ea', ['проспект Стачек, д. 90, корп. 2']],
        'Buharestskaya' => ['e1d525a71021d7ffdc3b88c225f4ae5e60cdad2677d17b9fdcd1ca6ad37f5a07', ['Бухарестская улица, д. 30']],
        'Chkalovskaya' => ['15cab83c3bdfb3b02dacfcb8fb9708e967e2478318d17d4d1cec668c47e9b94d', ['Большая Зеленина улица, д. 12']],
        'Chyornaya_rechka' => ['06a0d2757f8018c0179e572f06d11c7df03b61ad537a6c32e894b16d24b43fed', ['улица Савушкина, д. 2']],
        'Devyatkino' => ['0a35be4172b0f61a724cb247aa48c65fd6e5a9de3d1fa4b042d4d6befb4017dc', ['Мурино, Привокзальная площадь, д. 6']],
        'Dostoevskaya' => ['19a0b677c93bdcb21c3f137e1ceb7ed621c10556aba11b7d4386d9c1e1b5d7f7', ['Владимирский проспект, д. 21']],
        'Dunayskaya' => ['f5f9488c71f03a67b2a76930c26446d5e9687523f41dc61fcfb003afc4a89a5c', ['Бухарестская улица, д. 83, сооружение 1']],
        'Elektrosila' => ['1a632fc69a1eecdd1adf4dddeb586177673a33f8006341e2f6ae4418e9ff52a9', ['Московский проспект, д. 141а']],
        'Elizarovskaya' => ['8ba4746f9bf2dd13ac9e88e7b7e80192b52a02e2c3f770e51f2d5d05fe7dc4b6', ['улица Бабушкина, д. 10']],
        'Frunzenskaya' => ['22c319615f0f7ec2534e4b3c620b2aea84b0392a86d9d5a8bba887f96ca1e6fc', ['Московский проспект, д. 71']],
        'Gornyy_institut' => ['967e1f09af138ccbce73f7da1b7f0ca418072dd6f4fd1b1dccf4c731548dc7b6', ['Большой проспект В. О., д. 66, стр. 1']],
        'Gostinyy_dvor' => ['bdfafc47082085897759c9f8b5dc18b55b9da7ab9e12cf2ffc846efd146c608e', ['Невский проспект, д. 35']],
        'Grazhdanskiy_prospekt' => ['d4ee02093d3cd23e6fb504046256166a0a81f8c9436adf747987b85dc89a92a3', ['Гражданский проспект, д. 116, корп. 1']],
        'Kirovskiy_zavod' => ['46f47c0e40f885b2281f71d162544e6b787d30f9b80b273f5b701b5fb7b069a3', ['проспект Стачек, д. 70']],
        'Komendantskiy_prospekt' => ['6c1215e7f7e86e2d04b62e88762164de1ba4d1cd8db20d0deb1db9ad53234dc8', ['Комендантский проспект, д. 12, сооружение 1']],
        'Kupchino' => ['9a12f151d33db4d1856d9e6e5d24bd9fc2dc2bedd32f9602a40e6c95fa29ecd5', ['Витебский проспект, д. 108']],
        'Ladozhskaya' => ['fd0194edee7a35636a2aa8a7e1d9001116c5f5a29e756612f07dfff397370687', ['Заневский проспект, д. 69']],
        'Leninskiy_prospekt' => ['20680606f4b64385f3ac52db7eaa0d600ddfeb034de379bad48b1d27e8fe2171', ['бульвар Новаторов, сооружение 1']],
        'Ligovskiy_prospekt' => ['065a98fcc2975f1f00772afd911502b0dcbcb8297d0e8ad730a4c96caf90722f', ['Лиговский проспект, д. 88']],
        'Lomonosovskaya' => ['becb6441344916b57c92dbc8f450a10413c0013aa661279937a54d7878c62172', ['улица Бабушкина, д. 69']],
        'Mezhdunarodnaya' => ['48027cb6d0634e1ed165c601106d5b471301852b49590a00f144c23da428e5e8', ['улица Белы Куна, д. 3']],
        'Moskovskie_vorota' => ['52ccbbd6f698baea4cd480d38f2696c23d5c03c899098dd7a8ce1d0cb76ae959', ['Московский проспект, д. 103']],
        'Narvskaya' => ['7cb0c62f9cbc68597826f361ad72a3176550328cc00d943a3e2412c52aa4e27e', ['площадь Стачек, д. 2']],
        'Nevskiy_prospekt' => ['e7b65f891c0e12c54dcdde57e0a4e0d62f8d217d4acb16df225941d8657b5502', ['Невский проспект, сооружение 2', 'Невский проспект, д. 30']],
        'Novocherkasskaya' => ['81b92f15f6c3a1195dfe142f5829ba74cc3794dce644b6abda590e60087e0e60', ['Заневская площадь, сооружение 1']],
        'Obuhovo' => ['85d437462830dd0aa64b65c1a6fe268975658317b4ff5101b94e866a21972b83', ['улица Грибакиных, д. 27']],
        'Obvodnyy_kanal' => ['1f7d077b3ecaf5dcf829e673596596e16fb0ee0239b1419e98d30de1042904db', ['Лиговский проспект, д. 153']],
        'Ozerki' => ['1ad6997a8e323aa1a69ea5e3aa030c37f9db5214363fd75e86f3974eaf848a05', ['Выборгское шоссе, д. 1']],
        'Park_Pobedy' => ['8be3093fb3f3a8026a0d19efa9d894e1fc13c145a9d42086ed006f466b4f4aed', ['Московский проспект, д. 188']],
        'Parnas' => ['490a038eb163795caadcf1d2cff18930ff9a15436f8fc38ce5615bec752838c3', ['пос. Парголово, проспект Энгельса, д. 162']],
        'Petrogradskaya' => ['a0913ba35b426dbef25662a52b2ea8f2a1771060c054384d2ce46c88fdbe39ff', ['Каменноостровский проспект, д. 37']],
        'Pionerskaya' => ['84353c1f219e69c7dcf2d9d336bf9f12862be1d2623d1e193164e30cbb1107a6', ['Коломяжский проспект, д. 24']],
        'Ploshchad_Aleksandra_Nevskogo-1' => ['e872629fb6a668004a6009efdd1f8deb5a04384b916a5a04f6d264cd2270eb40', ['площадь Александра Невского, д. 2']],
        'Ploshchad_Aleksandra_Nevskogo-2' => ['d9477d73bc13414c94412df1dc07b409e72fb7f3bec4008ef9111af3776ba705', ['Чернорецкий переулок, д. 3']],
        'Ploshchad_Lenina' => ['baf1cbaa5372c5d4013af908b1850086984f939e580c8fb6bc9f4f9feaa6a6b7', ['площадь Ленина, д. 6', 'Боткинская улица, д. 5']],
        'Ploshchad_Muzhestva' => ['f2a60dcb01be6abba1c4e16ddfc07858a0117eea8b3b62d6586b4862294dd144', ['Политехническая улица, д. 17, корп. 1']],
        'Ploshchad_Vosstaniya' => ['03780fd27e1fea095464946520e7db22698f5ae835dde9d0fc981d3edc723396', ['площадь Восстания, д. 2', 'Невский проспект, д. 85']],
        'Politehnicheskaya' => ['cc48fcc54831ec6a3354cc3cc3757210e5915c4f1e48e33705bce4369c02f9dd', ['Политехническая улица, д. 29а']],
        'Proletarskaya' => ['99931fa4e4123657da8d08856c0edd39f2203854e8a2c72c1154ca87ed5b5794', ['проспект Обуховской Обороны, д. 114, корп. 1']],
        'Prospekt_Bolshevikov' => ['f94acb2af9c461aaa39a57f4396eb08f4719542244bf4f8452207593bbcebdc7', ['улица Коллонтай, д. 20']],
        'Prospekt_Prosveshcheniya' => ['9d35bf5ceddb86c68f873741ca80ff7ffda3db47509be04c98e2cb7e044f0850', ['проспект Энгельса, д. 140']],
        'Prospekt_Slavy' => ['668fd4f21b93c0c6bbd17437a4545591b7e65ca4c1856ecb5fdd17501d2639ea', ['Бухарестская улица, д. 100, сооружение 1']],
        'Prospekt_Veteranov' => ['ac0facfc913bdb875c2832a882768b4bc81becc7e370d36ee4d69c618078eed1', ['бульвар Новаторов, сооружение 2']],
        'Pushkinskaya' => ['1778d4cf2e6741016ecc24b1cf09eb5ac1489b660b9505cf2bbf5ceb433016cd', ['Загородный проспект, д. 52а']],
        'Putilovskaya' => ['8719a159e4369d91ebe9064bde73905c62128ffa622438d014c4442df1f46e61', ['улица Васи Алексеева, д. 1, корп. 2, стр. 1']],
        'Rybatskoe' => ['ec68e6664395944998e5def8a240c01471b7cb95fe35cc2bbbef5176429f166d', ['Тепловозная улица, д. 31']],
        'Sadovaya' => ['639726599ee2e7a7973bbc4cfc890f81aa27db4b502520b5c472ca2f3f2fd9ee', ['Сенная площадь, д. 7, сооружение 3']],
        'Sennaya_ploshchad' => ['b1a1bcd3faafc04fe5c871dc4994dfb0251eac61e17ad8c5287d66539fa3b3fb', ['Сенная площадь, д. 2']],
        'Shushary' => ['5b02bd26c1797a4b0c26add2678222e15c1f3985c3b936192e99637c48466bec', ['пос. Шушары, Софийская улица, д. 113, стр. 20']],
        'Spasskaya' => ['ea7fb90d630a4ec163267d34d0486491a4384f5fc8aad7fdc5ad782970ba1af9', ['Сенная площадь, д. 2, стр. 2']],
        'Sportivnaya' => ['64a2a29ff0f4a8df8f2b3c9362088a9e2f8c7f966e77780836f951fbaa943d66', ['проспект Добролюбова, сооружение 1', '1-я линия В. О., сооружение 58/1']],
        'Staraya_Derevnya' => ['745673cf6a018b889a69c76aed1fbcf29b9b1642786d50f2b6a173d1a34ad8b8', ['Торфяная дорога, д. 4']],
        'Tehnologicheskiy_institut' => ['8a8b79c9d311d371a17ed089ee32f822bae6e0bbfa4fdc733d5fb32d4eef48ca', ['Московский проспект, д. 28']],
        'Udelnaya' => ['9ae386f53e6512976b654fc027f505b7c665ae931a228c9144decd858d354cd0', ['Елецкая улица, д. 15а']],
        'Ulitsa_Dybenko' => ['adccf344cfe239e18d82db59212b552c87dc674fb2225c52570f050c54d59aad', ['улица Дыбенко, д. 26']],
        'Vasileostrovskaya' => ['2c5c217323c790417f322008e2c5015f4bde76b8928b547c0b271b737390ff85', ['Средний проспект В. О., д. 32']],
        'Vladimirskaya' => ['22d47059cfae97c6f0ec208edca5d63448b3a6396d8114dc48fa7f34eba2abe3', ['Большая Московская улица, д. 2/1']],
        'Volkovskaya' => ['497f7bd68fd3f52e6c9acb87d6e910eff2a37c588499d45d56bc854dcc86ae02', ['Касимовская улица, д. 7']],
        'Yugo-Zapadnaya' => ['259bc37cfadf9dbcfd27d5ca79d1580d636650061388e657af7271d5475cd481', ['улица Маршала Казакова, д. 18, стр. 1']],
        'Zvenigorodskaya' => ['3bd2001bbd3c16ddb81daa310b18769bd51cdbbdd8dbec60f0a88a1d78079273', ['Звенигородская улица, д. 1, корп. 2']],
        'Zvyozdnaya' => ['743e4154271a586e63580770e00060a7cad48de0e10c02f5a710135fab5b6caf', ['Звёздная улица, д. 3, корп. 1']],
    ];
    $guide = $stationVisitGuides[$currentSlug] ?? null;
    if ($guide !== null && hash('sha256', $pageText) === $guide[0]) {
        $stationVisitLandmarks = $guide[1];
    }
}

// Public page presentation: normalize legacy station templates without masking custom SEO edits.
if ($isStationHero) {
    $stationSeo = $seoData[$currentSlug];
    $legacyStationTitle = 'Выкуп авто в ' . $stationHeroName . ' | Срочный выкуп автомобилей | Автокай СПб';
    $legacyStationDescription = 'Срочный выкуп автомобилей в ' . $stationHeroName . '. Деньги сразу за 30 минут. Бесплатный эвакуатор. Любое состояние — битые, кредитные, не на ходу. Оценка онлайн. Звоните!';
    if (!is_string($stationSeo['title'] ?? null) || trim($stationSeo['title']) === '' || $pageTitle === $legacyStationTitle) {
        $pageTitle = 'Выкуп авто у метро «' . $stationHeroName . '» | Автокай';
    }
    if (!is_string($stationSeo['description'] ?? null) || trim($stationSeo['description']) === '' || $pageDescription === $legacyStationDescription) {
        $stationCity = $currentSlug === 'Devyatkino' ? 'Мурино' : 'СПб';
        $pageDescription = 'Выкуп авто у метро «' . $stationHeroName . '» в ' . $stationCity . '. Бесплатная оценка и выезд к автомобилю. Договор и расчёт после согласования цены, эвакуатор для авто не на ходу.';
    }
    if (!is_string($stationSeo['h1'] ?? null) || trim($stationSeo['h1']) === '') {
        $pageH1 = 'Выкуп авто у метро «' . $stationHeroName . '» в Санкт-Петербурге';
    } else {
        $stationLocationPattern = '~(?<![\p{L}\p{N}_])в\s+' . preg_quote($stationHeroName, '~') . '(?![\p{L}\p{N}_-])~iu';
        $pageH1 = preg_replace_callback($stationLocationPattern, static fn() => 'у метро «' . $stationHeroName . '»', $pageH1) ?? $pageH1;
    }
}
if ($isCampaignPage && $serviceSlug === 'vykup-avto') {
    $phoneHeroOffer = array_replace($phoneHeroOffer, ['title' => 'Выкуп автомобилей', 'variant' => 'catalog']);
    $pageTitle = 'Выкуп автомобилей в СПб и Ленобласти — оценка | Автокай';
    $pageH1 = 'Выкуп автомобилей в Санкт-Петербурге и Ленинградской области';
}

// Topic-specific guidance and FAQs for the advertising landings.
$campaignSeoProfiles = [
    'vykup-avto'=> [
        'context_h2'=> 'Выкуп автомобилей с учётом их состояния',
        'intro'=> 'Начните с оценки вашей машины: учтём пробег, комплектацию и известные дефекты, затем обсудим предложение.',
        'cards'=> [
            [
                'title'=> 'Характеристики автомобиля',
                'text'=> 'Марка, модель, год выпуска и комплектация задают основу для сравнения с похожими автомобилями.',
                'icon'=> 'fa-car',
            ],
            [
                'title'=> 'Оценка по фото и на осмотре',
                'text'=> 'Фотографии помогают обсудить предварительную стоимость. Итоговое предложение определяем после осмотра.',
                'icon'=> 'fa-calculator',
            ],
            [
                'title'=> 'Текущее состояние',
                'text'=> 'Сообщите о повреждениях и неисправностях. Машину можно предложить к выкупу без предварительного ремонта.',
                'icon'=> 'fa-tools',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Какие машины можно предложить к выкупу?',
                'answer'=> 'Рассматриваем автомобили с пробегом, повреждениями и неисправностями, в том числе старые и не на ходу. Укажите данные и состояние вашей машины — обсудим её отдельно.',
            ],
            [
                'question'=> 'От чего зависит предложение по цене?',
                'answer'=> 'Учитываем модель, год выпуска, комплектацию, пробег и фактическое состояние. Фотографии дают основу для предварительной оценки; итоговую цену обсуждаем после осмотра.',
            ],
            [
                'question'=> 'Нужно ли сначала ремонтировать автомобиль?',
                'answer'=> 'Для обращения предварительный ремонт не требуется. Опишите известные дефекты и приложите фотографии: оценим автомобиль в его текущем состоянии.',
            ],
        ],
        'footer'=> 'Выкуп автомобилей в Санкт-Петербурге и Ленинградской области начинается с оценки конкретной машины. Отправьте её данные и фотографии, чтобы обсудить состояние, осмотр и предложение по цене.',
    ],
    'srochnyj-vykup-avto'=> [
        'context_h2'=> 'Как организовать срочный выкуп авто',
        'intro'=> 'Если продажу нужно организовать без промедления, сообщите желаемый срок и место стоянки — согласуем дальнейшие шаги.',
        'cards'=> [
            [
                'title'=> 'Желаемый срок продажи',
                'text'=> 'Укажите, к какому сроку хотите продать машину. Возможность осмотра и оформления обсудим при обращении.',
                'icon'=> 'fa-clock',
            ],
            [
                'title'=> 'Место и доступ к машине',
                'text'=> 'Сообщите адрес стоянки и может ли автомобиль передвигаться. Это поможет подготовить осмотр.',
                'icon'=> 'fa-map-marker-alt',
            ],
            [
                'title'=> 'Условия до оформления',
                'text'=> 'Заранее обсудим состояние машины, предварительную оценку и порядок действий, чтобы не упустить важные детали.',
                'icon'=> 'fa-check-circle',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Что указать, если продажа нужна срочно?',
                'answer'=> 'Сообщите марку, модель, год, пробег, состояние и место стоянки. Добавьте фотографии и желаемый срок продажи — эти данные помогут обсудить возможность выкупа.',
            ],
            [
                'question'=> 'Как согласовать время осмотра?',
                'answer'=> 'Время и место осмотра согласовываем при обсуждении заявки. Укажите удобные для вас варианты и ограничения по доступу к автомобилю.',
            ],
            [
                'question'=> 'Цена по фотографиям окончательная?',
                'answer'=> 'Нет, по фото обсуждаем предварительную стоимость. Итоговое предложение зависит от результатов осмотра; цену согласовываем до оформления выкупа.',
            ],
        ],
        'footer'=> 'Срочный выкуп авто в СПб и Ленобласти требует понятных исходных данных. Расскажите о машине, месте стоянки и желаемом сроке: обсудим оценку и доступные варианты осмотра.',
    ],
    'skupka-avto'=> [
        'context_h2'=> 'Какие автомобили рассматриваем при скупке',
        'intro'=> 'Сравниваем характеристики и фактическое состояние: у исправной машины, автомобиля после ДТП и авто с поломкой разные особенности оценки.',
        'cards'=> [
            [
                'title'=> 'Исправные автомобили',
                'text'=> 'Для машины на ходу важны пробег, комплектация, обслуживание и состояние кузова и салона.',
                'icon'=> 'fa-car',
            ],
            [
                'title'=> 'Авто с большим пробегом',
                'text'=> 'Оцениваем не только показания одометра, но и износ основных узлов, особенности эксплуатации и известные дефекты.',
                'icon'=> 'fa-tachometer-alt',
            ],
            [
                'title'=> 'Машины с повреждениями',
                'text'=> 'Пришлите общий вид автомобиля и крупные планы повреждений. Отдельно сообщите, может ли он передвигаться.',
                'icon'=> 'fa-crash',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Рассматриваете машины разных марок и возрастов?',
                'answer'=> 'Да, можно предложить к оценке автомобили разных марок и годов выпуска. Стоимость обсуждаем по характеристикам и состоянию конкретной машины.',
            ],
            [
                'question'=> 'Можно предложить автомобиль после ДТП?',
                'answer'=> 'Да, рассматриваем и повреждённые машины. Добавьте фотографии со всех сторон и крупные планы дефектов, укажите известные неисправности и может ли авто передвигаться.',
            ],
            [
                'question'=> 'Влияет ли комплектация на оценку?',
                'answer'=> 'Да, комплектация учитывается вместе с годом выпуска, пробегом и состоянием. Укажите двигатель, коробку передач и значимое оборудование, если эти данные известны.',
            ],
        ],
        'footer'=> 'Скупка автомобилей в Санкт-Петербурге и Ленинградской области охватывает машины с разным пробегом и состоянием. Для предметной оценки нужны данные автомобиля и фотографии его особенностей.',
    ],
    'prodat-avto'=> [
        'context_h2'=> 'Как подготовиться к продаже автомобиля',
        'intro'=> 'Продажа начинается с понятного предложения: расскажите о машине, получите оценку и обсудите условия перед оформлением.',
        'cards'=> [
            [
                'title'=> 'Прямое обращение',
                'text'=> 'Чтобы обсудить продажу Автокаю, достаточно оставить заявку с данными автомобиля и контактом для связи.',
                'icon'=> 'fa-user',
            ],
            [
                'title'=> 'Понятная оценка',
                'text'=> 'Сообщите пробег, комплектацию и известные дефекты. Обсудим, как эти особенности учитываются в предложении.',
                'icon'=> 'fa-calculator',
            ],
            [
                'title'=> 'Согласованные условия',
                'text'=> 'До оформления обсудим стоимость, способ расчёта и сведения, необходимые для подготовки договора.',
                'icon'=> 'fa-file-signature',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Нужно ли размещать объявление перед обращением?',
                'answer'=> 'Нет, обратиться в Автокай можно напрямую. Укажите данные машины и приложите фотографии — наличие объявления для первоначальной оценки не требуется.',
            ],
            [
                'question'=> 'Как понять, подходит ли мне предложение?',
                'answer'=> 'Сначала обсудим предварительную оценку, затем состояние на осмотре и итоговую сумму. До оформления вы сможете уточнить условия и решить, подходит ли вам продажа.',
            ],
            [
                'question'=> 'О каких особенностях машины нужно сообщить?',
                'answer'=> 'Укажите известные повреждения, поломки и особенности документов. Если машина в кредите или залоге, сообщите об этом заранее: порядок сделки потребуется согласовать с банком.',
            ],
        ],
        'footer'=> 'Продать авто в СПб и Ленобласти можно через прямое обращение в Автокай. Начните с данных и фотографий машины, затем обсудите оценку и условия продажи до оформления договора.',
    ],
    'avtovykup'=> [
        'context_h2'=> 'Этапы автовыкупа: от заявки до расчёта',
        'intro'=> 'Выкуп проходит по согласованным этапам: знакомство с машиной, осмотр, обсуждение цены и оформление сделки.',
        'cards'=> [
            [
                'title'=> 'Заявка и фотографии',
                'text'=> 'Отправьте характеристики машины и фото. По этим данным обсудим состояние и предварительную оценку.',
                'icon'=> 'fa-camera',
            ],
            [
                'title'=> 'Осмотр и договор',
                'text'=> 'После осмотра согласуем итоговую стоимость и условия, затем подготовим оформление выкупа.',
                'icon'=> 'fa-file-signature',
            ],
            [
                'title'=> 'Способ расчёта',
                'text'=> 'Доступны наличные или перевод. Подходящий способ и порядок оплаты согласовываем до оформления.',
                'icon'=> 'fa-money-bill-wave',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Из каких этапов состоит автовыкуп?',
                'answer'=> 'Вы оставляете заявку и данные машины. Затем обсуждаем предварительную оценку, согласуем осмотр, итоговую цену и условия. При согласии сторон оформляем договор и расчёт.',
            ],
            [
                'question'=> 'Когда обсуждается способ оплаты?',
                'answer'=> 'Способ и порядок расчёта согласовываем до оформления сделки. Можно обсудить наличные или перевод с учётом условий конкретного выкупа.',
            ],
            [
                'question'=> 'Можно начать оценку кредитного автомобиля?',
                'answer'=> 'Да, сообщите данные машины, банк и известный остаток кредита. Стоимость автомобиля оценивается отдельно, а порядок погашения и расчётов согласовывается с банком до сделки.',
            ],
        ],
        'footer'=> 'Автовыкуп в Санкт-Петербурге и Ленинградской области включает оценку, осмотр и оформление согласованных условий. Данные машины и фотографии помогут начать обсуждение сделки.',
    ],
    'prodat-mashinu-bystro'=> [
        'context_h2'=> 'Что подготовить для быстрой продажи машины',
        'intro'=> 'Полная заявка помогает сократить уточнения: добавьте фотографии, основные характеристики и честное описание состояния.',
        'cards'=> [
            [
                'title'=> 'Покажите машину со всех сторон',
                'text'=> 'Добавьте общий вид кузова, салон и крупные планы дефектов. Хорошо различимые детали помогут обсудить оценку.',
                'icon'=> 'fa-camera',
            ],
            [
                'title'=> 'Соберите основные данные',
                'text'=> 'Укажите марку, модель, год, пробег и комплектацию. Если какие-то сведения неизвестны, сообщите об этом.',
                'icon'=> 'fa-file-invoice',
            ],
            [
                'title'=> 'Уточните детали осмотра',
                'text'=> 'Оставьте контакт для связи, место стоянки и удобное время. Сообщите, заводится ли автомобиль и может ли ехать.',
                'icon'=> 'fa-phone',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Какие фотографии подготовить для оценки?',
                'answer'=> 'Снимите машину со всех сторон, салон и известные повреждения крупным планом. Лучше прислать актуальные фото, на которых хорошо различимо текущее состояние автомобиля.',
            ],
            [
                'question'=> 'Какие сведения помогают избежать уточнений?',
                'answer'=> 'Марка, модель, год, пробег, комплектация и известные неисправности. Также полезны место стоянки, возможность передвижения автомобиля и удобное время для связи.',
            ],
            [
                'question'=> 'Нужно привозить машину для первого обращения?',
                'answer'=> 'Начать можно с заявки и фотографий, без поездки с автомобилем. После предварительного обсуждения согласуем, где и когда осмотреть машину.',
            ],
        ],
        'footer'=> 'Чтобы быстрее перейти к обсуждению продажи машины в СПб и Ленобласти, подготовьте её данные и фотографии. Полное описание поможет оценить состояние и согласовать осмотр с меньшим числом уточнений.',
    ],
    'avtovykup-v-spb'=> [
        'context_h2'=> 'Автовыкуп с осмотром в Санкт-Петербурге и области',
        'intro'=> 'Сообщите, где находится автомобиль: согласуем место осмотра в Санкт-Петербурге или Ленинградской области.',
        'cards'=> [
            [
                'title'=> 'Город и Ленинградская область',
                'text'=> 'Укажите район или населённый пункт и адрес стоянки. Возможность и время выезда обсудим по вашей заявке.',
                'icon'=> 'fa-map-marker-alt',
            ],
            [
                'title'=> 'Осмотр у автомобиля',
                'text'=> 'Заранее сообщите об особенностях доступа: закрытая территория, паркинг или ограничения на въезд.',
                'icon'=> 'fa-car',
            ],
            [
                'title'=> 'Автомобиль не на ходу',
                'text'=> 'Опишите место стоянки и состояние колёс. Эти сведения нужны, чтобы согласовать осмотр и перевозку машины.',
                'icon'=> 'fa-tools',
            ],
        ],
        'faqs'=> [
            [
                'question'=> 'Выезжаете ли за пределы Санкт-Петербурга?',
                'answer'=> 'Да, работаем также в Ленинградской области. Сообщите населённый пункт и место стоянки — обсудим возможность и время осмотра по вашему адресу.',
            ],
            [
                'question'=> 'Можно согласовать осмотр у места стоянки?',
                'answer'=> 'Сообщите адрес и удобные варианты времени. Если автомобиль находится на закрытой территории или в паркинге, заранее уточните условия доступа для осмотра.',
            ],
            [
                'question'=> 'Что указать, если машина не на ходу?',
                'answer'=> 'Опишите неисправность, место стоянки, состояние колёс и доступ к автомобилю. Для машин не на ходу предусмотрен бесплатный эвакуатор; детали перевозки согласовываем заранее.',
            ],
        ],
        'footer'=> 'Автокай проводит оценку и выкуп автомобилей в Санкт-Петербурге и Ленинградской области. Укажите место стоянки и особенности доступа к машине, чтобы согласовать осмотр и при необходимости перевозку.',
    ],
];
$pageContext = null;
$pageFaqs = null;
$pageFaqTitle = 'Вопросы о выкупе автомобиля';
$pageRelatedServices = [];
$footerTopicTitle = $pageH1;
$footerTopicParagraphs = [$pageDescription];
if ($isServicePage) {
    $contextIcons = ['fa-car', 'fa-tools', 'fa-camera', 'fa-check-circle'];
    $contextCards = [];
    foreach ($service['vehicles'] as $index => $vehicle) {
        $contextCards[] = $vehicle + ['icon' => $contextIcons[$index % count($contextIcons)]];
    }
    $pageContext = ['context_h2' => $service['vehicles_title'], 'intro' => $service['vehicles_intro'], 'cards' => $contextCards];
    $pageFaqs = $service['faq'];
    $pageFaqTitle = $service['faq_title'];
    $pageRelatedServices = $service['related'];
    $footerTopicParagraphs = [$service['intro'], $service['pricing_intro']];
} elseif ($isCampaignPage || $isAutovykupPage) {
    $profileSlug = $isAutovykupPage ? 'avtovykup-v-spb' : $serviceSlug;
    $pageContext = $campaignSeoProfiles[$profileSlug];
    $pageFaqs = $pageContext['faqs'];
    $pageFaqTitle = 'Ответы на вопросы: ' . ($isAutovykupPage ? 'Автовыкуп в СПб' : $campaignPage['hero_title']);
    $pageRelatedServices = match ($profileSlug) {
        'vykup-avto' => ['skupka-avto', 'vykup-avto-s-probegom', 'vykup-bityh-avto', 'vykup-neispravnyh-avto'],
        'srochnyj-vykup-avto' => ['prodat-mashinu-bystro', 'avtovykup-v-spb'],
        'skupka-avto' => ['vykup-avto-s-probegom', 'vykup-bityh-avto', 'prodat-avto'],
        'prodat-avto' => ['prodat-mashinu-bystro', 'vykup-kreditnyh-avto'],
        'prodat-mashinu-bystro' => ['srochnyj-vykup-avto', 'prodat-avto'],
        'avtovykup-v-spb' => ['srochnyj-vykup-avto', 'vykup-neispravnyh-avto'],
        default => [],
    };
    $footerTopicParagraphs = [$pageContext['footer'], 'Предварительная оценка по фотографиям помогает обсудить предложение. Итоговую цену и порядок расчёта согласовываем после осмотра автомобиля.'];
} elseif ($isStationHero) {
    $pageFaqTitle = 'Вопросы о выкупе у метро';
    $pageFaqs = [
        ['question' => 'Как продать авто у метро «' . $stationHeroName . '»?', 'answer' => 'Укажите марку, модель, год, пробег и место стоянки. После предварительной оценки по фотографиям согласуем осмотр автомобиля и обсудим условия выкупа.'],
        ['question' => 'Можно начать оценку без поездки с автомобилем?', 'answer' => 'Да, начните с данных машины и актуальных фотографий. Оценка по фото предварительная; окончательное предложение обсуждаем после осмотра.'],
        ['question' => 'Что сообщить, если автомобиль не на ходу?', 'answer' => 'Опишите неисправность, место стоянки и доступ к машине. Для автомобиля не на ходу предусмотрен бесплатный эвакуатор; время и детали перевозки согласуем заранее.'],
    ];
    $footerTopicParagraphs[] = 'Оценщик выезжает к автомобилю. Сообщите точное место стоянки и особенности доступа, чтобы согласовать осмотр и при необходимости перевозку машины.';
}
$showServiceDetails = $isAutovykupPage
    || ($isCampaignPage && $serviceSlug !== 'avtovykup')
    || ($isServicePage && $serviceSlug !== 'vykup-avto-posle-dtp');
$relatedServiceTitles = $serviceHeroTitles + array_map(static fn($page) => $page['hero_title'], $campaignPages)
    + ['avtovykup-v-spb' => 'Автовыкуп в СПб'];
$pageRelatedServices = array_values(array_filter($pageRelatedServices, static fn($slug) => is_string($slug) && isset($relatedServiceTitles[$slug]) && $slug !== $serviceSlug));
$pageBreadcrumbs = [];
if (!$isHomeHero) {
    $pageBreadcrumbs[] = ['name' => 'Главная', 'url' => 'https://avtokai.ru/'];
    if ($isServicePage) $pageBreadcrumbs[] = ['name' => 'Выкуп автомобилей', 'url' => 'https://avtokai.ru/vykup-avto/'];
    $breadcrumbName = $isStationHero ? 'У метро «' . $stationHeroName . '»'
        : ($isServicePage ? $serviceHeroTitles[$serviceSlug] : ($isCampaignPage ? $campaignPage['hero_title'] : 'Автовыкуп в СПб'));
    $pageBreadcrumbs[] = ['name' => $breadcrumbName, 'url' => $canonicalUrl];
}

// Chrome's requested desktop mode declares a desktop UA with a 980px viewport.
// Keep the normal mobile/tablet layout; use one shared threshold for desktop requests.
$heroRequestAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$heroDeclaredDesktop = is_string($heroRequestAgent) && strlen($heroRequestAgent) <= 2048
    && preg_match('/(?:Windows NT|X11|Macintosh|CrOS)/i', $heroRequestAgent) === 1
    && preg_match('/(?:Mobile|Android|iPhone|iPad|iPod)/i', $heroRequestAgent) === 0;
$phoneHeroMobileMaxWidth = $isPhoneHero && $heroDeclaredDesktop ? 979 : 1200;
$phoneHeroDesktopMinWidth = $phoneHeroMobileMaxWidth + 1;
if ($isPhoneHero) header('Vary: User-Agent', false);

// ============================================
// HTML ЛЕНДИНГ
// ============================================
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="google-site-verification" content="Qtt_eQi9Y5hzIkSDKlEXg9WsEuWZ9JVgNvK6f9U6BNE">
    <meta name="yandex-verification" content="e994e03af4673d5c">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <script id="logo-reload-top">
    (() => {
        const storageKey = 'avtokai_logo_reload_top_v1';
        const page = window.location.pathname + window.location.search;
        let pending = null;
        let reloading = false;
        try {
            const raw = window.sessionStorage.getItem(storageKey);
            if (raw !== null) {
                window.sessionStorage.removeItem(storageKey);
                const value = JSON.parse(raw);
                const age = Date.now() - value?.createdAt;
                if (value?.version === 1 && value.page === page
                    && (value.previousMode === 'auto' || value.previousMode === 'manual')
                    && Number.isFinite(age) && age >= 0 && age <= 120000) pending = value;
            }
        } catch (_) { /* Storage can be unavailable; the logo still reloads. */ }
        function scrollTopNow() {
            try { window.scrollTo({top: 0, left: 0, behavior: 'instant'}); } catch (_) {}
        }
        function setRestoration(mode) {
            try {
                if ('scrollRestoration' in window.history) window.history.scrollRestoration = mode;
            } catch (_) {}
        }
        function nextFrame(callback) {
            try { window.requestAnimationFrame(callback); } catch (_) { callback(); }
        }
        if (pending) {
            // Reset early, then restore the mode before later scripts push entries.
            setRestoration('manual');
            scrollTopNow();
            setRestoration(pending.previousMode);
            window.addEventListener('pageshow', () => {
                scrollTopNow();
                nextFrame(() => {
                    scrollTopNow();
                    nextFrame(() => {
                        scrollTopNow();
                        setRestoration(pending.previousMode);
                    });
                });
            }, {once: true});
        }
        window.reloadCurrentPageFromTop = () => {
            if (reloading) return;
            reloading = true;
            let previousMode = 'auto';
            try {
                if (window.history.scrollRestoration === 'manual') previousMode = 'manual';
            } catch (_) {}
            const request = {version: 1, page: window.location.pathname + window.location.search,
                previousMode, createdAt: Date.now()};
            let saved = false;
            try {
                const encoded = JSON.stringify(request);
                window.sessionStorage.setItem(storageKey, encoded);
                saved = window.sessionStorage.getItem(storageKey) === encoded;
            } catch (_) {}
            // A failed one-shot write must not leave manual restoration enabled.
            if (saved) setRestoration('manual');
            try {
                let state = window.history.state;
                if (state && typeof state === 'object'
                    && Object.prototype.hasOwnProperty.call(state, '__avtokaiModalV1')) {
                    state = Array.isArray(state) ? Object.assign(state.slice(), state) : {...state};
                    delete state.__avtokaiModalV1;
                }
                window.history.replaceState(state, '', request.page);
            } catch (_) { /* A denied history operation must not prevent reload. */ }
            scrollTopNow();
            window.location.reload();
        };
    })();
    </script>
    
    <title><?= pageEscape($pageTitle) ?></title>
    <meta name="description" content="<?= pageEscape($pageDescription) ?>">
    
    <meta name="author" content="Автокай">
    <meta name="robots" content="index, follow">
    
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Автокай">
    <meta property="og:title" content="<?= pageEscape($pageTitle) ?>">
    <meta property="og:description" content="<?= pageEscape($pageDescription) ?>">
    <meta property="og:image" content="https://avtokai.ru/img/optimized/logo.webp?v=b841bce2">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:locale" content="ru_RU">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= pageEscape($pageTitle) ?>">
    <meta name="twitter:description" content="<?= pageEscape($pageDescription) ?>">
    <meta name="twitter:image" content="https://avtokai.ru/img/optimized/logo.webp?v=b841bce2">
    <meta property="og:image:width" content="836">
    <meta property="og:image:height" content="470">
    <meta property="og:image:alt" content="Автокай — срочный выкуп автомобилей">
    <meta name="twitter:image:alt" content="Автокай — срочный выкуп автомобилей">
    
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($isPhoneHero): ?>
    <link rel="preload" href="<?= $portraitHeroImageUrl ?>" as="image" type="image/webp" media="(max-width: <?= $phoneHeroMobileMaxWidth ?>px)" fetchpriority="high">
<?php if ($isDesktopPhotoHero): ?>    <link rel="preload" href="/hero-prodat-phone.webp?v=0141e18b9b90" as="image" type="image/webp" media="(min-width: <?= $phoneHeroDesktopMinWidth ?>px)" fetchpriority="high"><?php else: ?>
    <link rel="preload" href="/hero-car.jpg?v=fa086346" as="image" media="(min-width: <?= $phoneHeroDesktopMinWidth ?>px)" fetchpriority="high">
<?php endif; ?>
    <?php else: ?>
    <link rel="preload" href="/hero-car.jpg?v=fa086346" as="image" fetchpriority="high">
    <?php endif; ?>
    <link rel="preload" href="/fonts/fa-solid-900.woff2?v=646458d5" as="font" type="font/woff2" crossorigin>
  
    <style id="system-fonts">
        :root {
            --font-system: -apple-system, BlinkMacSystemFont, 'SF Pro Display',
                           'Helvetica Neue', 'Segoe UI', Roboto, Arial, sans-serif;
            --font-system-bold: -apple-system, BlinkMacSystemFont, 'SF Pro Display',
                                'Helvetica Neue', 'Segoe UI', Roboto, Arial, sans-serif;
        }
        body { font-family: var(--font-system); font-weight: 400; }
        h1, h2, h3, h4, h5, h6, .logo-text, .section-title, .btn, .nav-link,
        .header-phone, .footer-title, .hero-stat-number, .step-number {
            font-weight: 700; font-family: var(--font-system-bold);
        }
        .hero h1, .logo-text { font-weight: 800; }
    </style>
    

    <style id="font-awesome-local">
        .fa, .fa-brands, .fa-regular, .fa-solid, .fab, .far, .fas {
            -moz-osx-font-smoothing: grayscale;
            -webkit-font-smoothing: antialiased;
            display: inline-block;
            font-style: normal;
            font-variant: normal;
            line-height: 1;
            text-rendering: auto;
        }
        
        .fa, .fa-solid, .fas { font-family: "Font Awesome 6 Free"; font-weight: 900; }
        .fa-regular, .far { font-family: "Font Awesome 6 Free"; font-weight: 400; }
        .fa-brands, .fab { font-family: "Font Awesome 6 Brands"; font-weight: 400; }
        
        .fa-spin { animation: fa-spin 2s infinite linear; }
        @keyframes fa-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        @font-face {
            font-family: 'Font Awesome 6 Free';
            font-style: normal;
            font-weight: 900;
            font-display: swap;
            src: url('/fonts/fa-solid-900.woff2?v=646458d5') format('woff2');
        }
        
        @font-face {
            font-family: 'Font Awesome 6 Brands';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/fonts/fa-brands-400.woff2?v=3eedaed8') format('woff2');
        }
    
.fa-calculator:before { content: "\f1ec"; }
.fa-check-circle:before { content: "\f058"; }
.fa-clock:before { content: "\f017"; }
.fa-bolt:before { content: "\f0e7"; }
.fa-user:before { content: "\f007"; }
.fa-car:before { content: "\f1b9"; }
.fa-calendar:before { content: "\f133"; }
.fa-tachometer-alt:before { content: "\f625"; }
.fa-ruble-sign:before { content: "\f158"; }
.fa-lock:before { content: "\f023"; }
.fa-phone:before { content: "\f095"; }
.fa-envelope:before { content: "\f0e0"; }
.fa-map-marker-alt:before { content: "\f3c5"; }
.fa-money-bill-wave:before { content: "\f53a"; }
.fa-truck:before { content: "\f0d1"; }
.fa-subway:before { content: "\f239"; }
.fa-camera:before { content: "\f030"; }
.fa-file-signature:before { content: "\f573"; }
.fa-shield-alt:before { content: "\f3ed"; }
.fa-chevron-down:before { content: "\f078"; }
.fa-chevron-up:before { content: "\f077"; }
.fa-chevron-right:before { content: "\f054"; }
.fa-times:before { content: "\f00d"; }
.fa-spinner:before { content: "\f110"; }
.fa-star:before { content: "\f005"; }
.fa-check:before { content: "\f00c"; }
.fa-building:before { content: "\f1ad"; }
.fa-tools:before { content: "\f7d9"; }
.fa-file-invoice:before { content: "\f570"; }
.fa-key:before { content: "\f084"; }
.fa-flag:before { content: "\f024"; }
.fa-crash:before { content: "\f5e1"; }
.fa-gavel:before { content: "\f0e3"; }
.fa-telegram-plane:before { content: "\f2c6"; }
.fa-telegram:before { content: "\f2c6"; }
.fa-whatsapp:before { content: "\f232"; }
.fa-fire:before { content: "\f06d"; }
    </style>
    <!-- ФАВИКОНЫ -->
    <link rel="icon" type="image/x-icon" href="/ico/favicon.ico?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="57x57" href="/ico/apple-icon-57x57.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="60x60" href="/ico/apple-icon-60x60.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="72x72" href="/ico/apple-icon-72x72.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="76x76" href="/ico/apple-icon-76x76.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="114x114" href="/ico/apple-icon-114x114.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="120x120" href="/ico/apple-icon-120x120.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="144x144" href="/ico/apple-icon-144x144.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="152x152" href="/ico/apple-icon-152x152.png?v=4650c4a1">
    <link rel="apple-touch-icon" sizes="180x180" href="/ico/apple-icon-180x180.png?v=4650c4a1">
    <link rel="icon" type="image/png" sizes="192x192" href="/ico/android-icon-192x192.png?v=4650c4a1">
    <link rel="icon" type="image/png" sizes="32x32" href="/ico/favicon-32x32.png?v=4650c4a1">
    <link rel="icon" type="image/png" sizes="96x96" href="/ico/favicon-96x96.png?v=4650c4a1">
    <link rel="icon" type="image/png" sizes="16x16" href="/ico/favicon-16x16.png?v=4650c4a1">
    <link rel="manifest" href="/ico/manifest.json?v=4650c4a1">
    <meta name="msapplication-TileColor" content="#e53e3e">
    <meta name="msapplication-TileImage" content="/ico/ms-icon-144x144.png?v=4650c4a1">
    <meta name="theme-color" content="#e53e3e">
    
    <!-- ЯНДЕКС МЕТРИКА - ПРАВИЛЬНОЕ РАЗМЕЩЕНИЕ -->
    <script>
    (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
    m[i].l=1*new Date();
    for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
    k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
    (window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

    ym(106799647, 'init', {
        clickmap: true,
        trackLinks: true,
        accurateTrackBounce: true,
        webvisor: true,
        ecommerce: false
    });
    </script>
    
    <style>
        /* ===== ОСНОВНЫЕ СТИЛИ ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            width: 100%;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            line-height: 1.6;
            color: #1a1a1a;
            background: white;
        }
        
        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 25px;
            width: 100%;
        }
        
        :root {
            --red: #e53e3e;
            --red-dark: #c53030;
            --button-red-bg: linear-gradient(180deg, #f05a56 0%, #e34344 22%, #ce2934 65%, #b91f2d 100%);
            --button-red-hover: linear-gradient(180deg, #fa7068 0%, #ed4e4e 22%, #d6323d 65%, #c22432 100%);
            --button-red-shadow: 0 7px 18px rgba(154,24,36,.28), inset 0 1px 0 rgba(255,255,255,.45), inset 0 -2px 0 rgba(105,12,24,.22);
            --dark: #1a1a1a;
            --gray: #5b6471;
            --light: #f7fafc;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
            line-height: 1.4;
            min-height: 58px;
            padding: 16px 36px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            border: 2px solid transparent;
            text-decoration: none;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary,
        .btn-secondary {
            background: var(--button-red-bg);
            color: white;
            border: 1px solid #c62b35;
            border-radius: 999px;
            box-shadow: var(--button-red-shadow);
            transition: transform .16s ease, box-shadow .2s ease, background .2s ease;
        }

        .btn-primary:focus-visible,
        .btn-secondary:focus-visible,
        .seo-toggle:focus-visible {
            outline: 3px solid var(--red); outline-offset: 4px;
        }

        @media (hover: hover) and (pointer: fine) {
            .btn-primary:hover,
            .btn-secondary:hover {
                background: var(--button-red-hover);
                transform: translateY(-2px);
                box-shadow: 0 10px 22px rgba(154,24,36,.32), inset 0 1px 0 rgba(255,255,255,.5), inset 0 -2px 0 rgba(105,12,24,.18);
            }
        }

        .btn-primary:active,
        .btn-secondary:active {
            transform: translateY(1px);
            box-shadow: 0 3px 8px rgba(154,24,36,.22), inset 0 2px 4px rgba(105,12,24,.24);
        }

        .btn-primary:disabled,
        .btn-primary.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
            transform: none !important;
            box-shadow: none !important;
            background: #aaa !important;
            border-color: #aaa;
        }
        
        .btn-block {
            width: 100%;
        }

        .section-cta { display: flex; justify-content: center; margin-top: 36px; }
        .section-cta .btn { min-width: 220px; max-width: 100%; }
        @media (max-width: 768px) {
            .section-cta { margin-top: 28px; }
        }
        
        /* ===== ХЕДЕР ===== */
        .header {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 15px 0;
            border-bottom: 3px solid var(--red);
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .header.scrolled {
            padding: 10px 0;
            background: rgba(26, 26, 26, 0.98);
        }
        
        .header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0;
            border: 0;
            background: transparent;
            font: inherit;
            cursor: pointer;
        }
        .logo-wrapper:focus-visible {
            outline: 2px solid var(--red);
            outline-offset: 5px;
        }
        
        .logo-img {
            display: block;
            width: 196px;
            height: 50px;
            object-fit: cover;
            object-position: center 45%;
        }
        .header-brand { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        
        .burger-menu {
            display: none;
            flex-direction: column;
            justify-content: space-between;
            width: 30px;
            height: 21px;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 1001;
        }
        
        .burger-line {
            width: 100%;
            height: 3px;
            background: white;
            border-radius: 3px;
            transition: all 0.3s ease;
        }
        
        .burger-menu.active .burger-line:nth-child(1) {
            transform: translateY(9px) rotate(45deg);
        }
        
        .burger-menu.active .burger-line:nth-child(2) {
            opacity: 0;
        }
        
        .burger-menu.active .burger-line:nth-child(3) {
            transform: translateY(-9px) rotate(-45deg);
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 40px;
        }
        
        .nav-links {
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .nav-link:after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--red);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--red);
        }
        
        .nav-link:hover:after {
            width: 100%;
        }
        
        .header-phone-block {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .header-phone {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            letter-spacing: 1px;
            transition: color 0.3s ease, transform 0.3s ease;
        }
        
        .header-phone:hover {
            color: var(--red);
            transform: scale(1.05);
        }
        
        .header-callback {
            flex-shrink: 0;
            min-height: 44px;
            padding: 10px 18px;
            font-size: .82rem;
            letter-spacing: 0;
            white-space: nowrap;
        }
        .header-callback i { font-size: .9rem; }
        
        .header-messengers { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .header-messenger {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 44px; flex-shrink: 0; border-radius: 10px;
            color: white; text-decoration: none; transition: transform .2s ease;
        }
        .header-messenger i {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 50%; font-size: 22px;
        }
        .header-messenger svg { display: block; width: 34px; height: 34px; flex-shrink: 0; }
        .header-messenger--whatsapp i { background: #25d366; font-size: 25px; }
        .header-messenger img { display: block; width: 34px; height: 34px; object-fit: contain; border-radius: 9px; }
        .header-messenger:focus-visible { outline: 2px solid white; outline-offset: 3px; }
        @media (hover: hover) and (pointer: fine) {
            .header-messenger:hover { transform: translateY(-2px); }
        }
        @media (max-width: 1280px) {
            .header-messengers { width: 100%; justify-content: flex-start; gap: 12px; }
            .header-messenger { width: 44px; height: 44px; }
            .header-messenger i, .header-messenger img, .header-messenger svg { width: 40px; height: 40px; }
            .header-messenger i { font-size: 25px; }
            .header-messenger--whatsapp i { font-size: 29px; }
            .header-messenger img { border-radius: 11px; }
        }

        /* ===== ГЕРОЙ ===== */
        .hero {
            padding: 180px 0 100px;
            position: relative;
            color: white;
            overflow: hidden;
        }
        
        .hero-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('/hero-car.jpg?v=fa086346');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: blur(8px) brightness(0.6);
            transform: scale(1.1);
            z-index: 1;
            background-color: #5d2626;
            background-blend-mode: overlay;
        }
        
        .hero .container {
            position: relative;
            z-index: 2;
        }
        
        .hero-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 50px;
            align-items: center;
        }

        .hero-content h1 {
            font-size: 3.2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 25px;
            text-transform: uppercase;
        }
        
        .hero-highlight {
            color: var(--red);
            position: relative;
            display: inline-block;
        }
        
        .hero-highlight:after {
            content: '';
            position: absolute;
            bottom: 5px;
            left: 0;
            width: 100%;
            height: 8px;
            background: rgba(229,62,62,0.3);
            z-index: -1;
        }
        
        .hero-subtitle {
            font-size: 1.3rem;
            color: #e2e8f0;
            margin-bottom: 30px;
        }
        
        .hero-features {
            display: flex;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .hero-feature {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .hero-feature i {
            color: var(--red);
            font-size: 1.3rem;
            animation: pulse 2s infinite;
        }
        
        .hero-buttons {
            display: flex;
            gap: 20px;
        }
        
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: clamp(12px, 3vw, 30px);
            margin-top: 50px;
        }
        
        .hero-stat-item {
            text-align: center;
        }
        
        .hero-stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--red);
        }
        
        .hero-stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        @media (min-width: 769px) {
            .hero-stats {
                grid-template-columns: repeat(3, max-content);
                justify-content: start;
                gap: 30px;
            }
        }

        /* ===== ФОРМА ===== */
        .form-card {
            background: white;
            padding: 40px 30px;
            border-radius: 30px;
            box-shadow: 0 30px 60px rgba(0,0,0,0.3);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .form-title {
            color: var(--dark);
            margin-bottom: 25px;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .form-title i {
            color: var(--red);
            margin-right: 10px;
            animation: pulse 2s infinite;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }
        
        .form-group {
            position: relative;
            min-width: 0;
            margin-bottom: 5px;
        }
        
        .form-group input,
        .form-group select,
        .form-group .condition-select-trigger {
            display: block;
            width: 100%;
            min-width: 0;
            min-height: 56px;
            padding: 14px 16px 14px 46px;
            border: 2px solid #edf2f7;
            border-radius: 15px;
            font: inherit;
            font-size: 1rem;
            line-height: 1.4;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #f8fafc;
        }

        .form-group select,
        .form-group .condition-select-trigger {
            appearance: none;
            padding-left: 16px;
            padding-right: 36px;
            color: var(--dark);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='10' viewBox='0 0 14 10'%3E%3Cpath d='M2 2l5 5 5-5' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group .condition-select-trigger:focus,
        .form-group .condition-select-trigger[aria-expanded="true"] {
            border-color: var(--red);
            outline: none;
            box-shadow: 0 0 0 4px rgba(229,62,62,0.1);
            background-color: white;
        }
        
        .form-group i {
            position: absolute;
            pointer-events: none;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--red);
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .form-group select[hidden] { display: none; }
        .condition-select { min-width: 0; }
        .condition-select-trigger { text-align: left; cursor: pointer; }
        .condition-select-options {
            position: fixed; z-index: 1100; margin: 0; padding: 6px;
            max-width: calc(100vw - 16px); max-height: 280px; overflow-y: auto;
            border: 1px solid #edf2f7; border-radius: 15px;
            background: white; color: var(--dark);
            box-shadow: 0 10px 30px rgba(26,26,26,.18);
            overscroll-behavior: contain; scrollbar-width: thin;
        }
        .condition-select-options[hidden] { display: none; }
        .condition-select-option {
            position: relative; display: flex; align-items: center;
            min-height: 44px; padding: 10px 34px 10px 12px; border-radius: 10px;
            font: inherit; font-size: 1rem; line-height: 1.4;
            cursor: pointer; user-select: none; overflow-wrap: anywhere;
        }
        .condition-select-option[aria-selected="true"] {
            background: #fff5f5; color: var(--red-dark); font-weight: 600;
        }
        .condition-select-option[aria-selected="true"]::after {
            content: ''; position: absolute; right: 14px; top: 50%;
            width: 6px; height: 11px; border: solid currentColor; border-width: 0 2px 2px 0;
            transform: translateY(-65%) rotate(45deg);
        }
        .condition-select-option.is-active { background: var(--red); color: white; }

        .form-group input:focus + i,
        .form-group select:focus + i {
            transform: translateY(-50%) scale(1.1);
            color: var(--red-dark);
        }
        
        .photo-upload-group {
            grid-column: 1 / -1;
        }
        
        .photo-upload-area {
            background: linear-gradient(135deg, #fff5f5, #fff);
            border: 3px dashed var(--red);
            border-radius: 20px;
            padding: 25px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .photo-upload-area:hover {
            background: #ffe6e6;
            border-color: var(--red-dark);
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 30px rgba(229,62,62,0.2);
        }
        
        .photo-upload-area span {
            font-size: 1rem;
            color: var(--dark);
            font-weight: 600;
        }
        
        .photo-hint {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 8px;
        }
        
        .photo-counter {
            display: inline-block;
            background: var(--red);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
        
        .photo-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .photo-thumb {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #edf2f7;
            cursor: pointer;
            transition: all 0.3s ease;
            animation: fadeIn 0.3s;
        }
        
        .photo-thumb:hover {
            transform: scale(1.05);
            border-color: var(--red);
            box-shadow: 0 5px 15px rgba(229,62,62,0.3);
        }
        
        .photo-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .photo-thumb:hover img {
            transform: scale(1.1);
        }
        
        .remove-photo-small {
            padding: 0;
            line-height: 1;
            z-index: 1;
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            background: rgba(229,62,62,0.9);
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .remove-photo-small svg {
            display: block; width: 14px; height: 14px; flex-shrink: 0; pointer-events: none;
        }
        .remove-photo-small:focus-visible { outline: 2px solid white; outline-offset: 2px; }

        .photo-thumb:hover .remove-photo-small,
        .photo-thumb:focus-within .remove-photo-small {
            opacity: 1;
        }

        @media (hover: none), (pointer: coarse) {
            .photo-thumb .remove-photo-small {
                opacity: 1;
                width: 36px;
                height: 36px;
            }
        }
        
        .remove-photo-small:hover {
            transform: scale(1.1);
            background: var(--red-dark);
        }
        
        /* ===== СЕКЦИИ ===== */
        .section {
            padding: 90px 0;
        }
        
        .section-title {
            font-size: 2.5rem;
            font-weight: 800;
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        
        .section-title span {
            color: var(--red);
        }
        
        .section-subtitle {
            text-align: center;
            font-size: 1.2rem;
            color: var(--gray);
            max-width: 700px;
            margin: 0 auto 60px;
        }
        
        /* ===== ПРЕИМУЩЕСТВА ===== */
        .benefits {
            background: white;
        }
        
        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
        }
        
        .benefit-card {
            background: white;
            padding: 40px 25px;
            border-radius: 25px;
            text-align: center;
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border: 1px solid #edf2f7;
        }
        
        .benefit-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 25px 50px rgba(229,62,62,0.15);
            border-color: var(--red);
        }
        
        .benefit-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, rgba(229,62,62,0.1), rgba(255,140,66,0.1));
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            transition: all 0.3s ease;
        }
        
        .benefit-card:hover .benefit-icon {
            transform: scale(1.1) rotate(5deg);
            background: var(--red);
        }
        
        .benefit-card:hover .benefit-icon i {
            color: white;
        }
        
        .benefit-icon i {
            font-size: 2.8rem;
            color: var(--red);
            transition: all 0.3s ease;
        }
        
        .benefit-icon .benefit-tow-icon {
            width: 60px; height: 48px; color: var(--red); transition: color 0.3s ease;
        }
        .benefit-card:hover .benefit-tow-icon { color: white; }

        .benefit-card h3 {
            font-size: 1.3rem;
            margin-bottom: 15px;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        
        .benefit-card:hover h3 {
            color: var(--red);
        }
        
        .benefit-card p {
            color: var(--gray);
            font-size: 0.95rem;
        }
        
        /* ===== КЕЙСЫ ===== */
        .cases { background: #f3f6fa; }
        .cases-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 25px;
        }
        .case-card {
            display: flex; flex-direction: column; min-width: 0;
            background: white; border-radius: 20px; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,.05);
            transition: transform .3s ease, box-shadow .3s ease;
        }
        .case-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 36px rgba(0,0,0,.1);
        }
        .case-image { height: 200px; overflow: hidden; position: relative; flex: none; }
        .case-image:after {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.3), transparent);
            transition: left .5s ease; pointer-events: none;
        }
        .case-card:hover .case-image:after { left: 100%; }
        .case-image img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
        .case-card:hover .case-image img { transform: scale(1.05); }
        .case-info { display: flex; flex-direction: column; flex: 1; padding: 22px; }
        .case-title, .case-price { font-size: 1.2rem; font-weight: 700; line-height: 1.3; }
        .case-title { margin-bottom: 5px; }
        .case-desc { color: var(--gray); font-size: .9rem; margin-bottom: 14px; }
        .case-summary { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 16px; }
        .case-price { color: #111; white-space: nowrap; }
        .case-time { display: inline-flex; align-items: center; gap: 8px; color: var(--gray); font-size: .95rem; white-space: nowrap; }
        .case-time i { color: var(--red); font-size: 1.1rem; }
        .case-cta {
            display: flex; width: 100%; min-height: 50px; margin-top: auto; padding: 12px 18px;
            gap: 10px; font-size: .95rem; line-height: 1.3; letter-spacing: 0;
        }
        .case-cta > .fa-calculator { flex: none; font-size: 1.05rem; }
        .case-cta > .fa-chevron-right { flex: none; margin-left: auto; font-size: .8rem; }
        @media (prefers-reduced-motion: reduce) {
            .case-card, .case-image img, .case-image:after { transition: none; }
            .case-card:hover, .case-card:hover .case-image img { transform: none; }
        }

        /* ===== ЭТАПЫ ===== */
        .steps {
            background: white;
        }
        
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            position: relative;
        }
        
        .steps-grid:before {
            content: '';
            position: absolute;
            top: 50px;
            left: 100px;
            right: 100px;
            height: 2px;
            background: linear-gradient(90deg, var(--red), transparent);
            z-index: 1;
        }
        
        .step-item {
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .step-number {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--red), var(--red-dark));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            margin: 0 auto 25px;
            border: 5px solid white;
            box-shadow: 0 10px 30px rgba(229,62,62,0.3);
            transition: all 0.3s ease;
        }
        
        #steps .step-number > svg { display: block; width: 40px; height: 40px; flex-shrink: 0; }

        .step-item:hover .step-number {
            transform: scale(1.1) rotate(360deg);
            box-shadow: 0 15px 40px rgba(229,62,62,0.5);
        }
        
        .step-item h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .step-item:hover h3 {
            color: var(--red);
            transform: scale(1.05);
        }
        
        .step-item p {
            color: var(--gray);
        }
        
        /* ===== ВЫКУПАЕМ ЛЮБЫЕ АВТО ===== */
        .specialization {
            background: linear-gradient(135deg, #f8f9fa, #edf2f7);
        }
        
        .special-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
        }
        
        .special-card {
            background: white;
            padding: 30px 20px;
            border-radius: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .special-card:hover {
            border-color: var(--red);
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 15px 35px rgba(229,62,62,0.15);
        }
        
        .special-card i {
            font-size: 2.5rem;
            color: var(--red);
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .special-card:hover i {
            transform: scale(1.2);
        }
        
        .special-card h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .special-card p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        /* ===== ОТЗЫВЫ ===== */
        .reviews {
            background: linear-gradient(135deg, #1a1a1a, #2d2d2d);
            color: white;
        }
        .reviews .section-title,
        .reviews .section-subtitle { color: white; }

        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            grid-auto-rows: 1fr;
            gap: 25px;
        }
        .review-card {
            min-width: 0;
            padding: 24px;
            background: #0a0a0a;
            border-radius: 16px;
            color: #dedede;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            overflow-wrap: anywhere;
        }
        .review-author {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        .review-avatar {
            --review-avatar-size: 60px;
            position: relative;
            display: block;
            flex: 0 0 var(--review-avatar-size);
            width: var(--review-avatar-size);
            height: var(--review-avatar-size);
            border-radius: 50%;
            overflow: hidden;
        }
        .review-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        /* Display the existing source avatars without creating duplicate assets. */
        .review-avatar--source img {
            position: absolute;
            max-width: none;
            width: calc(var(--review-avatar-size) * var(--avatar-scale));
            height: auto;
            left: calc(var(--review-avatar-size) * var(--avatar-x));
            top: calc(var(--review-avatar-size) * var(--avatar-y));
        }
        .review-author-info { min-width: 0; }
        .review-author-info h3 {
            margin: 0 0 2px;
            color: #e4e4e4;
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1.25;
        }
        .review-author-info p {
            margin: 0;
            color: #929292;
            font-size: 1rem;
            line-height: 1.4;
        }
        .review-rating {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 12px;
            margin-bottom: 6px;
            line-height: 1.4;
        }
        .review-stars { color: #ffbc32; font-size: 1.5rem; letter-spacing: 2px; white-space: nowrap; }
        .review-status, .review-service { color: #929292; font-size: 1.1rem; }
        .review-service { margin: 0 0 10px; line-height: 1.4; }
        .review-text { margin: 0; font-size: 1.2rem; line-height: 1.5; font-style: normal; }
        #screenshots { scroll-margin-top: calc(var(--header-height, 80px) + 24px); }
        @media (max-width: 768px) {
            .reviews-grid { grid-auto-rows: auto; gap: 20px; }
            .review-card { padding: 20px; }
            .review-avatar { --review-avatar-size: 56px; }
            .review-author-info h3 { font-size: 1.2rem; }
            .review-author-info p { font-size: .9rem; }
            .review-status, .review-service { font-size: 1rem; }
            .review-text { font-size: 1.05rem; }
        }

        /* ===== FAQ ===== */
        .faq {
            background: linear-gradient(135deg, #f8f9fa, #edf2f7);
        }
        
        .faq-grid {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .faq-item {
            background: white;
            border-radius: 20px;
            margin-bottom: 20px;
            padding: 25px 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.03);
            border: 1px solid #edf2f7;
            transition: all 0.3s ease;
        }
        
        .faq-item:hover {
            box-shadow: 0 10px 30px rgba(229,62,62,0.1);
            border-color: var(--red);
            transform: translateY(-5px);
        }
        
        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        
        .faq-question h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .faq-question i {
            color: var(--red);
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        
        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }
        
        .faq-answer {
            display: none;
            overflow: hidden;
            transition: max-height 0.3s ease, margin 0.3s ease;
            margin-top: 0;
            color: var(--gray);
        }
        
        .faq-item.active .faq-answer {
            display: block;
            margin-top: 20px;
        }
        
        /* ===== КОНТАКТЫ ===== */
        .contacts {
            background: white;
        }
        
        .contacts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            align-items: center;
        }
        
        .contacts-info h2 {
            font-size: 2rem;
            margin-bottom: 20px;
        }
        
        .contacts-list {
            margin: 30px 0;
        }

        .contacts-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 30px;
        }

        .contacts-actions .btn { max-width: 100%; }

        @media (max-width: 480px) {
            .contacts-actions { flex-direction: column; align-items: stretch; }
        }
        
        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        
        .contact-item:hover {
            transform: translateX(10px);
        }
        
        .contact-icon {
            width: 50px;
            height: 50px;
            background: rgba(229,62,62,0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--red);
            font-size: 1.3rem;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        
        .contact-item:hover .contact-icon {
            background: var(--red);
            color: white;
            transform: scale(1.1);
        }
        
        .contact-link {
            color: inherit;
            text-decoration: underline;
            text-decoration-color: transparent;
            text-underline-offset: 3px;
        }
        .contact-link:hover, .contact-link:focus-visible {
            text-decoration-color: currentColor;
        }
        .contact-link:focus-visible {
            outline: 2px solid currentColor;
            outline-offset: 3px;
        }

        .contact-text {
            font-size: 1.1rem;
            flex: 1;
            overflow-wrap: break-word;
            word-wrap: break-word;
            word-break: break-word;
        }
        
        .contact-text strong {
            display: block;
            font-size: 1.3rem;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .contacts-map {
            border-radius: 25px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .contacts-map:hover {
            transform: scale(1.02);
            box-shadow: 0 25px 50px rgba(229,62,62,0.2);
        }
        
        .contacts-map iframe {
            width: 100%;
            height: 400px;
            border: 0;
            display: block;
        }
        
        /* ===== ФУТЕР ===== */
        .footer {
            background: #0a0a0a;
            color: white;
            padding: 70px 0 30px;
        }
        
        .footer-grid {
            display: grid;
            grid-template-columns: minmax(180px, 1.35fr) minmax(0, 1.15fr) minmax(0, 1fr) minmax(0, 1.15fr) minmax(280px, 1.6fr);
            gap: 40px 24px;
            margin-bottom: 50px;
        }
        
        .footer-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .footer-logo-img {
            position: relative;
            width: 220px;
            max-width: 100%;
            aspect-ratio: 4;
            flex-shrink: 0;
        }
        .footer-logo-img img {
            display: block;
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center 45%;
        }
        
        .footer-about {
            color: #a0aec0;
            line-height: 1.8;
        }
        
        .footer-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 25px;
            color: white;
            position: relative;
        }
        
        .footer-title:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--red);
            transition: width 0.3s ease;
        }
        
        .footer-title:hover:after {
            width: 80px;
        }
        
        .footer-link-group > summary { list-style: none; cursor: default; }
        .footer-link-group > summary::-webkit-details-marker { display: none; }
        .footer-link-group .footer-title { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .footer-chevron { display: none; width: 20px; height: 20px; flex: 0 0 20px; color: var(--red); }
        .footer-link-group > summary:focus-visible { outline: 2px solid var(--red); outline-offset: 6px; border-radius: 4px; }
        @media (max-width: 768px) {
            .footer-link-group > summary { cursor: pointer; }
            .footer-link-group .footer-title { min-height: 44px; margin-bottom: 0; }
            .footer-link-group[open] .footer-title { margin-bottom: 25px; }
            .footer-chevron { display: block; }
            .footer-link-group[open] .footer-chevron { transform: rotate(180deg); }
        }

        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }
        
        .footer-links li:hover {
            transform: translateX(5px);
        }
        
        .footer-links a {
            color: #a0aec0;
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .footer-links a:hover {
            color: var(--red);
        }
        
        .footer-phone {
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 15px;
            word-break: break-word;
            transition: all 0.3s ease;
        }
        
        .footer-phone:hover {
            color: var(--red);
            transform: scale(1.05);
        }
        
        .footer-social {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .social-icon {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: 45px; height: 45px; flex-shrink: 0; border-radius: 12px;
            background: transparent; color: white; text-decoration: none; transition: transform .2s ease;
        }
        .social-icon svg, .social-icon img, .social-icon i {
            display: block; width: 34px; height: 34px; flex-shrink: 0;
        }
        .social-icon img { object-fit: contain; border-radius: 9px; }
        .social-icon--whatsapp i {
            display: inline-flex; align-items: center; justify-content: center;
            background: #25d366; border-radius: 50%; font-size: 25px;
        }
        .social-icon:focus-visible { outline: 2px solid white; outline-offset: 3px; }
        @media (hover: hover) and (pointer: fine) {
            .social-icon:hover { transform: translateY(-2px); }
        }

        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid #333;
            color: #718096;
        }
        
        .footer-bottom a {
            color: #718096;
            text-decoration: none;
            transition: color 0.3s ease;
            cursor: pointer;
        }
        
        .footer-bottom a:hover {
            color: var(--red);
        }
        
        .footer-studio {
            margin-top: 20px;
            font-size: 0.9rem;
        }
        
        .footer-studio a {
            color: var(--red);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .footer-studio a:hover {
            color: white;
            text-decoration: underline;
        }
        
        /* ===== SEO ТЕКСТ ===== */
        .seo-text {
            background: #111;
            color: #a0aec0;
            padding: 30px 0;
            border-top: 1px solid #333;
        }
        
        .seo-content {
            max-height: 100px;
            overflow: hidden;
            transition: max-height 0.5s ease;
            position: relative;
        }
        
        .seo-content.expanded {
            max-height: none;
        }
        
        .footer-legal {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0 16px;
        }
        .footer-legal a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            max-width: 100%;
            text-align: center;
        }

        .seo-content p {
            margin-bottom: 15px;
            line-height: 1.7;
        }
        
        .seo-toggle {
            background: var(--button-red-bg);
            color: white;
            border: 1px solid #c62b35;
            box-shadow: var(--button-red-shadow);
            padding: 10px 25px;
            border-radius: 30px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        @media (hover: hover) and (pointer: fine) {
            .seo-toggle:hover {
                background: var(--button-red-hover);
                transform: translateY(-2px);
                box-shadow: 0 10px 22px rgba(154,24,36,.32), inset 0 1px 0 rgba(255,255,255,.5), inset 0 -2px 0 rgba(105,12,24,.18);
            }
        }
        .seo-toggle:active {
            transform: translateY(1px);
            box-shadow: 0 3px 8px rgba(154,24,36,.22), inset 0 2px 4px rgba(105,12,24,.24);
        }
        
        .seo-toggle i {
            transition: transform 0.3s ease;
        }
        
        .seo-toggle.active i {
            transform: rotate(180deg);
        }
        
        .back-to-top {
            position: fixed; right: 24px; bottom: calc(144px + env(safe-area-inset-bottom, 0px));
            z-index: 998; display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; padding: 0; border: 1px solid #c62b35; border-radius: 50%;
            background: var(--button-red-bg); color: white; box-shadow: var(--button-red-shadow);
            cursor: pointer; transition: transform .16s ease, box-shadow .2s ease, background .2s ease;
        }
        .back-to-top[hidden] { display: none; }
        .back-to-top svg { display: block; width: 22px; height: 22px; pointer-events: none; }
        .back-to-top:focus-visible { outline: 3px solid white; outline-offset: 4px; }
        @media (hover: hover) and (pointer: fine) {
            .back-to-top:hover { background: var(--button-red-hover); transform: translateY(-2px); }
        }
        .back-to-top:active { transform: translateY(1px); }
        @media (max-width: 768px) {
            .form-in-view .back-to-top { visibility: hidden; pointer-events: none; }
        }
        @media (max-width: 640px) {
            .back-to-top { right: 16px; width: 42px; height: 42px; }
            .back-to-top svg { width: 20px; height: 20px; }
        }

        /* ===== МОДАЛКИ ===== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            backdrop-filter: blur(5px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: white;
            padding: 50px;
            border-radius: 30px;
            max-width: 600px;
            width: 90%;
            position: relative;
            animation: modalSlideIn 0.5s;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-content h3 {
            margin-bottom: 25px;
            font-size: 1.8rem;
            color: var(--dark);
        }
        
        .modal-content p {
            margin-bottom: 20px;
            color: var(--gray);
            line-height: 1.7;
        }
        
        .modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: transparent;
            appearance: none;
            outline: none;
            font-family: inherit;
            font-size: 2rem;
            line-height: 1;
            cursor: pointer;
            color: #999;
            transition: all 0.3s ease;
        }
        .modal-close:focus-visible {
            outline: 2px solid var(--red);
            outline-offset: 2px;
        }
        .modal-content h3 { padding-right: 44px; }
        .modal-content [data-modal-initial-focus]:focus { outline: none; }

        #estimateModal.active, #estimateModal .estimate-dialog { animation: none; }
        #estimateModal .estimate-dialog {
            width: calc(100% - 32px); max-width: 560px; padding: 0;
            max-height: calc(100vh - 32px); max-height: calc(100dvh - 32px);
            overflow: hidden;
        }
        .estimate-modal-body {
            max-height: calc(100vh - 32px); max-height: calc(100dvh - 32px);
            overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin;
        }
        #estimateModal .modal-close { z-index: 2; top: 10px; right: 10px; background: white; }
        #estimateModal .form-card { padding: 28px 24px 24px; border-radius: 0; box-shadow: none; }
        #estimateModal .form-title { margin: 0 0 18px; padding-right: 40px; font-size: 1.5rem; line-height: 1.3; }
        #estimateModal .form-grid { gap: 12px; }
        #estimateModal .form-group { margin-bottom: 0; }
        #estimateModal .form-group input, #estimateModal .condition-select-trigger { min-height: 52px; padding-block: 12px; }
        #estimateModal .photo-upload-area { padding: 14px 16px; margin: 0; }
        #estimateModal .photo-upload-area:hover { transform: none; }
        #estimateModal #submitBtn { margin-top: 16px; min-height: 52px; padding: 12px 20px; }
        #estimateModal .form-consent { margin: 12px 0 0; color: #666; font-size: .8rem; line-height: 1.4; }
        .estimate-open .condition-select-options { z-index: 2100; }
        @media (max-width: 480px) {
            #estimateModal .estimate-dialog { width: calc(100% - 24px); }
            #estimateModal .form-card { padding: 24px 20px; }
            #estimateModal .form-title { font-size: 1.25rem; }
            #estimateModal #submitBtn { padding-inline: 14px; font-size: .9rem; }
        }

        #callbackModal .modal-content { padding: 32px 36px; }
        #callbackModal h3 { margin-bottom: 12px; line-height: 1.25; }
        .modal-content .callback-intro { margin: 0 0 20px; line-height: 1.5; }
        .callback-form { display: grid; gap: 16px; }
        .callback-form .form-group { margin: 0; }
        .callback-form .callback-policy {
            margin: 0;
            color: #666;
            font-size: .8rem;
            line-height: 1.5;
            text-align: center;
        }
        @media (max-width: 480px) {
            #callbackModal .modal-content { padding: 28px 20px 24px; }
            #callbackModal h3 { font-size: 1.35rem; }
        }
        
        .modal-close:hover {
            color: var(--red);
            transform: rotate(90deg);
        }
        
        /* ===== ПОЛИТИКА КОНФИДЕНЦИАЛЬНОСТИ ===== */
        .policy-list {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }
        
        .policy-list li {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .policy-list li i {
            color: var(--red);
            margin-top: 3px;
        }
        
        /* ===== АДАПТИВ ДЛЯ МОБИЛЬНЫХ ===== */
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .container { padding: 0 20px; }
            .benefits-grid,
            .special-grid,
            .cases-grid,
            .steps-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .footer-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .steps-grid:before {
                display: none;
            }
            .hero h1 {
                font-size: 2.8rem;
            }
        }
        
        @media (max-width: 1280px) {
            .burger-menu {
                display: flex;
            }
            .nav-menu {
                position: fixed;
                top: var(--header-height, 80px);
                right: -100%;
                width: 85%;
                max-width: 350px;
                height: calc(100vh - var(--header-height, 80px));
                height: calc(100dvh - var(--header-height, 80px));
                background: #1a1a1a;
                flex-direction: column;
                align-items: flex-start;
                padding: 40px 30px;
                gap: 30px;
                transition: right 0.4s ease;
                box-shadow: -5px 0 30px rgba(0,0,0,0.3);
                z-index: 999;
                border-left: 3px solid var(--red);
            }
            .nav-menu.active {
                right: 0;
                animation: slideInRight 0.4s ease;
            }
            .nav-links {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
                gap: 25px;
            }
            .nav-link {
                font-size: 1.2rem;
            }
            .header-phone-block {
                align-items: flex-start;
                width: 100%;
                padding-top: 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            .header-phone {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 992px) {
            .reviews-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .hero {
                padding: 140px 0 60px;
            }
            .hero h1 {
                font-size: 2rem;
            }
            .hero-features {
                flex-direction: column;
                gap: 15px;
            }
            .hero-buttons {
                flex-direction: column;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .photo-upload-group {
                grid-column: span 1;
            }
            .benefits-grid,
            .special-grid,
            .cases-grid,
            .reviews-grid,
            .steps-grid,
            .contacts-grid,
            .footer-grid {
                grid-template-columns: 1fr;
            }
            .section {
                padding: 60px 0;
            }
            .section-title {
                font-size: 1.8rem;
            }
            .contacts-grid {
                gap: 30px;
            }
            .contact-item {
                align-items: flex-start;
            }
            .contact-text strong {
                font-size: 1.1rem;
            }
            .contacts-map iframe {
                height: 350px;
            }
            .footer-phone {
                font-size: 1.5rem;
            }
            .modal-content {
                padding: 30px 20px;
            }
            .modal-content h3 {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .logo-img { width: 176px; height: 44px; }
            .container {
                padding: 0 15px;
            }
            .hero h1 {
                font-size: 1.6rem;
            }
            .hero-subtitle {
                font-size: 1rem;
            }
            .hero-stats {
                flex-wrap: wrap;
                justify-content: space-between;
            }
            .hero-stat-number {
                font-size: 1.6rem;
            }
            .hero-stat-label {
                font-size: 0.8rem;
            }
            .form-card {
                padding: 25px 20px;
            }
            .photo-gallery {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
            }
            .photo-thumb .remove-photo-small {
                opacity: 1;
                width: 36px;
                height: 36px;
            }
            .contacts-info h2 {
                font-size: 1.5rem;
            }
            .contact-icon {
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }
            .contact-text {
                font-size: 0.95rem;
            }
            .contact-text strong {
                font-size: 1rem !important;
            }
            .contacts-map iframe {
                height: 300px;
                border-radius: 15px;
            }
            .footer-phone {
                font-size: 1.3rem !important;
            }
            .social-icon {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 360px) {
            .logo-img { width: 164px; height: 41px; }
            .contacts-map iframe {
                height: 250px;
            }
            .footer-phone {
                font-size: 1.1rem !important;
            }
            .contact-text strong {
                font-size: 0.9rem !important;
            }
            
        }
        
        .nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }
        
        .header-phone {
            font-size: 1.3rem;
            font-weight: 800;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .45em;
            letter-spacing: 0.5px;
            transition: color 0.3s ease, transform 0.3s ease;
            white-space: nowrap;
        }

        .header-phone-icon {
            flex: 0 0 1em;
            width: 1em;
            color: inherit;
            font-size: 1.1em;
            line-height: 1;
        }
        
        .phone-number {
            display: inline-block;
        }
        
        @media (max-width: 1300px) {
            .nav-links {
                gap: 15px;
            }
            
            .nav-link {
                font-size: 0.85rem;
            }
            
            .header-phone {
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 1150px) {
            .header-wrapper {
                flex-wrap: wrap;
            }
            
            .nav-menu {
                order: 3;
                width: 100%;
                margin-top: 15px;
                justify-content: space-between;
            }
            
            .nav-links {
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            
            .header-phone-block {
                align-items: flex-end;
            }
        }
        
        @media (max-width: 1280px) {
            .nav-menu {
                position: fixed;
                top: var(--header-height, 80px);
                right: -100%;
                width: 85%;
                max-width: 260px;
                height: calc(100vh - var(--header-height, 80px));
                height: calc(100dvh - var(--header-height, 80px));
                background: #1a1a1a;
                flex-direction: column;
                align-items: flex-start;
                padding: 40px 16px;
                gap: 30px;
                transition: right 0.4s ease;
                box-shadow: -5px 0 30px rgba(0,0,0,0.3);
                z-index: 999;
                border-left: 3px solid var(--red);
                margin-top: 0;
            }
            
            .nav-menu.active {
                right: 0;
                animation: slideInRight 0.4s ease;
            }
            
            .nav-links {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
                gap: 20px;
            }
            
            .nav-link {
                font-size: 1.1rem;
                white-space: normal;
                max-width: 100%;
                text-align: left;
            }
            
            .header-phone-block {
                align-items: flex-start;
                width: 100%;
                padding-top: 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }
            
            .header-phone {
                font-size: 1.2rem;
                white-space: normal;
                word-break: break-word;
            }
        }
        
        @media (max-width: 480px) {
            .header-phone {
                font-size: 1.1rem;
            }
            
            .phone-number {
                font-size: 1.1rem;
            }
        }

        /* ============================================ */
        /* ОБЛАКО ТЕГОВ МЕТРО */
        /* ============================================ */
        .metro-tags-section {
            background: linear-gradient(135deg, #0d0d0d, #1a1a1a);
            padding: 40px 0 30px;
            border-top: 3px solid #e53e3e;
            border-bottom: 1px solid #333;
        }

        .metro-tags-wrapper {
            text-align: center;
        }

        .metro-tags-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-height: 44px;
            margin-bottom: 0;
            flex-wrap: wrap;
            list-style: none;
            cursor: pointer;
        }

        .metro-tags-title::-webkit-details-marker {
            display: none;
        }

        .metro-tags-title:focus-visible {
            outline: 2px solid var(--red);
            outline-offset: 8px;
            border-radius: 4px;
        }

        .metro-tags-wrapper[open] .metro-tags-title {
            margin-bottom: 25px;
        }

        .metro-tags-title i {
            font-size: 2rem;
            color: #e53e3e;
            animation: pulse 2s infinite;
        }

        .metro-tags-label {
            font-size: 1.6rem;
            font-weight: 700;
            color: white;
            letter-spacing: 0.5px;
        }

        .metro-tags-label:after {
            content: '';
            display: block;
            width: 60px;
            height: 3px;
            background: #e53e3e;
            margin: 8px auto 0;
        }

        .metro-tags-toggle {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            width: 100%;
            color: #cbd5e1;
            font-size: 0.9rem;
        }

        .metro-tags-toggle:before {
            content: 'Показать районы';
        }

        .metro-tags-toggle:after {
            content: '';
            width: 8px;
            height: 8px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: translateY(-2px) rotate(45deg);
        }

        .metro-tags-wrapper[open] .metro-tags-toggle:before {
            content: 'Скрыть районы';
        }

        .metro-tags-wrapper[open] .metro-tags-toggle:after {
            transform: translateY(2px) rotate(225deg);
        }

        .metro-tags-cloud {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 10px 16px;
            padding: 10px 0;
        }

        .metro-tag {
            display: inline-block;
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 500;
            padding: 6px 16px;
            border-radius: 25px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            transition: all 0.3s ease;
            white-space: nowrap;
            letter-spacing: 0.3px;
            font-family: 'Montserrat', sans-serif;
        }

        .metro-tag:hover {
            color: white;
            background: #e53e3e;
            border-color: #e53e3e;
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(229,62,62,0.3);
        }

        .metro-tag.active {
            background: #e53e3e;
            color: white;
            border-color: #e53e3e;
            box-shadow: 0 8px 25px rgba(229,62,62,0.3);
        }

        .metro-tag.tag-sm { font-size: 0.75rem; padding: 4px 12px; }
        .metro-tag.tag-md { font-size: 0.85rem; padding: 6px 16px; }
        .metro-tag.tag-lg { font-size: 0.95rem; padding: 8px 20px; font-weight: 600; }
        .metro-tag.tag-xl { font-size: 1.05rem; padding: 10px 24px; font-weight: 700; }

        @media (max-width: 768px) {
            .metro-tags-label { font-size: 1.2rem; }
            .metro-tags-title i { font-size: 1.5rem; }
            .metro-tags-cloud { gap: 8px 12px; }
            .metro-tag { font-size: 0.7rem !important; padding: 4px 12px !important; white-space: normal; word-break: break-word; }
            .metro-tag.tag-xl { font-size: 0.8rem !important; padding: 5px 14px !important; }
            .metro-tag.tag-lg { font-size: 0.75rem !important; padding: 4px 12px !important; }
        }

        @media (max-width: 480px) {
            .metro-tags-section { padding: 25px 0 20px; }
            .metro-tags-label { font-size: 1rem; }
            .metro-tags-cloud { gap: 6px 10px; }
            .metro-tag { font-size: 0.6rem !important; padding: 3px 10px !important; border-radius: 20px; }
        }

        /* Стили для SEO-текста */
        .station-seo-content {
            color: #1a1a1a;
            line-height: 1.8;
        }

        .station-seo-content h2 {
            font-size: 1.8rem;
            margin-bottom: 20px;
            color: #1a1a1a;
        }

        .station-seo-content h3 {
            font-size: 1.4rem;
            margin: 30px 0 15px;
            color: #1a1a1a;
        }

        .station-seo-content h4 {
            font-size: 1.1rem;
            margin: 20px 0 10px;
            color: #1a1a1a;
        }

        .station-seo-content p {
            margin-bottom: 15px;
            color: #4a5568;
        }

        .station-seo-content ul, .station-seo-content ol {
            margin: 15px 0 20px 25px;
        }

        .station-seo-content ul li, .station-seo-content ol li {
            margin-bottom: 8px;
            color: #4a5568;
        }

        .station-seo-content a {
            color: #e53e3e;
            text-decoration: none;
        }

        .station-seo-content a:hover {
            text-decoration: underline;
        }

        .station-seo-content strong {
            color: #1a1a1a;
        }
        .hot-lead { padding: 30px 0; background: var(--light); }
        .hot-lead-box { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;
            background: #fff; border: 2px solid var(--red); border-radius: 16px; padding: 24px 30px; box-shadow: 0 8px 30px rgba(229,62,62,.15); }
        .hot-lead-badge { display: inline-block; background: var(--red-dark); color: #fff; border-radius: 20px; padding: 4px 14px; font-size: .85rem; font-weight: 700; margin-bottom: 8px; }
        .hot-lead-text h2 { font-size: 1.4rem; margin-bottom: 6px; }
        .hot-lead-text h2 b { color: var(--red); font-size: 1.8rem; }
        .hot-lead-text p { color: var(--gray); }
        .vslider {
            --video-max-height: min(600px, calc(80vh - var(--header-height, 80px)));
            position: relative;
            min-width: 0;
            width: 100%;
            max-width: calc(var(--video-max-height) * 2.25 + 60px);
            margin-inline: auto;
        }
        @supports (height: 1svh) {
            .vslider { --video-max-height: min(600px, calc(80svh - var(--header-height, 80px))); }
        }
        .vslider-track { position: relative; display: flex; gap: 20px; overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: none; }
        .vslider-track::-webkit-scrollbar { display: none; }
        .vslide { position: relative; flex: 0 0 calc((100% - 60px) / 4); min-width: 0; scroll-snap-align: start; border-radius: 16px; overflow: hidden; background: #000; aspect-ratio: 9/16; }
        .vslide video { display: block; width: 100%; height: 100%; object-fit: contain; }
        .video-loop-edge { display: none; pointer-events: none; }
        .video-loop-edge img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .vslide video::-webkit-media-controls-overlay-play-button { display: none; }
        .video-start { position: absolute; inset: 0; z-index: 1; display: block; border: 0; border-radius: inherit; background: transparent; cursor: pointer; }
        .video-start[hidden] { display: none; }
        .video-start:focus-visible, .vslide video:focus-visible { outline: 3px solid var(--red); outline-offset: -5px; }
        .vslider-controls { z-index: 3; position: absolute; inset: 0; display: flex; justify-content: space-between; align-items: center; pointer-events: none; }
        .vslider-controls[hidden] { display: none; }
        .vslider-arrow { pointer-events: auto; flex-shrink: 0; box-shadow: 0 4px 16px rgba(0,0,0,.2); display: inline-flex; align-items: center; justify-content: center; font-size: 30px; line-height: 1; padding: 0 0 4px; width: 48px; height: 48px; border-radius: 50%; border: 0; background: var(--red); color: #fff; cursor: pointer; }
        .vslider-arrow:disabled { visibility: hidden; cursor: default; }
        .vslider-arrow:focus-visible, .vslider-track:focus-visible { outline: 3px solid var(--red); outline-offset: 4px; }
        .vslider-arrow.left { transform: translateX(-50%); }
        .vslider-arrow.right { transform: translateX(50%); }
        @media (max-width: 1300px) {
            .vslider-controls { padding: 0 8px; }
            .vslider-arrow.left, .vslider-arrow.right { transform: none; }
        }
        @media (max-width: 600px) { .vslider-arrow { width: 44px; height: 44px; font-size: 28px; } }
        @media (max-width: 992px) {
            .vslider { max-width: calc(var(--video-max-height) * 1.2375 + 40px); }
            .vslide { flex-basis: calc((100% - 40px) / 2.2); }
        }
        @media (max-width: 600px) {
            .vslider { max-width: calc(var(--video-max-height) * .72115); }
            .vslider-track { gap: 12px; padding-inline: 11%; }
            .vslide { flex-basis: 100%; scroll-snap-align: center; }
            .video-loop-edge { display: block; }
        }
        .video-feedback[hidden] { display: none; }
        .video-feedback {
            position: absolute; left: 12px; right: 12px; bottom: 64px; z-index: 2;
            padding: 8px 12px; border-radius: 8px; background: rgba(0,0,0,.85);
            color: white; font-size: .85rem; line-height: 1.4; pointer-events: none;
        }
        @media (max-height: 480px) {
            .vslider-controls { padding: 0; }
            .vslider-arrow.left { transform: translateX(-50%); }
            .vslider-arrow.right { transform: translateX(50%); }
        }
        .evac-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .evac-grid img { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; }
        .evac-grid .evac-main { grid-column: span 2; max-height: 460px; }
        .team-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; margin-top: 40px; }
        .team-card { min-width: 0; text-align: center; }
        .team-card img { width: min(140px, 100%); height: auto; aspect-ratio: 1; border-radius: 50%; object-fit: cover; border: 4px solid var(--red); margin-bottom: 14px; }
        .team-card h3 { font-size: 1.1rem; margin-bottom: 4px; }
        .team-card p { color: var(--gray); font-size: .95rem; }
        @media (max-width: 768px) {
            .team-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hot-lead-box { padding: 20px; text-align: center; justify-content: center; }
        }
        .messenger-widget {
            --messenger-bottom: calc(28px + env(safe-area-inset-bottom, 0px));
            --messenger-edge: 30px;
            --messenger-size: 64px; --messenger-link-size: 52px; --messenger-icon-size: 44px;
            --messenger-radius: 104px; --messenger-rise: 104px;
            --messenger-diagonal-x: 74px; --messenger-diagonal-y: 74px;
            position: fixed; right: var(--messenger-edge); bottom: var(--messenger-bottom); z-index: 999;
            width: var(--messenger-size); height: var(--messenger-size);
        }
        .messenger-widget-toggle {
            position: absolute; top: 0; right: 0; z-index: 2;
            display: inline-flex; align-items: center; justify-content: center;
            width: var(--messenger-size);
            height: 100%; padding: 0; border: 0; border-radius: 50%;
            background: var(--button-red-bg);
            box-shadow: 0 7px 22px #a51b344d, inset 0 1px 0 #ffb2b259;
            color: white; font: inherit; cursor: pointer;
            transition: box-shadow .2s ease;
        }
        .messenger-widget-icon {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            width: var(--messenger-size); height: var(--messenger-size); flex-shrink: 0; border-radius: 50%;
            background: var(--button-red-bg);
            box-shadow: inset 0 0 0 1px #ffb2b273, -4px 0 14px #a51b3429;
        }
        .messenger-widget-icon svg { display: block; width: 40px; height: 40px; overflow: visible; pointer-events: none; }
        .messenger-widget-toggle::after {
            content: ''; position: absolute; inset: 0; z-index: -1;
            border: 2px solid #ff5968; border-radius: inherit;
            opacity: 0; pointer-events: none;
        }
        .messenger-widget:has(> .messenger-widget-panel[hidden]) > .messenger-widget-toggle:not(:hover):not(:focus-visible)::after {
            animation: messenger-invite-wave 4.8s ease-out infinite;
            animation-play-state: var(--messenger-invite-play-state, running);
        }
        .messenger-widget:has(> .messenger-widget-panel[hidden]) > .messenger-widget-toggle:not(:hover):not(:focus-visible) .messenger-widget-icon > svg {
            transform-origin: 50% 50%;
            animation: messenger-invite-wiggle 4.8s ease-in-out infinite;
            animation-play-state: var(--messenger-invite-play-state, running);
        }
        body:has(.modal.active, .phone-zoom-closing, .nav-menu.active, form:focus-within) .messenger-widget {
            --messenger-invite-play-state: paused;
        }
        @keyframes messenger-invite-wave {
            0% { opacity: 0; transform: scale(1); }
            4% { opacity: .55; }
            25%, 100% { opacity: 0; transform: scale(1.6); }
        }
        @keyframes messenger-invite-wiggle {
            0%, 4%, 28%, 100% { transform: rotate(0) scale(1); }
            8% { transform: rotate(-8deg) scale(1.06); }
            12% { transform: rotate(8deg) scale(1.06); }
            16% { transform: rotate(-6deg) scale(1.04); }
            20% { transform: rotate(4deg) scale(1.02); }
            24% { transform: rotate(0) scale(1); }
        }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .form-in-view:has(.hero--sell) .messenger-widget { --messenger-invite-play-state: paused; }
        }
        @media (prefers-reduced-motion: reduce) {
            #messengerWidgetToggle::after,
            #messengerWidgetToggle .messenger-widget-icon > svg { animation: none !important; }
        }
        .messenger-widget-toggle:hover .messenger-widget-icon { filter: brightness(1.06); }
        .messenger-widget-toggle:focus-visible { outline: 3px solid white; outline-offset: 4px; }
        .messenger-status-dot {
            position: absolute; top: 3px; right: 3px; width: 13px; height: 13px;
            border: 2px solid white; border-radius: 50%; background: #19cb65;
            box-shadow: 0 1px 5px #06381933;
        }
        .messenger-typing-dot { transform-box: fill-box; transform-origin: center; animation: messenger-typing 1.5s ease-in-out infinite; }
        .messenger-typing-dot:nth-child(2) { animation-delay: .18s; }
        .messenger-typing-dot:nth-child(3) { animation-delay: .36s; }
        .messenger-cloud-close { display: none; }
        .messenger-widget-toggle[aria-expanded="true"] .messenger-status-dot,
        .messenger-widget-toggle[aria-expanded="true"] .messenger-chat-bubble,
        .messenger-widget-toggle[aria-expanded="true"] .messenger-typing-dots { display: none; }
        .messenger-widget-toggle[aria-expanded="true"] .messenger-cloud-close { display: block; }
        @keyframes messenger-typing {
            0%, 65%, 100% { opacity: .4; transform: translateY(0); }
            25% { opacity: 1; transform: translateY(-1px); }
        }
        .messenger-widget-panel {
            position: absolute; bottom: 0; right: 0;
            width: calc(var(--messenger-radius) + var(--messenger-size));
            height: calc(var(--messenger-rise) + var(--messenger-size));
            pointer-events: none;
        }
        .messenger-widget-panel[hidden] { display: none; }
        .messenger-widget-panel::before {
            content: ''; position: absolute;
            right: calc(var(--messenger-size) / 2); bottom: calc(var(--messenger-size) / 2);
            width: var(--messenger-radius); height: var(--messenger-rise);
            border: 2px solid #e53e3e60; border-right: 0; border-bottom: 0;
            border-top-left-radius: 100%;
            opacity: 0; transform: scale(.35); transform-origin: bottom right;
            transition: opacity .22s cubic-bezier(.2, .75, .25, 1), transform .22s cubic-bezier(.2, .75, .25, 1);
        }
        .messenger-widget-panel .header-messenger {
            position: absolute;
            right: calc((var(--messenger-size) - var(--messenger-link-size)) / 2 + var(--messenger-x));
            bottom: calc((var(--messenger-size) - var(--messenger-link-size)) / 2 + var(--messenger-y));
            width: var(--messenger-link-size); height: var(--messenger-link-size);
            padding: 0; border: 0; border-radius: 50%;
            background: transparent; box-shadow: none;
            pointer-events: none; opacity: 0;
            transform: translate(var(--messenger-x), var(--messenger-y)) scale(.35);
            transition: transform .22s cubic-bezier(.2, .75, .25, 1), opacity .22s cubic-bezier(.2, .75, .25, 1);
        }
        .messenger-widget-toggle[aria-expanded="true"] ~ .messenger-widget-panel .header-messenger {
            pointer-events: auto; opacity: 1; transform: translate(0, 0) scale(1);
        }
        .messenger-widget-toggle[aria-expanded="true"] ~ .messenger-widget-panel::before {
            opacity: 1; transform: scale(1);
        }
        .messenger-widget-panel .header-messenger--telegram { --messenger-x: 0px; --messenger-y: var(--messenger-rise); }
        .messenger-widget-panel .header-messenger--whatsapp { --messenger-x: var(--messenger-diagonal-x); --messenger-y: var(--messenger-diagonal-y); }
        .messenger-widget-panel .header-messenger--max { --messenger-x: var(--messenger-radius); --messenger-y: 0px; }
        .messenger-widget-panel .header-messenger i,
        .messenger-widget-panel .header-messenger img,
        .messenger-widget-panel .header-messenger svg { width: var(--messenger-icon-size); height: var(--messenger-icon-size); flex-shrink: 0; }
        .messenger-widget-panel .header-messenger--whatsapp i { font-size: 29px; }
        .messenger-widget-panel .header-messenger:hover { background: transparent; filter: brightness(1.08); }
        .messenger-widget-panel .header-messenger:focus-visible { outline: 3px solid var(--red-dark); outline-offset: 3px; }
        .messenger-widget:has(.messenger-widget-panel:not([hidden])) {
            bottom: min(var(--messenger-bottom), max(20px, calc(100dvh - var(--header-height, 80px) - var(--messenger-rise) - var(--messenger-size) - 12px)));
        }
        body:has(.modal.active) .messenger-widget,
        body:has(.nav-menu.active) .messenger-widget,
        body:has(form:focus-within) .messenger-widget { visibility: hidden; pointer-events: none; }
        body:has(#messengerWidgetLinks:not([hidden])) .back-to-top { visibility: hidden; pointer-events: none; }
        @media (max-width: 768px) {
            .messenger-widget {
                --messenger-bottom: calc(20px + env(safe-area-inset-bottom, 0px));
                --messenger-edge: 20px;
                --messenger-size: 60px; --messenger-link-size: 48px; --messenger-icon-size: 40px;
                --messenger-radius: 96px; --messenger-rise: 96px;
                --messenger-diagonal-x: 68px; --messenger-diagonal-y: 68px;
            }
            body.cookie-notice-open:not(.form-in-view) .messenger-widget {
                --messenger-bottom: min(calc(var(--cookie-notice-height, 0px) + 24px + env(safe-area-inset-bottom, 0px)), calc(100dvh - var(--header-height, 80px) - var(--messenger-size) - 12px));
            }
        }
        @media (max-height: 480px) {
            body.cookie-notice-open:not(.form-in-view) .messenger-widget { z-index: 1300; }
        }
        @media (max-height: 280px) {
            .messenger-widget {
                --messenger-link-size: 44px; --messenger-icon-size: 36px;
                --messenger-radius: 144px; --messenger-rise: 56px;
                --messenger-diagonal-x: 84px; --messenger-diagonal-y: 40px;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .messenger-widget-toggle { transition: none; }
            .messenger-typing-dot { animation: none; }
            .messenger-widget-panel::before, .messenger-widget-panel .header-messenger { transition: none; }
        }
        .mobile-header-phone { display: none; }
        @media (max-width: 1280px) {
            .mobile-header-phone {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 44px;
                flex-shrink: 0;
                margin-left: auto;
                margin-right: 16px;
                font-size: 1.1rem;
                font-weight: 800;
                line-height: 1.2;
                letter-spacing: 0;
                white-space: nowrap;
                color: white;
                text-decoration: none;
            }
            .mobile-header-phone .phone-number { font-size: inherit; white-space: nowrap; }
            .mobile-header-phone:hover { color: var(--red); transform: none; }
            .mobile-header-phone:focus-visible { outline: 2px solid white; outline-offset: 3px; }
            .burger-menu { flex-shrink: 0; }
        }
        @media (max-width: 768px) {
            .header-brand .logo-img { width: 156px; height: 40px; }
        }
        @media (max-width: 600px) {
            .header { padding: 10px 0; }
            .header-brand { flex: 1; min-width: 0; }
            .header-brand .logo-wrapper { flex: none; width: 112px; max-width: 100%; min-width: 0; }
            .header-brand .logo-img { width: 100%; height: 32px; }
            .mobile-header-phone { margin-left: 8px; margin-right: 18px; font-size: clamp(.82rem, 3.65vw, 1rem); }
        }
        @media (max-width: 380px) {
            .header-brand .logo-wrapper { flex: none; width: 100px; max-width: 100%; }
            .header-brand .logo-img { height: 26px; }
            .mobile-header-phone { margin-right: 12px; }
            .burger-menu { width: 26px; }
        }

        @media (min-width: 1281px) {
            .header-wrapper { gap: 20px; }
            .logo-wrapper { flex-shrink: 0; }
            .nav-menu, .nav-links { gap: 16px; }
            .nav-links { margin-right: 24px; }
        }
        @media (max-width: 1280px) {
            .nav-menu { overflow-y: auto; justify-content: flex-start; }
            .header-callback { width: 100%; }
        }

        .hero-form #submitBtn { margin-top: 25px; }
        .form-consent { margin-top: 20px; font-size: .85rem; color: #666; text-align: center; }
        .hero-form .photo-upload-area .photo-hint {
            font-size: .875rem; font-weight: 400; color: var(--gray); line-height: 1.4; margin-top: 4px;
        }
        .hero-form .photo-gallery:empty { display: none; margin-top: 0; }
        @media (min-width: <?= $phoneHeroDesktopMinWidth ?>px) {
            .hero {
                min-height: 100vh; min-height: 100svh;
                padding: calc(var(--header-height, 90px) + 24px) 0 32px;
                display: flex; align-items: center;
            }
            .hero > .container { width: 100%; }
            .hero-form .form-card { padding: 24px; }
            .hero-form .form-title { margin-bottom: 18px; line-height: 1.3; }
            .hero-form .form-grid { gap: 12px; }
            .hero-form .form-group { margin-bottom: 0; }
            .hero-form .form-group input, .hero-form .form-group select,
            .hero-form .form-group .condition-select-trigger {
                min-height: 50px; padding-block: 10px;
            }
            .hero-form .photo-upload-area { padding: 14px 16px; margin: 0; }
            .hero-form .photo-upload-area:hover { transform: none; }
            .hero-form #submitBtn { margin-top: 16px; min-height: 52px; padding: 12px 20px; }
            .hero-form .form-consent { margin-top: 12px; line-height: 1.4; }
        }
        @media (min-width: <?= $phoneHeroDesktopMinWidth ?>px) and (max-height: 800px) {
            .hero { padding-top: calc(var(--header-height, 90px) + 16px); padding-bottom: 24px; }
            .hero-content h1 { font-size: 2.8rem; margin-bottom: 18px; }
            .hero-subtitle { font-size: 1.125rem; margin-bottom: 24px; }
            .hero-features { margin-bottom: 24px; }
            .hero-stats { margin-top: 28px; }
            .hero-stat-number { font-size: 2rem; }
        }

        [id] { scroll-margin-top: calc(var(--header-height, 80px) + 12px); }
        .hero-grid > *, .contacts-grid > *, .footer-grid > * { min-width: 0; }
        .header-wrapper { flex-wrap: nowrap; }
        .modal-content h3 { overflow-wrap: anywhere; }
        .hero-buttons .btn { flex-shrink: 0; }
        .faq-question { gap: 16px; }
        .faq-question i { flex-shrink: 0; }
        @media (max-width: 768px) {
            .footer { padding-bottom: 100px; }
        }
        .section { opacity: 1; transform: none; }
        @media (max-width: 1280px) {
            .nav-menu { overflow-y: auto; overscroll-behavior: contain; visibility: hidden; }
            .nav-menu.active { visibility: visible; }
        }
        /* Shared service navigation and landing-page components. */
        .hero-content h1 {
            font-size: clamp(2rem, 3.3vw, 2.8rem); line-height: 1.16;
            text-transform: none; text-wrap: balance; margin-bottom: 18px;
        }
        .hero-price-accent {
            color: #ff5252; font-size: clamp(1.65rem, 2.5vw, 2.2rem);
            font-weight: 800; line-height: 1.2; margin: 0 0 22px;
        }
        .hero-content .hero-home-title {
            font-size: clamp(2rem, 3.9vw, 3.2rem); line-height: 1.2;
            text-transform: uppercase; text-wrap: initial; margin-bottom: 30px;
        }
        .home-page .hero-home-title .hero-highlight { color: #f31d29; text-wrap: balance; }
        @supports (background-clip: text) or (-webkit-background-clip: text) {
            .home-page .hero-home-title .hero-highlight {
                background: linear-gradient(180deg, #ff2636 0%, #f21c2c 55%, #cc1424 100%);
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
            }
        }
        @media print, (forced-colors: active) {
            .home-page .hero-home-title .hero-highlight { background: none; color: #f31d29; }
        }
        .hero-buttons { flex-wrap: wrap; }
        .hero-buttons .btn { flex-shrink: 1; }
        .hero-buttons .btn-secondary {
            background: var(--button-red-bg);
            color: #fff; border-color: #c62b35;
            box-shadow: var(--button-red-shadow);
        }
        @media (hover: hover) and (pointer: fine) {
            .hero-buttons .btn-secondary:hover {
                background: var(--button-red-hover);
                box-shadow: 0 10px 22px rgba(0, 0, 0, .25), inset 0 1px 0 #fff, inset 0 -2px 0 rgba(91, 98, 112, .18);
            }
        }
        .hero-buttons .btn-secondary:active {
            box-shadow: 0 3px 8px rgba(0, 0, 0, .2), inset 0 2px 4px rgba(91, 98, 112, .2);
        }
        .service-section .section-title { font-size: clamp(1.65rem, 3vw, 2.35rem); line-height: 1.2; text-transform: none; text-wrap: balance; }
        .service-section .section-subtitle { max-width: 830px; margin-inline: auto; font-size: 1.1rem; }
        .service-card-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 22px; }
        .service-card { min-width: 0; background: white; border: 1px solid #e8eaee; border-radius: 24px; padding: 26px; }
        .service-card > i { color: var(--red); font-size: 1.8rem; margin-bottom: 20px; }
        .service-card h3 { font-size: 1.2rem; line-height: 1.3; margin-bottom: 12px; }
        .service-card p { color: #5b6471; font-size: 1rem; line-height: 1.65; }
        .service-process { list-style: none; margin: 0; padding: 0; }
        .service-step-number { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; margin-bottom: 20px; border-radius: 50%; background: var(--red); color: white; font-size: 1.25rem; font-weight: 800; }
        .service-final { background: #301718; color: white; }
        .service-final h2 { font-size: clamp(1.7rem, 3vw, 2.5rem); line-height: 1.2; margin-bottom: 24px; }
        .service-final p { line-height: 1.65; }
        .page-breadcrumbs { background: #fff; border-bottom: 1px solid #edf0f4; padding-block: 16px; }
        .page-breadcrumbs ol { display: flex; flex-wrap: wrap; gap: 6px 12px; list-style: none; font-size: .875rem; line-height: 1.5; color: #596574; }
        .page-breadcrumbs li { min-width: 0; overflow-wrap: anywhere; }
        .page-breadcrumbs li + li::before { content: '›'; margin-right: 12px; color: #8993a0; }
        .page-breadcrumbs a { color: #bd1f2e; text-decoration: none; }
        .page-breadcrumbs a:hover { text-decoration: underline; }
        .page-breadcrumbs a:focus-visible, .context-links a:focus-visible { outline: 3px solid #df2634; outline-offset: 4px; }
        .special-grid.special-grid--context { grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr)); }
        .special-grid.special-grid--four { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (min-width: 1100px) { .special-grid.special-grid--four { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 600px) { .special-grid.special-grid--four { grid-template-columns: minmax(0, 1fr); } }
        .special-grid--context .special-card p { line-height: 1.6; }
        .context-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 28px; }
        .context-links a { display: inline-flex; align-items: center; min-height: 44px; padding: 10px 15px; border: 1px solid #e1e6ed; border-radius: 999px; color: #b9202e; background: #fff; text-decoration: none; font-size: .9rem; }
        .context-links a:hover { border-color: #d72c3b; background: #fff6f6; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        @media (min-width: 1281px) and (max-width: 1500px) {
            .nav-menu, .nav-links { gap: 12px; }
            .nav-link { font-size: .88rem; }
            .header-phone { font-size: 1.2rem; letter-spacing: 0; }
            .header-callback { padding-inline: 14px; }
        }
        @media (max-width: 1280px) {
            .service-card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 768px) {
            .hero-content h1 { font-size: clamp(1.8rem, 5.7vw, 2.4rem); }
            .hero-content .hero-home-title { font-size: clamp(1.8rem, 7.7vw, 2.4rem); }
            .hero-price-accent { font-size: 1.7rem; }
            .service-section { padding-block: 55px; }
            .service-final .btn { white-space: normal; }
        }
        @media (max-width: 480px) {
            .service-card-grid { grid-template-columns: 1fr; gap: 16px; }
            .service-card { padding: 24px; border-radius: 20px; }
            .hero-buttons .btn { width: 100%; }
        }
        .cookie-notice {
            position: fixed; left: 50%; bottom: calc(22px + env(safe-area-inset-bottom, 0px));
            transform: translateX(-50%); width: min(860px, calc(100% - 240px));
            max-height: calc(100dvh - var(--header-height, 80px) - 36px); overflow-y: auto;
            z-index: 1200; display: flex; align-items: center; gap: 26px;
            padding: 22px 26px; border: 1px solid #754245; border-radius: 22px;
            background: #261b1d; color: white; box-shadow: 0 12px 40px #0005;
        }
        .cookie-notice[hidden] { display: none; }
        .cookie-notice-copy { min-width: 0; flex: 1; }
        .cookie-notice h2 { font-size: 1.05rem; line-height: 1.3; margin: 0 0 7px; color: white; }
        .cookie-notice p { margin: 0; font-size: .86rem; line-height: 1.55; color: #eadcdd; }
        .cookie-notice a { color: #ffb1b5; text-decoration: underline; text-underline-offset: 3px; }
        .cookie-notice .btn { flex-shrink: 0; min-height: 44px; padding: 12px 25px; font-size: .9rem; }
        .cookie-notice a:focus-visible, .cookie-notice button:focus-visible { outline: 2px solid white; outline-offset: 4px; }
        .cookie-info-link { border: 0; padding: 0; background: none; font: inherit; color: #a0aec0; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
        body:has(.modal.active) .cookie-notice,
        body:has(.nav-menu.active) .cookie-notice,
        body:has(#messengerWidgetToggle[aria-expanded="true"]) .cookie-notice,
        body:has(form:focus-within) .cookie-notice { visibility: hidden; pointer-events: none; }
        @media (max-width: 768px) {
            .cookie-notice { width: calc(100% - 24px); bottom: calc(12px + env(safe-area-inset-bottom, 0px)); padding: 18px; border-radius: 18px; gap: 14px; flex-direction: column; align-items: stretch; }
            .cookie-notice .btn { align-self: flex-end; }
            .form-in-view .cookie-notice { visibility: hidden; pointer-events: none; }
            .cookie-notice-open .back-to-top { visibility: hidden; pointer-events: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            html, body, .vslider-track { scroll-behavior: auto; }
            *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
        }
        .hero .hero-stat-label { color: #cbd5e1; }
        <?php if ($isPhotoHero): ?>
        /* Photo-led first screen for this advertising landing only. */
        .hero--vykup {
            background: #090909;
            padding: calc(var(--header-height, 90px) + 42px) 0 48px;
            min-height: 100svh;
        }
        .hero--vykup .hero-background {
            top: var(--header-height, 90px); height: calc(100% - var(--header-height, 90px));
            background: none; filter: none; transform: none;
        }
        .hero--vykup .hero-background img {
            display: block; width: 100%; height: 100%; object-fit: cover; object-position: 56% top;
        }
        .hero--vykup .hero-background::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(90deg, #070707d9 0%, #070707a6 30%, #07070700 49%),
                linear-gradient(0deg, #0707079c, #07070700 35%);
        }
        .hero--vykup > .container { max-width: 1640px; padding-inline: clamp(24px, 4vw, 64px); }
        .hero--vykup .hero-grid { grid-template-columns: minmax(0, 38fr) minmax(0, 32fr); column-gap: 30%; }
        .hero--vykup .hero-content .hero-home-title {
            font-size: clamp(2rem, 2.85vw, 3rem); line-height: 1.08; margin-bottom: 22px;
            font-weight: 800; letter-spacing: -.035em; text-shadow: 0 2px 16px #0006;
        }
        .hero--vykup .hero-title-service { display: block; color: #ff202e; font-size: 1.22em; }
        .hero--vykup .hero-title-location { display: block; margin-top: 7px; }
        .hero--vykup .hero-title-price {
            display: block; margin-top: 20px; padding-top: 18px;
            border-top: 5px solid #f31d29; font-size: .9em; line-height: 1.15;
        }
        .hero--vykup .hero-subtitle { font-size: 1rem; line-height: 1.65; margin-bottom: 24px; color: #f0f0f0; }
        .hero--vykup .hero-features { display: flex; flex-direction: row; flex-wrap: wrap; gap: 14px 24px; margin-bottom: 28px; }
        .hero--vykup .hero-feature { font-size: .88rem; gap: 8px; }
        .hero--vykup .hero-feature i { color: #ff202e; animation: none; }
        .hero--vykup .hero-buttons { flex-direction: row; gap: 12px; flex-wrap: nowrap; }
        .hero--vykup .hero-buttons .btn { padding: 15px 21px; font-size: .9rem; white-space: nowrap; }
        .hero--vykup .hero-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 30px; }
        .hero--vykup .hero-stat-item { text-align: left; }
        .hero--vykup .hero-stat-number { color: #ff2937; font-size: clamp(1.6rem, 2.1vw, 2.1rem); }
        .hero--vykup .hero-stat-label { font-size: .75rem; line-height: 1.4; color: #e0e0e0; }
        .hero--vykup .hero-form .form-card { padding: 24px; border-radius: 26px; box-shadow: 0 20px 60px #0006; }
        .hero--vykup .hero-form .form-title { font-size: clamp(1.15rem, 1.5vw, 1.45rem); margin-bottom: 20px; }
        .hero--vykup .hero-form .form-grid { gap: 12px; }
        .hero--vykup .hero-form .form-group { margin-bottom: 0; }
        .hero--vykup .hero-form .form-group input,
        .hero--vykup .hero-form .form-group select,
        .hero--vykup .hero-form .condition-select-trigger { font-size: .85rem; min-height: 48px; padding-block: 11px; }
        .hero--vykup .hero-form .photo-upload-area { padding: 16px 10px; margin: 0; }
        .hero--vykup .hero-form .photo-upload-area > span:first-child { font-size: 1rem !important; }
        .hero--vykup .hero-form .photo-upload-area .photo-hint { font-size: .72rem; }
        .hero--vykup .hero-form #submitBtn { min-height: 50px; margin-top: 15px; padding: 12px 10px; font-size: .88rem; }
        .hero--vykup .hero-form .form-consent { margin-top: 12px; font-size: .72rem; line-height: 1.5; }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--vykup { min-height: 0; padding-top: calc(var(--header-height, 80px) + 310px); padding-bottom: 36px; }
            .hero--vykup .hero-background { height: 690px; }
            .hero--vykup .hero-background img { object-position: 54% top; }
            .hero--vykup .hero-background::after { background: linear-gradient(0deg, #090909 2%, #090909e6 35%, #09090900 77%); }
            .hero--vykup .hero-grid { grid-template-columns: minmax(0, 1fr); gap: 32px; max-width: 680px; margin-inline: auto; }
            .hero--vykup .hero-content .hero-home-title { font-size: clamp(2rem, 4.8vw, 2.75rem); }
            .hero--vykup .hero-title-price { font-size: .85em; max-width: 580px; }
            .hero--vykup .hero-form .form-title { font-size: 1.4rem; }
            .hero--vykup .hero-form .form-group input,
            .hero--vykup .hero-form .form-group select,
            .hero--vykup .hero-form .condition-select-trigger { font-size: 16px; }
        }
        @media (max-width: 600px) {
            .hero--vykup { padding-top: calc(var(--header-height, 80px) + 275px); padding-bottom: 28px; }
            .hero--vykup > .container { padding-inline: 18px; }
            .hero--vykup .hero-background { height: 520px; }
            .hero--vykup .hero-background::after { background: linear-gradient(0deg, #090909 0%, #090909eb 25%, #09090900 72%); }
            .hero--vykup .hero-content .hero-home-title { font-size: clamp(1.6rem, 6.5vw, 2.4rem); line-height: 1.12; margin-bottom: 20px; }
            .hero--vykup .hero-title-service { font-size: 1.32em; }
            .hero--vykup .hero-title-location { margin-top: 5px; }
            .hero--vykup .hero-title-price { margin-top: 16px; padding-top: 14px; font-size: .93em; border-top-width: 4px; }
            .hero--vykup .hero-subtitle { font-size: .9rem; line-height: 1.6; margin-bottom: 20px; }
            .hero--vykup .hero-features { gap: 12px 18px; margin-bottom: 22px; }
            .hero--vykup .hero-feature { flex: 1 1 calc(50% - 18px); font-size: .77rem; }
            .hero--vykup .hero-buttons { flex-wrap: wrap; }
            .hero--vykup .hero-buttons .btn { width: auto; flex: 1 1 145px; padding: 13px 12px; font-size: .8rem; }
            .hero--vykup .hero-stats { gap: 10px; margin-top: 25px; }
            .hero--vykup .hero-stat-number { font-size: clamp(1.1rem, 5.5vw, 1.55rem); white-space: nowrap; }
            .hero--vykup .hero-stat-label { font-size: .65rem; }
            .hero--vykup .hero-form .form-card { padding: 22px 18px; border-radius: 22px; }
            .hero--vykup .hero-form .form-title { font-size: 1.16rem; }
            .hero--vykup .hero-form .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .hero--vykup .hero-form .photo-upload-group { grid-column: 1 / -1; }
            .hero--vykup .hero-form .form-group:has([name="name"], [name="phone"], [name="car_brand"], [name="car_condition"]) { grid-column: 1 / -1; }
            .hero--vykup .hero-form .form-group input,
            .hero--vykup .hero-form .form-group select,
            .hero--vykup .hero-form .condition-select-trigger { padding-left: 35px; font-size: 16px; }
            .hero--vykup .hero-form .form-group > i { left: 12px; font-size: .95rem; }
            .hero--vykup .hero-form .condition-select-trigger { padding-left: 12px; padding-right: 25px; }
        }
        @media (max-width: 360px) {
            .hero--vykup .hero-form .form-grid { grid-template-columns: minmax(0, 1fr); }
        }
        <?php if ($isPhoneHero): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--vykup { padding-top: calc(var(--header-height, 80px) + 490px); }
        }
        @media (max-width: 600px) {
            .hero--vykup { padding-top: calc(var(--header-height, 80px) + 370px); }
        }
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($isPhoneHero): ?>
        /* One live form: on desktop it shares the photograph's screen coordinates. */
<?php if ($isDesktopPhotoHero): ?>        .hero--sell .hero-form {
            position: relative; width: 100%; max-width: 410px; justify-self: end;
            padding: 8px; border: 1px solid #77787d; border-radius: 43px;
            background: #121315; box-shadow: 0 24px 70px #0009, inset 0 0 0 2px #303136;
        }
        .hero--sell .hero-form::before {
            content: ''; position: absolute; top: 17px; left: 50%; transform: translateX(-50%);
            width: 70px; height: 18px; border-radius: 12px; background: #151619; z-index: 3; pointer-events: none;
        }
        .hero--sell .hero-form::after {
            content: ''; position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%);
            width: 90px; height: 4px; border-radius: 3px; background: #333; z-index: 3; pointer-events: none;
        }
        .hero--sell .hero-form .form-card { padding: 44px 18px 28px; border-radius: 34px; transform: none; }
        .hero--sell .hero-form .form-title { font-size: 1.15rem; margin-bottom: 18px; }
        .hero--sell .hero-form .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .hero--sell .hero-form .form-group:has([name="name"], [name="phone"], [name="car_brand"], [name="car_condition"]) { grid-column: 1 / -1; }
        .hero--sell .hero-form .form-group input,
        .hero--sell .hero-form .form-group select,
        .hero--sell .hero-form .condition-select-trigger { min-height: 48px; font-size: 16px; }
        .hero--sell .hero-form .form-group > i { left: 12px; }
        .hero--sell .hero-form .form-group input { padding-left: 35px; }
        .hero--sell .hero-form .photo-upload-group { grid-column: 1 / -1; }
        .hero--sell .hero-form .photo-upload-area { padding: 14px 10px; }
        .hero--sell .hero-form .photo-upload-area .photo-hint { font-size: .78rem; }
        .hero--sell .hero-form .form-consent { font-size: .75rem; padding-bottom: 8px; }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            :root {
                --hero-stage-width: clamp(1458px, 82vw, 1920px);
                --site-container-width: calc(1300px + (var(--hero-stage-width) - 1458px) * .85759294266);
            }
            .container { max-width: var(--site-container-width); }
            .hero--sell {
                --hero-canvas-width: calc(var(--hero-stage-width) * 2.58034026465028);
                --hero-wing-shift: calc(var(--hero-stage-width) * .07120352867 - max(25px, (var(--hero-stage-width) - var(--site-container-width) + 50px) / 2));
                --hero-grid-inset: max(25px, calc((100% - var(--site-container-width) + 50px) / 2));
                --hero-photo-shift: calc(7.120352867% - var(--hero-grid-inset));
                min-height: 0; padding: var(--header-height, 90px) 0 0;
            }
            .hero--sell > .container { position: relative; width: 100%; max-width: var(--hero-stage-width); padding: 0; container-type: inline-size; }
            .hero--sell .hero-grid { display: block; position: relative; aspect-ratio: 1587 / 991; }
            .hero--sell .hero-background {
                top: var(--header-height, 90px); bottom: 0; left: 50%;
                width: 100%; max-width: var(--hero-stage-width); height: auto; transform: translateX(calc(-50% + var(--hero-photo-shift)));
                overflow: visible;
            }
            .hero--sell .hero-background img {
                position: relative; left: -79.017013232514%;
                width: 258.034026465028%; max-width: none; object-fit: fill; object-position: center;
            }
            .hero--sell .hero-background::after {
                left: -79.017013232514%; right: -79.017013232514%;
                background: linear-gradient(90deg, #070707b3 0%, #070707b3 30.622710622711%, #07070780 42.63663003663%, #07070740 47.741147741148%, #07070700 49.57264957265%);
            }
            /* Beyond the generated canvas, continue only its outer background wings. */
            .hero--sell::before, .hero--sell::after {
                content: ''; position: absolute; top: var(--header-height, 90px); bottom: 0;
                z-index: 0; pointer-events: none; background-repeat: no-repeat; transform: scaleX(-1);
            }
            .hero--sell::before {
                left: 0; width: max(0px, calc((100% - var(--hero-canvas-width)) / 2 + var(--hero-wing-shift) + 1px));
                background-image: linear-gradient(#070707b3, #070707b3), url('/hero-prodat-phone.webp?v=0141e18b9b90');
                background-size: 100% 100%, max(var(--hero-canvas-width), 326.555023923445%) 100%; background-position: left top;
            }
            .hero--sell::after {
                right: 0; width: max(0px, calc((100% - var(--hero-canvas-width)) / 2 - var(--hero-wing-shift) + 1px));
                background-image: url('/hero-prodat-phone.webp?v=0141e18b9b90');
                background-size: max(var(--hero-canvas-width), 326.555023923445%) 100%; background-position: right top;
            }
            .hero--sell .hero-content { position: absolute; left: var(--hero-grid-inset); top: 6.5%; width: min(44%, max(540px, calc(var(--hero-stage-width) * .37037037037))); }
            .hero--sell .hero-content .hero-home-title { font-size: clamp(1.75rem, 2.55vw, 2.6875rem); }
            .hero--sell .hero-title-price { font-size: min(.9em, 2.22rem); }
            .hero--sell .hero-subtitle { font-size: .95rem; line-height: 1.6; }
            .hero--sell .hero-form {
                position: absolute; left: calc(70.26% + var(--hero-photo-shift)); top: 11.5%; width: 20.8%; height: 68%;
                max-width: none; padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none;
                container-type: inline-size;
            }
            .hero--sell .hero-form::before, .hero--sell .hero-form::after { content: none; }
            .hero--sell .hero-form .form-card {
                height: 100%; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin;
                scrollbar-color: #ccc transparent; scroll-padding-block: 12px;
                padding: 0 2px 8px; border-radius: 0; background: #fff; box-shadow: none;
            }
            .hero--sell .hero-form .form-title { font-size: clamp(1rem, 7.2cqw, max(1.2rem, calc(var(--hero-stage-width) * .01316872428))); margin: 0 0 14px; }
            .hero--sell .hero-form .form-grid { grid-template-columns: minmax(0, 1fr); gap: 8px; }
            .hero--sell .hero-form .form-group input,
            .hero--sell .hero-form .form-group select,
            .hero--sell .hero-form .condition-select-trigger { min-height: 40px; height: clamp(40px, 15cqw, 64px); padding-block: 8px; font-size: clamp(1rem, 5cqw, 1.25rem); }
            .hero--sell .hero-form .photo-upload-area { padding: 12px 8px; }
            .hero--sell .hero-form .photo-upload-area > span:first-child { font-size: clamp(.92rem, 4.9cqw, 1.125rem) !important; }
            .hero--sell .hero-form .photo-upload-area .photo-hint { font-size: clamp(.7rem, 3.6cqw, .85rem); }
            .hero--sell .hero-form #submitBtn { min-height: clamp(46px, 15cqw, 60px); margin-top: 12px; padding: 10px 6px; font-size: clamp(.79rem, 4.2cqw, 1rem); }
            .hero--sell .hero-form .form-consent { margin-top: 10px; font-size: clamp(.7rem, 3.4cqw, .85rem); line-height: 1.45; }
        }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) and (max-width: 1360px) {
            .hero--sell .hero-form .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) and (max-width: 1280px) {
            .hero--sell .hero-form .form-card { padding-bottom: 0; }
            .hero--sell .hero-form .form-title { margin-bottom: 10px; }
            .hero--sell .hero-form .form-grid { gap: 6px; }
            .hero--sell .hero-form .photo-upload-area { padding-block: 10px; }
            .hero--sell .hero-form #submitBtn { margin-top: 10px; }
            .hero--sell .hero-form .form-consent { margin-top: 8px; }
        }
<?php endif; ?>        .hero-mobile-scene, .phone-preview-trigger { display: none; }
        .modal--phone-estimate.phone-zoom-closing { display: flex; pointer-events: none; }
<?php if ($isDesktopPhotoHero): ?>        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            @container (max-width: 250px) {
                .hero--sell .hero-form .form-grid { grid-template-columns: minmax(0, 1fr); }
            }
        }
<?php endif; ?>        .hero--sell .hero-background picture { display: block; width: 100%; height: 100%; }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--sell {
                --hero-mobile-gutter: clamp(18px, 4vw, 32px);
                padding: calc(var(--header-height, 80px) + 24px) 0 32px;
                min-height: 0;
            }
            .hero--sell > .container { padding-inline: var(--hero-mobile-gutter); }
            .hero--sell .hero-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0; max-width: 680px; }
            .hero--sell .hero-content, .hero--sell .hero-content .hero-home-title { display: contents; }
            .hero--sell .hero-title-service {
                grid-row: 1; margin: 0 0 8px; font-size: clamp(2rem, 7.6vw, 3.1rem);
                font-weight: 800; line-height: 1.06; letter-spacing: -.035em; text-transform: uppercase;
            }
            .hero--sell .hero-title-location {
                grid-row: 2; margin: 0 0 18px; font-size: clamp(1.15rem, 5.4vw, 1.8rem);
                font-weight: 800; line-height: 1.14; letter-spacing: -.035em; text-transform: uppercase;
            }
            .form-in-view .messenger-widget:has(.messenger-widget-panel[hidden]):not(:focus-within) { visibility: hidden; pointer-events: none; }
            #estimateModal.modal--phone-estimate { padding: 0; align-items: stretch; }
            #estimateModal.modal--phone-estimate .estimate-dialog {
                width: 100%; max-width: none; height: 100vh; height: 100dvh; max-height: none; margin: 0;
                border: 0; border-radius: 0; background: #fff;
                box-shadow: none; transform-origin: 0 0;
            }
            #estimateModal.modal--phone-estimate .estimate-dialog::before {
                content: none;
            }
            #estimateModal.modal--phone-estimate .estimate-modal-body {
                height: 100%; max-height: 100%; padding-top: env(safe-area-inset-top, 0px);
                padding-bottom: env(safe-area-inset-bottom, 0px); background: #fff;
            }
            #estimateModal.modal--phone-estimate .modal-close {
                top: calc(8px + env(safe-area-inset-top, 0px)); right: 8px; width: 44px; height: 44px;
                color: #555; background: #fff;
            }
            #estimateModal.modal--phone-estimate .form-card { padding: 16px 20px 24px; }
            #estimateModal.modal--phone-estimate .form-title { padding-right: 44px; font-size: 1.25rem; }
            #estimateModal.modal--phone-estimate .form-group:has([name="name"], [name="phone"], [name="car_brand"], [name="car_condition"]) { grid-column: 1 / -1; }
            #estimateModal.modal--phone-estimate .form-group input,
            #estimateModal.modal--phone-estimate .form-group select,
            #estimateModal.modal--phone-estimate .condition-select-trigger { min-height: 44px; font-size: 16px; }
            #estimateModal.modal--phone-estimate #submitBtn { min-height: 48px; font-size: .9rem; }
            .hero--sell .hero-title-price {
                grid-row: 4; position: relative; max-width: none; margin: 0; padding: 0 0 15px;
                border: 0; font-size: clamp(1.25rem, 5vw, 1.9rem); font-weight: 800; line-height: 1.15;
                letter-spacing: -.035em; text-transform: uppercase;
            }
            .hero--sell .hero-title-price::after { content: ''; position: absolute; left: 0; bottom: 0; width: 42%; height: 3px; background: #ff202e; }
            .hero--sell .hero-subtitle { grid-row: 5; margin: 16px 0 22px; font-size: clamp(.9rem, 3.6vw, 1.05rem); line-height: 1.55; }
            .hero--sell .hero-features { grid-row: 6; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; margin: 0 0 24px; }
            .hero--sell .hero-feature { flex: none; gap: 8px; font-size: clamp(.72rem, 2.8vw, .95rem); line-height: 1.35; }
            .hero--sell .hero-feature i { flex: none; font-size: clamp(1.15rem, 5vw, 1.5rem); }
            .hero--sell .hero-buttons { grid-row: 7; display: flex; flex-wrap: nowrap; gap: 10px; }
            .hero--sell .hero-buttons .btn { width: auto; min-width: 0; flex: 1 1 0; min-height: 50px; padding: 12px 8px; font-size: clamp(.7rem, 2.7vw, .95rem); }
            .hero--sell .hero-stats { grid-row: 8; grid-template-columns: repeat(3, minmax(0, 1fr)); justify-content: stretch; gap: 0; margin-top: 26px; }
            .hero--sell .hero-stat-item { text-align: center; padding-inline: 6px; }
            .hero--sell .hero-stat-item + .hero-stat-item { border-left: 1px solid #666; }
            .hero--sell .hero-stat-number { font-size: clamp(1.4rem, 6vw, 2.1rem); line-height: 1.15; }
            .hero--sell .hero-stat-label { margin-top: 6px; font-size: clamp(.6rem, 2.25vw, .78rem); line-height: 1.35; }
        }
        <?php endif; ?>
        .hero-content > h1, .hero-copy-stage > h1 { text-align: center; }
        .hero-mobile-copy { display: none; }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--sell { background: #070707; padding-top: calc(var(--header-height, 80px) + 20px); }
            .hero--sell .hero-title-service {
                font-size: clamp(1.75rem, 10.5vw, 4.6rem); line-height: 1;
                letter-spacing: -.045em; text-wrap: balance; margin-bottom: 10px;
            }
            .hero--sell .hero-title-location { margin-bottom: 18px; text-wrap: balance; }
            .hero--sell .hero-title-price {
                grid-row: 3; margin: 0; padding: 18px 0 0;
                font-size: clamp(1.1rem, 4.8vw, 2rem); text-align: center;
            }
            .hero--sell .hero-title-price::after {
                top: 0; bottom: auto; left: 0; width: 100%; height: 2px;
                background: linear-gradient(90deg, transparent, #ff202e 35%, #ff6a70 50%, #ff202e 65%, transparent);
                box-shadow: 0 0 12px #ff202e60;
            }
            .hero--sell .hero-subtitle {
                grid-row: 4; margin: 8px 0 18px; text-align: center;
                font-size: clamp(.8rem, 3.4vw, 1.1rem); line-height: 1.45;
            }
            .hero--sell .hero-desktop-copy { display: none; }
            .hero--sell .hero-mobile-copy { display: inline; }
            .hero--sell .hero-buttons { grid-row: 6; margin: 8px 0 26px; }
            .hero--sell .hero-buttons > .btn-primary { display: none; }
            .hero--sell .hero-buttons > .btn-secondary {
                flex: 1; min-height: 58px; padding: 16px 14px; gap: 10px;
                font-size: clamp(.9rem, 4.2vw, 1.4rem); font-weight: 700; text-transform: uppercase;
                color: #fff; border: 1px solid #c62b35; border-radius: 999px;
                background: var(--button-red-bg);
                box-shadow: var(--button-red-shadow);
            }
            .hero--sell .hero-buttons > .btn-secondary::after { content: '→'; margin-left: auto; font-size: 1.35em; line-height: 1; }
            @media (hover: hover) and (pointer: fine) {
                .hero--sell .hero-buttons > .btn-secondary:hover {
                    background: var(--button-red-hover);
                    box-shadow: 0 10px 22px rgba(154,24,36,.32), inset 0 1px 0 rgba(255,255,255,.5), inset 0 -2px 0 rgba(105,12,24,.18);
                }
            }
            .hero--sell .hero-buttons > .btn-secondary:active {
                box-shadow: 0 3px 8px rgba(154,24,36,.22), inset 0 2px 4px rgba(105,12,24,.24);
            }
            @media (max-width: 360px) {
                .hero--sell .hero-buttons > .btn-secondary { padding-inline: 10px; gap: 8px; }
            }
            .hero--sell .hero-features { grid-row: 7; gap: 18px 14px; margin: 0; }
            .hero--sell .hero-feature { gap: 10px; font-size: clamp(.75rem, 3.25vw, 1.1rem); line-height: 1.3; }
            .hero--sell .hero-feature i {
                display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
                width: 36px; height: 36px; border: 1px solid #f31d29; border-radius: 50%;
                color: #ff2030; font-size: 18px; background: radial-gradient(circle, #6e001a50, transparent 75%);
            }
            .hero--sell .hero-stats { margin-top: 28px; }
        }
<?php if ($isPhoneHero): ?>
<?php if ($isAutovykupPage): ?>        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
<?php endif; ?>        .hero--offer .hero-title-lead { display: block; white-space: nowrap; }
        .hero--offer .hero-title-followup { display: block; color: #fff; line-height: 1.02; }
        .hero--offer .hero-title-service--compact .hero-title-lead { font-size: .9em; }
        .hero--offer .hero-title-service--long .hero-title-lead { font-size: .72em; }
        .hero--offer .hero-title-service--catalog .hero-title-lead { font-size: .72em; }
        .hero--offer .hero-title-service--service-long .hero-title-followup { font-size: .76em; }
        .hero--offer .hero-feature-icon {
            display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
            width: 32px; height: 32px; border: 2px solid #f31d29; border-radius: 7px;
            color: #ff2030; background: #10060680;
        }
        .hero--offer .hero-feature-icon > svg {
            display: block; width: 100%; height: 100%; fill: none; stroke: currentColor;
            stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }
        .hero--offer .hero-feature { display: grid; grid-template-columns: auto minmax(0, 1fr); text-align: center; }
<?php if ($isAutovykupPage): ?>        }
<?php endif; ?><?php if ($isDesktopPhotoHero): ?>        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer .hero-content .hero-home-title {
                font-size: clamp(2.75rem, 4.3vw, 4rem); line-height: 1.01;
                text-align: left; margin-bottom: 12px; letter-spacing: -.035em;
            }
            .hero--offer .hero-title-service { font-size: 1em; }
            .hero--offer .hero-title-service--catalog .hero-title-lead { font-size: .60em; }
            .hero--offer .hero-title-location { font-size: 1em; margin-top: 0; white-space: nowrap; }
            .hero--offer .hero-title-price {
                margin-top: 8px; padding: 0; border: 0;
                font-size: clamp(1.45rem, 2.05vw, 2rem); line-height: 1.2;
            }
            .hero--offer .hero-title-price::after { content: none; }
            .hero--offer .hero-subtitle { margin-bottom: 18px; font-size: .96rem; line-height: 1.4; color: #f0f0f0; }
            .hero--offer .hero-features {
                display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 12px; margin-bottom: 20px;
            }
            .hero--offer .hero-feature { min-width: 0; gap: 8px; font-size: .75rem; font-weight: 400; line-height: 1.3; text-align: left; }
            .hero--offer .hero-feature-icon { width: 28px; height: 28px; }
            .hero--offer .hero-buttons { gap: 12px; }
            .hero--offer .hero-buttons .btn {
                flex: 1 1 0; min-width: 0; min-height: 58px; padding: 15px 14px; gap: 10px;
                border-radius: 999px; font-size: clamp(.85rem, 1vw, .95rem);
            }
            .hero--offer .hero-buttons .btn::after { content: none; }
            .hero--offer .hero-stats { margin-top: 22px; }
        }
<?php endif; ?>        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer .hero-buttons { grid-row: 5; }
            .hero--offer .hero-buttons > .btn-secondary::after { content: none; }
            .hero--offer .hero-subtitle { grid-row: 6; margin: 0 0 24px; }
        }
        /* Size desktop type with the actual photo stage, including short displays. */
<?php if ($isDesktopPhotoHero): ?>        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer .hero-content {
                --hero-grow: clamp(0px, calc(100cqw - 1120px), 800px);
                --desktop-title-column-width: min(44cqw, max(540px, calc(var(--hero-stage-width) * .37037037037)));
            }
            .hero--offer .hero-content .hero-home-title {
                font-size: calc(var(--desktop-title-column-width) * <?= $desktopHeroTitleScale ?>);
                margin-bottom: calc(12px + var(--hero-grow) / 68);
            }
            .hero--offer .hero-title-price {
                font-size: clamp(1.45rem, 2.5cqw, 3rem);
            }
            .hero--offer .hero-subtitle {
                font-size: clamp(.94rem, 1.28cqw, 1.5rem);
            }
            .hero--offer .hero-feature {
                font-size: clamp(.75rem, 1.03cqw, 1.25rem);
                text-shadow: 0 1px 2px #000, 0 0 4px #000;
            }
            .hero--offer .hero-feature-icon {
                width: clamp(28px, 2.4cqw, 48px);
                height: clamp(28px, 2.4cqw, 48px);
            }
            .hero--offer .hero-buttons .btn {
                min-height: clamp(54px, 4.5cqw, 86px);
                padding: 14px 12px;
                font-size: clamp(.85rem, 1.1cqw, 1.25rem);
            }
            .hero--offer .hero-stat-number { font-size: clamp(1.6rem, 2.6cqw, 3.125rem); }
            .hero--offer .hero-stat-label { font-size: clamp(.75rem, .95cqw, 1.15rem); }
        }
<?php endif; ?>
<?php if ($isServicePage && $serviceSlug === 'vykup-neispravnyh-avto'): ?>
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer .hero-title-service--service-long .hero-title-followup { font-size: calc(var(--desktop-title-column-width) * .1278); }
        }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) and (max-width: 1308px) {
            .hero--offer .hero-title-service--service-long .hero-title-followup { font-size: calc(var(--desktop-title-column-width) * .0947); }
        }
<?php endif; ?>        /* Mobile offer first; the existing photograph and live form follow the actions. */
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer { padding-top: calc(var(--header-height, 80px) + 32px); }
            .hero--offer .hero-grid { position: relative; isolation: isolate; }
            .hero--offer .hero-grid::before {
                content: ''; position: absolute; grid-area: 1 / 1 / 7 / 2;
                inset: -32px calc(var(--hero-mobile-gutter) * -1) -28px; z-index: -1;
                background: radial-gradient(ellipse at 65% 60%, #281710, #0b0808 62%, #070707 87%);
                pointer-events: none;
            }
            .hero--offer .hero-title-service { grid-row: 1; text-align: center; margin-bottom: 8px; }
            .hero--offer .hero-title-location {
                grid-row: 2; margin-bottom: 12px; text-align: center;
                font-size: clamp(2rem, 10.8vw, 4.2rem); line-height: 1.04;
            }
            .hero--offer .hero-title-price {
                grid-row: 3; padding: 0; font-size: clamp(1.05rem, 4.7vw, 1.75rem); line-height: 1.2;
            }
            .hero--offer .hero-title-price::after { content: none; }
            .hero--offer .hero-subtitle {
                grid-row: 4; margin: 14px 0 26px; font-size: clamp(.85rem, 3.55vw, 1.05rem); line-height: 1.5;
            }
            .hero--offer .hero-features {
                grid-row: 5; grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 10px; margin: 0 0 28px;
            }
            .hero--offer .hero-feature {
                grid-template-columns: minmax(0, 1fr); justify-items: center; align-content: center;
                gap: 6px; min-width: 0; min-height: 104px; padding: 10px 6px;
                border: 1px solid #ffffff29; border-radius: 12px; background: #050505;
                box-shadow: inset 0 1px 1px #ffffff08, 0 4px 12px #0004;
                font-size: clamp(.7rem, 3.1vw, .95rem); line-height: 1.3;
            }
            .hero--offer .hero-feature-icon {
                width: clamp(36px, 10vw, 46px); height: clamp(36px, 10vw, 46px); padding: 0;
                border: 0; border-radius: 0; background: none; box-shadow: none;
            }
            .hero--offer .hero-feature > span:last-child { width: 100%; min-height: 2.6em; }
            .hero--offer .hero-feature:nth-child(3) { grid-area: 2 / 1; }
            .hero--offer .hero-feature:nth-child(4) { grid-area: 1 / 3; }
            .hero--offer .hero-buttons { grid-row: 6; flex-direction: column; gap: 12px; margin: 0 0 28px; }
            .hero--offer .hero-buttons > .btn {
                display: inline-flex; width: 100%; flex: none; justify-content: center; align-items: center;
                min-height: 58px; padding: 15px 18px; gap: 12px; border-radius: 999px;
                font-size: clamp(1rem, 4.4vw, 1.3rem); font-weight: 700; text-transform: none;
            }
            .hero--offer .hero-buttons > .btn-secondary {
                color: #fff; border: 1px solid #c62b35;
                background: var(--button-red-bg);
                box-shadow: var(--button-red-shadow);
            }
            @media (hover: hover) and (pointer: fine) {
                .hero--offer .hero-buttons > .btn-secondary:hover {
                    color: #fff; background: var(--button-red-hover);
                    box-shadow: 0 9px 22px #0005, inset 0 1px 0 #fff;
                }
            }
            .hero--offer .hero-buttons > .btn-secondary:active {
                color: #fff; background: var(--button-red-hover);
                box-shadow: 0 3px 9px #0004, inset 0 2px 4px #0001;
            }
            .hero--offer .hero-buttons > .btn::after { content: none; }
            .hero--offer .hero-buttons .hero-desktop-copy { display: inline; }
            .hero--offer .hero-buttons .hero-mobile-copy { display: none; }
            .hero--offer .hero-stats { grid-row: 8; }
        }
<?php if ($isPortraitHero): ?>
        /* Oswald Bold: Copyright 2016 The Oswald Project Authors; SIL OFL 1.1, https://scripts.sil.org/OFL. */
        @font-face { font-family: 'Avtokai Hero'; font-style: normal; font-weight: 700; font-display: swap; src: url(data:font/ttf;base64,AAEAAAAQAQAABAAAR0RFRgOMAtUAAAJEAAAAYkdQT1MvDyDDAAAJ3AAADO5HU1VCHKMh2AAAAqgAAAByT1MvMrBXnLoAAAHkAAAAYFNUQVR5lWtJAAABgAAAACpjbWFwXRJeuQAABAwAAAHKZ2FzcAAAABAAAAEUAAAACGdseWa4ZjhDAAAWzAAAGWpoZWFkGnPnDQAAAawAAAA2aGhlYQk7AzYAAAFcAAAAJGhtdHj9lBIyAAAF2AAAAdhsb2Nhbyl2DQAAAxwAAADwbWF4cACHAM8AAAEcAAAAIG5hbWUtblCxAAAHsAAAAixwb3N0/58AMgAAATwAAAAgcHJlcGgGjIUAAAEMAAAAB7gB/4WwBI0AAAEAAf//AA8AAQAAAHcAZQAHAGgABQABAAAAAAAAAAAAAAAAAAMAAQADAAAAAAAA/5wAMgAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAASp/t8AAAWf/yH+QgVuAAEAAAAAAAAAAAAAAAAAAAB1AAEAAQAIAAEAAAAUAAEAAAAcAAJ3Z2h0AQAAAAACAAEAAAAAAQYCvAAAAAAAAQAAAAQaXjsqygdfDzz1AAMD6AAAAADV6qBlAAAAAODe56b/If6bBW4FJgABAAYAAgAAAAAAAAAEAgUCvAAFAAACigJYAAAASwKKAlgAAAFeADIBWwAAAAAAAAAAAAAAAKAAAv9AACBLAAAAAAAAAABuZXd0AKAAICAUBKn+3wAABS0BeSAAAZcAAAAAAkIDKgAAACAAAwABAAIADgAAAAAAAABOAAIACgABACgAAQAqACsAAQAtADMAAQA1ADcAAQA5AD0AAQA/AEAAAQBFAEUAAQBHAEcAAQBKAFUAAQB1AHYAAwABAAIAAAAMAAAADAABAAIAdQB2AAAAAQAAAAoAJAAyAAJERkxUAA5sYXRuAA4ABAAAAAD//wABAAAAAWZyYWMACAAAAAEAAAABAAQABAAAAAEACAABACwAAgAWAAoAAQAEAGMAAwBsAFoAAgAOAAYAYQADAGwAWABiAAMAbABaAAEAAgBXAFkAAAAAABQALAA4AEQAgAC5AN8A8wD/AQsBHgFbAWcBfQGJAZUBoQG6AdMB4QH9AhICSgJWAmICiALQAvoDPgNPA3MDfwOLA50DuQPFA94D8wP/BBMEGwRDBEsEWgSIBJAEnATDBRMFKAU0BU4FbwV3BX8FhwWZBaEFqQWxBd4F6gZKBlIGdAaMBqIGvwblBxMHPAd5B7oH5AfwB/wICAgUCCAILAg4CEQIUAhcCGgIdAisCMYI8wlCCVwJlQniCfMKTwqaCqgKuArICtgK6QsTC1ELagtqC3ULigucC6kLtQvBC80L6QwDDBcMcgyLDJ0MtQAAAAIAAAADAAAAFAADAAEAAAAUAAQBtgAAAFQAQAAFABQAIAAlAC8AOQA6AFoAqwC7AMQAywDPANYA3AECARQBHgEsAU4BbAF4AwYDCAQBBA4EFQQjBC8EwQTQBNIE1gTcBN4E5ATmBOwE8AT0BPgehCAU//8AAAAgACUAKwAwADoAQQCrALsAxADLAM8A1gDcAQIBFAEeASwBTgFsAXgDBgMIBAEEDgQQBBYEJATBBNAE0gTWBNwE3gTkBOYE7ATwBPQE+B6EIBP//wBIAE4AAAAmADEAAP/F/7b/P/8+/0H/Qv9E/wD+9P7u/uP+yf6z/q79cP1t/C38L/wY/BkAAPuJ+3v7evt3+3L7cfts+2v7Zvtj+2D7XeGf4FsAAQAAAAAAUAAAAAAAVAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAXgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAByAGoAbQBpAGwAAQAEAAUABgAHAAoACwANAA4AEQASABMAFAAVABYAGQAaABsAHAAdAB4AIQAiACQAJQAnAD4APwBBAEAAQgBDAEYARQBEAEcASABJAAACmwBSAicAFQInABUCJwAVAkwAPAIzADECSgA8Ab8APAG/ADwBvwA8AbIAPAJGADECRgAxAmIAPwEtAD0BLf/5AS3/6AFdAA0CNwA8AbsAPALAADkCMQA8AkoAMQJKADECSgAxAjsAPAJKADECWAA8AgIAKgG9AAsCTAA2AkwANgJMADYCDgAWArkAGwK5ABsCAwAHAe0ACAHtAAgBsQAdAicAFQIqAD8CTAA8AdAAPwK1ABIBvwA8Ab8APAMbAAYCDgAiAk4APwJOAD8CPAA/ApAABwLAADkCYgA/AkoAMQJdADwCOwA8AjMAMQG9AAsCKgAWAioAFgL3AC4CAwAHAmEALQKlAEEDOQA/A3gARQIsAD8DPwA/AngADQI/ADIDOQA/Al4ACgMbAAYCJwAVAicAFQG/ADwDGwAGAg4AIgJOAD8CSgAxAj8AMgIqABYCYQAtAz8APwImADYBgQAWAgIAJwICACYCDAAZAf0AKwIaADQBtwAUAgkAJgIaACcArP9YA38AGgNUACIDmgA8AXgAQQHaAE8B2gBQAeMAQAEAAAAA9AAuAO4AKwEWAEMBpgArAUQAIAIuACAEGwAgAegAJQHuAC0BwQAaA+4ALQAAAIT/Uv9jAAAACgB+AAMAAQQJAAAAqgEEAAMAAQQJAAEADAD4AAMAAQQJAAIACADwAAMAAQQJAAMALADEAAMAAQQJAAQAFgCuAAMAAQQJAAUAVgBYAAMAAQQJAAYAFgBCAAMAAQQJAA4ANgAMAAMAAQQJAQAADAAAAAMAAQQJAQYACADwAFcAZQBpAGcAaAB0AGgAdAB0AHAAcwA6AC8ALwBzAGMAcgBpAHAAdABzAC4AcwBpAGwALgBvAHIAZwAvAE8ARgBMAE8AcwB3AGEAbABkAC0AQgBvAGwAZABWAGUAcgBzAGkAbwBuACAANAAuADEAMAAzADsAZwBmAHQAbwBvAGwAcwBbADAALgA5AC4AMwAzAC4AZABlAHYAOAArAGcAMAAyADkAZQAxADkAZgBdAE8AcwB3AGEAbABkACAAQgBvAGwAZAA0AC4AMQAwADMAOwBuAGUAdwB0ADsATwBzAHcAYQBsAGQALQBCAG8AbABkAEIAbwBsAGQATwBzAHcAYQBsAGQAQwBvAHAAeQByAGkAZwBoAHQAIAAyADAAMQA2ACAAVABoAGUAIABPAHMAdwBhAGwAZAAgAFAAcgBvAGoAZQBjAHQAIABBAHUAdABoAG8AcgBzACAAKABoAHQAdABwAHMAOgAvAC8AZwBpAHQAaAB1AGIALgBjAG8AbQAvAGcAbwBvAGcAbABlAGYAbwBuAHQAcwAvAE8AcwB3AGEAbABkAEYAbwBuAHQAKQABAAAACgAoAE4AAkRGTFQADmxhdG4ADgAEAAAAAP//AAMAAAABAAIAA2tlcm4AIG1hcmsAGm1rbWsAFAAAAAEAAgAAAAEAAQAAAAEAAAADAmIAMAAIAAYAEAABAAoAAQABAkgCSAABAjgADAACAAwABgABAAADIgABAAADKgAEAAAAAQAIAAECIgHYAAECEgAMAEkBxgHAAboBtAGuAagBogGcAZYBkAGKAYQBfgF4AXIBbAFmAWABWgFUAU4BqAFIAUIBPAGoATYBMAEqASQBHgEYARIBDAEGATABAAD6APQBxgG0AO4BogGWAOgBEgDiANwA1gFUAX4BqAE8Aa4BKgDQAMoBMAF+AMQAvgDoAcYBugGiALgAsgCsAUIApgCgAJoAlAABAaAEEgABATEEEgABARUEEgABASAEEgABATQEEgABAQcEEgABAY4EEgABASADKgABAaADKgABARUECgABARUDKgABAR8DKgABATQECgABATQDKgABAY4DKgABAOgDKgABANkDKgABAPcEEgABAPcDKgABAV0EEgABAV0DKgABAQcDKgABASYEEgABASYECgABASYDKgABAN4DKgABAQEDKgABASwDKgABAR4DKgABASUEEgABASUECgABARgDKgABAWADKgABAJUDKgABASkDKgABAMgDKgABAJYEEgABAJYECgABAJYDKgABATEDKgABASMECgABASMDKgABANoDKgABAPQEEgABAPQECgABAPQDKgABASUDKgABASIDKgABAScDKgABARQEEgABARQECgABARQDKgACAAkAAQAoAAAAKgArACgALQAzACoANQA3ADEAOQA9ADQAPwBAADkARQBFADsARwBHADwASgBVAD0AAgAAAAoAAAAKAAEAAAJCAAEAAgB1AHYAAgAIAAIGhgAKAAIEbAAEAAAFoATEAB8AEgAAAAAAAAAA//MAAP/vAAAAAP/4AAD/+P/vAAAAAP/4AAAAAAAAAAAAAP/zAAD/+P/UAAD/+P/T/+wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//oAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//gAAAAAAAAAAAAAAAAAAP/vAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//gAAAAAAAAAAP/v//P/7wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/v/9QAAAAAAAD/+AAAAAAAAP+zAAAAAP/7//j/xwAAAAAAAAAAAAAAAP/wAAAAAP/w//0AAAAAAAAAAAAAAAAAAAAAAAAAAAAA//gAAP/4AAD/7wAAAAAAAAAAAAAAAAAA//gAAAAAAAAAAAAAAAAAAP/8AAAAAAAAAAAAAP/4AAAAAAAAAAAAAAAAAAAAAP/4AAAAAAAAAAD/+gAAAAAAAAAAAAAAAAAAAAAAAAAAAAD/+gAAAAAAAAAAAAAAAAAAAAAAAP/G/7UAAAAAAAD/vAAAAAAAAP/4AAD/+v/pAAD//P/1//0AAAAAAAAAAAAAAAAAAAAAAAAAAP/4/9MAAAAAAAAAAAAAAAAAAP/GAAAAAP/k//r/vAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/bAAAAAAAAAAAAAAAAAAAAAAAA/+wAAAAAAAD//AAAAAAAAP/ZAAAAAAAAAAD/5gAAAAAAAP/zAAD/7v+sAAD/+P+7/9MAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/+wAAAAAAAAAAP/v//7/7AAAAAAAAAAA//j/+P/vAAD/7//K/8YAAP/G/9kAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/9MAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/4AAD/+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP/pAAAAAAAAAAD/7QAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//4AAP/lAAAAAP/s//sAAP/vAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP9q/9D/7wAAAAAAAAAAAAAAAAAA/98AAAAAAAD/+gAAAAAAAP+uAAAAAP/qAAD/7gAAAAAAAAAAAAAAAP/4AAD/8//6AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/9AAAAAAAAD/+AAAAAD/+v9qAAAAAP/iAAAAAAAAAAAAAAAAAAAAAP/Q/7wAAP+8/+YAAAAAAAAAAAAAAAAAAAACAA4AAQAMAAAAEQAUAAwAFgAgABAAIgAjABsAJQAoAB0AKgArACEALQA7ACMAPwBAADIAQgBCADQARQBFADUARwBMADYATgBSADwAVABVAEEAaQBrAEMAAQABAGsABAAEAAQAAQADAAEAAQABAAEAAQADAAMAAQABAAEAAQAPAAEAAQAQAAEAAwADAAMAAQADAAEACAAJAAUABQAFAAAACgAKAAAABgAGAAsADQACAAIAAgAAAAIAAgAAAAAAAgACAAIAAAACAAIAAAACAAIAAAAHAAAAAAAAAA4AAAACAAIAAgACAAIABwAAAAIAAAAAAA0ADQACAAAAAAACAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwADAARAAEAAQBrAAEAAQABABkACgAAAAQABAAEABsACAAIAAAAAAAAAAAADwAYABEAHAAAAAAAAAAAAB0AAAANAAkADgADAAMAAwAAABAAEAAAAAcABwALABQAAAASAAwAAAAXABcABgASAAIAAgAGAAIAAgACAAUAAgAaABUADAAAAAAAAAAWAAIAAAACAAAAAAACAAAABQAFAAIABgAUABQAAAAGABIAAgAFAAUAAAACAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAATABMAHgABAIYABAAAAD4DrgOuA64DpAOeA5ADigOKA4ADagNkA5ADkAOQA0YDkANAAzIDJAMeAx4DHgLUAsoCygKgAoICggJ0Am4CWAMkAlICUgJMAkYCGAIKAegB3gJGAkYCUgJSAkYCRgHIAcIBvAGyAawBkgGMAX4BeAFqAWoBYAE+ARQBCgD8AAIAEwABAAYAAAALAAwABgASABQACAAWACYACwAoACsAHAAvAC8AIAAzADMAIQA1ADUAIgA3ADcAIwA5ADsAJAA/AD8AJwBHAEgAKABKAEoAKgBOAE4AKwBRAFIALABWAFgALgBaAF8AMQBpAG0ANwBwAHEAPAADAB3/1QA7/9UARv/VAAIAJf/vACb/7wAKAB3/1QAh/+oAIv/4ACP/+AAk/+8AJf/VACb/1QA7/9UAP//vAEb/1QAIAAX/7wAL/+8ADP/vABb/7wAX/+8AGP/vABr/7wAc/+YAAgAh/+8AO/+8AAMAIf/QADn/+AA7/8YAAQBd/+4AAwAl//gAJv/4AF3/9QABAFr/6QAGACX/+AAm//gAV//4AFj/+ABd//YAX//zAAEAXf/vAAIAV//vAF3/9AABAFr/7wABAF3//QAFACT/+AAl/+8AJv/vAD//+ABd//4AAgBW//gAbf/vAAgAKP/TADX/+gBp/8YAav/GAGv/vABs/80Abf/VAHD/1QADAD//+ABp//gAav/4AAsAKP/QACz/rAAv//kANP/MAD//7wBa//oAXf/4AGn/rgBq/7oAbP+2AG3/zQABAF3/+AABADv/+gABAG3/5gAFACj//gA7/+wAP//sAGn/7wBq/+8AAQBA/+QAAwA7/9MAQP/rAEb//QAHAFb/7wBa/+YAXP/4AF7/+ABs/9UAbf/VAHH/8AAKAAX/7wAL/+8ADP/vABb/7wAX/+8AGP/vABr/7wAc//EAVv/4AG3/7wACAGz/3gBt//gAEgAB/94AAv/eAAP/3gAF//cAC//3AAz/9wAR//sAFP/4ABb/9wAX//cAGP/3ABr/9wAc//kAaf/HAGr/xwBr/+YAbP/+AG3/6gABAGz/5gADAGz/zQBt/9UAcP/VAAMAIf/4ACT/+ABs/+8AAQAh//QABwAk/+8AWv/6AF3/+ABp/64Aav+6AGz/tgBt/80AAQAh//gABQAa/+8AIf+7AFf/3gBd//gAbf/VAAIAGv/qAG3/5gABACH/9wADACH/+AAk/+8AXf/4AAEAJP/4AAIAIf/pACT/7AACABoAAAAh/94AAAACAFIAAAJIAyoAAwAHAAAzESERJSERIVIB9v5xASj+2AMq/NZaAnYAAgAVAAACEQMqAAcACgAAMxMzEyMnIwcTMwMVmcyXpRt3HCxWKwMq/NarqwEWATz//wAVAAACEQQHAiYAAQAAAAcAdgEUAOj//wAVAAACEQQSAiYAAQAAAAcAdQEUAOgAAwA8AAACKgMqABQAHgApAAAzETMyHgIVFAYGBx4CFRQOAiMnMzI2NTQmJiMjNTMyNjY1NCYmIyM80zRdSCkdNSUvQCEhPls7RiY7LRQuJyUjKSoPFy0iHwMqEC1TRDFDKAcGMVI6P1s6HH08Oi42GXYZMCIgKxYAAQAx//QCDQM1ACcAAAUiJiY1ETQ2NjMyFhYVFSM1NCYmIyIGBhURFBYWMzI2NjU1MxUUBgYBJFxrLCxrXFplKq8FGB0cGwcJGxocGAavKWUMQ3lSASRUeUI7akVGURgsHR4tGf6DGy0bHS4YVUZFbT8AAgA8AAACGQMqAAsAFwAAMxEzMhYWFREUBgYjJzMyNjY1ETQmJiMjPM1qdjAwdWkcHCkkCQskKBsDKjVxWv7aW3M2fBgwJAFhJCwUAAEAPAAAAaoDKgALAAAzESEVIxUzFSMVMxU8AWy5jY27Ayp6yXzyef//ADwAAAGqBAcCJgAHAAAABwB2APQA6P//ADwAAAGqBBICJgAHAAAABwB1APQA6AABADwAAAGaAyoACQAAMxEhFSMVMxUjETwBXquVlQMqecx9/pgAAAEAMf/0AhIDNQAqAAAFIiYmNRE0NjYzMhYWFRUjNTQmJiMiBgYVERQWFjMyNjY1NSM1MxEjJwYGAQ9UYSksa1xaZyyvBxkcHRoHCx0bHB4MSep2ChA+DEZ/UwEQVn5FPGpHNEIaLBsfLhn+iRsvHR4wG19p/lxDIi3//wAx//QCEgQHAiYACwAAAAcAdgEjAOgAAQA/AAACIwMqAAsAADMRMxEzETMRIxEjET+zfrOzfgMq/rgBSPzWAWL+ngABAD0AAADwAyoAAwAAMxEzET2zAyr81v////kAAAEzBAcCJgAOAAAABwB2AJYA6P///+gAAAFEBBICJgAOAAAABwB1AJYA6AABAA3/6wEhAyoADQAAFzUyPgI1ETMRFAYGIw0bJhYKsyFSShV9BhEhGgJw/YhCWSwAAAEAPAAAAi0DKgALAAAzETMREzMDEyMDBxE8s4ivlp22dRMDKv61AUv+kv5EAWYg/roAAQA8AAABqQMqAAUAADMRMxEzFTyzugMq/U95AAEAOQAAAocDKgAMAAAzEzMTEzMTIwMDIwMDOQ7AWWC5Dn8PXnFkDAMq/ikB1/zWAhv95QIf/eEAAQA8AAAB9AMqAAkAADMRMxMRMxEjAxE8fqaUeaYDKv56AYb81gGk/lwAAgAx//QCGQM1ABEAIwAABSImJjURNDY2MzIWFhURFAYGJzI2NjURNCYmIyIGBhURFBYWASRbai4ualtcay4ua1wcGggIGhwaGggHGQxAeFMBLVR2Pz92VP7TU3hAfxwsFwGGGCscHCsY/noXLBwA//8AMf/0AhkEBwImABYAAAAHAHYBJQDo//8AMf/0AhkEEgImABYAAAAHAHUBJQDoAAIAPAAAAiEDKgAMABcAADMRITIWFhUUBgYjIxERMzI2NjU0JiYjIzwBEEleLj5qQkg8ISILCSIkOwMqN2lLXmMm/qgB1RgwJR8vHAACADH/YQIZAzUAHQAvAAAFLgInBiIjIiYmNRE0NjYzMhYWFREUBgYHHgIXJzI2NjURNCYmIyIGBhURFBYWAdsWPj0VBAgFW2ouLmpbXGsuFS8nDRwcDt0cGggIGhwcGQcHGZ8KLT0gAUB4UwEtVHY/P3ZU/tM5XEQUDhsXB6gcLBcBhhgrHBwrGP56FywcAAACADwAAAI0AyoADwAZAAAzETMyFhYVFAYGBxMjAyMRETMyNjY1NCYjIzzfUnhDECsob7lZMzImKhEmMzoDKiRdVTJOORP+eAFr/pUB0hszJDQ6AAABACr/9AHnAzUALQAABSImJic3HgIzMjY1NCYnJyYmNTQ2MzIeAhcHLgIjIgYVFBYXFxYWFRQGBgETTGY0A5kBER8WHBEwKUYuP3ppQU8pDwGaAQcWFxkXKyNDNUk0Xww2cVoXNEAcJhUyRSM9J2NJZ20sR08kEyQ1HSoVLTkfOy1yVDldNgAAAQALAAABsgMqAAcAADMRIzUhFSMRhXoBp3kCpYWF/VsAAAEANv/0AhYDKgAVAAAFIiYmNREzERQWFjMyNjY1ETMRFAYGASZdaSqxCBscHRoHsitoDD11VQIv/bYZMiAgMhkCSv3RVXU9//8ANv/0AhYEBwImAB4AAAAHAHYBJgDo//8ANv/0AhYEEgImAB4AAAAHAHUBJgDoAAEAFgAAAfkDKgAGAAAzAzMTEzMDqJKZV1GilAMq/doCJvzWAAEAGwAAAp4DKgAMAAAzAzMTEzMTEzMDIwMDfWKYMD13QDGWYZxFQQMq/igB1/4sAdX81gHp/hf//wAbAAACngQSAiYAIgAAAAcAdQFdAOgAAQAHAAAB/QMqAAsAADMTAzMXNzMDEyMnBwedlqlZSJWOnqhjVwG0AXbb2/5b/nvy8gABAAgAAAHlAyoACAAAMxEDMxMTMwMRopqpTUWilwFLAd//AAEA/iH+tf//AAgAAAHlBBICJgAlAAAABwB1APcA6AABAB0AAAGVAyoACQAAMzUTIzUhFQMzFR3GvQFtyctsAkV5Zf20eQD//wAVAAACEQMqAgYAAQAAAAIAPwAAAggDKgAPABkAADMRIRUjFTMyFhYVFA4CIyczMjY2JyYmIyM/AZLfJk1rOCdGXTYWFyIoEAEBJDYVAyqIzjJnUUpcMhJ1GjYpLT3//wA8AAACKgMqAgYABAAAAAEAPwAAAbcDKgAFAAAzESEVIxE/AXjFAyqF/VsAAAIAEv9gAqMDKgARABsAABcRMz4ENxMhETMRIychBxMzESMHDgQSFxcnIBoSBhYBeVtyDf5zE07PSwkFExkcH6ABIAcjPFp9UgEb/Vb+4KCgASACK65WeE8yIAD//wA8AAABqgMqAgYABwAA//8APAAAAaoEEgImAAcAAAAHAHUA9ADoAAEABgAAAxUDKgAVAAAzEwMzEzMRMxEzEzMDEyMDIxEjESMDBqqmp40IjgmNp6aqoJgJjgiYAZcBk/6FAXv+hQF7/m3+aQGJ/ncBif53AAEAIv/xAegDNgA8AAAFIiYmNTUzFBQVFBYzMjY2NTQmJyYiIiM1OgIzNjY1NCYjIgYHFBQVIzU0NjYzMhYWFRQGBx4CFRQGBgEKVWEpqBgiFxcJJDkBDxQJBw4OBjsmHSIhGQGoOWZFR2U2OikcLRorYg9Ac0sdAhQJPDMYLyQ9PwEBbQE+PS8yNSwCHQ4xSWU0MmFIR2ELCytKOkpzQAABAD8AAAIPAyoACQAAMxEzERMzESMRAz+Nz3SQ0wMq/jAB0PzWAdj+KP//AD8AAAIPBAcCJgAxAAAABwB2ATQA6AABAD8AAAIyAyoADAAAMxEzETMTMwMTIwMjET+zEneylJm4dhIDKv6/AUH+hP5SAWH+nwAAAQAH//wCUAMqABMAADM1Mj4DNxMhESMRIwMOBAciKhgOCQcaAa2yWRAHDh89an4JIkZ2WwFq/NYCof79ZJFiNxT//wA5AAAChwMqAgYAFAAA//8APwAAAiMDKgIGAA0AAP//ADH/9AIZAzUCBgAWAAAAAQA8AAACIQMqAAcAADMRIREjESMRPAHlsIUDKvzWAqr9VgD//wA8AAACIQMqAgYAGQAA//8AMf/0Ag0DNQIGAAUAAP//AAsAAAGyAyoCBgAdAAAAAQAW/1oCHgMqABsAABc1FhYzMj4CNTQmJwMzExc3EzMDDgMjIiY1DhkLGCccDwMCtqZGIRg7qKYOKDpTOw8io3sBAQgRHRUPFwsC2P6z2doBTP0XQFk2GAL//wAW/1oCHgQHAiYAPAAAAAcAdgEVAOgAAwAu/+MCyANZACMAMwBDAAAFNQYGIyImJjU1NDY2MzIWFzUzFTY2MzIWFhUVFAYGIyImJxUnMjY3ESYmIyIGBhUVFBYWNzI2NjU1NCYmIyIGBxEWFgEzDx8QN1s1N2NACxcJkAkUC0NjNzVXNREhEqQFDAMEDQUZJBQQJNgdJBESIRcIDgcFCx15AQIuWUD0OlozAQGAgAEBM1o69UBZLgIBeOUBAQGkAQEWKBz5GCYXARUmGfkeKBQBAf5dAQH//wAHAAAB/QMqAgYAJAAAAAEALQAAAiMDKgAUAAAhEQYGIyImNREzFRQWFjMyNjcRMxEBcBsoF2t+sxAoIgseDbMBTwUEZ2kBFO0yPRoEAwFv/NYAAQBB/2ACgAMqAAsAAAUnIREzETMRMxEzEQIPEf5DsomzUaCgAyr9TwKx/U/+5wAAAQA/AAAC+gMqAAsAADMRMxEzETMRMxEzET+pY6hfqAMq/VYCqv1WAqr81gABAEX/YANTAyoADwAABSchETMRMxEzETMRMxEzEQLhEP10qWOoX6lSoKADKv1PArH9TwKx/U/+5wAAAgA/AAACCAMqAA0AFwAAMxEzETMyFhYVFA4CIyczMjY2JyYmIyM/syhMajgnRl01FxgiJxEBASM3FgMq/qoyZ1FKXDISdRo2KS09AAMAPwAAAwADKgANABcAGwAAMxEzETMyFhYVFA4CIyczMjY2JyYmIyMBETMRP7MoTWs3J0ZcNhgZIicRAQEkNhcBW7MDKv6qMmdRSlwyEnUaNiktPf6oAyr81gAAAgANAAACVgMqAA8AGQAAMxEjNSERMzIWFhUUDgIjJzMyNjYnJiYjI4Z5ASwuTWs3J0ZdNR4fIicRAQEkNxwCsHr+qjJnUUpcMhJ1GjYpLT0AAAEAMv/2Ag4DOAArAAAFIiYmNTUzFRQWFjMyNjY1NSM1MzU0JiYjIgYGFRUjNTQ2NjMyFhYVERQGBgEbWWYqrwUZHBwaB4aGCRoaHBgGrylmWl1qLCxqCjxqRCs1GC0cHS8ZjlqUHC0aHS0ZOChFbj9DeVP+3VR6QgAAAgA///QDCAM1ABkAKwAABSImJjU1IxEjETMRMzU0NjYzMhYWFREUBgYnMjY2NRE0JiYjIgYGFREUFhYCKFZiKFixsVgoYlZWYigoYlYbGAYGGBsaGQYGGQxFfVNP/qgDKv6yRlR7RER8U/7nU31FfxwsFwGGGCscHCsY/noXLBwAAAIACgAAAh4DKgARABoAADMTJiY1NDY2MzMRIxEjIiIjAxMzNSMiBhUUFgqaLjtCe1bQsiABCAJ2hxocNzcwAWgcbEheaSv81gFA/sABvvE9OzRF//8ABgAAAxUEBwImAC8AAAAHAHQAbADo//8AFQAAAhEEBwImAAEAAAAHAHT/8gDo//8AFQAAAhEEEgImAAEAAAAHAHUBFADo//8APAAAAaoEBwImAAcAAAAHAHT/0gDo//8ABgAAAxUEEgImAC8AAAAHAHUBjgDo//8AIv/xAegEEgImADAAAAAHAHUBBwDo//8APwAAAg8EEgImADEAAAAHAHUBNADo//8AMf/0AhkEEgImABYAAAAHAHUBJQDo//8AMv/2Ag4EEgImAEcAAAAHAHUBIADo//8AFv9aAh4EEgImADwAAAAHAHUBFQDo//8ALQAAAiMEEgImAEAAAAAHAHUBMQDo//8APwAAAwAEEgImAEUAAAAHAHUBoADoAAIANv/xAe8DNgARACMAAAUiJiY1ETQ2NjMyFhYVERQGBicyNjY1ETQmJiMiBgYVERQWFgETSWIyL2JMTGEvMmJIGBUFBBUZGRUEBhUPO2hFAW9Hazw8a0f+kURpO5UfKQ8BZxErISErEf6ZDykfAAABABYAAAEsAyoADgAAMxEOAwc1PgM3MxGFCxwfHQwLKC4oCoMChQYMDQwGfAYVGhsK/NYAAQAnAAAB5gM1AB4AADM1Ez4CNTQmIyIGBhUVIzU0NjYzMhYVFAYGBwczFSnCFicZHBgbHguiLmRRbW8fMx2U7XYBKiE+QCMjIh4yHygrSHFBcWUxVE8r3YMAAQAm//EB4wM2ADsAAAUiJiY1NTMUFBUUFhYzMjY2NTQmJyIiIzUyMjMyNjU0JiMiBhUUFBUjNTQ2NjMyFhYVFAYHHgIVFAYGAQVVYSmnChoXFxcIITQBFAoHDgY1JBkiIRWnNWNFRmQ2OikcLRorYg9Ac0sdARQIKDIXGDAkPD8Bczo9LzI4LAoVCzFJZTQyYUhHYAsKLEs6SnJBAAACABkAAAH5AyoACgANAAAhNSM1EzMRMxUjFQEzEQES+dPCS0v+6nq2ewH5/gh8tgEyAVEAAQAr//EB3AMqACUAABciJiY1MxQWFjc+AjU0JiYjIgYHIxMhFSMHNjY3NhYWFRQOAv9ZWyChChsZGhYFCRsbGSAHkA8BevQJCjggPlUrEC5YDz9ySy46GQEBKEs1KD4lJCYB04ynDhUDBzt4V0FtTywAAgA0//QB8QM1ACMANAAABSImJjURNDY2MzIWFhUUFhUjNCYjIgYGFRU2Njc2FhYVFAYGJzI2NjU0JiYjIgYGBxUUFhYBFE9jLiVgWk5aJgGdDyIXFwgMNiJATSIoYFMXFQcFFhoMFhAECRkMRXhNASVPfEcwXEQEBwMxLxxGP0gVFwECPmtCVYFIex89LSg6IQoPB4QbMB0AAAEAFAAAAZQDKgAGAAAzEyM1IRUDQbDdAYCwAqt/c/1JAAADACb/8wHkAzUAHwAvAD0AAAUiJiY1PgM3JiYnJjY2MzIWFgcGBgceAxUWBgYnMjY2NTYmJiMiBgYVFBYWEzI2NjU0JiMiBhUUFhYBBFJiKgELFyMYHzACAS9eRUZdLgIBLiAYIxcMAitiUxsYBgEHGBsaGAcGGRoRFgsZGRkbCxgNQXNKHjkxJQwXV0NEYjQ1YUREVxYMJTE5HkpzQXsoNxcfOiUlOSAXOCcBeBs2KCkwMCgoNxsAAgAn//QB5AM1ACMAMwAABSImJjU0NDUzFBYzMjY2NTUGBgcGJiY1NDY2MzIWFhURFAYGAzI2NzU0JiYjIgYGFRQWFgEFTlomnBEiFxYHDTUhQE4iK2JRUGItJWBYFBgHCBYXFhYHBRYMMF1EAwcDMS8cRz5IFRYCAUJwQlV7REBzTv7ST3xHAboWCoQcLx0fPS0nOyEAAf9YAAABOwMqAAMAACMBMwGoAYNg/n4DKvzW//8AGgAAA04DKgAmAGTZsgAnAGABSwAAAAcAZQG+/k///wAiAAADGgMqACYAZOGyACcAYAFTAAAABwBnAYP+T///ADwAAANsAzUAJgBm7LMAJwBgAaQAAAAHAGcB1f5PAAEAQQGxARcDeAAGAAATEQc1NzMRnVyDUwGxAUAkaUL+OQABAE8BsQGQA4IAGwAAEzU3PgI1NCYjIgYVIzU0NjYzMhYVFAYHBzMVUXMeJA8TEhgTdiFJOkdSLyk6lgGxUHQeKyMSFxYrJxAuSixMRDRKKTlhAAABAFABpwGNA4IALQAAEyImJjU1MxUUFjMyNjU0JiMjNTMyNjU0JiMiBhUVIzU0NjMyFhUUBgcWFhUUBu49RRx1FBYUFBoiGhchGhEUFhN1UkxHVSgaHClVAacjPSgQBBkZGRYcHkgbGxcZGxcEEENFPj4qNQoJMDVASAAAAgBAAbEBlwN4AAoADQAAEzUjNRMzETMVIxUnMzXxscRnLCzXXQGxZUoBGP7qTGWxhQABAC4AAADIAJoAAwAAMzUzFS6ampoAAQAr/3QAxgCaAAoAABcnNjY1IzUzFQYGRAoaLVabAUCMNQclK5qFSlIAAgBDADgA2wI0AAMABwAAEzUzFQM1MxVDmJiYAZyYmP6cmJgAAQArAAABewMqAAMAADMTMwMr+Fj5Ayr81gABACAA8gEkAVsAAwAANzUhFSABBPJpaQABACAA8gIOAWEAAwAANzUhFSAB7vJvbwABACAA8gP7AWEAAwAANzUhFSAD2/JvbwACACUAJQHCAjEABgANAAAlJzU3FQcXByc1NxUHFwHCxcVmZtjFxWZmJeo46pxqapzqOOqcamoAAAIALQAlAcwCMQAGAA0AADc1Nyc1FxUXNTcnNRcVLWhoxxFoaMclnGtpnOo46pxraZzqOAABABoAzwGnAmoACwAANzUjNTM1MxUzFSMVqpCQbJGRz6BfnJxfoAAABQAt//8DwAMrAAMAEQAhAC8APwAAIRMzAwMiJjU1NDYzMhYVFRQGJzI2NjU1NCYjIgYVFRQWFgEiJjU1NDYzMhYVFRQGJzI2NjU1NCYjIgYVFRQWFgFM+Vr4vWdWVGlpVVZoGhcGDikoDwYYAjFnVVNpaVVWaBoXBg4pKA8GGAMq/NYBXmtsJG5kYm4oa2poGzUlGTk1NDkbJTQb/jlrbCRuZGJuJ2xqaBs0JRs5NDQ5GyU0GwABAIQCgwG+Ax8ADQAAASImNTMUFjMyNjUzFAYBIUdWXB0kIx5cVgKDUEwlJCUkTFAAAAL/UgKWAK4DKgADAAcAABM1MxUhNTMVLIL+pIIClpSUlJQAAAH/YwKDAJ0DHwANAAARIiY1MxQWMzI2NTMUBkdWXB0kIx5cVgKDUEwlJCUkTFAAAAA=) format('truetype'); }
        .hero-copy-stage { display: contents; }
        /* Shared mobile portrait composition; keep page copy and the single live form. */
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer { padding-top: calc(var(--header-height, 80px) + 16px); }
            .portrait-hero .hero-copy-stage {
                display: grid; grid-area: 1 / 1 / 5 / 2; grid-template-rows: auto auto auto 1fr;
                grid-template-columns: minmax(0, 68%) minmax(0, 1fr);
                padding-top: clamp(24px, 7vw, 32px);
                min-height: min(620px, 90vw); align-content: start;
            }
            .portrait-hero .hero-grid:has(.hero-title-followup) { --portrait-photo-y: -25px; }
            .portrait-hero .hero--offer .hero-grid::before {
                inset-block: -16px -20px;
            }
            .portrait-hero .hero-grid::after {
                content: ''; position: absolute; grid-area: 1 / 1 / 5 / 2;
                inset: -16px calc(var(--hero-mobile-gutter) * -1) -12px; z-index: -1;
                background-image: linear-gradient(90deg, #070707c7 0%, #070707b3 24%, #0707077a 46%, #07070733 62%, #07070700 76%),
                    url('<?= pageEscape($portraitHeroImageUrl) ?>');
                background-size: 100% 100%, calc(100% + clamp(16px, 4.5vw, 28px)) auto; background-position: center, clamp(22px, 6.5vw, 32px) var(--portrait-photo-y, 0px);
                background-repeat: no-repeat; pointer-events: none;
                mask-image: linear-gradient(90deg, transparent 0, transparent clamp(22px, 6.5vw, 32px), #000 calc(clamp(22px, 6.5vw, 32px) + clamp(34px, 9.5vw, 44px))), linear-gradient(#000 0%, #000 78%, transparent 90%);
                mask-composite: intersect;
            }
            .portrait-hero .hero--offer .hero-title-service {
                display: block; font-size: clamp(2.4rem, 12vw, 5.5rem);
                grid-column: 1 / -1; width: 100%; margin-bottom: 6px; text-align: left;
                font-family: 'Avtokai Hero', var(--font-system-bold); font-weight: 700; font-synthesis: none;
                letter-spacing: .01em; line-height: 1.02; text-shadow: 0 2px 7px #0007;
            }
            .portrait-hero .hero--offer .hero-title-lead { display: block; white-space: normal; font-size: 1em; }
            .portrait-hero .hero-title-lead:has(.old-car-buy) { display: contents; }
            .portrait-hero .old-car-buy { display: block; font-size: clamp(2.8rem, 14.6vw, 6.25rem); }
            .portrait-hero .old-car-age { font-size: inherit; color: #fff; }
            .portrait-hero .hero-title-service:has(.old-car-buy) .hero-title-followup { display: inline; font-size: inherit; }
            .portrait-hero .hero--offer .hero-title-followup {
                display: block; font-size: inherit; line-height: 1.02;
            }
            .portrait-hero .hero--offer .hero-title-location {
                font-family: 'Avtokai Hero', var(--font-system-bold); font-weight: 700; font-synthesis: none;
                grid-column: 1 / -1; font-size: clamp(2.4rem, 12vw, 5.5rem); line-height: 1.02;
                text-align: left; letter-spacing: normal; margin-bottom: 8px;
            }
            .portrait-hero .hero--offer .hero-title-price {
                grid-column: 1 / -1; text-align: left; font-family: 'Avtokai Hero', var(--font-system-bold); font-weight: 700; font-synthesis: none;
                font-size: clamp(1.3rem, 5.6vw, 1.75rem); line-height: 1.22;
                letter-spacing: normal; word-spacing: .03em;
            }
            .portrait-hero .hero--offer .hero-subtitle {
                width: 100%; min-height: 0; align-self: start;
                margin: 20px 0 6px; text-align: left; text-wrap: balance;
                font-size: clamp(1rem, 4.3vw, 1.25rem); font-weight: 700; line-height: 1.25; color: #fff;
                text-shadow: 0 1px 4px #000, 0 0 8px #0008;
            }
            .portrait-hero .hero--offer .hero-mobile-scene { display: none; }
            .portrait-hero .hero--offer .hero-features { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; }
            .portrait-hero .hero--offer .hero-feature {
                grid-template-columns: auto minmax(0, 1fr); justify-items: start; align-items: center;
                min-height: 78px; padding: 10px 6px; gap: 6px; text-align: left;
                border-color: #e53e3e73; background: linear-gradient(145deg, #151516f2, #050505fa);
                font-size: clamp(.72rem, 3.35vw, 1rem);
            }
            .portrait-hero .hero--offer .hero-feature-icon {
                width: clamp(36px, 11vw, 50px); height: clamp(36px, 11vw, 50px); padding: 7px;
                border-radius: 50%; background: radial-gradient(circle at 35% 25%, #ff10262b, #ff102609);
            }
            .portrait-hero .hero--offer .hero-feature > span:last-child { min-height: 0; }
            .portrait-hero .hero--offer .hero-feature:nth-child(3) { grid-area: 2 / 2; }
            .portrait-hero .hero--offer .hero-feature:nth-child(4) { grid-area: 2 / 1; }
            .portrait-hero .hero--offer .hero-buttons { gap: 10px; margin-bottom: 22px; }
            .portrait-hero .hero--offer .hero-buttons > .btn { min-height: 54px; border-radius: 18px; }
            .portrait-hero .hero--offer .hero-buttons > .btn-primary { background: var(--button-red-bg); }
            .portrait-hero .hero--offer .hero-stats { grid-row: 7; }
            .portrait-hero .mobile-header-phone .header-phone-icon { display: none; }
        }
<?php endif; ?>
<?php if ($isCampaignPage && $serviceSlug === 'skupka-avto'): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer .skupka-title-auto { display: block; }
            .portrait-hero .hero--offer .hero-title-service { font-size: clamp(3.2rem, 16.8vw, 7.2rem); }
        }
<?php endif; ?>
<?php if ($isServicePage && $serviceSlug === 'vykup-avto-posle-dtp'): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer .dtp-title-auto { display: block; }
            .portrait-hero .hero--offer .hero-title-lead { font-size: clamp(3.2rem, 16.8vw, 7.2rem); }
        }
<?php endif; ?>
<?php if (($isServicePage && $serviceSlug === 'vykup-avto-s-probegom') || ($isCampaignPage && $serviceSlug === 'prodat-avto')): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer .hero-title-auto { display: block; }
            .portrait-hero .hero--offer .hero-title-lead { font-size: <?= $serviceSlug === 'prodat-avto' ? 'clamp(2.8rem, 14.6vw, 6.25rem)' : 'clamp(3.2rem, 16.8vw, 7.2rem)' ?>; }
        }
<?php endif; ?>
<?php if ($isCampaignPage && $serviceSlug === 'vykup-avto'): ?>
        .hero--offer .catalog-title-auto { display: block; }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--offer .hero-title-service--catalog .hero-title-lead { font-size: .87em; }
        }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer .hero-title-lead { font-size: clamp(3.2rem, 16.8vw, 7.2rem); }
            .portrait-hero .hero--offer .catalog-title-auto { font-size: .55em; }
        }
<?php endif; ?>
<?php if ($isHomeHero): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--home .home-title-auto { display: block; }
            .portrait-hero .hero--home .hero-title-service { font-size: clamp(3.2rem, 16.8vw, 7.2rem); }
            .portrait-hero .hero--home .hero-title-location { font-size: clamp(2.4rem, 12.8vw, 5.5rem); }
            .portrait-hero .hero--home .hero-title-price { font-size: clamp(1.4rem, 6.2vw, 2rem); color: #fff; }
        }
<?php endif; ?>
<?php if ($isBityhHero): ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--offer .hero-title-lead { white-space: nowrap; }
            .portrait-hero .hero--offer .bityh-title-type { display: block; }
            .portrait-hero .hero--offer .hero-title-service,
            .portrait-hero .hero--offer .hero-title-location { font-size: clamp(2.8rem, 14.6vw, 6.25rem); }
            .portrait-hero .hero--offer .hero-title-price { font-size: clamp(1.4rem, 6.2vw, 2rem); }
            .portrait-hero .hero-title-location .bityh-location-prefix { display: none; }
            .portrait-hero .hero-grid:has(.hero-title-followup) { --portrait-photo-y: 0px; }
        }
<?php endif; ?>
<?php if ($isAutovykupPage): ?>
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--legacy .hero-content { container-type: inline-size; }
            .hero--legacy .hero-home-title .hero-highlight { font-size: calc(100cqw * <?= $desktopHeroTitleScale ?>); }
        }
        .hero--legacy .legacy-mobile-features { display: none; }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--legacy { background: #070707; }
            .hero--legacy .legacy-mobile-features { display: grid; }
            .hero--legacy .legacy-desktop-features, .hero--legacy .legacy-hero-break { display: none; }
            .hero--legacy .hero-home-title .hero-highlight { display: contents; background: none; color: inherit; }
            .hero--legacy .hero-home-title .hero-highlight::after { content: none; }
            .hero--legacy .hero-title-service { color: #ff202e; }
        }
<?php endif; ?>
<?php if ($isStationHero): ?>
        .hero--station .hero-title-location {
            white-space: normal; overflow-wrap: anywhere;
            font-size: clamp(1.65rem, 2.5cqw, 3.25rem); line-height: 1.12; margin-top: 6px;
        }
        .hero--station .hero-station-name { display: inline-block; max-width: 100%; }
        @media (width > <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--station .hero-title-location { letter-spacing: calc(clamp(2.75rem, 5.2cqw, 6.25rem) * -.035); }
        }
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .hero--station .hero-title-location {
                font-size: clamp(1.5rem, 6.8vw, 2.75rem); line-height: 1.12;
            }
        }
<?php endif; ?>
        @media (max-width: <?= $phoneHeroMobileMaxWidth ?>px) {
            .portrait-hero .hero--station .hero-title-location { max-width: 100%; font-size: clamp(2.4rem, 12vw, 5.5rem); }
        }
<?php endif; ?>
<?php if ($isPhoneHero): ?>
        .hero .hero-buttons > .btn-secondary {
            background: linear-gradient(180deg, #fff, #eceef2); color: #ca1726;
            border: 1px solid #ffffffb3; box-shadow: 0 7px 18px #0004, inset 0 1px 0 #fff;
        }
        @media (hover: hover) and (pointer: fine) {
            .hero .hero-buttons > .btn-secondary:hover { background: linear-gradient(180deg, #fff, #e0e3e9); color: #b11120; }
        }
        .hero .hero-buttons > .btn-secondary:active { background: linear-gradient(180deg, #e5e7eb, #fff); color: #b11120; }
<?php endif; ?>    </style>
</head>
<body class="<?= $isServicePage ? 'service-page' : 'home-page' ?><?= $isPortraitHero ? ' portrait-hero' : '' ?>">

<button class="back-to-top" id="backToTop" type="button" aria-label="Вернуться наверх" title="Вернуться наверх" hidden>
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>

<div class="messenger-widget" id="messengerWidget">
    <button class="messenger-widget-toggle" id="messengerWidgetToggle" type="button" aria-label="Открыть выбор мессенджера" title="Написать в мессенджере" aria-expanded="false" aria-controls="messengerWidgetLinks">
        <span class="messenger-widget-icon" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 64 60" focusable="false">
                <path class="messenger-chat-bubble" d="M32 4C17.1 4 5 14.1 5 26.5C5 33.1 8.5 39 14.4 43.1L10 55L24.3 48.1C26.8 48.7 29.4 49 32 49C46.9 49 59 38.9 59 26.5C59 14.1 46.9 4 32 4Z" fill="white"/>
                <g class="messenger-typing-dots" fill="#e53e3e"><circle class="messenger-typing-dot" cx="23" cy="26.5" r="3"/><circle class="messenger-typing-dot" cx="32" cy="26.5" r="3"/><circle class="messenger-typing-dot" cx="41" cy="26.5" r="3"/></g>
                <path class="messenger-cloud-close" d="m21 19 22 22m0-22L21 41" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span class="messenger-status-dot"></span>
        </span>
    </button>
    <div class="messenger-widget-panel" id="messengerWidgetLinks" role="group" aria-label="Написать в мессенджере" hidden>
        <a class="header-messenger header-messenger--telegram" href="<?= SITE_TELEGRAM ?>" aria-label="Написать в Telegram" title="Telegram" target="_blank" rel="noopener noreferrer">
            <!-- Official Telegram logo: https://telegram.org/img/t_logo.svg -->
            <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 128 128" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="widget-telegram-gradient" x1="50%" x2="50%" y1="0%" y2="99.258%">
                        <stop offset="0%" stop-color="#2AABEE"/>
                        <stop offset="100%" stop-color="#229ED9"/>
                    </linearGradient>
                </defs>
                <g fill="none" fill-rule="evenodd">
                    <circle cx="64" cy="64" r="64" fill="url(#widget-telegram-gradient)" fill-rule="nonzero"/>
                    <path fill="#FFF" fill-rule="nonzero" d="M28.9700376,63.3244248 C47.6273373,55.1957357 60.0684594,49.8368063 66.2934036,47.2476366 C84.0668845,39.855031 87.7600616,38.5708563 90.1672227,38.528 C90.6966555,38.5191258 91.8804274,38.6503351 92.6472251,39.2725385 C93.294694,39.7979149 93.4728387,40.5076237 93.5580865,41.0057381 C93.6433345,41.5038525 93.7494885,42.63857 93.6651041,43.5252052 C92.7019529,53.6451182 88.5344133,78.2034783 86.4142057,89.5379542 C85.5170662,94.3339958 83.750571,95.9420841 82.0403991,96.0994568 C78.3237996,96.4414641 75.5015827,93.6432685 71.9018743,91.2836143 C66.2690414,87.5912212 63.0868492,85.2926952 57.6192095,81.6896017 C51.3004058,77.5256038 55.3966232,75.2369981 58.9976911,71.4967761 C59.9401076,70.5179421 76.3155302,55.6232293 76.6324771,54.2720454 C76.6721165,54.1030573 76.7089039,53.4731496 76.3346867,53.1405352 C75.9604695,52.8079208 75.4081573,52.921662 75.0095933,53.0121213 C74.444641,53.1403447 65.4461175,59.0880351 48.0140228,70.8551922 C45.4598218,72.6091037 43.1463059,73.4636682 41.0734751,73.4188859 C38.7883453,73.3695169 34.3926725,72.1268388 31.1249416,71.0646282 C27.1169366,69.7617838 23.931454,69.0729605 24.208838,66.8603276 C24.3533167,65.7078514 25.9403832,64.5292172 28.9700376,63.3244248 Z"/>
                </g>
            </svg>
            <span class="visually-hidden">Telegram</span>
        </a>
        <a class="header-messenger header-messenger--whatsapp" href="<?= pageEscape($publicWhatsAppUrl) ?>" aria-label="Написать в WhatsApp" title="WhatsApp" target="_blank" rel="noopener noreferrer">
            <i class="fab fa-whatsapp" aria-hidden="true"></i>
            <span class="visually-hidden">WhatsApp</span>
        </a>
        <a class="header-messenger header-messenger--max" href="<?= SITE_MAXIM ?>" aria-label="Написать в MAX" title="MAX" target="_blank" rel="noopener noreferrer">
            <img src="/maxim.png?v=d73830b0" alt="" width="64" height="64" decoding="async">
            <span class="visually-hidden">MAX</span>
        </a>
    </div>
</div>

<header class="header" id="header">
    <div class="container">
        <div class="header-wrapper">
            <div class="header-brand">
            <button class="logo-wrapper" type="button" aria-label="Автокай — обновить текущую страницу и вернуться наверх" title="Обновить страницу и вернуться наверх" onclick="reloadCurrentPageFromTop()">
                <img class="logo-img" src="/img/optimized/logo.webp?v=b841bce2" alt="Автокай" width="836" height="470" decoding="async">
            </button>
            </div>
            
            <a class="mobile-header-phone header-phone" href="tel:+7<?= substr(SITE_PHONE, 1) ?>" title="Позвонить" rel="nofollow">
                <i class="fas fa-phone header-phone-icon" aria-hidden="true"></i>
                <span class="phone-number"><?= SITE_PHONE_FORMATTED ?></span>
            </a>
            <button class="burger-menu" id="burgerMenu" aria-label="Меню" aria-expanded="false" aria-controls="navMenu">
                <span class="burger-line"></span>
                <span class="burger-line"></span>
                <span class="burger-line"></span>
            </button>
            
            <nav class="nav-menu" id="navMenu" aria-label="Основная навигация">
                <div class="nav-links">
                    <a href="<?= $homeAnchorPrefix ?>#cases" class="nav-link">Кейсы</a>
                    <a href="<?= $homeAnchorPrefix ?>#reviews" class="nav-link">Отзывы</a>
                    <a href="#faq" class="nav-link">Вопросы</a>
                    <a href="#contacts" class="nav-link">Контакты</a>
                </div>
                
                <div class="header-phone-block">
                    <a href="tel:+7<?= substr(SITE_PHONE, 1) ?>" class="header-phone" rel="nofollow">
                        <i class="fas fa-phone header-phone-icon" aria-hidden="true"></i>
                        <span class="phone-number"><?= SITE_PHONE_FORMATTED ?></span>
                    </a>
                </div>
                <div class="header-messengers" role="group" aria-label="Написать в мессенджере">
                    <a class="header-messenger header-messenger--telegram" href="<?= SITE_TELEGRAM ?>" aria-label="Написать в Telegram" title="Telegram" target="_blank" rel="noopener noreferrer">
                        <!-- Official Telegram logo: https://telegram.org/img/t_logo.svg -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 128 128" aria-hidden="true" focusable="false">
                            <defs>
                                <linearGradient id="header-telegram-gradient" x1="50%" x2="50%" y1="0%" y2="99.258%">
                                    <stop offset="0%" stop-color="#2AABEE"/>
                                    <stop offset="100%" stop-color="#229ED9"/>
                                </linearGradient>
                            </defs>
                            <g fill="none" fill-rule="evenodd">
                                <circle cx="64" cy="64" r="64" fill="url(#header-telegram-gradient)" fill-rule="nonzero"/>
                                <path fill="#FFF" fill-rule="nonzero" d="M28.9700376,63.3244248 C47.6273373,55.1957357 60.0684594,49.8368063 66.2934036,47.2476366 C84.0668845,39.855031 87.7600616,38.5708563 90.1672227,38.528 C90.6966555,38.5191258 91.8804274,38.6503351 92.6472251,39.2725385 C93.294694,39.7979149 93.4728387,40.5076237 93.5580865,41.0057381 C93.6433345,41.5038525 93.7494885,42.63857 93.6651041,43.5252052 C92.7019529,53.6451182 88.5344133,78.2034783 86.4142057,89.5379542 C85.5170662,94.3339958 83.750571,95.9420841 82.0403991,96.0994568 C78.3237996,96.4414641 75.5015827,93.6432685 71.9018743,91.2836143 C66.2690414,87.5912212 63.0868492,85.2926952 57.6192095,81.6896017 C51.3004058,77.5256038 55.3966232,75.2369981 58.9976911,71.4967761 C59.9401076,70.5179421 76.3155302,55.6232293 76.6324771,54.2720454 C76.6721165,54.1030573 76.7089039,53.4731496 76.3346867,53.1405352 C75.9604695,52.8079208 75.4081573,52.921662 75.0095933,53.0121213 C74.444641,53.1403447 65.4461175,59.0880351 48.0140228,70.8551922 C45.4598218,72.6091037 43.1463059,73.4636682 41.0734751,73.4188859 C38.7883453,73.3695169 34.3926725,72.1268388 31.1249416,71.0646282 C27.1169366,69.7617838 23.931454,69.0729605 24.208838,66.8603276 C24.3533167,65.7078514 25.9403832,64.5292172 28.9700376,63.3244248 Z"/>
                            </g>
                        </svg>
                    </a>
                    <a class="header-messenger header-messenger--whatsapp" href="<?= pageEscape($publicWhatsAppUrl) ?>" aria-label="Написать в WhatsApp" title="WhatsApp" target="_blank" rel="noopener noreferrer">
                        <i class="fab fa-whatsapp" aria-hidden="true"></i>
                    </a>
                    <a class="header-messenger header-messenger--max" href="<?= SITE_MAXIM ?>" aria-label="Написать в MAX" title="MAX" target="_blank" rel="noopener noreferrer">
                        <img src="/maxim.png?v=d73830b0" alt="" width="64" height="64" decoding="async">
                    </a>
                </div>
                <button class="btn btn-primary header-callback" type="button" aria-haspopup="dialog" aria-controls="callbackModal" onclick="openModal('callback')">
                    <i class="fas fa-phone" aria-hidden="true"></i> Заказать звонок
                </button>
            </nav>
        </div>
    </div>
</header>

<main id="main-content">
<section class="hero<?= $isPhotoHero ? ' hero--vykup' : '' ?><?= $isPhoneHero ? ' hero--sell' : '' ?><?= $isPhoneHero ? ' hero--offer' : '' ?><?= $isStationHero ? ' hero--station' : '' ?><?= $isAutovykupPage ? ' hero--legacy' : '' ?><?= $isHomeHero ? ' hero--home' : '' ?>">
    <?php if ($isDesktopPhotoHero): ?>
    <div class="hero-background" aria-hidden="true"><picture><source media="(max-width: <?= $phoneHeroMobileMaxWidth ?>px)" srcset="<?= pageEscape($portraitHeroImageUrl) ?>"><img src="/hero-prodat-phone.webp?v=0141e18b9b90" width="4095" height="991" alt="" fetchpriority="high" decoding="async"></picture></div>
    <?php else: ?>
    <div class="hero-background"></div>
    <?php endif; ?>
    <div class="container">
        <div class="hero-grid">
            <div class="hero-content">
                <?php if ($isPortraitHero): ?><div class="hero-copy-stage"><?php endif; ?>
                <?php if ($isAutovykupPage): ?>
                <h1 class="hero-home-title"><span class="hero-highlight"><span class="hero-title-service"><span class="hero-title-lead">АВТОВЫКУП</span></span> <span class="hero-title-location">В СПБ</span></span><br class="legacy-hero-break">
                    <span class="hero-title-price">ДО 95% РЫНОЧНОЙ ЦЕНЫ</span></h1>
                <div class="hero-mobile-scene" id="heroMobileScene"><button class="phone-preview-trigger" id="phonePreviewTrigger" type="button" aria-label="Открыть полную форму оценки авто" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')"><span><svg class="phone-tap-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M9 12V4a2 2 0 0 1 4 0v6m0-1a2 2 0 0 1 4 0v2m0-1a2 2 0 0 1 4 0v6c0 3-2 5-5 5h-3a5 5 0 0 1-4-2l-4-5a2 2 0 0 1 3-2l1 1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Нажмите, чтобы заполнить</span></button></div>
                <?php elseif ($isPhoneHero): ?>
                <h1 class="hero-home-title"><span class="hero-title-service<?= empty($phoneHeroOffer['variant']) ? '' : ' hero-title-service--' . pageEscape($phoneHeroOffer['variant']) ?>"><span class="hero-title-lead"><?php if ($isOldCarHero): ?><span class="old-car-buy">Выкуп</span> <span class="old-car-age">старых</span><?php elseif ($isBityhHero): ?>Выкуп <span class="bityh-title-type">битых</span><?php elseif ($isHomeHero): ?>Выкуп <span class="home-title-auto">авто</span><?php elseif ($isCampaignPage && $serviceSlug === 'skupka-avto'): ?>Скупка <span class="skupka-title-auto">авто</span><?php elseif ($isServicePage && $serviceSlug === 'vykup-avto-posle-dtp'): ?>Выкуп <span class="dtp-title-auto">авто</span><?php elseif ($isServicePage && $serviceSlug === 'vykup-avto-s-probegom'): ?>Выкуп <span class="hero-title-auto">авто</span><?php elseif ($isCampaignPage && $serviceSlug === 'prodat-avto'): ?>Продать <span class="hero-title-auto">авто</span><?php elseif ($isCampaignPage && $serviceSlug === 'vykup-avto'): ?>Выкуп <span class="catalog-title-auto">автомобилей</span><?php else: ?><?= pageEscape($phoneHeroOffer['title'] ?? ($isCampaignPage ? $campaignPage['hero_title'] : 'Выкуп авто')) ?><?php endif; ?></span><?php if (!empty($phoneHeroOffer['followup'])): ?> <span class="hero-title-followup"><?= pageEscape($phoneHeroOffer['followup']) ?></span><?php endif; ?></span> <span class="hero-title-location"><?php if ($isStationHero): ?>У метро <span class="hero-station-name">«<?= pageEscape($stationHeroName) ?>»</span><?php else: ?><?php if ($isBityhHero): ?><span class="bityh-location-prefix">В </span>СПБ И ЛО<?php else: ?>В СПБ И ЛО<?php endif; ?><?php endif; ?></span> <span class="hero-title-price">ДО 95% РЫНОЧНОЙ ЦЕНЫ</span></h1>
                <div class="hero-mobile-scene" id="heroMobileScene"><button class="phone-preview-trigger" id="phonePreviewTrigger" type="button" aria-label="Открыть полную форму оценки авто" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')"><span><svg class="phone-tap-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M9 12V4a2 2 0 0 1 4 0v6m0-1a2 2 0 0 1 4 0v2m0-1a2 2 0 0 1 4 0v6c0 3-2 5-5 5h-3a5 5 0 0 1-4-2l-4-5a2 2 0 0 1 3-2l1 1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Нажмите, чтобы заполнить</span></button></div>
                <?php elseif (!$isStationPage): ?>
                <h1 class="hero-home-title"><span class="hero-highlight"><?= ($isServicePage || $isAutovykupPage || $isCampaignPage) ? pageEscape($pageH1) : 'ВЫКУП АВТО В СПБ' ?></span><br>
                    ДО 95% РЫНОЧНОЙ ЦЕНЫ</h1>
                <?php else: ?>
                <h1><?= pageEscape($pageH1) ?></h1>
                <p class="hero-price-accent">ДО 95% РЫНОЧНОЙ ЦЕНЫ</p>
                <?php endif; ?>
                <p class="hero-subtitle"><?php if ($isPhoneHero && !$isAutovykupPage): ?><?= pageEscape($phoneHeroOffer['intro']) ?><?php else: ?>

                    <span class="hero-desktop-copy">
                    Выкупаем авто с пробегом, повреждениями и неисправностями.
                    </span><span class="hero-mobile-copy">Выкупаем авто с пробегом, повреждениями и неисправностями.</span>
<?php endif; ?>                </p>
                <?php if ($isPortraitHero): ?></div><?php endif; ?>
                
                <?php if ($isPhoneHero): ?><div class="hero-features<?= $isAutovykupPage ? ' legacy-mobile-features' : '' ?>">
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="M12 3h8m-4 0v5m8 2 2-2"/><circle cx="16" cy="18" r="10"/><path d="M16 12v7l4 2"/></svg></span><span>Деньги<br>за 30 минут</span></div>
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg class="hero-tow-icon" viewBox="0 0 32 32" focusable="false"><g stroke-width="1.7"><path d="M20.5 23V14L11 5.5m9.5 12L9.5 6.5"/><circle cx="9.5" cy="4.5" r="1.6"/><path d="M9.5 6.1v4c-1.8 0-1.8 2.7.1 2.7 1.3 0 1.9-1.3 1.2-2"/><g transform="translate(.85 3.15) scale(.85) rotate(-16 9 17)"><path d="M2 17.5v-2.2l2-1 2-2.5h5.5l3 2.5H16v3.2h-1M2 17.5h1m3.2 0H12M5 14.3h9m-6-2.5v2.5"/><circle cx="4.6" cy="17.8" r="1.5"/><circle cx="13.6" cy="17.8" r="1.5"/></g><path d="M2.5 26.5v-2.8l4-0.7H21v-8h4.5l4 7v4.5H28M2.5 26.5h3.8m5 0h11.6M23 17.5h1.2l2.8 4.5h-4z"/><circle cx="8.8" cy="26.5" r="2.5"/><circle cx="25.4" cy="26.5" r="2.5"/></g></svg></span><span>Бесплатный<br>эвакуатор</span></div>
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="M19 28H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6M9 9h8m-8 5h5m-5 5h3m2 9 1-5 10-10 4 4-10 10zm9-13 4 4"/></svg></span><span>Оформление<br>за наш счёт</span></div>
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="M7 20H3V5h23v7M7 9H6v7h1m14-7h1v2"/><circle cx="14" cy="12" r="3"/><rect x="10" y="16" width="20" height="12" rx="2"/><path d="M10 20h20m-16 4h4"/></svg></span><span>Наличные/<br>перевод</span></div>
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="m16 3 11 4v8c0 7-5 11-11 14C10 26 5 22 5 15V7z"/><path d="m10 16 4 4 8-9"/></svg></span><span>Юридическая чистота</span></div>
                    <div class="hero-feature"><span class="hero-feature-icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="M6 9a12 12 0 1 1-2 12M3 5v8h8"/><text x="16" y="20" text-anchor="middle" fill="currentColor" stroke="none" font-family="Arial, sans-serif" font-size="10" font-weight="700">24/7</text></svg></span><span>Работаем 24/7</span></div>
                </div><?php if ($isAutovykupPage): ?><div class="hero-features legacy-desktop-features">
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Наличные/перевод</span><span class="hero-mobile-copy">Бесплатный эвакуатор</span>
                    </div>
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Юридическая чистота</span><span class="hero-mobile-copy">Любое состояние</span>
                    </div>
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Работаем 24/7</span><span class="hero-mobile-copy">Оформление за наш счёт</span>
                    </div>
                </div><?php endif; ?><?php else: ?><div class="hero-features">
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Наличные/перевод</span><span class="hero-mobile-copy">Бесплатный эвакуатор</span>
                    </div>
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Юридическая чистота</span><span class="hero-mobile-copy">Любое состояние</span>
                    </div>
                    <div class="hero-feature">
                        <i class="fas fa-check-circle"></i>
                        <span class="hero-desktop-copy">Работаем 24/7</span><span class="hero-mobile-copy">Оформление за наш счёт</span>
                    </div>
                </div><?php endif; ?>
                
                <div class="hero-buttons">
                    <button class="btn btn-primary" onclick="openModal('callback')">
                        <i class="fas fa-phone"></i> Заказать звонок
                    </button>
                    <button class="btn btn-secondary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator"></i> <span class="hero-desktop-copy">Оценить авто</span><span class="hero-mobile-copy">Узнать стоимость авто</span>
                    </button>
                </div>
                
                <div class="hero-stats">
                    <div class="hero-stat-item">
                        <div class="hero-stat-number">98%</div>
                        <div class="hero-stat-label">довольных клиентов</div>
                    </div>
                    <div class="hero-stat-item">
                        <div class="hero-stat-number">45 мин</div>
                        <div class="hero-stat-label">среднее время приезда</div>
                    </div>
                    <div class="hero-stat-item">
                        <div class="hero-stat-number">1500+</div>
                        <div class="hero-stat-label">выкупленных авто</div>
                    </div>
                </div>
            </div>
            
            <div class="hero-form" id="form">
                <div class="form-card">
                    <h2 class="form-title" id="estimateTitle" tabindex="-1" data-modal-initial-focus>
                        <i class="fas fa-bolt"></i> УЗНАТЬ СТОИМОСТЬ
                    </h2>
                    
                    <form id="orderForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="submit_order">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['form_token'] ?>">
                        <input type="hidden" name="submission_id" value="<?= bin2hex(random_bytes(16)) ?>">
                        <input type="hidden" name="form_time" value="<?= $form_time ?>">
                        
                        <div hidden aria-hidden="true">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <input type="text" name="name" id="name" placeholder="Ваше имя" maxlength="100" pattern="(?=.*\p{L})[\p{L}\p{M} '\u2019\u02BC\-]{1,100}" title="Буквы, пробелы, дефис или апостроф — без цифр" data-person-name required aria-label="Ваше имя" autocomplete="name">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="form-group">
                                <input type="tel" name="phone" id="phone" placeholder="+7 (___) ___-__-__" required aria-label="Номер телефона" autocomplete="tel">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="form-group">
                                <input type="text" name="car_brand" maxlength="120" placeholder="Марка и модель" aria-label="Марка и модель">
                                <i class="fas fa-car"></i>
                            </div>
                            <div class="form-group">
                                <input type="text" name="car_year" placeholder="Год выпуска" aria-label="Год выпуска" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" data-digits-max="4" data-year-max="<?= (int)date('Y') + 1 ?>" title="Год выпуска: от 1886 до <?= (int)date('Y') + 1 ?>">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div class="form-group">
                                <input type="text" name="car_mileage" placeholder="Пробег, км" aria-label="Пробег в километрах" inputmode="numeric" maxlength="7" pattern="[0-9]{1,7}" data-digits-max="7">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="form-group">
                                <select name="car_condition" aria-label="Состояние авто">
                                    <option value="">Состояние авто</option>
                                    <option value="perfect">Отличное</option>
                                    <option value="good">Хорошее</option>
                                    <option value="accident">Битое/ДТП</option>
                                    <option value="not_driving">Не на ходу</option>
                                    <option value="credit">Кредитное</option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <input type="text" name="desired_price" id="desired_price" placeholder="Желаемая цена, ₽" inputmode="numeric" maxlength="10" pattern="[0-9]{1,10}" data-digits-max="10" aria-label="Желаемая цена в рублях">
                                <i class="fas fa-ruble-sign"></i>
                            </div>
                            
                            <div class="form-group photo-upload-group">
                                <div class="photo-upload-area" id="photoDropArea" role="button" tabindex="0" aria-label="Добавить фотографии автомобиля" onkeydown="if(event.key === 'Enter' || event.key === ' '){event.preventDefault();document.getElementById('carPhotos').click();}" onclick="document.getElementById('carPhotos').click()">
                                    <span style="font-size: 1.2rem; font-weight: 700;">📸 Добавить фото авто</span>
                                    <span class="photo-hint">JPG, PNG, WEBP, GIF · до 20 фото, всего до 19 МБ</span>
                                    <span class="photo-counter" id="photoCounter" style="display: none;">0/20</span>
                                    <input type="file" name="car_photos[]" id="carPhotos" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display: none;">
                                </div>
                                
                                <div id="photoGallery" class="photo-gallery"></div>
                            </div>
                        </div>
                        
                        <button type="submit" id="submitBtn" class="btn btn-primary btn-block" disabled>
                            <i class="fas fa-calculator"></i> ОЦЕНИТЬ АВТО — БЕСПЛАТНО
                        </button>
                        
                        <p class="form-consent">
                            <i class="fas fa-lock" style="color: var(--red);"></i>
                            Отправляя форму, вы соглашаетесь с 
                            <a href="#" onclick="openModal('policy'); return false;" style="color: var(--red-dark); text-decoration: underline;">политикой конфиденциальности</a>
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>



<!-- ГОРЯЧАЯ ЗАЯВКА -->
<section class="hot-lead" id="hot-lead">
    <div class="container">
        <div class="hot-lead-box">
            <div class="hot-lead-text">
                <span class="hot-lead-badge"><i class="fas fa-fire"></i> Горячая заявка</span>
            <h2>На сегодня осталось выездов оценщика: <b id="slotsLeft">1</b></h2>
                <p>Оставьте заявку сейчас, и мы закрепим за вами свободное время выезда.</p>
            </div>
            <button class="btn btn-primary hot-lead-btn" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- СЕКЦИЯ ВЫКУПАЕМ ЛЮБЫЕ АВТО -->
<section class="section specialization" id="specialization">
    <div class="container">
        <h2 class="section-title">
            ВЫКУПАЕМ <span>ЛЮБЫЕ АВТО</span>
        </h2>
        <p class="section-subtitle">
            Специализируемся на сложных случаях. Работаем со всеми состояниями и документами.
        </p>
        
        <div class="special-grid">
            <div class="special-card">
                <i class="fas fa-car"></i>
                <h3>Целые</h3>
                <p>Без повреждений, в идеальном состоянии</p>
            </div>
            <div class="special-card">
                <i class="fas fa-crash"></i>
                <h3>Битые / ДТП</h3>
                <p>Любые повреждения, тотальные</p>
            </div>
            <div class="special-card">
                <i class="fas fa-building"></i>
                <h3>Кредитные</h3>
                <p>С невыплаченным кредитом, залоговые</p>
            </div>
            <div class="special-card">
                <i class="fas fa-tools"></i>
                <h3>Не на ходу</h3>
                <p>Сломанные, после пожара, затопления</p>
            </div>
            <div class="special-card">
                <i class="fas fa-file-invoice"></i>
                <h3>С запретами</h3>
                <p>Ограничения ГИБДД, аресты</p>
            </div>
            <div class="special-card">
                <i class="fas fa-truck"></i>
                <h3>Коммерческие</h3>
                <p>Грузовики, автобусы, спецтехника</p>
            </div>
            <div class="special-card">
                <i class="fas fa-flag"></i>
                <h3>Китайские авто</h3>
                <p>Haval, Geely, Chery, Exeed</p>
            </div>
            <div class="special-card">
                <i class="fas fa-key"></i>
                <h3>Без документов</h3>
                <p>Потерян ПТС, СТС, дубликаты</p>
            </div>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- СЕКЦИЯ КЕЙСЫ -->
<section class="section cases" id="cases">
    <div class="container">
        <h2 class="section-title">
            <span>РЕАЛЬНЫЕ КЕЙСЫ</span> ВЫКУПА
        </h2>
        <p class="section-subtitle">
            Мы уже выкупили более 1500 автомобилей. Вот некоторые из них:
        </p>
        
        <div class="cases-grid">
            <div class="case-card" data-case="audi-coupe-2026-10-28">
                <div class="case-image">
                    <img src="/img/optimized/case-audi-coupe-2026-10-28.webp" alt="Audi купе" loading="lazy" width="960" height="1280" decoding="async" style="object-position: center 83%;">
                </div>
                <div class="case-info">
                    <div class="case-title">Audi купе, 2010</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">1 050 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 1 час</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
           
    <div class="case-card">
        <div class="case-image">
            <img src="/img/optimized/a-q2.webp" alt="Hyundai Solaris" loading="lazy" width="1024" height="768" decoding="async">
        </div>
        <div class="case-info">
            <div class="case-title">Hyundai Solaris, 2014</div>
            <div class="case-desc">Срочный выкуп</div>
            <div class="case-summary">
                <span class="case-price">800 000 ₽</span>
                <span class="case-time"><i class="fas fa-clock"></i> 30 мин</span>
            </div>
            <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="case-card">
        <div class="case-image">
            <img src="/img/optimized/a-q3.webp" alt="Mitsubishi Pajero Sport" loading="lazy" width="1024" height="768" decoding="async">
        </div>
        <div class="case-info">
            <div class="case-title">Mitsubishi Pajero Sport, 2007</div>
            <div class="case-desc">Срочный выкуп</div>
            <div class="case-summary">
                <span class="case-price">800 000 ₽</span>
                <span class="case-time"><i class="fas fa-clock"></i> 45 мин</span>
            </div>
            <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="case-card">
        <div class="case-image">
            <img src="/img/optimized/a-q4.webp" alt="Nissan X-Trail" loading="lazy" width="1024" height="772" decoding="async">
        </div>
        <div class="case-info">
            <div class="case-title">Nissan X-Trail, 2010</div>
            <div class="case-desc">Срочный выкуп</div>
            <div class="case-summary">
                <span class="case-price">850 000 ₽</span>
                <span class="case-time"><i class="fas fa-clock"></i> 40 мин</span>
            </div>
            <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
    </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-2.webp" alt="Ауди А4 Б5" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Ауди А4 Б5, 1996</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">200 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 40 мин</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-3.webp" alt="Opel Astra J" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Opel Astra J, 2013</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">550 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 1 час</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-7.webp" alt="Ford Mondeo 3" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Ford Mondeo 3, 2005</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">280 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 45 мин</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-8.webp" alt="BMW E60" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">BMW E60, 2008</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">800 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 1 час</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-9.webp" alt="Opel Insignia" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Opel Insignia, 2009</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">600 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 50 мин</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-10.webp" alt="Mitsubishi Lancer 9" loading="lazy" width="768" height="1024" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Mitsubishi Lancer 9, 2007</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">280 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 40 мин</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-12.webp" alt="Chevrolet Cruze" loading="lazy" width="1024" height="768" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Chevrolet Cruze, 2012</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">510 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 1 час</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="case-card">
                <div class="case-image">
                    <img src="/img/optimized/a-13.webp" alt="Volkswagen Tiguan" loading="lazy" width="768" height="1024" decoding="async">
                </div>
                <div class="case-info">
                    <div class="case-title">Volkswagen Tiguan, 2019</div>
                    <div class="case-desc">Срочный выкуп</div>
                    <div class="case-summary">
                        <span class="case-price">1 000 000 ₽</span>
                        <span class="case-time"><i class="fas fa-clock"></i> 45 мин</span>
                    </div>
                    <button class="btn btn-primary case-cta" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i><span>Оценить авто</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- СЕКЦИЯ ОТЗЫВЫ -->
<section class="section reviews" id="reviews">
    <div class="container">
        <h2 class="section-title"><span>ОТЗЫВЫ</span> КЛИЕНТОВ</h2>
        <p class="section-subtitle">Более 1000 положительных отзывов на Яндекс.Картах и 2ГИС</p>
        <div class="reviews-grid">
            <article class="review-card" aria-labelledby="review-name-1">
                <div class="review-author">
                    <span class="review-avatar" aria-hidden="true">
                        <img src="https://avtokai.ru/o/32.jpg?v=da2b57d30c80" alt="" loading="lazy" width="128" height="128" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-1">Александр Петров</h3>
                        <p>Audi Q5, 2018</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Выкуп авто после ДТП</p>
                <p class="review-text">Продавал битую Audi Q5 после серьезного ДТП. Думал, возьмут только в утиль. Оценили быстро, приехали через 40 минут, сразу деньги. Огромное спасибо!</p>
            </article>
            <article class="review-card" aria-labelledby="review-name-2">
                <div class="review-author">
                    <span class="review-avatar" aria-hidden="true">
                        <img src="https://avtokai.ru/o/44.jpg?v=3f212369bf36" alt="" loading="lazy" width="128" height="128" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-2">Екатерина Смирнова</h3>
                        <p>Hyundai Solaris, 2020</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Выкуп кредитного авто</p>
                <p class="review-text">Продавала Hyundai Solaris в кредите. Боялась, что будут проблемы. Ребята сами связались с банком, выплатили кредит, остаток отдали мне. Очень порядочно!</p>
            </article>
            <article class="review-card" aria-labelledby="review-name-3">
                <div class="review-author">
                    <span class="review-avatar" aria-hidden="true">
                        <img src="https://avtokai.ru/o/75.jpg?v=2c0ed139a9e4" alt="" loading="lazy" width="128" height="128" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-3">Дмитрий Волков</h3>
                        <p>Chevrolet Niva, 2019</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Выкуп авто не на ходу</p>
                <p class="review-text">Chevrolet Niva 2019, двигатель накрылся. Эвакуатор бесплатно пригнали, оценка честная, цена выше чем у других контор. Деньги перевели на карту сразу после подписания договора. Рекомендую!</p>
            </article>
            <article class="review-card" id="screenshots" aria-labelledby="review-name-4">
                <div class="review-author">
                    <span class="review-avatar review-avatar--source" style="--avatar-scale: 10.66666667; --avatar-x: -0.40000000; --avatar-y: -0.33333333" aria-hidden="true">
                        <img src="/epi/q1.jpg" alt="" loading="lazy" width="1280" height="610" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-4">Екатерина</h3>
                        <p>Kia Rio, 2014</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Срочный выкуп авто / Выкуп автомобилей</p>
                <p class="review-text">Срочно потребовались деньги, нужно было продать киа рио. Состояние было среднее, подготовку к продаже не делали - отдали как есть. Продали в тот же день. Спасибо за оперативность.</p>
            </article>
            <article class="review-card" aria-labelledby="review-name-5">
                <div class="review-author">
                    <span class="review-avatar review-avatar--source" style="--avatar-scale: 10.55833333; --avatar-x: -0.40000000; --avatar-y: -0.26666667" aria-hidden="true">
                        <img src="/epi/q2.jpg" alt="" loading="lazy" width="1267" height="577" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-5">Мария Белая</h3>
                        <p>Opel Astra J, 2012</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Выкуп авто быстро</p>
                <p class="review-text">Большое спасибо за оперативную сделку) продала машину в день обращения. По цене договорились. Приятно общаться с адекватным человеком) очень рекомендую для продажи авто без головной боли.</p>
            </article>
            <article class="review-card" aria-labelledby="review-name-6">
                <div class="review-author">
                    <span class="review-avatar review-avatar--source" style="--avatar-scale: 10.61666667; --avatar-x: -0.40000000; --avatar-y: -0.20833333" aria-hidden="true">
                        <img src="/epi/q3.jpg" alt="" loading="lazy" width="1274" height="608" decoding="async">
                    </span>
                    <div class="review-author-info">
                        <h3 id="review-name-6">Алексей</h3>
                        <p>Ford Mondeo 3, 2006</p>
                    </div>
                </div>
                <div class="review-rating">
                    <span class="review-stars" role="img" aria-label="5 из 5">★★★★★</span>
                    <span class="review-status">Сделка состоялась</span>
                </div>
                <p class="review-service">Срочный выкуп авто / Выкуп авто в любом состоянии</p>
                <p class="review-text">Всех обзвонили всем показали, но именно им продали дороже, очень довольны, потеряли совсем не много от желаемой суммы но сэкономили кучу времени и нервотрепки, советую</p>
            </article>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- ВИДЕООТЗЫВЫ -->
<section class="section video-reviews" id="video-reviews">
    <div class="container">
        <h2 class="section-title"><span>ВИДЕООТЗЫВЫ</span> НАШИХ КЛИЕНТОВ</h2>
        <p class="section-subtitle">Люди рассказывают, как продали авто</p>
        <div class="vslider">
            <div class="vslider-track" id="vsliderTrack" tabindex="0" role="region" aria-label="Видеоотзывы клиентов. Используйте стрелки для перелистывания.">
                <div class="vslide">
                    <video id="review-video-1" loading="lazy" width="540" height="960" aria-label="Видеоотзыв клиента 1" tabindex="-1" preload="none" playsinline poster="/video/review1.jpg" src="/video/review1.mp4"></video>
                    <a class="video-start" href="/video/review1.mp4" aria-label="Смотреть видеоотзыв клиента 1" aria-controls="review-video-1"></a>
                    <span class="video-feedback" role="status" hidden></span>
                </div>
                <div class="vslide">
                    <video id="review-video-2" loading="lazy" width="540" height="960" aria-label="Видеоотзыв клиента 2" tabindex="-1" preload="none" playsinline poster="/video/review2.jpg" src="/video/review2.mp4"></video>
                    <a class="video-start" href="/video/review2.mp4" aria-label="Смотреть видеоотзыв клиента 2" aria-controls="review-video-2"></a>
                    <span class="video-feedback" role="status" hidden></span>
                </div>
                <div class="vslide">
                    <video id="review-video-3" loading="lazy" width="540" height="960" aria-label="Видеоотзыв клиента 3" tabindex="-1" preload="none" playsinline poster="/video/review3.jpg" src="/video/review3.mp4"></video>
                    <a class="video-start" href="/video/review3.mp4" aria-label="Смотреть видеоотзыв клиента 3" aria-controls="review-video-3"></a>
                    <span class="video-feedback" role="status" hidden></span>
                </div>
                <div class="vslide">
                    <video id="review-video-4" loading="lazy" width="540" height="960" aria-label="Видеоотзыв клиента 4" tabindex="-1" preload="none" playsinline poster="/video/review4.jpg" src="/video/review4.mp4"></video>
                    <a class="video-start" href="/video/review4.mp4" aria-label="Смотреть видеоотзыв клиента 4" aria-controls="review-video-4"></a>
                    <span class="video-feedback" role="status" hidden></span>
                </div>
            </div>
            <div class="vslider-controls" hidden>
                <button class="vslider-arrow left" type="button" aria-label="Предыдущий видеоотзыв" aria-controls="vsliderTrack" onclick="slideVideos(-1)" disabled>‹</button>
                <button class="vslider-arrow right" type="button" aria-label="Следующий видеоотзыв" aria-controls="vsliderTrack" onclick="slideVideos(1)">›</button>
            </div>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- ЭВАКУАТОР -->
<section class="section evac" id="evac">
    <div class="container">
        <h2 class="section-title">НАШ <span>ЭВАКУАТОР</span></h2>
        <p class="section-subtitle">Приезжаем сами и бесплатно забираем авто, даже если оно не на ходу</p>
        <div class="evac-grid">
            <img class="evac-main" src="/img/evac1.jpg" alt="Эвакуатор Автокай с автомобилем" loading="lazy" width="1400" height="1050" decoding="async">
            <img src="/img/evac2.jpg" alt="Эвакуатор Автокай" loading="lazy" width="900" height="675" decoding="async">
            <img src="/img/evac3.jpg" alt="Эвакуатор Автокай" loading="lazy" width="900" height="675" decoding="async">
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- КОМАНДА -->
<section class="section team" id="team">
    <div class="container">
        <h2 class="section-title">НАША <span>КОМАНДА</span></h2>
        <div class="team-grid">
            <div class="team-card"><img src="/img/team1.webp" alt="Евгений" loading="lazy" width="400" height="400" decoding="async"><h3>Евгений, 33 года</h3><p>Опыт в автовыкупах более 5 лет</p></div>
            <div class="team-card"><img src="/img/team2.webp" alt="Сергей" loading="lazy" width="400" height="400" decoding="async"><h3>Сергей, 35 лет</h3><p>Опыт в автобизнесе более 7 лет</p></div>
            <div class="team-card"><img src="/img/team3.webp" alt="Сергей" loading="lazy" width="400" height="400" decoding="async"><h3>Сергей, 50 лет</h3><p>Опыт в продажах более 20 лет</p></div>
            <div class="team-card"><img src="/img/team4.webp" alt="Кай" loading="lazy" width="400" height="400" decoding="async"><h3>Кай, 28 лет</h3><p>Опыт в продажах 5 лет</p></div>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- СЕКЦИЯ ЭТАПЫ -->
<section class="section steps" id="steps">
    <div class="container">
        <h2 class="section-title">
            КАК МЫ <span>РАБОТАЕМ</span>
        </h2>
        <p class="section-subtitle">
            Всего 4 шага и деньги у вас
        </p>
        
        <div class="steps-grid">
            <div class="step-item">
                <div class="step-number"><svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M41 23c0 8.3-7.6 15-17 15h-5l-10 6 3-10c-3.2-2.7-5-6.7-5-11 0-8.3 7.6-15 17-15s17 6.7 17 15Z"/><circle cx="17" cy="23" r="1.7" fill="currentColor" stroke="none"/><circle cx="24" cy="23" r="1.7" fill="currentColor" stroke="none"/><circle cx="31" cy="23" r="1.7" fill="currentColor" stroke="none"/></svg></div>
                <h3>Оставьте заявку</h3>
                <?php if ($isHomeHero): ?>
                <p>Позвоните или заполните форму на сайте. Если сроки важны, узнайте, как проходит <a href="/srochnyj-vykup-avto/">срочный выкуп</a>.</p>
                <?php else: ?>
                <p>Позвоните или заполните форму на сайте</p>
                <?php endif; ?>
            </div>
            <div class="step-item">
                <div class="step-number"><svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 42H9a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3h24a3 3 0 0 1 3 3v11M12 11h18v7H12zM13 25h1m7 0h1m-9 6h1m7 0h1m-9 6h1"/><circle cx="34" cy="34" r="10"/><path d="m29 34 3 3 6-7"/></svg></div>
                <h3>Бесплатная оценка</h3>
                <?php if ($isHomeHero): ?>
                <p>Пришлем цену за 5 минут по фото или на осмотре. Узнайте, <a href="/vykup-avto/">от чего зависит оценка авто</a>.</p>
                <?php else: ?>
                <p>Пришлем цену за 5 минут по фото или на осмотре</p>
                <?php endif; ?>
            </div>
            <div class="step-item">
                <div class="step-number"><svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 21 5-12h19l5 12M6 21h23M6 21v13h12M10 34v5M10 27h5"/><circle cx="30" cy="29" r="9"/><path d="m37 36 7 7" stroke-width="4"/></svg></div>
                <h3>Осмотр авто</h3>
                <?php if ($isHomeHero): ?>
                <p><a href="/avtovykup-v-spb/">Выезд оценщика по СПб и Ленобласти</a></p>
                <?php else: ?>
                <p>Выезд эксперта в любую точку СПб и ЛО</p>
                <?php endif; ?>
            </div>
            <div class="step-item">
                <div class="step-number"><svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="15" width="32" height="25" rx="2"/><path d="M11 10h30v24M16 5h30v24M17 34V21h6a4 4 0 0 1 0 8h-6m-3 2h11"/></svg></div>
                <h3>Получите деньги</h3>
                <?php if ($isHomeHero): ?>
                <p>Наличные или перевод, договор, снятие с учета. <a href="/prodat-avto/">Как оформить продажу автомобиля</a>.</p>
                <?php else: ?>
                <p>Наличные или перевод, договор, снятие с учета</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<?php if ($showServiceDetails): ?>
<section class="section service-section specialization" id="service-details" aria-labelledby="service-details-title">
    <div class="container">
        <h2 class="section-title" id="service-details-title"><?= pageEscape($pageContext['context_h2']) ?></h2>
        <p class="section-subtitle"><?= pageEscape($pageContext['intro']) ?></p>
        <div class="special-grid special-grid--context">
            <?php foreach ($pageContext['cards'] as $card): ?>
            <div class="service-card">
                <i class="fas <?= pageEscape($card['icon']) ?>" aria-hidden="true"></i>
                <h3><?= pageEscape($card['title']) ?></h3>
                <p><?= pageEscape($card['text']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <nav class="context-links" aria-label="Связанные услуги">
            <?php foreach ($pageRelatedServices as $slug): ?>
            <a href="/<?= pageEscape($slug) ?>/"><?= pageEscape($relatedServiceTitles[$slug]) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
<?php endif; ?>

<!-- СЕКЦИЯ FAQ -->
<section class="section faq" id="faq">
    <div class="container">
        <?php if ($pageFaqs !== null): ?>
        <h2 class="section-title"><?= pageEscape($pageFaqTitle) ?></h2>
        <p class="section-subtitle">Коротко об оценке, осмотре и условиях выкупа.</p>
        <div class="faq-grid">
            <?php foreach ($pageFaqs as $index => $faq): ?>
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false" aria-controls="topic-faq-answer-<?= $index ?>">
                    <h3><?= pageEscape($faq['question']) ?></h3>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </div>
                <div class="faq-answer" id="topic-faq-answer-<?= $index ?>"><p><?= pageEscape($faq['answer']) ?></p></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <h2 class="section-title">
            ЧАСТО <span>СПРАШИВАЮТ</span>
        </h2>
        <p class="section-subtitle">
            Напишите нам в любом мессенджере — ответим за 1 минуту
        </p>
        
        <div class="faq-grid">
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                    <h3>Можно продать автомобиль без ПТС?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Да, мы выкупаем авто без ПТС. Если документ утерян — поможем восстановить. 
                    Если авто в залоге — работаем с банками. В любом случае найдем решение.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                    <h3>Как происходит оценка автомобиля?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Два варианта: 1) По фото в WhatsApp/Telegram или любой другой месенджер — предварительная цена за 5 минут. 
                    2) Бесплатный выезд оценщика — точная цена на месте. Осмотр занимает 15-20 минут.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                    <h3>Выкупаете кредитные авто?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Да, это наша специализация. Мы сами связываемся с банком, запрашиваем сумму остатка, 
                    переводим деньги банку, остаток — вам. Никаких кредитов на вас не переоформляем.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                    <h3>Какие документы нужны для сделки?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Минимальный набор: паспорт продавца, ПТС, СТС. Если собственник не один — 
                    нужны паспорта всех собственников или доверенность. Если авто в браке — согласие супруга.</p>
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                    <h3>Есть ли скрытые комиссии?</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    <p>Нет. Называем финальную цену и платим ровно столько. Никаких процентов за снятие с учета, 
                    эвакуатор, оформление — всё бесплатно. Вы получаете ту сумму, о которой договорились.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- ========== СЕКЦИЯ 6 ПРИЧИН РАБОТАТЬ С НАМИ ========== -->
<section class="section benefits" id="benefits">
    <div class="container">
        <h2 class="section-title">
            <span>6 ПРИЧИН</span> РАБОТАТЬ С НАМИ
        </h2>
        <p class="section-subtitle">
            Мы ценим ваше время и предлагаем лучшие условия на рынке автовыкупа
        </p>
        
        <div class="benefits-grid">
            <div class="benefit-card">
                <div class="benefit-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <h3>Деньги сразу</h3>
                <p>Наличные или перевод на карту в день сделки. Без задержек и скрытых комиссий.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <svg class="benefit-tow-icon" viewBox="0 0 64 48" width="60" height="48" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M3 30h40V17h10l9 11v9h-3M3 30v7h3m14 0h26M48 21h3l6 7h-9z"/>
                        <circle cx="13" cy="37" r="5"/><circle cx="53" cy="37" r="5"/>
                        <path d="M5 23v-6h4l5-8h15l7 8h3v7h-2M5 23h2m10 1h10M12 17h21m-11-7v7"/>
                        <circle cx="12" cy="24" r="3.5"/><circle cx="32" cy="24" r="3.5"/>
                    </svg>
                </div>
                <h3>Эвакуатор бесплатно</h3>
                <p>Если авто не на ходу — привезем за наш счет. Экономия от 5000₽.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <i class="fas fa-camera"></i>
                </div>
                <h3>Онлайн-оценка</h3>
                <p>Узнайте цену за 5 минут по фото. Точность 97%.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <i class="fas fa-file-signature"></i>
                </div>
                <h3>Юридическая чистота</h3>
                <p>Оформляем по регламенту ГИБДД. Проверка на штрафы и запреты.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Работаем 24/7</h3>
                <p>В любое время, даже ночью и в праздники. Без выходных.</p>
            </div>
            <div class="benefit-card">
                <div class="benefit-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3>Снимем с учета</h3>
                <p>Полное сопровождение в ГИБДД. Сохраняем номера. Работаем 24/7.</p>
            </div>
        </div>
        <div class="section-cta">
            <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
            </button>
        </div>
    </div>
</section>

<!-- СЕКЦИЯ КОНТАКТЫ -->
<section class="section contacts" id="contacts">
    <div class="container">
        <div class="contacts-grid">
            <div class="contacts-info">
                <h2><span>СВЯЖИТЕСЬ</span> С НАМИ</h2>
                <p style="font-size: 1.1rem; color: var(--gray); margin-bottom: 30px;">
                    Работаем 24/7 без выходных. Приедем в любое удобное время.
                </p>
                
                <div class="contacts-list">
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="contact-text">
                            <strong><a class="contact-link" href="tel:+7<?= substr(SITE_PHONE, 1) ?>"><?= SITE_PHONE_FORMATTED ?></a></strong>
                            <span style="color: var(--gray);">Бесплатно по России</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fab fa-telegram"></i>
                        </div>
                        <div class="contact-text">
                            <strong><a class="contact-link" href="<?= SITE_TELEGRAM ?>" target="_blank" rel="noopener noreferrer">Telegram</a></strong>
                            <span style="color: var(--gray);">Быстрый ответ за 1 минуту</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="contact-text">
                            <strong style="font-size: clamp(1rem, 4vw, 1.3rem); word-break: break-word;"><a class="contact-link" href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a></strong>
                            <span style="color: var(--gray);">Ответ в течение часа</span>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="contact-text">
                            <strong>Санкт-Петербург</strong>
                            <span style="color: var(--gray);">Выезд в любой район</span>
                        </div>
                    </div>
                </div>
                
                <div class="contacts-actions">
                    <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="callbackModal" onclick="openModal('callback')">
                        <i class="fas fa-phone"></i> Заказать звонок
                    </button>
                    <button class="btn btn-primary" type="button" aria-haspopup="dialog" aria-controls="estimateModal" onclick="openModal('estimate')">
                        <i class="fas fa-calculator" aria-hidden="true"></i> Оценить авто
                    </button>
                </div>
            </div>
            
            <div class="contacts-map">
                <iframe src="https://yandex.ru/map-widget/v1/?ll=30.315868%2C59.939095&amp;z=10&amp;l=map" 
                        title="Карта Санкт-Петербурга"
                        allowfullscreen
                        loading="lazy">
                </iframe>
            </div>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- ДИНАМИЧЕСКИЙ SEO ТЕКСТ ДЛЯ СТАНЦИЙ МЕТРО -->
<!-- ============================================ -->
<?php if ($isStationPage && !empty($pageText)): ?>
<div class="container" style="max-width:1300px;margin:0 auto;padding:40px 25px;">
<?php if ($stationVisitLandmarks): ?>
    <section class="station-seo-content" id="station-visit" aria-labelledby="station-visit-title">
        <h2 id="station-visit-title">Осмотр автомобиля у метро «<?= pageEscape($stationHeroName) ?>»</h2>
        <p><strong>Адрес для ориентира у метро</strong> — <?= pageEscape(implode(' · ', $stationVisitLandmarks)) ?>.</p>
        <p>Осмотр проходит по адресу стоянки автомобиля. Укажите улицу, номер дома и как подъехать. Если машина стоит в закрытом дворе или паркинге, уточните условия въезда.</p>
        <p>Если автомобиль <a href="/vykup-neispravnyh-avto/">не на ходу</a>, сообщите, вращаются ли колёса и сможет ли подъехать эвакуатор.</p>
        <p><a href="https://metro.spb.ru/uploads/document/adress_vestibule_26.12.2025.pdf">Адреса вестибюлей</a> · <a href="https://metro.spb.ru/remeskltr.html">Изменения в работе метро</a></p>
    </section>
<?php else: ?>
    <div class="station-seo-content">
        <?= $pageText ?>
    </div>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- ОБЛАКО ТЕГОВ СТАНЦИЙ МЕТРО -->
<!-- ============================================ -->
<?php
// Получаем все станции из seo_data.json
$allStations = $seoData;
?>
<div class="metro-tags-section">
    <div class="container" style="max-width:1300px;margin:0 auto;padding:0 25px;width:100%;">
        <details class="metro-tags-wrapper">
            <summary class="metro-tags-title">
                <i class="fas fa-subway" aria-hidden="true"></i>
                <span class="metro-tags-label">Выкуп авто в районах Санкт-Петербурга</span>
                <span class="metro-tags-toggle" aria-hidden="true"></span>
            </summary>
            <div class="metro-tags-cloud">
                <?php if (!empty($allStations)):
                    $stationKeys = array_keys(array_filter($allStations, 'is_array'));
                    usort($stationKeys, static fn($a, $b) => strcmp((string)($allStations[$a]['station_name'] ?? $a), (string)($allStations[$b]['station_name'] ?? $b)));
                    $sizeClasses = ['tag-sm', 'tag-md', 'tag-lg', 'tag-xl'];
                    foreach ($stationKeys as $index => $slug):
                        $station = $allStations[$slug];
                        $stationName = is_string($station['station_name'] ?? null) && trim($station['station_name']) !== '' ? trim($station['station_name']) : (string)$slug;
                        $sizeClass = $sizeClasses[$index % count($sizeClasses)];
                        $isActive = $isStationHero && $currentSlug === $slug;
                        $linkText = 'У метро «' . $stationName . '»';
                        $url = '/?spb=' . rawurlencode((string)$slug);
                    ?>
                    <a href="<?= pageEscape($url) ?>" class="metro-tag <?= $sizeClass . ($isActive ? ' active' : '') ?>" title="<?= pageEscape('Выкуп авто у метро «' . $stationName . '» — Санкт-Петербург') ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= pageEscape($linkText) ?></a>
                <?php endforeach; endif; ?>
            </div>
        </details>
    </div>
</div>



</main>
<!-- ФУТЕР -->
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <div class="footer-logo">
                    <div class="footer-logo-img">
                        <img src="/img/optimized/logo.webp?v=b841bce2" alt="Автокай — выкуп авто" width="836" height="470" decoding="async" loading="lazy">
                    </div>
                </div>
                <p class="footer-about">
                    Срочный выкуп автомобилей в Санкт-Петербурге и Ленинградской области. 
                    Деньги в день обращения. Бесплатный выезд оценщика. Работаем 24/7.
                </p>
            </div>
            
            <details class="footer-link-group" open>
                <summary><h2 class="footer-title">Выкуп и продажа<svg class="footer-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg></h2></summary>
                <ul class="footer-links">
                    <?php foreach ($campaignSlugs as $slug): ?>
                    <li><a href="/<?= $slug ?>/"><i class="fas fa-chevron-right" aria-hidden="true"></i> <?= pageEscape($campaignPages[$slug]['hero_title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>

            <details class="footer-link-group" open>
                <summary><h2 class="footer-title">Быстрые ссылки<svg class="footer-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg></h2></summary>
                <ul class="footer-links">
                    <li><a href="/"><i class="fas fa-chevron-right"></i> Главная</a></li>
                    <li><a href="#form"><i class="fas fa-chevron-right"></i> Оценка онлайн</a></li>
                    <li><a href="<?= $homeAnchorPrefix ?>#cases"><i class="fas fa-chevron-right"></i> Кейсы</a></li>
                    <li><a href="<?= $homeAnchorPrefix ?>#reviews"><i class="fas fa-chevron-right"></i> Отзывы</a></li>
                    <li><a href="#steps"><i class="fas fa-chevron-right"></i> Как мы работаем</a></li>
                    <li><a href="#faq"><i class="fas fa-chevron-right"></i> Вопросы</a></li>
                    <li><a href="#contacts"><i class="fas fa-chevron-right"></i> Контакты</a></li>
                </ul>
            </details>
            
            <details class="footer-link-group" open>
                <summary><h2 class="footer-title">Выкуп авто<svg class="footer-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg></h2></summary>
                <ul class="footer-links">
                    <?php foreach ($serviceSlugs as $slug): ?>
                    <li><a href="/<?= $slug ?>/"><i class="fas fa-chevron-right" aria-hidden="true"></i> <?= pageEscape($servicePages[$slug]['nav']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            
            <div>
                <h2 class="footer-title">Контакты</h2>
                <a href="tel:+7<?= substr(SITE_PHONE, 1) ?>" class="footer-phone" rel="nofollow"><?= SITE_PHONE_FORMATTED ?></a>
                <p style="color: #a0aec0; margin-bottom: 10px;">Бесплатно по РФ</p>
                
                <p style="color: #a0aec0; margin-bottom: 10px; display: flex; align-items: flex-start; gap: 8px;">
                    <i class="fas fa-envelope" style="color: var(--red); margin-top: 3px;"></i>
                    <a class="contact-link" href="mailto:<?= SITE_EMAIL ?>" style="word-break: break-word; flex: 1;"><?= SITE_EMAIL ?></a>
                </p>
                
                <p style="color: #a0aec0; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-map-marker-alt" style="color: var(--red);"></i>
                    <span>СПб, м. Московская</span>
                </p>
                
                <div class="footer-social">
                    <a href="<?= SITE_TELEGRAM ?>" class="social-icon social-icon--telegram" aria-label="Написать в Telegram" title="Telegram" target="_blank" rel="noopener noreferrer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 128 128" aria-hidden="true" focusable="false">
<defs>
<linearGradient id="footer-telegram-gradient" x1="50%" x2="50%" y1="0%" y2="99.258%">
<stop offset="0%" stop-color="#2AABEE"/>
<stop offset="100%" stop-color="#229ED9"/>
</linearGradient>
</defs>
<g fill="none" fill-rule="evenodd">
<circle cx="64" cy="64" r="64" fill="url(#footer-telegram-gradient)" fill-rule="nonzero"/>
<path fill="#FFF" fill-rule="nonzero" d="M28.9700376,63.3244248 C47.6273373,55.1957357 60.0684594,49.8368063 66.2934036,47.2476366 C84.0668845,39.855031 87.7600616,38.5708563 90.1672227,38.528 C90.6966555,38.5191258 91.8804274,38.6503351 92.6472251,39.2725385 C93.294694,39.7979149 93.4728387,40.5076237 93.5580865,41.0057381 C93.6433345,41.5038525 93.7494885,42.63857 93.6651041,43.5252052 C92.7019529,53.6451182 88.5344133,78.2034783 86.4142057,89.5379542 C85.5170662,94.3339958 83.750571,95.9420841 82.0403991,96.0994568 C78.3237996,96.4414641 75.5015827,93.6432685 71.9018743,91.2836143 C66.2690414,87.5912212 63.0868492,85.2926952 57.6192095,81.6896017 C51.3004058,77.5256038 55.3966232,75.2369981 58.9976911,71.4967761 C59.9401076,70.5179421 76.3155302,55.6232293 76.6324771,54.2720454 C76.6721165,54.1030573 76.7089039,53.4731496 76.3346867,53.1405352 C75.9604695,52.8079208 75.4081573,52.921662 75.0095933,53.0121213 C74.444641,53.1403447 65.4461175,59.0880351 48.0140228,70.8551922 C45.4598218,72.6091037 43.1463059,73.4636682 41.0734751,73.4188859 C38.7883453,73.3695169 34.3926725,72.1268388 31.1249416,71.0646282 C27.1169366,69.7617838 23.931454,69.0729605 24.208838,66.8603276 C24.3533167,65.7078514 25.9403832,64.5292172 28.9700376,63.3244248 Z"/>
</g>
</svg>
                    </a>
                    <a href="<?= pageEscape($publicWhatsAppUrl) ?>" class="social-icon social-icon--whatsapp" aria-label="Написать в WhatsApp" title="WhatsApp" target="_blank" rel="noopener noreferrer">
                        <i class="fab fa-whatsapp" aria-hidden="true"></i>
                    </a>
                    <a href="<?= SITE_MAXIM ?>" class="social-icon social-icon--max" aria-label="Написать в MAX" title="MAX" target="_blank" rel="noopener noreferrer">
                        <img src="/maxim.png?v=d73830b0" alt="" width="64" height="64" decoding="async">
                    </a>
                </div>
            </div>
        </div>
        
        <!-- SEO ТЕКСТ -->
        <div class="seo-text">
            <div class="container">
                <div class="seo-content" id="seoContent">
                    <?php if (!$isHomeHero): ?>
                    <h3 style="color: white; margin-bottom: 15px; font-size: 1.3rem;"><?= pageEscape($footerTopicTitle) ?></h3>
                    <?php foreach ($footerTopicParagraphs as $paragraph): ?><p><?= pageEscape($paragraph) ?></p><?php endforeach; ?>
                    <?php else: ?>
                    <h3 style="color: white; margin-bottom: 15px; font-size: 1.3rem;">Срочный выкуп авто в Санкт-Петербурге и Ленинградской области</h3>
                    <p>Компания «Автокай» — это профессиональный и быстрый выкуп автомобилей в Санкт-Петербурге и Ленинградской области. Мы работаем 24/7 без выходных и праздников. Наша специализация — срочный выкуп авто в любом состоянии: битые, кредитные, не на ходу, с пробегом, после ДТП, арестованные, с запретом на регистрацию, коммерческий транспорт и китайские автомобили.</p>
                    <p>Почему выбирают именно нас? Во-первых, мы предлагаем до 95% рыночной стоимости автомобиля. Во-вторых, деньги вы получаете в день сделки — наличными или переводом на карту. В-третьих, мы бесплатно вызываем эвакуатор, если ваше авто не на ходу, и самостоятельно снимаем его с учета в ГИБДД. Вам не нужно посещать ведомства — мы всё делаем сами.</p>
                    <p>Мы ценим ваше время: оценка по фото занимает всего 5 минут, выезд оценщика — 30–40 минут в любую точку СПб и ЛО. Оформление договора купли-продажи происходит на месте. Никаких скрытых комиссий и дополнительных платежей. Вы получаете ровно ту сумму, которую назвали при оценке.</p>
                    <p>С нами сотрудничают частные лица, автопарки, таксопарки, лизинговые компании. Мы выкупаем как отечественные, так и импортные автомобили любых марок и годов выпуска. Работаем с залоговыми авто и кредитными обязательствами — сами погашаем остаток кредита в банке, остаток отдаем вам.</p>
                    <p>«Автокай» — это прозрачность, честность и скорость. Оставьте заявку на сайте или позвоните по номеру <a class="contact-link" href="tel:+7<?= substr(SITE_PHONE, 1) ?>"><?= SITE_PHONE_FORMATTED ?></a>, чтобы получить бесплатную оценку вашего автомобиля прямо сейчас!</p>
                    <?php endif; ?>
                </div>
                <button type="button" class="seo-toggle" id="seoToggle" aria-expanded="false" aria-controls="seoContent" onclick="toggleSeoText()">
                    <span>Читать далее</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p><a href="https://romanulin.ru/" target="_blank" rel="noopener noreferrer">© 2025 Автокай</a> — Срочный выкуп авто в СПб. Все права защищены.</p>
            <p class="footer-legal" style="margin-top: 10px; font-size: 0.8rem;">
                <a href="#" onclick="openModal('policy'); return false;" style="color: #718096; text-decoration: none;">Политика конфиденциальности</a> 
                <a href="#" onclick="openModal('agreement'); return false;" style="color: #718096; text-decoration: none;">Пользовательское соглашение</a>
                <button type="button" class="cookie-info-link" id="cookieInfoLink" aria-controls="cookieNotice">Cookies</button>
            </p>
        </div>
    </div>
</footer>

<section class="cookie-notice" id="cookieNotice" role="region" aria-labelledby="cookieNoticeTitle" hidden data-nosnippet>
    <div class="cookie-notice-copy">
        <h2 id="cookieNoticeTitle">Мы используем cookies</h2>
        <p>Cookies помогают сайту работать, а нам — анализировать посещаемость и обращения.
            Подробнее — в <a href="#" onclick="openModal('policy'); return false;" aria-haspopup="dialog" aria-controls="policyModal">политике конфиденциальности</a>.</p>
    </div>
    <button type="button" class="btn btn-primary" id="cookieNoticeAccept">Понятно</button>
</section>

<!-- МОДАЛКА ОЦЕНКИ: СЮДА ВРЕМЕННО ПЕРЕНОСИТСЯ ОБЩАЯ ФОРМА -->
<div class="modal<?= $isPhoneHero ? ' modal--phone-estimate' : '' ?>" id="estimateModal" role="dialog" aria-modal="true" aria-labelledby="estimateTitle">
    <div class="modal-content estimate-dialog">
        <button type="button" class="modal-close" aria-label="Закрыть оценку авто" onclick="closeModal('estimate')">&times;</button>
        <div class="estimate-modal-body" id="estimateFormHost"></div>
    </div>
</div>

<!-- МОДАЛКА ЗВОНКА -->
<div class="modal" id="callbackModal" role="dialog" aria-modal="true" aria-labelledby="callbackTitle">
    <div class="modal-content">
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('callback')">&times;</button>
        <h3 id="callbackTitle" tabindex="-1" data-modal-initial-focus>Заказать звонок</h3>
        <p class="callback-intro">Оставьте номер — мы перезвоним через 1 минуту</p>
        
        <form id="callbackForm" method="post" class="callback-form">
            <input type="hidden" name="action" value="submit_callback">
            <input type="hidden" name="submission_id" value="<?= bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['form_token'] ?>">
            
            <div hidden aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>
            
            <div class="form-group">
                <input type="text" name="name" id="callbackName" placeholder="Ваше имя" maxlength="100" pattern="(?=.*\p{L})[\p{L}\p{M} '\u2019\u02BC\-]{1,100}" title="Буквы, пробелы, дефис или апостроф — без цифр" data-person-name required aria-label="Ваше имя" autocomplete="name">
                <i class="fas fa-user"></i>
            </div>
            <div class="form-group">
                <input type="tel" name="phone" id="callbackPhone" placeholder="+7 (___) ___-__-__" required aria-label="Номер телефона" autocomplete="tel">
                <i class="fas fa-phone"></i>
            </div>
            <button type="submit" id="callbackSubmitBtn" class="btn btn-primary btn-block" disabled>
                <i class="fas fa-phone"></i> Перезвоните мне
            </button>
            
            <p class="callback-policy">
                Отправляя форму, вы соглашаетесь с 
                <a href="#" onclick="openModal('policy'); return false;" style="color: var(--red-dark); text-decoration: underline;">политикой конфиденциальности</a>
            </p>
        </form>
    </div>
</div>

<!-- МОДАЛКА УСПЕХА -->
<div class="modal" id="successModal" role="dialog" aria-modal="true" aria-label="Заявка принята">
    <div class="modal-content" style="text-align: center;">
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('success')">&times;</button>
        <i class="fas fa-check-circle" style="font-size: 6rem; color: var(--red); margin-bottom: 25px;"></i>
        <h3 style="margin-bottom: 15px;">Спасибо за заявку!</h3>
        <p style="margin-bottom: 30px; font-size: 1.1rem;">
            Ваша заявка успешно отправлена!<br>Мы свяжемся с вами в ближайшее время.
        </p>
        <button class="btn btn-primary" onclick="closeModal('success')">
            Хорошо
        </button>
    </div>
</div>

<!-- МОДАЛКА ПОЛИТИКИ КОНФИДЕНЦИАЛЬНОСТИ -->
<div class="modal" id="policyModal" role="dialog" aria-modal="true" aria-label="Политика конфиденциальности" data-nosnippet>
    <div class="modal-content">
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('policy')">&times;</button>
        <h3>Политика конфиденциальности</h3>
        
        <p>Настоящая Политика конфиденциальности регулирует порядок обработки и использования персональных данных пользователей сайта https://avtokai.ru/.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">1. Сбор персональных данных</h4>
        <p>Мы собираем следующие персональные данные:</p>
        <ul class="policy-list">
            <li><i class="fas fa-check"></i> Имя и номер телефона при заполнении форм</li>
            <li><i class="fas fa-check"></i> Данные об автомобиле (марка, год, пробег, состояние)</li>
            <li><i class="fas fa-check"></i> Фотографии автомобиля</li>
        </ul>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">2. Использование данных</h4>
        <p>Персональные данные используются для:</p>
        <ul class="policy-list">
            <li><i class="fas fa-check"></i> Связи с вами для оценки автомобиля</li>
            <li><i class="fas fa-check"></i> Оформления сделки купли-продажи</li>
            <li><i class="fas fa-check"></i> Отправки уведомлений о статусе заявки</li>
        </ul>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">3. Защита данных</h4>
        <p>Мы используем современные методы шифрования и защиты данных. Ваши данные не передаются третьим лицам без вашего согласия.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">4. Хранение данных</h4>
        <p>Данные хранятся на защищенных серверах в течение срока, необходимого для выполнения услуг и соблюдения законодательства РФ.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">5. Ваши права</h4>
        <p>Вы можете запросить удаление ваших персональных данных, отправив запрос на email: <a class="contact-link" href="mailto:<?= SITE_EMAIL ?>"><?= SITE_EMAIL ?></a></p>

        <h4 style="margin: 20px 0 10px; color: var(--dark);">6. Cookies и данные браузера</h4>
        <p>Сайт использует технические cookies для работы сессии и защиты форм. Для анализа посещаемости и обращений на сайте подключены Яндекс.Метрика и Callibri.</p>
        <p>Нажатие «Понятно» сохраняет отметку об ознакомлении с уведомлением в вашем браузере на один год. Повторно открыть уведомление можно по кнопке «Cookies» в подвале сайта. Эта отметка не меняет работу подключённых сервисов аналитики.</p>
        <p>Управлять cookies и сохранёнными данными сайта можно в настройках браузера. Ограничение технических cookies может повлиять на работу форм.</p>
        
        <p style="margin-top: 25px; color: var(--gray); font-size: 0.9rem;">
            <i class="fas fa-shield-alt" style="color: var(--red);"></i>
            Последнее обновление: 21.10.2026
        </p>
        
        <button class="btn btn-primary" onclick="closeModal('policy')" style="margin-top: 20px;">
            Закрыть
        </button>
    </div>
</div>

<!-- МОДАЛКА ПОЛЬЗОВАТЕЛЬСКОГО СОГЛАШЕНИЯ -->
<div class="modal" id="agreementModal" role="dialog" aria-modal="true" aria-label="Пользовательское соглашение" data-nosnippet>
    <div class="modal-content">
        <button type="button" class="modal-close" aria-label="Закрыть" onclick="closeModal('agreement')">&times;</button>
        <h3>Пользовательское соглашение</h3>
        
        <p>Настоящее Пользовательское соглашение регулирует отношения между компанией «Автокай» и пользователем сайта https://avtokai.ru/.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">1. Общие положения</h4>
        <p>1.1. Используя сайт, вы подтверждаете свое согласие с условиями настоящего соглашения.</p>
        <p>1.2. Компания оставляет за собой право изменять условия соглашения без уведомления пользователей.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">2. Обязательства компании</h4>
        <p>2.1. Компания обязуется предоставить услуги по оценке и выкупу автомобиля в соответствии с заявкой.</p>
        <p>2.2. Компания гарантирует конфиденциальность персональных данных пользователя.</p>
        <p>2.3. Компания обязуется выплатить согласованную сумму за автомобиль в день сделки.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">3. Обязательства пользователя</h4>
        <p>3.1. Пользователь гарантирует достоверность предоставленной информации об автомобиле.</p>
        <p>3.2. Пользователь подтверждает свое право на продажу автомобиля и отсутствие ограничений.</p>
        <p>3.3. Пользователь обязуется предоставить все необходимые документы для оформления сделки.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">4. Порядок оказания услуг</h4>
        <p>4.1. Оценка автомобиля производится на основании предоставленных фото или осмотра.</p>
        <p>4.2. Окончательная цена фиксируется в договоре купли-продажи.</p>
        <p>4.3. Оплата производится наличными или переводом на банковскую карту.</p>
        
        <h4 style="margin: 20px 0 10px; color: var(--dark);">5. Ответственность сторон</h4>
        <p>5.1. Компания не несет ответственности за технические сбои в работе сайта.</p>
        <p>5.2. Пользователь несет ответственность за достоверность предоставленных документов.</p>
        
        <p style="margin-top: 25px; color: var(--gray); font-size: 0.9rem;">
            <i class="fas fa-gavel" style="color: var(--red);"></i>
            Последнее обновление: 01.03.2025
        </p>
        
        <button class="btn btn-primary" onclick="closeModal('agreement')" style="margin-top: 20px;">
            Закрыть
        </button>
    </div>
</div>



<!-- СХЕМА ORG -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "AutomotiveBusiness",
  "@id": "https://avtokai.ru/#organization",
  "name": "Автокай",
  "description": "Срочный выкуп автомобилей в Санкт-Петербурге. Деньги за 30 минут, бесплатный эвакуатор, любые авто.",
  "image": "https://avtokai.ru/img/optimized/logo.webp?v=b841bce2",
  "telephone": "+7<?= substr(SITE_PHONE, 1) ?>",
  "email": "<?= SITE_EMAIL ?>",
  "address": {
    "@type": "PostalAddress",
    "addressLocality": "Санкт-Петербург",
    "addressCountry": "RU"
  },
  "openingHoursSpecification": {
    "@type": "OpeningHoursSpecification",
    "dayOfWeek": [
      "Monday",
      "Tuesday",
      "Wednesday",
      "Thursday",
      "Friday",
      "Saturday",
      "Sunday"
    ],
    "opens": "00:00",
    "closes": "23:59"
  },
  "priceRange": "₽₽₽",
  "areaServed": "Санкт-Петербург и Ленинградская область",
  "url": "https://avtokai.ru/",
  "logo": "https://avtokai.ru/img/optimized/logo.webp?v=b841bce2"
}
</script>

<script type="application/ld+json">
<?php
$structuredPage = [
    '@type' => 'WebPage',
    '@id' => $canonicalUrl . '#webpage',
    'url' => $canonicalUrl,
    'name' => $pageTitle,
    'description' => $pageDescription,
    'inLanguage' => 'ru-RU',
    'isPartOf' => ['@id' => 'https://avtokai.ru/#website'],
    'about' => ['@id' => 'https://avtokai.ru/#organization'],
];
$structuredGraph = [[
    '@type' => 'WebSite',
    '@id' => 'https://avtokai.ru/#website',
    'url' => 'https://avtokai.ru/',
    'name' => 'Автокай',
    'inLanguage' => 'ru-RU',
    'publisher' => ['@id' => 'https://avtokai.ru/#organization'],
]];
if ($isServicePage || $isStationPage || $isCampaignPage || $isAutovykupPage) {
    $serviceId = $canonicalUrl . '#service';
    $structuredPage['mainEntity'] = ['@id' => $serviceId];
    $structuredGraph[] = [
        '@type' => 'Service',
        '@id' => $serviceId,
        'name' => $pageH1,
        'serviceType' => 'Выкуп автомобилей',
        'description' => $pageDescription,
        'url' => $canonicalUrl,
        'provider' => ['@id' => 'https://avtokai.ru/#organization'],
        'areaServed' => 'Санкт-Петербург и Ленинградская область',
    ];
}
$structuredGraph[] = $structuredPage;
echo json_encode(
    ['@context' => 'https://schema.org', '@graph' => $structuredGraph],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
);
?>
</script>

<script>
// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ МЕТРИКИ
// ============================================
function sendMetricaGoal(goalName, goalParams = {}) {
    goalParams = {...goalParams, landing_page: window.location.pathname};
    if (typeof ym !== 'undefined') {
        try {
            ym(106799647, 'reachGoal', goalName, goalParams);
            console.log('✅ Цель отправлена:', goalName, goalParams);
            
            // Также отправляем в dataLayer для Google Analytics если есть
            if (typeof dataLayer !== 'undefined') {
                dataLayer.push({
                    'event': 'ym_goal',
                    'goal_name': goalName,
                    'goal_params': goalParams
                });
            }
            return true;
        } catch(e) {
            console.error('Ошибка отправки цели:', e);
            return false;
        }
    } else {
        console.warn('⚠️ Метрика не загружена, сохраняем цель:', goalName);
        
        // Сохраняем в localStorage для повторной отправки
        try {
            const pendingGoals = JSON.parse(localStorage.getItem('pendingMetricaGoals') || '[]');
            pendingGoals.push({
                name: goalName,
                params: goalParams,
                timestamp: Date.now()
            });
            localStorage.setItem('pendingMetricaGoals', JSON.stringify(pendingGoals));
        } catch(e) {
            console.error('Ошибка сохранения цели:', e);
        }
        return false;
    }
}

// Отправка сохраненных целей
function processPendingGoals() {
    try {
        const pendingGoals = JSON.parse(localStorage.getItem('pendingMetricaGoals') || '[]');
        if (pendingGoals.length > 0 && typeof ym !== 'undefined') {
            pendingGoals.forEach(goal => {
                setTimeout(() => {
                    ym(106799647, 'reachGoal', goal.name, goal.params);
                    console.log('✅ Отправлена сохраненная цель:', goal.name);
                }, 1000);
            });
            localStorage.removeItem('pendingMetricaGoals');
        }
    } catch(e) {
        console.error('Ошибка обработки сохраненных целей:', e);
    }
}

// ============================================
// МАСКА ТЕЛЕФОНА
// ============================================
function parsePhoneDigits(value) {
    const text = value.trim();
    if (!/^(?:\+7)?[\d\s().\u2010-\u2015-]*$/.test(text)) return null;

    let digits = text.replace(/\D/g, '');
    if (text.startsWith('+7') || /^[78]\d{10}$/.test(digits)) {
        digits = digits.slice(1);
    }
    return digits.length <= 10 ? digits : null;
}

function formatPhoneNumber(digits) {
    if (!digits) return '';

    let formatted = `+7 (${digits.slice(0, 3)}`;
    if (digits.length > 3) formatted += `) ${digits.slice(3, 6)}`;
    if (digits.length > 6) formatted += `-${digits.slice(6, 8)}`;
    if (digits.length > 8) formatted += `-${digits.slice(8, 10)}`;
    return formatted;
}

function completePhoneNumber(value) {
    const digits = parsePhoneDigits(value);
    return digits?.length === 10 ? formatPhoneNumber(digits) : null;
}

document.querySelectorAll('input[type="tel"]').forEach(input => {
    input.addEventListener('input', function() {
        const digits = parsePhoneDigits(input.value);
        const undecidedPrefix = /^[78]/.test(input.value.trim()) && digits?.length === input.value.replace(/\D/g, '').length;
        if (digits && !undecidedPrefix && input.selectionEnd === input.value.length) {
            input.value = formatPhoneNumber(digits);
        }
        checkFormValidity();
        checkCallbackFormValidity();
    });

    input.addEventListener('change', () => {
        checkFormValidity();
        checkCallbackFormValidity();
    });
});

// ============================================
// МНОЖЕСТВЕННАЯ ЗАГРУЗКА ФОТО
// ============================================
const MAX_PHOTOS = 20;
const MAX_PHOTO_BYTES = 19 * 1024 * 1024;
let selectedFiles = [];
const photoPreviewUrls = new Map();

const photoInput = document.getElementById('carPhotos');
const photoGallery = document.getElementById('photoGallery');
const photoCounter = document.getElementById('photoCounter');

if (photoInput) {
    photoInput.addEventListener('change', function(e) {
        handleMultipleFiles(this.files);
    });
}

function handleMultipleFiles(files) {
    const newFiles = Array.from(files);
    
    if (selectedFiles.length + newFiles.length > MAX_PHOTOS) {
        alert(`Можно загрузить не более ${MAX_PHOTOS} фото`);
        updateFileInput();
        return;
    }
    
    const validFiles = newFiles.filter(file => {
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
            alert(`Файл "${file.name}" не является изображением`);
            return false;
        }
        if (file.size > 20 * 1024 * 1024) {
            alert(`Файл "${file.name}" больше 20MB`);
            return false;
        }
        return true;
    });
    
    const nextFiles = [...selectedFiles, ...validFiles];
    if (nextFiles.reduce((total, file) => total + file.size, 0) > MAX_PHOTO_BYTES) {
        alert('Общий размер фотографий должен быть не больше 19 МБ.');
        updateFileInput();
        return;
    }
    selectedFiles = nextFiles;
    updateFileInput();
    renderAllThumbnails();
    checkFormValidity();
}

function updateFileInput() {
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => {
        dataTransfer.items.add(file);
    });
    photoInput.files = dataTransfer.files;
    
    if (selectedFiles.length > 0) {
        photoCounter.style.display = 'inline-block';
        photoCounter.textContent = `${selectedFiles.length}/${MAX_PHOTOS}`;
    } else {
        photoCounter.style.display = 'none';
    }
}

function renderAllThumbnails() {
    const activeFiles = new Set(selectedFiles);
    for (const [file, url] of photoPreviewUrls) {
        if (!activeFiles.has(file)) {
            URL.revokeObjectURL(url);
            photoPreviewUrls.delete(file);
        }
    }
    photoGallery.innerHTML = selectedFiles.map((file, index) => {
        if (!photoPreviewUrls.has(file)) photoPreviewUrls.set(file, URL.createObjectURL(file));
        return `
            <div class="photo-thumb">
                <img src="${photoPreviewUrls.get(file)}" alt="Фото ${index + 1}" loading="lazy">
                <button type="button" class="remove-photo-small" aria-label="Удалить фото ${index + 1}" onclick="removePhoto(${index})">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>
        `;
    }).join('');
}

function releasePhotoPreviews() {
    for (const url of photoPreviewUrls.values()) URL.revokeObjectURL(url);
    photoPreviewUrls.clear();
}

function removePhoto(index) {
    selectedFiles.splice(index, 1);
    updateFileInput();
    renderAllThumbnails();
    checkFormValidity();
}

photoInput.form.addEventListener('reset', () => {
    selectedFiles = [];
    renderAllThumbnails();
    photoCounter.style.display = 'none';
});
window.addEventListener('pagehide', releasePhotoPreviews);
window.addEventListener('pageshow', () => {
    if (selectedFiles.length > 0) {
        updateFileInput();
        renderAllThumbnails();
    }
});

// POPUP HISTORY ADAPTERS START
const popupHistoryAdapters = new Map();
// POPUP HISTORY ADAPTERS END

// Keep the native field for form submission and no-JS fallback; style its enhanced popup.
(() => {
    const select = document.querySelector('#orderForm select[name="car_condition"]');
    if (!select) return;
    const widget = document.createElement('div');
    widget.className = 'condition-select';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'condition-select-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-label', 'Состояние авто');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', 'carConditionOptions');
    const popup = document.createElement('div');
    popup.id = 'carConditionOptions';
    popup.className = 'condition-select-options';
    popup.setAttribute('role', 'listbox');
    popup.setAttribute('aria-label', 'Состояние авто');
    popup.hidden = true;
    const items = [...select.options].map((option, index) => {
        const item = document.createElement('div');
        item.id = 'carConditionOption-' + index;
        item.className = 'condition-select-option';
        item.setAttribute('role', 'option');
        item.textContent = option.textContent;
        item.addEventListener('click', () => commit(index));
        item.addEventListener('pointermove', event => {
            if (event.pointerType === 'mouse') activate(index);
        });
        popup.append(item);
        return item;
    });
    let active = select.selectedIndex;
    let search = '';
    let searchTimer;
    function sync() {
        trigger.textContent = select.options[select.selectedIndex]?.textContent || 'Состояние авто';
        trigger.disabled = select.disabled;
        items.forEach((item, index) => item.setAttribute('aria-selected', String(index === select.selectedIndex)));
    }
    function hide() {
        popup.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        trigger.removeAttribute('aria-activedescendant');
        items.forEach(item => item.classList.remove('is-active'));
        search = '';
        clearTimeout(searchTimer);
    }
    function close() {
        if (!popup.hidden) closeHistoryLayer('condition');
    }
    function position() {
        if (popup.hidden) return;
        const rect = trigger.getBoundingClientRect();
        const height = document.documentElement.clientHeight;
        const width = document.documentElement.clientWidth;
        const topEdge = trigger.closest('.modal.active') ? 8 : Math.max(8, (document.getElementById('header')?.getBoundingClientRect().bottom || 0) + 8);
        if (rect.bottom <= topEdge || rect.top >= height) {
            if (modalHistorySyncing) hide();
            else close();
            return;
        }
        popup.style.width = rect.width + 'px';
        const below = Math.max(0, height - rect.bottom - 16);
        const above = Math.max(0, rect.top - topEdge - 8);
        const naturalHeight = Math.min(280, popup.scrollHeight + 2);
        const openBelow = below >= naturalHeight || below >= above;
        popup.style.maxHeight = Math.max(44, Math.min(280, openBelow ? below : above)) + 'px';
        popup.style.left = Math.max(8, Math.min(rect.left, width - rect.width - 8)) + 'px';
        popup.style.top = (openBelow ? rect.bottom + 8 : rect.top - popup.offsetHeight - 8) + 'px';
    }
    function activate(index) {
        active = Math.max(0, Math.min(items.length - 1, index));
        items.forEach((item, i) => item.classList.toggle('is-active', i === active));
        trigger.setAttribute('aria-activedescendant', items[active].id);
        const item = items[active];
        if (item.offsetTop < popup.scrollTop) popup.scrollTop = item.offsetTop;
        else if (item.offsetTop + item.offsetHeight > popup.scrollTop + popup.clientHeight) {
            popup.scrollTop = item.offsetTop + item.offsetHeight - popup.clientHeight;
        }
    }
    let openingIndex = select.selectedIndex;
    function show() {
        if (select.disabled) return;
        sync();
        popup.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        position();
        if (!popup.hidden) activate(openingIndex);
    }
    function open(index = select.selectedIndex) {
        if (select.disabled) return;
        openingIndex = index;
        openHistoryLayer('condition');
    }
    function commit(index) {
        const previous = select.value;
        select.selectedIndex = index;
        sync();
        close();
        trigger.focus({preventScroll: true});
        if (select.value !== previous) {
            select.dispatchEvent(new Event('input', {bubbles: true}));
            select.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }
    trigger.addEventListener('click', () => popup.hidden ? open() : close());
    trigger.addEventListener('focus', () => select.dispatchEvent(new Event('focus')));
    trigger.addEventListener('keydown', event => {
        const isOpen = !popup.hidden;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (isOpen) activate(active + (event.key === 'ArrowDown' ? 1 : -1));
            else open();
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            const index = event.key === 'Home' ? 0 : items.length - 1;
            if (isOpen) activate(index); else open(index);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (isOpen) commit(active); else open();
        } else if (event.key === 'Escape' && isOpen) {
            event.preventDefault(); event.stopPropagation(); close();
        } else if (event.key === 'Tab') {
            close();
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            clearTimeout(searchTimer);
            search += event.key.toLocaleLowerCase('ru');
            const query = [...search].every(letter => letter === search[0]) ? search[0] : search;
            const start = isOpen ? active : select.selectedIndex;
            for (let offset = 1; offset <= items.length; offset++) {
                const index = (start + offset) % items.length;
                if (items[index].textContent.toLocaleLowerCase('ru').startsWith(query)) {
                    if (isOpen) activate(index);
                    else {
                        const typed = search;
                        commit(index);
                        search = typed;
                    }
                    break;
                }
            }
            searchTimer = setTimeout(() => { search = ''; }, 600);
        }
    });
    popup.addEventListener('mousedown', event => event.preventDefault());
    document.addEventListener('click', event => {
        if (!widget.contains(event.target) && !popup.contains(event.target)) close();
    });
    document.addEventListener('focusin', event => {
        if (!widget.contains(event.target) && !popup.contains(event.target)) close();
    });
    select.addEventListener('change', sync);
    select.form.addEventListener('reset', () => queueMicrotask(() => { sync(); close(); }));
    select.form.addEventListener('estimate-location-change', close);
    window.addEventListener('pageshow', sync);
    window.addEventListener('resize', position, {passive: true});
    window.addEventListener('scroll', position, {passive: true, capture: true});
    widget.append(trigger);
    select.after(widget);
    document.body.append(popup);
    sync();
    select.hidden = true;
    popupHistoryAdapters.set('condition', {show, hide, available: () => {
        const rect = trigger.getBoundingClientRect();
        const topEdge = trigger.closest('.modal.active') ? 8 : Math.max(8, (document.getElementById('header')?.getBoundingClientRect().bottom || 0) + 8);
        return !select.disabled && rect.bottom > topEdge && rect.top < document.documentElement.clientHeight;
    }});
})();

// Person names allow letters and common name separators, including pasted text.
function isValidPersonName(value) {
    const name = value.trim();
    return [...name].length <= 100 && /^(?=.*\p{L})[\p{L}\p{M} '\u2019\u02BC-]+$/u.test(name);
}
function normalizePersonName(input) {
    const clean = value => value.replace(/[^\p{L}\p{M} '\u2019\u02BC-]/gu, '');
    const original = input.value;
    const value = clean(original);
    if (value !== original) {
        const caret = clean(original.slice(0, input.selectionStart ?? original.length)).length;
        input.value = value;
        input.setSelectionRange(caret, caret);
    }
}
document.querySelectorAll('input[data-person-name]').forEach(input => {
    input.addEventListener('input', event => {
        if (!event.isComposing) normalizePersonName(input);
    });
    const finish = () => {
        normalizePersonName(input);
        checkFormValidity();
        checkCallbackFormValidity();
    };
    input.addEventListener('change', finish);
    input.addEventListener('compositionend', finish);
    normalizePersonName(input);
});

// Keep numeric fields bounded for typing, pasting, autofill and mobile keyboards.
function validateCarYear(input) {
    const value = input.value;
    const max = Number(input.dataset.yearMax);
    const valid = value === '' || (/^[0-9]{4}$/.test(value) && Number(value) >= 1886 && Number(value) <= max);
    input.setCustomValidity(valid ? '' : `Укажите год выпуска от 1886 до ${max}.`);
}

document.querySelectorAll('#orderForm input[data-digits-max]').forEach(input => {
    const limit = Number(input.dataset.digitsMax);
    const normalize = () => {
        const caret = input.selectionStart ?? input.value.length;
        const prefix = input.value.slice(0, caret).replace(/\D/g, '').slice(0, limit);
        const clean = input.value.replace(/\D/g, '').slice(0, limit);
        if (clean !== input.value) {
            input.value = clean;
            input.setSelectionRange(prefix.length, prefix.length);
        }
        if (input.dataset.yearMax) validateCarYear(input);
    };
    input.addEventListener('input', normalize);
    input.addEventListener('change', normalize);
    input.addEventListener('paste', event => {
        const clipboard = event.clipboardData;
        if (!clipboard) return;
        event.preventDefault();
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? start;
        const available = Math.max(0, limit - (input.value.length - (end - start)));
        const digits = clipboard.getData('text').replace(/\D/g, '').slice(0, available);
        input.setRangeText(digits, start, end, 'end');
        input.dispatchEvent(new Event('input', {bubbles: true}));
    });
    normalize();
});

document.getElementById('orderForm')?.addEventListener('reset', () => {
    document.querySelectorAll('#orderForm input[data-year-max]').forEach(input => input.setCustomValidity(''));
});

// ============================================
// ПРОВЕРКА ВАЛИДНОСТИ ФОРМ
// ============================================
function checkFormValidity() {
    const nameInput = document.getElementById('name');
    const phoneInput = document.getElementById('phone');
    const submitBtn = document.getElementById('submitBtn');
    
    if (document.getElementById('orderForm')?.dataset.submitting === 'true') {
        submitBtn.disabled = true;
        return;
    }
    const isNameValid = nameInput && isValidPersonName(nameInput.value);
    const isPhoneFilled = phoneInput && completePhoneNumber(phoneInput.value);
    if (isNameValid && isPhoneFilled) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('disabled');
    } else {
        submitBtn.disabled = true;
        submitBtn.classList.add('disabled');
    }
}

function checkCallbackFormValidity() {
    const nameInput = document.getElementById('callbackName');
    const phoneInput = document.getElementById('callbackPhone');
    const submitBtn = document.getElementById('callbackSubmitBtn');
    
    if (nameInput && phoneInput && submitBtn) {
        if (document.getElementById('callbackForm')?.dataset.submitting === 'true') {
            submitBtn.disabled = true;
            return;
        }
        const isNameValid = isValidPersonName(nameInput.value);
        const isPhoneFilled = completePhoneNumber(phoneInput.value);
        
        if (isNameValid && isPhoneFilled) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('disabled');
        } else {
            submitBtn.disabled = true;
            submitBtn.classList.add('disabled');
        }
    }
}

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const nameInput = document.getElementById('name');
    const phoneInput = document.getElementById('phone');
    const callbackName = document.getElementById('callbackName');
    const callbackPhone = document.getElementById('callbackPhone');
    
    if (nameInput) nameInput.addEventListener('input', checkFormValidity);
    if (phoneInput) phoneInput.addEventListener('input', checkFormValidity);
    if (callbackName) callbackName.addEventListener('input', checkCallbackFormValidity);
    if (callbackPhone) callbackPhone.addEventListener('input', checkCallbackFormValidity);
    
    checkFormValidity();
    checkCallbackFormValidity();
    
    // Отправляем просмотр страницы
    sendMetricaGoal('page_view', {
        page_title: document.title,
        page_url: window.location.href
    });
    
    // Обрабатываем сохраненные цели
    setTimeout(processPendingGoals, 2000);
    
    // Анимация секций при скролле
    const sections = document.querySelectorAll('.section');
    
    let sectionObserver = null;
    try {
        if (typeof IntersectionObserver === 'function') {
            sectionObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('visible');
                    sectionObserver.unobserve(entry.target);
                    sendMetricaGoal('section_view', {
                        section_id: entry.target.id,
                        section_class: entry.target.className
                    });
                });
            }, {threshold: 0.1});
            sections.forEach(section => sectionObserver.observe(section));
        }
    } catch (_) { sectionObserver?.disconnect(); }
    // Without this optional observer the existing CSS keeps every section visible.

    // Изменение хедера при скролле
    const header = document.getElementById('header');
    window.addEventListener('scroll', function() {
        if (window.scrollY > 100) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
});

// ============================================
// БУРГЕР-МЕНЮ
// ============================================
// AUXILIARY LAYERS START
const burgerMenu = document.getElementById('burgerMenu');
const navMenu = document.getElementById('navMenu');
const messengerWidget = document.getElementById('messengerWidget');
const messengerWidgetToggle = document.getElementById('messengerWidgetToggle');
const messengerWidgetLinks = document.getElementById('messengerWidgetLinks');
const messengerReducedMotion = (() => {
    try { return typeof matchMedia === 'function' ? matchMedia('(prefers-reduced-motion: reduce)') : null; }
    catch (_) { return null; }
})() || {matches: false};
let messengerMotionGeneration = 0;
let messengerCloseTimer = null;
let messengerCloseCleanup = null;
function closeMessengerWidget(restoreFocus = false) {
    if (!messengerWidgetLinks || messengerWidgetToggle?.getAttribute('aria-expanded') !== 'true') return;
    closeHistoryLayer('messengers');
    if (restoreFocus) messengerWidgetToggle.focus({preventScroll: true});
}
function setMessengerWidget(open) {
    if (!messengerWidgetLinks || !messengerWidgetToggle || !messengerWidget) return;
    const generation = ++messengerMotionGeneration;
    if (messengerCloseTimer !== null) {
        clearTimeout(messengerCloseTimer);
        messengerCloseTimer = null;
    }
    messengerCloseCleanup?.();
    messengerCloseCleanup = null;
    const wasHidden = messengerWidgetLinks.hidden;
    if (open) {
        messengerWidgetLinks.hidden = false;
        // Establish the collapsed style only when returning from display:none.
        if (wasHidden) void messengerWidgetLinks.offsetWidth;
    }
    const restoreToggleFocus = !open && messengerWidget.contains(document.activeElement);
    messengerWidgetToggle.setAttribute('aria-expanded', String(open));
    messengerWidgetToggle.setAttribute('aria-label', open ? 'Закрыть выбор мессенджера' : 'Открыть выбор мессенджера');
    messengerWidgetLinks.inert = !open;
    messengerWidgetLinks.setAttribute('aria-hidden', String(!open));
    if (restoreToggleFocus) messengerWidgetToggle.focus({preventScroll: true});
    if (open || wasHidden) return;
    const finishClose = () => {
        if (generation !== messengerMotionGeneration || messengerWidgetToggle.getAttribute('aria-expanded') === 'true') return;
        if (messengerCloseTimer !== null) clearTimeout(messengerCloseTimer);
        messengerCloseTimer = null;
        messengerCloseCleanup?.();
        messengerCloseCleanup = null;
        messengerWidgetLinks.hidden = true;
    };
    const lastLink = messengerWidgetLinks.querySelector('.header-messenger:last-child');
    const onTransitionEnd = event => {
        if (event.target === lastLink && event.propertyName === 'transform') finishClose();
    };
    const onMotionChange = () => { if (messengerReducedMotion.matches) finishClose(); };
    messengerWidgetLinks.addEventListener('transitionend', onTransitionEnd);
    try { messengerReducedMotion.addEventListener?.('change', onMotionChange); } catch (_) { /* The close timer remains available. */ }
    messengerCloseCleanup = () => {
        messengerWidgetLinks.removeEventListener('transitionend', onTransitionEnd);
        try { messengerReducedMotion.removeEventListener?.('change', onMotionChange); } catch (_) { /* No visual or focus work is deferred here. */ }
    };
    if (messengerReducedMotion.matches) finishClose();
    else messengerCloseTimer = setTimeout(finishClose, 260);
}
function setNavigationMenu(open) {
    if (!burgerMenu || !navMenu) return;
    burgerMenu.classList.toggle('active', open);
    navMenu.classList.toggle('active', open);
    burgerMenu.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open || document.querySelector('.modal.active') ? 'hidden' : '';
    if (open) sendMetricaGoal('menu_open');
    else if (navMenu.contains(document.activeElement)) burgerMenu.focus({preventScroll: true});
}
popupHistoryAdapters.set('menu', {show: () => setNavigationMenu(true), hide: () => setNavigationMenu(false), available: () => !!burgerMenu && !!navMenu && window.innerWidth <= 1280});
popupHistoryAdapters.set('messengers', {show: () => setMessengerWidget(true), hide: () => setMessengerWidget(false), available: () => !!messengerWidgetLinks});
if (messengerWidget && messengerWidgetToggle && messengerWidgetLinks) {
    messengerWidgetToggle.addEventListener('click', () => {
        const shouldOpen = messengerWidgetToggle.getAttribute('aria-expanded') !== 'true';
        if (navMenu?.classList.contains('active')) closeHistoryLayer('menu');
        if (shouldOpen) openHistoryLayer('messengers');
        else closeMessengerWidget(true);
    });
    document.addEventListener('click', event => {
        if (!messengerWidget.contains(event.target)) closeMessengerWidget();
    });
    messengerWidget.addEventListener('focusout', event => {
        if (event.relatedTarget && !messengerWidget.contains(event.relatedTarget)) closeMessengerWidget();
    });
}
const pageHeader = document.getElementById('header');
const cookieNoticeElement = document.getElementById('cookieNotice');
let fixedChromeMeasureFrame = 0;
function updateFixedChromeMetrics() {
    fixedChromeMeasureFrame = 0;
    if (pageHeader) document.documentElement.style.setProperty('--header-height', pageHeader.offsetHeight + 'px');
    if (cookieNoticeElement) document.documentElement.style.setProperty('--cookie-notice-height', cookieNoticeElement.offsetHeight + 'px');
}
function requestFixedChromeMetrics() {
    if (!fixedChromeMeasureFrame) fixedChromeMeasureFrame = requestAnimationFrame(updateFixedChromeMetrics);
}
let fixedChromeObserver = null;
try {
    if (typeof ResizeObserver === 'function') {
        fixedChromeObserver = new ResizeObserver(requestFixedChromeMetrics);
        [pageHeader, cookieNoticeElement].filter(Boolean).forEach(element => fixedChromeObserver.observe(element, {box: 'border-box'}));
    }
} catch (_) {
    fixedChromeObserver?.disconnect();
    fixedChromeObserver = null;
}
updateFixedChromeMetrics();
if (!fixedChromeObserver) {
    window.addEventListener('scroll', requestFixedChromeMetrics, {passive: true});
    window.addEventListener('resize', requestFixedChromeMetrics, {passive: true});
    window.addEventListener('load', requestFixedChromeMetrics, {once: true});
}

if (burgerMenu && navMenu) {
    burgerMenu.addEventListener('click', function() {
        closeMessengerWidget();
        if (navMenu.classList.contains('active')) closeHistoryLayer('menu');
        else openHistoryLayer('menu');
    });
    
    document.querySelectorAll('.nav-link, .header-messenger').forEach(link => {
        link.addEventListener('click', () => {
            closeMessengerWidget();
            closeHistoryLayer('menu');
        });
    });
}
// AUXILIARY LAYERS END

// Carry campaign attribution through the site's own page links, keeping canonical URLs clean.
const campaignParameters = new URLSearchParams(window.location.search);
const campaignKeys = [...new Set(campaignParameters.keys())].filter(key => /^utm_[a-z0-9_]{1,40}$/i.test(key) || key === 'yclid');
const landingPaths = new Set(['/', '/index.php', '/avtovykup-v-spb/', ...<?= json_encode(array_map(static fn($slug) => '/' . $slug . '/', array_merge($serviceSlugs, $campaignSlugs)), JSON_UNESCAPED_SLASHES) ?>]);
document.querySelectorAll('a[href^="/"]').forEach(link => {
    const url = new URL(link.getAttribute('href'), window.location.origin);
    if (url.origin !== window.location.origin || !landingPaths.has(url.pathname)) return;
    campaignKeys.forEach(key => {
        const value = campaignParameters.get(key);
        if (value !== null && value.length <= 2048) url.searchParams.set(key, value);
    });
    link.href = url.pathname + url.search + url.hash;
});

// ============================================
// Keep the menu from locking the desktop after device rotation.
window.addEventListener('resize', () => {
    if (window.innerWidth > 1280 && navMenu?.classList.contains('active')) {
        burgerMenu.click();
    }
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const activeLayer = getTopHistoryLayer();
    if (activeLayer) { event.preventDefault(); closeHistoryLayer(activeLayer); return; }
    if (messengerWidgetToggle?.getAttribute('aria-expanded') === 'true') { closeMessengerWidget(true); return; }
    if (navMenu?.classList.contains('active')) burgerMenu.click();
});
// A key survives an uncertain response. Rotate only after acceptance, never on
// a network error, so a normal retry can return the existing saved lead.
function ensureLeadSubmissionId(form, rotate = false) {
    let input = form.querySelector('input[name="submission_id"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'submission_id';
        form.appendChild(input);
    }
    if (rotate || !/^[a-f0-9]{32}$/.test(input.value)) {
        try {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            input.value = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            input.defaultValue = input.value;
        } catch (_) { /* Existing server-rendered keys remain usable. */ }
    }
    return input.value;
}
const acknowledgedLeadGoals = new Set();
function firstLeadAcknowledgement(result, form) {
    const key = result.order_id ? 'order:' + result.order_id : form.id + ':' + ensureLeadSubmissionId(form);
    if (acknowledgedLeadGoals.has(key)) return false;
    acknowledgedLeadGoals.add(key);
    return true;
}
// ОТПРАВКА ФОРМЫ (ОСНОВНАЯ)
// ============================================
document.getElementById('orderForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    if (this.dataset.submitting === 'true' || !this.reportValidity()) return;
    const phone = completePhoneNumber(this.elements.phone.value);
    if (!phone) return;
    this.dataset.submitting = 'true';
    
    ensureLeadSubmissionId(this);
    const formData = new FormData(this);
    formData.set('phone', phone);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Отправляем цель начала заполнения формы
    sendMetricaGoal('form_start', {
        form_type: 'main_order',
        has_photos: selectedFiles.length > 0
    });
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
    submitBtn.disabled = true;
    
    let acceptedResponse = false;
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success === true && result.accepted !== false) {
            acceptedResponse = true;
            // A completed request consumes the automatic callback for this visit.
            rememberCallbackShown();
            if (firstLeadAcknowledgement(result, this)) {
            // ✅ ОТПРАВКА ЦЕЛИ В МЕТРИКУ (ВАШ ИДЕНТИФИКАТОР 3331212)
            sendMetricaGoal('3331212', {
                form_type: 'main_order',
                car_brand: document.querySelector('input[name="car_brand"]')?.value || 'не указано',
                desired_price: document.querySelector('input[name="desired_price"]')?.value || 'не указано',
                photos_count: selectedFiles.length
            });
            
            // Также отправляем дополнительную цель для отслеживания
            sendMetricaGoal('form_success', {
                order_id: result.order_id || 'unknown'
            });
            }
            
            this.reset();
            ensureLeadSubmissionId(this, true);
            selectedFiles = [];
            renderAllThumbnails();
            photoCounter.style.display = 'none';
            
            closeModal('estimate');
            openModal('success');
        } else {
            alert(result.message || 'Ошибка отправки');
            // Отправляем цель ошибки
            sendMetricaGoal('form_error', {
                error_message: result.message
            });
        }
    } catch (error) {
        if (acceptedResponse) {
            alert('Заявка принята');
            return;
        }
        console.error('Не удалось получить подтверждение заявки');
        alert('Не удалось получить подтверждение от сайта. Заявка могла сохраниться; данные формы оставлены.');
        sendMetricaGoal('form_error', {
            error_message: 'confirmation_unavailable'
        });
    } finally {
        delete this.dataset.submitting;
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        checkFormValidity();
    }
});

// ============================================
// ОТПРАВКА ФОРМЫ ЗВОНКА (ОТДЕЛЬНО)
// ============================================
document.getElementById('callbackForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    if (this.dataset.submitting === 'true' || !this.reportValidity()) return;
    const phone = completePhoneNumber(this.elements.phone.value);
    if (!phone) return;
    this.dataset.submitting = 'true';
    
    ensureLeadSubmissionId(this);
    const formData = new FormData(this);
    formData.set('phone', phone);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Отправляем цель начала заполнения формы звонка
    sendMetricaGoal('callback_start');
    
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
    submitBtn.disabled = true;
    
    let acceptedResponse = false;
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success === true && result.accepted !== false) {
            acceptedResponse = true;
            rememberCallbackShown();
            if (firstLeadAcknowledgement(result, this)) {
            // ✅ ОТПРАВКА ЦЕЛИ ЗВОНКА (тоже используем 3331212 или отдельную цель)
            sendMetricaGoal('3331212', {
                form_type: 'callback', order_id: result.order_id || 'unknown'
            });
            
            // Отдельная цель для звонков
            sendMetricaGoal('callback_success', {order_id: result.order_id || 'unknown'});
            }
            
            closeModal('callback');
            openModal('success');
            this.reset();
            ensureLeadSubmissionId(this, true);
            checkCallbackFormValidity();
        } else {
            alert(result.message || 'Ошибка отправки');
            sendMetricaGoal('callback_error', {
                error_message: result.message
            });
        }
    } catch (error) {
        if (acceptedResponse) {
            alert('Заявка принята');
            return;
        }
        console.error('Не удалось получить подтверждение заявки');
        alert('Не удалось получить подтверждение от сайта. Заявка могла сохраниться; данные формы оставлены.');
        sendMetricaGoal('callback_error', {
            error_message: 'confirmation_unavailable'
        });
    } finally {
        delete this.dataset.submitting;
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        checkCallbackFormValidity();
    }
});

// ============================================
// ОТСЛЕЖИВАНИЕ КЛИКОВ ПО КНОПКАМ
// ============================================
document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
    btn.addEventListener('click', function(e) {
        const buttonText = this.innerText.trim();
        const buttonClass = this.className;
        
        sendMetricaGoal('button_click', {
            button_text: buttonText.substring(0, 50),
            button_class: buttonClass,
            button_href: this.href || 'none'
        });
    });
});

// ============================================
// ОТСЛЕЖИВАНИЕ КЛИКОВ ПО КАРТОЧКАМ КЕЙСОВ
// ============================================
document.querySelectorAll('.case-card').forEach((card, index) => {
    card.addEventListener('click', function() {
        const title = this.querySelector('.case-title')?.innerText || 'unknown';
        const price = this.querySelector('.case-price')?.innerText || '0';
        
        sendMetricaGoal('case_click', {
            case_title: title,
            case_price: price,
            case_index: index
        });
    });
});

// ============================================
// ОТСЛЕЖИВАНИЕ КЛИКОВ ПО ТЕЛЕФОНУ
// ============================================
document.querySelectorAll('a[href^="tel:"]').forEach(phoneLink => {
    phoneLink.addEventListener('click', function() {
        sendMetricaGoal('phone_click', {
            phone_number: this.href.replace('tel:', '')
        });
    });
});

// ============================================
// ОТСЛЕЖИВАНИЕ КЛИКОВ ПО МЕССЕНДЖЕРАМ
// ============================================
document.querySelectorAll('a[href*="wa.me"], a[href*="t.me"], a[href*="max.ru"]').forEach(socialLink => {
    socialLink.addEventListener('click', function() {
        const network = this.href.includes('wa.me') ? 'whatsapp'
            : this.href.includes('max.ru') ? 'max' : 'telegram';
        sendMetricaGoal('social_click', {
            network,
            url: this.href
        });
    });
});

// ============================================
// FOOTER LINK GROUPS
(() => {
    const groups = document.querySelectorAll('.footer-link-group');
    const mobile = matchMedia('(max-width: 768px)');
    function syncFooterGroups() {
        groups.forEach(group => {
            const summary = group.querySelector('summary');
            if (mobile.matches && group.contains(document.activeElement) && document.activeElement !== summary) {
                summary.focus({preventScroll: true});
            }
            group.open = !mobile.matches;
            summary.tabIndex = mobile.matches ? 0 : -1;
        });
    }
    groups.forEach(group => group.querySelector('summary').addEventListener('click', event => {
        if (!mobile.matches) event.preventDefault();
    }));
    syncFooterGroups();
    mobile.addEventListener('change', syncFooterGroups);
})();

// FAQ АККОРДЕОН
// ============================================
document.querySelectorAll('.faq-question').forEach(question => {
    question.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); question.click(); }
    });
    question.addEventListener('click', function() {
        const item = this.closest('.faq-item');
        const wasActive = item.classList.contains('active');
        item.classList.toggle('active');
        this.setAttribute('aria-expanded', String(item.classList.contains('active')));
        
        // Отправляем событие открытия FAQ
        if (!wasActive) {
            const questionText = this.querySelector('h3')?.innerText || 'unknown';
            sendMetricaGoal('faq_open', {
                question: questionText.substring(0, 50)
            });
        }
    });
});

// ============================================
// SEO ТЕКСТ
// ============================================
function toggleSeoText() {
    const seoContent = document.getElementById('seoContent');
    const seoToggle = document.getElementById('seoToggle');
    const toggleSpan = seoToggle.querySelector('span');
    const toggleIcon = seoToggle.querySelector('i');
    
    seoContent.classList.toggle('expanded');
    seoToggle.classList.toggle('active');
    seoToggle.setAttribute('aria-expanded', String(seoContent.classList.contains('expanded')));
    
    // Отправляем событие
    sendMetricaGoal('seo_toggle', {
        action: seoContent.classList.contains('expanded') ? 'expand' : 'collapse'
    });
    
    if (seoContent.classList.contains('expanded')) {
        toggleSpan.textContent = 'Свернуть';
        toggleIcon.classList.remove('fa-chevron-down');
        toggleIcon.classList.add('fa-chevron-up');
    } else {
        toggleSpan.textContent = 'Читать далее';
        toggleIcon.classList.remove('fa-chevron-up');
        toggleIcon.classList.add('fa-chevron-down');
    }
}

// ============================================
// МОДАЛКИ
// ============================================
document.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const activeType = getTopModalType();
    const modal = activeType ? document.getElementById(activeType + 'Modal') : null;
    if (!modal) return;
    const items = [...modal.querySelectorAll('button:not([disabled]), a[href], input:not([type=hidden]):not([disabled]), select, textarea, [tabindex="0"]')].filter(el => el.offsetParent !== null);
    const first = items[0], last = items[items.length - 1];
    if (!first) return;
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
});
const modalReturnFocus = new WeakMap();
// Informational notice: acknowledgement is shared by all pages of this site.
// It does not change the existing analytics integrations or form consent.
// COOKIE HISTORY START
(() => {
    const notice = document.getElementById('cookieNotice');
    const accept = document.getElementById('cookieNoticeAccept');
    const infoLink = document.getElementById('cookieInfoLink');
    const storageKey = 'avtokai_cookie_notice_v1';
    const lifetime = 365 * 24 * 60 * 60 * 1000;
    function isAcknowledged() {
        for (const storageName of ['localStorage', 'sessionStorage']) {
            try {
                const value = JSON.parse(window[storageName].getItem(storageKey) || 'null');
                if (value?.version === 1 && Number.isFinite(value.expiresAt) && value.expiresAt > Date.now()
                    && value.expiresAt <= Date.now() + lifetime) return true;
            } catch (_) { /* Storage may be disabled; the notice still works on this page. */ }
        }
        return false;
    }
    function show() {
        notice.hidden = false;
        document.body.classList.add('cookie-notice-open');
        requestFixedChromeMetrics();
    }
    function hide() {
        notice.hidden = true;
        document.body.classList.remove('cookie-notice-open');
        requestFixedChromeMetrics();
    }
    accept.addEventListener('click', () => {
        const value = JSON.stringify({version: 1, expiresAt: Date.now() + lifetime});
        for (const storageName of ['localStorage', 'sessionStorage']) {
            try { window[storageName].setItem(storageKey, value); } catch (_) {}
        }
        const hadFocus = notice.contains(document.activeElement);
        closeHistoryLayer('cookie');
        if (hadFocus) document.querySelector('#header .logo-wrapper')?.focus({preventScroll: true});
    });
    infoLink.addEventListener('click', () => { openHistoryLayer('cookie'); accept.focus({preventScroll: true}); });
    window.addEventListener('storage', event => {
        if (event.key === storageKey && isAcknowledged() && !notice.contains(document.activeElement)) closeHistoryLayer('cookie');
    });
    popupHistoryAdapters.set('cookie', {show, hide, available: () => !!notice});
    if (!isAcknowledged()) queueMicrotask(() => {
        syncModalHistory();
        if (!modalOpenTypes.length) openHistoryLayer('cookie');
    });
})();
// COOKIE HISTORY END
const estimateForm = document.getElementById('form');
const estimateFormHost = document.getElementById('estimateFormHost');
const estimateFormPlaceholder = document.createElement('div');
estimateFormPlaceholder.setAttribute('aria-hidden', 'true');
<?php if ($isPhoneHero): ?>
// PHONE HERO PORTAL START
// One real form: a noninteractive full preview at home, normal controls in the dialog.
const phoneHero = document.querySelector('.hero--sell');
const phoneHeroScene = document.getElementById('heroMobileScene');
const phoneHeroBackground = phoneHero.querySelector('.hero-background');
const phoneHeroTrigger = document.getElementById('phonePreviewTrigger');
<?php if ($isPortraitHero): ?>
const phoneHeroReturnTrigger = phoneHero.querySelector('.hero-buttons > .btn-secondary');
<?php endif; ?>
const phoneHeroModal = document.getElementById('estimateModal');
const phoneHeroShell = phoneHeroModal.querySelector('.estimate-dialog');
const phoneHeroMedia = matchMedia('(max-width: <?= $phoneHeroMobileMaxWidth ?>px)');
const phoneHeroReducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
const phoneHeroBackgroundSlot = document.createComment('phone-hero-background-desktop');
const estimateDesktopSlot = document.createComment('estimate-form-desktop');
const estimateCurrentHome = document.createComment('estimate-form-current-home');
phoneHeroBackground.before(phoneHeroBackgroundSlot);
estimateForm.before(estimateDesktopSlot, estimateCurrentHome);
estimateFormPlaceholder.className = 'estimate-form-placeholder';
let phoneZoomAnimation = null;
let phoneZoomGeneration = 0;
let phoneZoomClosing = false;
let phoneZoomOrigin = null;
let phoneZoomOpeningStart = null;

function syncPhoneHeroAccess() {
    const preview = phoneHeroMedia.matches && estimateForm.parentElement === phoneHeroScene;
    estimateForm.inert = preview;
    if (preview) estimateForm.setAttribute('aria-hidden', 'true');
    else estimateForm.removeAttribute('aria-hidden');
    phoneHeroTrigger.tabIndex = phoneHeroMedia.matches ? 0 : -1;
}
function syncPhoneHeroHome() {
    estimateForm.querySelector('form').dispatchEvent(new Event('estimate-location-change'));
    if (phoneHeroMedia.matches) {
        phoneHeroScene.prepend(phoneHeroBackground);
        phoneHeroScene.append(estimateCurrentHome);
    } else {
        phoneHeroBackgroundSlot.after(phoneHeroBackground);
        estimateDesktopSlot.after(estimateCurrentHome);
    }
    // Breakpoint changes move the home marker, never a form owned by the dialog.
    estimateCurrentHome.after(estimateForm.parentElement === estimateFormHost ? estimateFormPlaceholder : estimateForm);
    syncPhoneHeroAccess();
}
function capturePhoneZoomTransform() {
    if (!phoneZoomAnimation || phoneZoomAnimation.playState === 'idle' || phoneZoomAnimation.playState === 'finished'
        || !phoneHeroShell.getClientRects().length) return null;
    const transform = window.getComputedStyle(phoneHeroShell).transform;
    return transform && transform !== 'none' ? transform : null;
}
function stopPhoneZoomAnimation() {
    phoneZoomGeneration += 1;
    if (phoneZoomAnimation) {
        phoneZoomAnimation.onfinish = null;
        phoneZoomAnimation.cancel();
        phoneZoomAnimation = null;
    }
}
function phoneOriginRect() {
    if (!phoneHeroMedia.matches) return null;
    const rect = phoneHeroTrigger.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth
        && rect.bottom > 0 && rect.top < window.innerHeight ? rect : null;
}
function phoneZoomTransform(origin) {
    const target = phoneHeroShell.getBoundingClientRect();
    if (!origin || !target.width || !target.height) return null;
    return 'translate(' + (origin.left - target.left) + 'px,' + (origin.top - target.top) + 'px) scale('
        + (origin.width / target.width) + ',' + (origin.height / target.height) + ')';
}
function focusPhoneEstimateReturn() {
    queueMicrotask(() => {
        // A newly opened policy, success notice or reopened form owns focus.
        if (phoneZoomClosing || phoneHeroModal.classList.contains('active') || document.querySelector('.modal.active, .nav-menu.active')) return;
        let target = modalReturnFocus.get(phoneHeroModal);
        if (!target || !target.getClientRects().length || (estimateForm.contains(target) && estimateForm.inert)) {
            target = phoneHeroMedia.matches ? <?= $isPortraitHero ? 'phoneHeroReturnTrigger' : 'phoneHeroTrigger' ?> : estimateForm.querySelector('[name="name"]');
        }
        target?.focus({preventScroll: true});
    });
}
function finishPhoneEstimateClose(generation) {
    if (generation !== phoneZoomGeneration || !phoneZoomClosing || phoneHeroModal.classList.contains('active')) return;
    stopPhoneZoomAnimation();
    phoneZoomClosing = false;
    phoneHeroModal.classList.remove('phone-zoom-closing');
    if (estimateForm.parentElement === estimateFormHost) {
        if (estimateFormPlaceholder.parentElement) estimateFormPlaceholder.replaceWith(estimateForm);
        else estimateCurrentHome.after(estimateForm);
    }
    syncPhoneHeroHome();
    document.body.style.overflow = document.querySelector('.modal.active, .nav-menu.active') ? 'hidden' : '';
    focusPhoneEstimateReturn();
}
function preparePhoneEstimate(modal) {
    // Reopening continues from the visible frame before cancelling the old exit.
    phoneZoomOpeningStart = capturePhoneZoomTransform();
    stopPhoneZoomAnimation();
    phoneZoomClosing = false;
    modal.classList.remove('phone-zoom-closing');
    modal.inert = false;
    modal.removeAttribute('aria-hidden');
    phoneZoomOrigin = phoneOriginRect();
    if (estimateForm.parentElement !== estimateFormHost) {
        estimateFormPlaceholder.style.height = estimateForm.offsetHeight + 'px';
        estimateForm.before(estimateFormPlaceholder);
        estimateFormHost.append(estimateForm);
    }
    syncPhoneHeroAccess();
}
function animatePhoneEstimateOpen() {
    const transform = phoneZoomOpeningStart || phoneZoomTransform(phoneZoomOrigin);
    phoneZoomOpeningStart = null;
    if (!transform || phoneHeroReducedMotion.matches || typeof phoneHeroShell.animate !== 'function') return;
    const generation = phoneZoomGeneration;
    try {
        phoneZoomAnimation = phoneHeroShell.animate([{transform}, {transform: 'none'}], {
            duration: 320, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'both'
        });
    } catch (_) { phoneZoomAnimation = null; return; }
    phoneZoomAnimation.finished?.catch(() => {});
    phoneZoomAnimation.onfinish = () => {
        if (generation === phoneZoomGeneration) stopPhoneZoomAnimation();
    };
}
function beginPhoneEstimateClose(modal) {
    const startTransform = capturePhoneZoomTransform() || 'none';
    stopPhoneZoomAnimation();
    phoneZoomClosing = true;
    const generation = phoneZoomGeneration;
    modal.inert = true;
    modal.setAttribute('aria-hidden', 'true');
    const origin = phoneOriginRect();
    const transform = phoneZoomTransform(origin);
    if (!transform || phoneHeroReducedMotion.matches || typeof phoneHeroShell.animate !== 'function') {
        // Finish after hideModal has removed the logical active class.
        queueMicrotask(() => finishPhoneEstimateClose(generation));
        return;
    }
    modal.classList.add('phone-zoom-closing');
    try {
        phoneZoomAnimation = phoneHeroShell.animate([{transform: startTransform}, {transform}], {
            duration: 280, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'both'
        });
    } catch (_) {
        phoneZoomAnimation = null;
        queueMicrotask(() => finishPhoneEstimateClose(generation));
        return;
    }
    phoneZoomAnimation.finished?.catch(() => {});
    phoneZoomAnimation.onfinish = () => finishPhoneEstimateClose(generation);
}
function settlePhoneZoomForViewport() {
    if (phoneZoomClosing) finishPhoneEstimateClose(phoneZoomGeneration);
    else stopPhoneZoomAnimation();
    syncPhoneHeroHome();
}
phoneHeroMedia.addEventListener('change', settlePhoneZoomForViewport);
phoneHeroReducedMotion.addEventListener('change', () => {
    if (phoneHeroReducedMotion.matches) settlePhoneZoomForViewport();
});
window.addEventListener('resize', settlePhoneZoomForViewport, {passive: true});
window.visualViewport?.addEventListener('resize', settlePhoneZoomForViewport, {passive: true});
window.addEventListener('scroll', () => {
    if (phoneZoomClosing) finishPhoneEstimateClose(phoneZoomGeneration);
}, {passive: true});
syncPhoneHeroHome();
// PHONE HERO PORTAL END
<?php endif; ?>
// MODAL HISTORY START
// A same-URL entry lets browser/Android Back dismiss the top dialog first.
// Keep unrelated history state and let normal navigation work once dialogs close.
const modalHistoryKey = '__avtokaiModalV1';
const modalHistoryPage = window.location.pathname + window.location.search;
const modalHistoryDialogs = new Set(['estimate', 'callback', 'success', 'policy', 'agreement']);
const modalHistoryTypes = new Set([...modalHistoryDialogs, 'menu', 'messengers', 'condition', 'cookie']);
function modalHistoryState() {
    try { return window.history?.state; } catch (_) { return null; }
}
function readModalHistory(state) {
    const entry = state && typeof state === 'object' ? state[modalHistoryKey] : null;
    return entry?.version === 1 && entry.page === modalHistoryPage && typeof entry.token === 'string'
        && Array.isArray(entry.stack) && entry.stack.length > 0
        && entry.stack.every(type => modalHistoryTypes.has(type))
        && new Set(entry.stack).size === entry.stack.length ? entry : null;
}
const initialModalHistory = readModalHistory(modalHistoryState());
const modalHistoryToken = initialModalHistory?.token || Date.now().toString(36) + Math.random().toString(36).slice(2);
let modalOpenTypes = [];
let modalHistoryPending = false;
let modalHistoryEnabled = true;
let modalHistorySyncing = false;
let modalCloseWatcher = null;
const modalHistoryActions = [];

function getTopModalType() {
    return modalOpenTypes.slice().reverse().find(type => modalHistoryDialogs.has(type));
}
function getTopHistoryLayer() {
    return modalOpenTypes[modalOpenTypes.length - 1];
}

function showModal(type) {
    const adapter = popupHistoryAdapters.get(type);
    if (adapter) { if (adapter.available()) adapter.show(); return; }
    const modal = document.getElementById(type + 'Modal');
    if (modal && !modal.classList.contains('active')) {
        modalReturnFocus.set(modal, document.activeElement);
        if (type === 'estimate') {
            estimateForm.querySelector('form').dispatchEvent(new Event('estimate-location-change'));
<?php if ($isPhoneHero): ?>
            preparePhoneEstimate(modal);
<?php else: ?>
            estimateFormPlaceholder.style.height = estimateForm.offsetHeight + 'px';
            estimateForm.before(estimateFormPlaceholder);
            estimateFormHost.append(estimateForm);
<?php endif; ?>
            estimateFormHost.scrollTop = 0;
            document.body.classList.add('estimate-open');
        }
        modal.classList.add('active');
        if (type === 'callback') rememberCallbackShown();
        const initialFocus = modal.querySelector('[data-modal-initial-focus]') || modal.querySelector('input:not([type=hidden]):not([tabindex="-1"]), button, a[href]');
        initialFocus?.focus({preventScroll: true});
        document.body.style.overflow = 'hidden';
<?php if ($isPhoneHero): ?>
        if (type === 'estimate') animatePhoneEstimateOpen();
<?php endif; ?>
        
        // Отправляем событие открытия модалки
        sendMetricaGoal('modal_open', {
            modal_type: type
        });
    }
}

function hideModal(type) {
    const adapter = popupHistoryAdapters.get(type);
    if (adapter) { adapter.hide(); return; }
    const modal = document.getElementById(type + 'Modal');
    if (modal && modal.classList.contains('active')) {
        if (type === 'estimate') {
            estimateForm.querySelector('form').dispatchEvent(new Event('estimate-location-change'));
<?php if ($isPhoneHero): ?>
            beginPhoneEstimateClose(modal);
<?php else: ?>
            estimateFormPlaceholder.replaceWith(estimateForm);
<?php endif; ?>
            document.body.classList.remove('estimate-open');
        }
        modal.classList.remove('active');
        modal.style.zIndex = '';
<?php if ($isPhoneHero): ?>
        document.body.style.overflow = phoneZoomClosing || document.querySelector('.modal.active, .nav-menu.active') ? 'hidden' : '';
        if (type !== 'estimate') {
            const returnFocus = modalReturnFocus.get(modal);
            if (returnFocus && (!returnFocus.getClientRects().length || (estimateForm.contains(returnFocus) && estimateForm.inert))) {
                <?= $isPortraitHero ? 'phoneHeroReturnTrigger' : 'phoneHeroTrigger' ?>.focus({preventScroll: true});
            } else returnFocus?.focus({preventScroll: true});
        }
<?php else: ?>
        document.body.style.overflow = document.querySelector('.modal.active, .nav-menu.active') ? 'hidden' : '';
        modalReturnFocus.get(modal)?.focus({preventScroll: true});
<?php endif; ?>
        
        // Отправляем событие закрытия модалки
        sendMetricaGoal('modal_close', {
            modal_type: type
        });
    }
}

function syncModalStack(stack) {
    modalHistorySyncing = true;
    let shared = 0;
    while (shared < modalOpenTypes.length && shared < stack.length && modalOpenTypes[shared] === stack[shared]) shared += 1;
    for (let index = modalOpenTypes.length - 1; index >= shared; index -= 1) hideModal(modalOpenTypes[index]);
    for (let index = shared; index < stack.length; index += 1) showModal(stack[index]);
    // Legal dialogs and asynchronous success notices follow opening order,
    // including above the estimate form's detached dropdown (z-index 2100).
    stack.filter(type => modalHistoryDialogs.has(type)).forEach((type, index) => { document.getElementById(type + 'Modal').style.zIndex = String(2000 + index * 200); });
    modalOpenTypes = stack.slice();
    modalHistorySyncing = false;
    updateModalCloseWatcher();
    queueMicrotask(requestLeadFormVisibility);
}

function openModal(type) {
    if (!modalHistoryDialogs.has(type)) return;
    closeMessengerWidget();
    closeHistoryLayer('menu');
    closeHistoryLayer('condition');
    openHistoryLayer(type);
}
function closeModal(type) {
    closeHistoryLayer(type);
}

function openHistoryLayer(type) {
    const available = modalHistoryDialogs.has(type) ? !!document.getElementById(type + 'Modal') : popupHistoryAdapters.get(type)?.available();
    if (!modalHistoryTypes.has(type) || !available || modalHistorySyncing) return;
    if (modalHistoryPending) { modalHistoryActions.push({open: type}); return; }
    if (modalOpenTypes.includes(type)) return;
    if (type !== 'cookie' && modalOpenTypes.includes('cookie')) {
        // The banner becomes invisible under other overlays. Dismiss it without
        // acknowledgement so Back never encounters a hidden banner entry.
        closeHistoryLayer('cookie');
        if (modalHistoryPending) { modalHistoryActions.push({open: type}); return; }
    }
    if (type === 'cookie' && modalOpenTypes.length) return;
    const stack = [...modalOpenTypes, type];
    const state = modalHistoryState();
    const current = readModalHistory(state);
    const previousState = current?.token === modalHistoryToken ? current.previousState : state;
    const nextState = state && typeof state === 'object' && !Array.isArray(state) ? {...state} : {};
    nextState[modalHistoryKey] = {version: 1, page: modalHistoryPage, token: modalHistoryToken, stack, previousState};
    if (modalHistoryEnabled) {
        try {
            if (typeof window.history?.pushState !== 'function') throw new Error('History unavailable');
            window.history.pushState(nextState, '', window.location.href);
        } catch (_) {
            // Never claim multiple entries after a transient first push failure.
            modalHistoryEnabled = false;
        }
    }
    syncModalStack(stack);
}

function closeHistoryLayer(type) {
    if (modalHistorySyncing) return;
    if (modalHistoryPending) { modalHistoryActions.push({close: type}); return; }
    const index = modalOpenTypes.indexOf(type);
    if (index === -1) return;
    const current = readModalHistory(modalHistoryState());
    const ownsEntry = current?.token === modalHistoryToken
        && current.stack.length <= modalOpenTypes.length
        && current.stack.every((item, position) => item === modalOpenTypes[position]);
    const removed = ownsEntry ? Math.max(0, current.stack.length - index) : 0;
    syncModalStack(modalOpenTypes.slice(0, index));
    if (removed > 0) {
        // Traversal is asynchronous. A form's close→success transition must wait
        // before pushing its next entry, otherwise Back could remove that dialog.
        modalHistoryPending = true;
        updateModalCloseWatcher();
        try { window.history.go(-removed); }
        catch (_) {
            modalHistoryPending = false;
            // A restricted browser must not leave future opens waiting forever.
            try {
                const state = modalOpenTypes.length ? {...modalHistoryState(), [modalHistoryKey]: {...current, stack: modalOpenTypes.slice()}} : current.previousState;
                window.history.replaceState(state, '', window.location.href);
            } catch (_) {}
            updateModalCloseWatcher();
        }
    }
}

function syncModalHistory() {
    const current = readModalHistory(modalHistoryState());
    modalHistoryPending = false;
    syncModalStack(current?.token === modalHistoryToken ? current.stack : []);
    // A restored mobile menu or offscreen select may no longer be usable after
    // resizing/scrolling. Consume its own entry without displaying a dead layer.
    const unavailable = modalOpenTypes.find(type => {
        const adapter = popupHistoryAdapters.get(type);
        return adapter && !adapter.available();
    });
    if (unavailable) closeHistoryLayer(unavailable);
    while (!modalHistoryPending && modalHistoryActions.length) {
        const action = modalHistoryActions.shift();
        if (action.open) openHistoryLayer(action.open);
        else if (action.close) closeHistoryLayer(action.close);
        else action.after();
    }
}

function afterPopupHistory(callback) {
    // Native scroll restoration happens after popstate dispatch. Run section
    // navigation in the next task so dismissing the menu cannot undo the scroll.
    const after = () => setTimeout(callback, 0);
    if (modalHistoryPending) modalHistoryActions.push({after});
    else after();
}

function updateModalCloseWatcher() {
    if (!modalOpenTypes.length || modalHistoryPending) {
        modalCloseWatcher?.destroy();
        modalCloseWatcher = null;
        return;
    }
    if (modalCloseWatcher || typeof window.CloseWatcher !== 'function') return;
    try {
        // Native Android Back works even for automatically shown overlays,
        // whose unactivated pushState entries Chromium may skip.
        modalCloseWatcher = new window.CloseWatcher();
        modalCloseWatcher.addEventListener('close', () => {
            modalCloseWatcher = null;
            const top = getTopHistoryLayer();
            if (top) closeHistoryLayer(top);
        });
    } catch (_) { /* History remains the fallback on browsers without CloseWatcher. */ }
}
window.addEventListener('popstate', syncModalHistory);
// A reload on a modal entry and a restored page retain the same Back behavior.
window.addEventListener('pageshow', syncModalHistory);
// MODAL HISTORY END

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        const modalId = e.target.id;
        const modalType = modalId.replace('Modal', '');
        closeModal(modalType);
        
        sendMetricaGoal('modal_close', {
            modal_type: modalType,
            method: 'outside_click'
        });
    }
});

// ============================================
// ПЛАВНАЯ ПРОКРУТКА ДЛЯ ЯКОРЕЙ
// ============================================
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        
        const targetId = this.getAttribute('href');
        if (targetId === '#') return;
<?php if ($isPortraitHero): ?>
        if (targetId === '#form' && phoneHeroMedia.matches) { openModal('estimate'); return; }
<?php endif; ?>
        
        const targetElement = document.querySelector(targetId);
        if (targetElement) {
            const headerHeight = document.getElementById('header').offsetHeight;
            const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - headerHeight - 12;
            
            afterPopupHistory(() => window.scrollTo({top: targetPosition, behavior: 'smooth'}));
            
            // Отправляем событие навигации
            sendMetricaGoal('navigation_click', {
                target: targetId,
                link_text: this.innerText.substring(0, 30)
            });
        }
    });
});

// ============================================
// AUTO CALLBACK: EXIT INTENT OR 70% SCROLL
// ============================================
// Floating controls and the cookie notice must not cover the mobile estimate form.
const visibleLeadForms = new Set();
let leadFormObserver = null;
let leadFormVisibilityFrame = 0;
function syncLeadFormVisibility() {
    leadFormVisibilityFrame = 0;
    const height = document.documentElement.clientHeight || window.innerHeight;
    const width = document.documentElement.clientWidth || window.innerWidth;
    visibleLeadForms.clear();
    document.querySelectorAll('#form').forEach(form => {
        const rect = form.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < height && rect.right > 0 && rect.left < width) visibleLeadForms.add(form);
    });
    document.body.classList.toggle('form-in-view', visibleLeadForms.size > 0);
}
function requestLeadFormVisibility() {
    if (!leadFormObserver && !leadFormVisibilityFrame) leadFormVisibilityFrame = requestAnimationFrame(syncLeadFormVisibility);
}
try {
    if (typeof IntersectionObserver === 'function') {
        leadFormObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => entry.isIntersecting ? visibleLeadForms.add(entry.target) : visibleLeadForms.delete(entry.target));
            document.body.classList.toggle('form-in-view', visibleLeadForms.size > 0);
        }, {threshold: 0});
        document.querySelectorAll('#form').forEach(form => leadFormObserver.observe(form));
    }
} catch (_) {
    leadFormObserver?.disconnect();
    leadFormObserver = null;
}
if (!leadFormObserver) {
    syncLeadFormVisibility();
    window.addEventListener('scroll', requestLeadFormVisibility, {passive: true});
    window.addEventListener('resize', requestLeadFormVisibility, {passive: true});
    window.addEventListener('load', requestLeadFormVisibility, {once: true});
    window.addEventListener('pageshow', requestLeadFormVisibility);
    window.visualViewport?.addEventListener('resize', requestLeadFormVisibility, {passive: true});
}
// A session cookie shares one visit across pages, reloads and tabs.
const AUTO_CALLBACK_VISIT_COOKIE = 'avtokai_callback_visit_v1';
const autoCallbackPageStartedAt = Date.now();
function readAutoCallbackVisit() {
    try {
        const prefix = AUTO_CALLBACK_VISIT_COOKIE + '=';
        const entry = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith(prefix));
        if (!entry) return null;
        const visit = JSON.parse(decodeURIComponent(entry.slice(prefix.length)));
        if (!Number.isFinite(visit?.startedAt) || visit.startedAt <= 0 || visit.startedAt > Date.now()
            || typeof visit.shown !== 'boolean') return null;
        return {startedAt: visit.startedAt, shown: visit.shown};
    } catch (_) { return null; }
}
function saveAutoCallbackVisit(visit) {
    try {
        document.cookie = AUTO_CALLBACK_VISIT_COOKIE + '=' + encodeURIComponent(JSON.stringify(visit))
            + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        const saved = readAutoCallbackVisit();
        return saved?.startedAt === visit.startedAt && saved.shown === visit.shown;
    } catch (_) { return false; }
}
const autoCallbackVisit = readAutoCallbackVisit();
let autoCallbackShown = autoCallbackVisit?.shown === true;
let pendingExitIntent = null;
const autoCallbackVisitReady = (async () => {
    if (autoCallbackVisit || !navigator.locks?.request) return;
    try {
        await navigator.locks.request('avtokai-auto-callback-v1', {ifAvailable: true}, lock => {
            // Another tab may already have created or consumed this visit while we waited.
            if (!lock || readAutoCallbackVisit()) return;
            saveAutoCallbackVisit({startedAt: autoCallbackPageStartedAt, shown: autoCallbackShown});
        });
    } catch (_) { /* Without shared storage, only explicit manual openings are available. */ }
})();

function cancelPendingExitIntent() {
    if (pendingExitIntent !== null) {
        clearTimeout(pendingExitIntent);
        pendingExitIntent = null;
    }
}

function rememberCallbackShown() {
    autoCallbackShown = true;
    cancelPendingExitIntent();
    const visit = readAutoCallbackVisit() || {startedAt: autoCallbackPageStartedAt, shown: false};
    return saveAutoCallbackVisit({...visit, shown: true});
}

function autoCallbackBlocked() {
    return document.visibilityState !== 'visible'
        || document.querySelector('.modal.active, .nav-menu.active, #messengerWidgetToggle[aria-expanded="true"], form[data-submitting="true"]')
        || document.activeElement?.closest('form');
}

async function showAutoCallback(trigger) {
    await autoCallbackVisitReady;
    const modal = document.getElementById('callbackModal');
    if (autoCallbackShown || autoCallbackBlocked() || !modal || !navigator.locks?.request) return;
    try {
        // Claim the shared visit before opening, including across simultaneous tabs.
        await navigator.locks.request('avtokai-auto-callback-v1', {ifAvailable: true}, lock => {
            if (!lock || autoCallbackShown || autoCallbackBlocked()) return;
            const visit = readAutoCallbackVisit();
            if (!visit || visit.shown || (trigger === 'exit' && Date.now() - visit.startedAt < 5000)) return;
            if (!rememberCallbackShown()) return;
            openModal('callback');
            if (modal.classList.contains('active') && trigger === 'exit') sendMetricaGoal('exit_intent');
        });
    } catch (_) { /* Manual buttons still work when shared visit storage is unavailable. */ }
}

document.addEventListener('mouseleave', function(e) {
    const visit = readAutoCallbackVisit();
    if (e.clientY > 0 || !visit || visit.shown || Date.now() - visit.startedAt < 5000
        || autoCallbackShown || pendingExitIntent !== null
        || !matchMedia('(hover: hover)').matches || autoCallbackBlocked()) return;
    pendingExitIntent = setTimeout(() => {
        pendingExitIntent = null;
        showAutoCallback('exit');
    }, 500);
});
document.addEventListener('mouseenter', cancelPendingExitIntent);
document.addEventListener('mousemove', cancelPendingExitIntent, {passive: true});
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') cancelPendingExitIntent();
});
window.addEventListener('pagehide', cancelPendingExitIntent);

// ============================================
// ОТСЛЕЖИВАНИЕ ВРЕМЕНИ НА САЙТЕ
// ============================================
let timeOnSite = 0;
const timeOnSiteInterval = setInterval(() => {
    timeOnSite += 1;
    if (timeOnSite === 30) { // 30 секунд
        sendMetricaGoal('time_30s');
    } else if (timeOnSite === 60) { // 1 минута
        sendMetricaGoal('time_1m');
    } else if (timeOnSite === 120) { // 2 минуты
        sendMetricaGoal('time_2m');
    } else if (timeOnSite === 300) { // 5 минут
        sendMetricaGoal('time_5m');
    }
    if (timeOnSite >= 300) clearInterval(timeOnSiteInterval);
}, 1000);

// ============================================
// ОТСЛЕЖИВАНИЕ ГЛУБИНЫ ПРОКРУТКИ
// ============================================
let scrollDepth = {
    '25%': false,
    '50%': false,
    '75%': false,
    '100%': false
};

window.addEventListener('scroll', function() {
    const windowHeight = window.innerHeight;
    const documentHeight = document.documentElement.scrollHeight - windowHeight;
    const scrollTop = window.scrollY;
    const scrollPercentage = (scrollTop / documentHeight) * 100;

    if (documentHeight > 0 && scrollPercentage >= 70) showAutoCallback('scroll');
    
    if (scrollPercentage >= 25 && !scrollDepth['25%']) {
        scrollDepth['25%'] = true;
        sendMetricaGoal('scroll_25');
    }
    if (scrollPercentage >= 50 && !scrollDepth['50%']) {
        scrollDepth['50%'] = true;
        sendMetricaGoal('scroll_50');
    }
    if (scrollPercentage >= 75 && !scrollDepth['75%']) {
        scrollDepth['75%'] = true;
        sendMetricaGoal('scroll_75');
    }
    if (scrollPercentage >= 99 && !scrollDepth['100%']) {
        scrollDepth['100%'] = true;
        sendMetricaGoal('scroll_100');
    }
});

// ============================================
// ОТСЛЕЖИВАНИЕ ФОКУСА НА ПОЛЯХ ФОРМЫ
// ============================================
document.querySelectorAll('#orderForm input, #orderForm select').forEach(field => {
    field.addEventListener('focus', function() {
        const fieldName = this.name || this.id || 'unknown';
        sendMetricaGoal('form_field_focus', {
            field_name: fieldName
        });
    });
});

// ============================================
// ПРОВЕРКА РАБОТОСПОСОБНОСТИ МЕТРИКИ
// ============================================
setTimeout(() => {
    if (typeof ym !== 'undefined') {
        console.log('✅ Яндекс.Метрика загружена и работает');
        console.log('📊 ID счетчика: 106799647');
        console.log('🎯 ID цели для форм: 3331212');
    } else {
        console.error('❌ Яндекс.Метрика НЕ загружена! Проверьте подключение');
    }
}, 3000);
</script>

<noscript>
    <img src="https://mc.yandex.ru/watch/106799647" style="position:absolute; left:-9999px;" alt="">
</noscript>




<script>
const backToTop = document.getElementById('backToTop');
if (backToTop) {
    const topLogo = document.querySelector('#header .logo-wrapper');
    function updateBackToTop() {
        const hide = window.scrollY <= 600;
        if (hide && document.activeElement === backToTop) topLogo?.focus({preventScroll: true});
        if (backToTop.hidden !== hide) backToTop.hidden = hide;
    }
    backToTop.addEventListener('click', () => {
        if (window.location.hash) {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        }
        topLogo?.focus({preventScroll: true});
        window.scrollTo({top: 0, left: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    });
    window.addEventListener('scroll', updateBackToTop, {passive: true});
    window.addEventListener('pageshow', updateBackToTop);
    updateBackToTop();
}

// VIDEO CAROUSEL START
const videoTrack = document.getElementById('vsliderTrack');
const videoPrevious = document.querySelector('.vslider-arrow.left');
const videoNext = document.querySelector('.vslider-arrow.right');
const videoControls = document.querySelector('#video-reviews .vslider-controls');
const videoSlides = videoTrack ? [...videoTrack.querySelectorAll('.vslide')] : [];
const reviewVideos = videoSlides.map(slide => slide.querySelector('video'));
const reviewVideoStates = new Map();
const videoMobile = matchMedia('(max-width: 600px)');
const videoReducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let activeVideoIndex = 0;
let centeredVideoLayout = null;
let videoLoopTimer;
let videoTouching = false;
let videoNavigation = null;
let videoNavigationTimer;
let videoFirstEdge = null;
let videoLastEdge = null;
function videoPosition(card, centered) {
    return card.offsetLeft + (centered ? (card.offsetWidth - videoTrack.clientWidth) / 2 : 0);
}
function videoMetrics() {
    const cards = videoSlides;
    const step = cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : videoTrack.clientWidth;
    const centered = videoMobile.matches;
    const start = videoPosition(cards[0], centered);
    const visible = centered ? 1 : Math.max(1, Math.round((videoTrack.clientWidth + 20) / step));
    return {step, start, centered, visible, count: cards.length, first: Math.round((videoTrack.scrollLeft - start) / step)};
}
function updateVideoControls() {
    const fits = videoTrack.scrollWidth <= videoTrack.clientWidth + 2;
    if (fits && videoControls.contains(document.activeElement)) videoTrack.focus({preventScroll: true});
    videoControls.hidden = fits;
    videoPrevious.disabled = !videoMobile.matches && activeVideoIndex <= 0;
    videoNext.disabled = !videoMobile.matches && activeVideoIndex >= videoSlides.length - 1;
}
function cancelVideoNavigation() {
    clearTimeout(videoNavigationTimer);
    videoNavigation = null;
}
function finishVideoNavigation() {
    const navigation = videoNavigation;
    if (!navigation || Math.abs(videoTrack.scrollLeft - navigation.left) > 2) return false;
    if (navigation.loop) videoTrack.scrollTo({left: navigation.realLeft, behavior: 'instant'});
    cancelVideoNavigation();
    checkReviewVisibility();
    updateVideoControls();
    return true;
}
function focusReviewVideo(video, direction = 0) {
    cancelVideoNavigation();
    clearTimeout(videoLoopTimer);
    const index = reviewVideos.indexOf(video);
    const centered = videoMobile.matches;
    const realLeft = videoPosition(videoSlides[index], centered);
    let card = videoSlides[index];
    if (centered && !videoReducedMotion.matches) {
        if (direction > 0 && activeVideoIndex === videoSlides.length - 1 && index === 0) card = videoLastEdge;
        else if (direction < 0 && activeVideoIndex === 0 && index === videoSlides.length - 1) card = videoFirstEdge;
    }
    card ||= videoSlides[index];
    const left = Math.max(0, Math.min(videoPosition(card, centered), videoTrack.scrollWidth - videoTrack.clientWidth));
    const animated = !videoReducedMotion.matches && Math.abs(videoTrack.scrollLeft - left) > 2;
    activeVideoIndex = index;
    if (animated) {
        const navigation = {video, left, realLeft, loop: card !== videoSlides[index]};
        videoNavigation = navigation;
        videoNavigationTimer = setTimeout(() => {
            if (videoNavigation !== navigation || finishVideoNavigation()) return;
            cancelVideoNavigation();
            checkReviewVisibility();
        }, 1200);
    }
    videoTrack.scrollTo({left, behavior: animated ? 'smooth' : 'instant'});
}
function slideVideos(direction) {
    if (!videoTrack || !reviewVideos.length) return;
    const count = reviewVideos.length;
    const next = activeVideoIndex + direction;
    const target = videoMobile.matches ? ((next % count) + count) % count : Math.min(Math.max(0, next), count - 1);
    // Keep play() in the original gesture while the selected real card moves into view.
    startReviewVideo(reviewVideos[target], direction);
    updateVideoControls();
}
function reviewVideoVisible(video) {
    if (document.hidden) return false;
    if (document.fullscreenElement?.contains(video) || video.webkitDisplayingFullscreen) return true;
    const bounds = video.getBoundingClientRect();
    const trackBounds = videoTrack.getBoundingClientRect();
    if (bounds.width <= 0 || bounds.height <= 0 || trackBounds.width <= 0) return false;
    const height = window.innerHeight || document.documentElement.clientHeight;
    const width = window.innerWidth || document.documentElement.clientWidth;
    const headerBottom = document.getElementById('header')?.getBoundingClientRect().bottom || 0;
    const visibleHeight = Math.max(0, Math.min(bounds.bottom, height) - Math.max(bounds.top, Math.min(headerBottom, height), 0));
    const visibleWidth = Math.max(0, Math.min(bounds.right, trackBounds.right, width) - Math.max(bounds.left, trackBounds.left, 0));
    return visibleHeight >= Math.min(bounds.height, height) * .25
        && (visibleWidth >= Math.min(bounds.width, trackBounds.width) * .5 || videoNavigation?.video === video);
}
function updateReviewVideo(video) {
    const state = reviewVideoStates.get(video);
    if (!state) return;
    // The invisible picture target stays available; native seek/volume/fullscreen keep their lower strip.
    state.start.hidden = false;
    state.start.style.bottom = video.controls ? '64px' : '0';
    state.start.setAttribute('aria-label', `${state.wanted ? 'Приостановить' : 'Смотреть'} видеоотзыв клиента ${reviewVideos.indexOf(video) + 1}`);
    if (state.starting) state.start.setAttribute('aria-busy', 'true');
    else state.start.removeAttribute('aria-busy');
}
function pauseReviewVideo(video) {
    const state = reviewVideoStates.get(video);
    if (!state) return;
    if (videoNavigation?.video === video) cancelVideoNavigation();
    state.wanted = false;
    state.starting = false;
    state.generation++;
    video.pause();
    updateReviewVideo(video);
}
function pauseReviewVideos(except = null) {
    reviewVideos.forEach(video => { if (video !== except) pauseReviewVideo(video); });
}
function checkReviewVisibility() {
    reviewVideos.forEach(video => {
        const state = reviewVideoStates.get(video);
        if (state && (state.wanted || !video.paused) && !reviewVideoVisible(video)) pauseReviewVideo(video);
    });
}
function showReviewVideoError(video) {
    const state = reviewVideoStates.get(video);
    if (!state) return;
    pauseReviewVideo(video);
    video.controls = false;
    video.tabIndex = -1;
    state.feedback.textContent = 'Не удалось запустить видео. Попробуйте ещё раз.';
    state.feedback.hidden = false;
    updateReviewVideo(video);
    if (document.activeElement === video) state.start.focus({preventScroll: true});
}
function startReviewVideo(video, direction = 0) {
    const state = reviewVideoStates.get(video);
    if (!state || document.hidden) return;
    pauseReviewVideos(video);
    state.wanted = true;
    state.starting = true;
    const generation = ++state.generation;
    state.feedback.hidden = true;
    focusReviewVideo(video, direction);
    updateReviewVideo(video);
    try {
        if (video.error) video.load();
        const request = video.play();
        request?.then(() => {
            if (state.generation !== generation) return;
            state.starting = false;
            if (!state.wanted || !reviewVideoVisible(video)) pauseReviewVideo(video);
            else updateReviewVideo(video);
        }).catch(error => {
            if (state.generation !== generation || !state.wanted) return;
            if (error.name === 'AbortError') pauseReviewVideo(video);
            else showReviewVideoError(video);
        });
    } catch (error) {
        if (state.generation === generation) showReviewVideoError(video);
    }
}
function settleVideoLoop() {
    clearTimeout(videoLoopTimer);
    if (videoTouching) return;
    if (videoNavigation) { finishVideoNavigation(); return; }
    const m = videoMetrics();
    if (!m.centered || (m.first >= 0 && m.first < m.count)) return;
    activeVideoIndex = m.first < 0 ? m.count - 1 : 0;
    videoTrack.scrollTo({left: videoPosition(videoSlides[activeVideoIndex], true), behavior: 'instant'});
}
function layoutVideoCarousel() {
    cancelVideoNavigation();
    clearTimeout(videoLoopTimer);
    const m = videoMetrics();
    centeredVideoLayout = m.centered;
    const target = m.centered ? activeVideoIndex : Math.min(activeVideoIndex, Math.max(0, m.count - m.visible));
    videoTrack.scrollTo({left: videoPosition(videoSlides[target], m.centered), behavior: 'instant'});
    updateVideoControls();
}
if (videoTrack && videoPrevious && videoNext && videoControls) {
function videoEdgePoster(slide) {
    const edge = document.createElement('div');
    edge.className = 'vslide video-loop-edge';
    edge.setAttribute('aria-hidden', 'true');
    const poster = document.createElement('img');
    poster.src = slide.querySelector('video').poster;
    poster.alt = '';
    poster.width = 288;
    poster.height = 512;
    poster.loading = 'lazy';
    poster.decoding = 'async';
    edge.append(poster);
    return edge;
}
videoFirstEdge = videoEdgePoster(videoSlides[videoSlides.length - 1]);
videoLastEdge = videoEdgePoster(videoSlides[0]);
videoTrack.prepend(videoFirstEdge);
videoTrack.append(videoLastEdge);
videoTrack.addEventListener('scroll', () => {
    const m = videoMetrics();
    if (m.centered !== centeredVideoLayout) return;
    if (videoNavigation) finishVideoNavigation();
    if (!videoNavigation) {
        if (m.centered) activeVideoIndex = ((m.first % m.count) + m.count) % m.count;
        else if (videoTrack.scrollWidth > videoTrack.clientWidth + 2 && !reviewVideoStates.get(reviewVideos[activeVideoIndex])?.wanted) activeVideoIndex = Math.min(Math.max(0, m.first), m.count - 1);
    }
    checkReviewVisibility();
    updateVideoControls();
    clearTimeout(videoLoopTimer);
    if (m.centered) videoLoopTimer = setTimeout(settleVideoLoop, 160);
}, {passive: true});
videoTrack.addEventListener('scrollend', settleVideoLoop);
videoTrack.addEventListener('touchstart', () => {
    videoTouching = true;
    cancelVideoNavigation();
    checkReviewVisibility();
    clearTimeout(videoLoopTimer);
}, {passive: true});
function finishVideoTouch(event) {
    videoTouching = event.touches.length > 0;
    if (!videoTouching) videoLoopTimer = setTimeout(settleVideoLoop, 160);
}
videoTrack.addEventListener('touchend', finishVideoTouch, {passive: true});
videoTrack.addEventListener('touchcancel', finishVideoTouch, {passive: true});
videoTrack.addEventListener('keydown', event => {
    if (event.target !== videoTrack) return;
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
        event.preventDefault(); slideVideos(event.key === 'ArrowRight' ? 1 : -1);
    }
});
let videoLayoutObserver = null;
try {
    if (typeof ResizeObserver === 'function') {
        videoLayoutObserver = new ResizeObserver(layoutVideoCarousel);
        videoLayoutObserver.observe(videoTrack);
    }
} catch (_) {
    videoLayoutObserver?.disconnect();
    videoLayoutObserver = null;
}
if (!videoLayoutObserver) window.addEventListener('resize', layoutVideoCarousel, {passive: true});
videoMobile.addEventListener('change', layoutVideoCarousel);
layoutVideoCarousel();
reviewVideos.forEach(video => {
    const slide = video.closest('.vslide');
    const feedback = slide.querySelector('.video-feedback');
    const start = slide.querySelector('.video-start');
    const state = {start, feedback, wanted: false, starting: false, generation: 0};
    reviewVideoStates.set(video, state);
    start.setAttribute('role', 'button');
    function toggleVideo() {
        if (state.wanted || !video.paused) pauseReviewVideo(video);
        else startReviewVideo(video);
    }
    start.addEventListener('click', event => {
        event.preventDefault();
        toggleVideo();
    });
    start.addEventListener('keydown', event => {
        if (event.key === ' ') {
            event.preventDefault();
            if (!event.repeat) toggleVideo();
        }
    });
    video.addEventListener('play', () => {
        if (video.paused) return; // A queued play event may arrive after an intentional pause.
        if (!reviewVideoVisible(video)) { pauseReviewVideo(video); return; }
        pauseReviewVideos(video);
        state.wanted = true;
        activeVideoIndex = reviewVideos.indexOf(video);
        if (videoMobile.matches && videoNavigation?.video !== video) focusReviewVideo(video);
        video.controls = true;
        video.tabIndex = 0;
        updateReviewVideo(video);
        feedback.hidden = true;
    });
    video.addEventListener('playing', () => {
        if (!state.wanted || !reviewVideoVisible(video)) { pauseReviewVideo(video); return; }
        state.starting = false;
        updateReviewVideo(video);
    });
    video.addEventListener('pause', () => {
        // Ignore an obsolete queued pause if the user has already resumed this video.
        if (!video.paused) return;
        state.wanted = false;
        state.starting = false;
        state.generation++;
        updateReviewVideo(video);
    });
    video.addEventListener('ended', () => {
        pauseReviewVideo(video);
        video.controls = false;
        video.tabIndex = -1;
        updateReviewVideo(video);
        if (document.activeElement === video) start.focus({preventScroll: true});
    });
    video.addEventListener('error', () => showReviewVideoError(video));
    updateReviewVideo(video);
});
let videoVisibilityFrame = 0;
function scheduleReviewVisibility() {
    if (videoVisibilityFrame) return;
    videoVisibilityFrame = requestAnimationFrame(() => {
        videoVisibilityFrame = 0;
        checkReviewVisibility();
    });
}
window.addEventListener('scroll', scheduleReviewVisibility, {passive: true});
window.addEventListener('resize', scheduleReviewVisibility, {passive: true});
window.addEventListener('pagehide', () => pauseReviewVideos());
document.addEventListener('visibilitychange', () => { if (document.hidden) pauseReviewVideos(); });
let reviewVisibilityObserver = null;
try {
    if (typeof IntersectionObserver === 'function') {
        reviewVisibilityObserver = new IntersectionObserver(checkReviewVisibility, {threshold: [0, .25, .5]});
        reviewVideos.forEach(video => reviewVisibilityObserver.observe(video));
    }
} catch (_) { reviewVisibilityObserver?.disconnect(); }
}
// VIDEO CAROUSEL END
</script>
<!-- Callibri -->
<script src="https://cdn.callibri.ru/callibri.js" type="text/javascript" charset="utf-8" defer></script>
<!-- /Callibri -->
</body>
</html>
<?php
// КОНЕЦ index.php
?>
