<?php
// Private deployment values belong in the server environment, never in Git.
function avtokaiEnvironment(string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : (string) $value;
}

define('DB_HOST', avtokaiEnvironment('AVTOKAI_DB_HOST', 'localhost'));
define('DB_NAME', avtokaiEnvironment('AVTOKAI_DB_NAME'));
define('DB_USER', avtokaiEnvironment('AVTOKAI_DB_USER'));
define('DB_PASS', avtokaiEnvironment('AVTOKAI_DB_PASS'));
define('TELEGRAM_BOT_TOKEN', avtokaiEnvironment('AVTOKAI_TELEGRAM_BOT_TOKEN'));
define('TELEGRAM_CHAT_ID', avtokaiEnvironment('AVTOKAI_TELEGRAM_CHAT_ID'));

// Refuse to connect with empty or anonymous database credentials.
if (DB_NAME === '' || DB_USER === '' || DB_PASS === '') {
    http_response_code(503);
    $message = 'Сервис временно недоступен. Позвоните нам или попробуйте позже.';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'accepted' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}
