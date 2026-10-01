<?php
// security.php - Session hardening and CSRF protection helpers

// Start the session with hardened cookie settings
function secure_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Get (or create) the CSRF token for the current session
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hidden input to embed inside every POST form
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

// Query-string fragment for state-changing GET links (e.g. delete actions)
function csrf_query() {
    return 'csrf_token=' . urlencode(csrf_token());
}

// Abort the request when the submitted token does not match the session token
function csrf_verify() {
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        die('คำขอไม่ถูกต้องหรือหมดอายุ (CSRF token ไม่ตรงกัน) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }
}
