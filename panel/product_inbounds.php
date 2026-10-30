<?php
// Admin-only JSON endpoint: live inbound list of one x-ui_single panel, selected by id on the server.
// Returns id/remark/protocol/port/enable only; panel credentials and client data never leave the server.
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
session_start();
require_once __DIR__ . '/../config.php';
if (!@include_once __DIR__ . '/lib/ad_boot.php') { http_response_code(503); exit; }
require_once __DIR__ . '/../function.php';
if (!function_exists('xpi_fetch_panel_inbounds') && is_file(__DIR__ . '/../x-ui_single.php')) {
    require_once __DIR__ . '/../x-ui_single.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$adminRow = false;
if (isset($_SESSION['user'])) {
    $q = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
    $q->bindParam("username", $_SESSION["user"], PDO::PARAM_STR);
    $q->execute();
    $adminRow = $q->fetch(PDO::FETCH_ASSOC);
}
if (!$adminRow) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'unauthorized']);
    exit;
}

$panelId = (int) ($_GET['panel_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :id");
$stmt->execute([':id' => $panelId]);
$panel = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$panel || ($panel['type'] ?? '') !== 'x-ui_single') {
    echo json_encode(['ok' => false, 'message' => 'این پنل از نوع ثنایی (3x-ui) نیست.']);
    exit;
}

$messages = [
    'unsupported_panel_type' => 'نوع پنل پشتیبانی نمی‌شود.',
    'login_failed' => 'ورود به پنل ناموفق بود؛ نام کاربری/رمز پنل را بررسی کنید.',
    'panel_unreachable' => 'پنل در دسترس نیست.',
    'panel_bad_response' => 'پاسخ پنل نامعتبر بود.',
];
$result = xpi_fetch_panel_inbounds($panel);
if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => $messages[$result['msg']] ?? 'خطا در دریافت اینباندها']);
    exit;
}
$list = array_values($result['inbounds']);
usort($list, function ($a, $b) { return $a['id'] <=> $b['id']; });
echo json_encode(['ok' => true, 'token_mode' => xui_panel_uses_token($panel), 'inbounds' => $list], JSON_UNESCAPED_UNICODE);
