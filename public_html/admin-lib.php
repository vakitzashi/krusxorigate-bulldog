<?php

function admin_headers()
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

function admin_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_random_token()
{
    if (function_exists('random_bytes')) return bin2hex(random_bytes(32));
    $bytes = openssl_random_pseudo_bytes(32, $strong);
    if ($bytes === false || !$strong) throw new RuntimeException('Secure random generator is unavailable.');
    return bin2hex($bytes);
}

function admin_config($config)
{
    if (empty($config['admin']['username']) || empty($config['admin']['password_hash'])) {
        throw new RuntimeException('Admin access is not configured.');
    }
    return $config['admin'];
}

function admin_cookie_name()
{
    return 'ORIGATE_ADMIN_SESSION';
}

function admin_set_cookie($value, $expires)
{
    $cookie = rawurlencode(admin_cookie_name()) . '=' . rawurlencode($value)
        . '; Path=/; Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . max(0, $expires - time())
        . '; Secure; HttpOnly; SameSite=Strict';
    header('Set-Cookie: ' . $cookie, false);
}

function admin_clear_cookie()
{
    admin_set_cookie('', time() - 3600);
}

function admin_client_hash($config)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return hash_hmac('sha256', $ip . '|' . $agent, $config['admin']['password_hash']);
}

function admin_origin_valid($config)
{
    $source = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
    if ($source === '') return true;
    $host = strtolower((string) parse_url($source, PHP_URL_HOST));
    $allowed = isset($config['allowed_hosts']) && is_array($config['allowed_hosts']) ? array_map('strtolower', $config['allowed_hosts']) : array();
    return $host !== '' && in_array($host, $allowed, true);
}

function admin_attempts_blocked($db, $clientHash)
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $statement = $db->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE client_hash = :hash AND success = 0 AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)');
    } else {
        $statement = $db->prepare("SELECT COUNT(*) FROM admin_login_attempts WHERE client_hash = :hash AND success = 0 AND created_at >= datetime('now', '-15 minutes')");
    }
    $statement->execute(array(':hash' => $clientHash));
    return (int) $statement->fetchColumn() >= 8;
}

function admin_record_attempt($db, $clientHash, $success)
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $statement = $db->prepare('INSERT INTO admin_login_attempts (client_hash, success) VALUES (:hash, :success)');
    } else {
        $statement = $db->prepare('INSERT INTO admin_login_attempts (client_hash, success, created_at) VALUES (:hash, :success, CURRENT_TIMESTAMP)');
    }
    $statement->execute(array(':hash' => $clientHash, ':success' => $success ? 1 : 0));
    $db->exec($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'DELETE FROM admin_login_attempts WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)'
        : "DELETE FROM admin_login_attempts WHERE created_at < datetime('now', '-2 days')");
}

function admin_login($db, $config, $username, $password)
{
    $admin = admin_config($config);
    $clientHash = admin_client_hash($config);
    if (admin_attempts_blocked($db, $clientHash)) return array(false, 'Слишком много попыток. Повторите вход через 15 минут.');
    $valid = hash_equals((string) $admin['username'], (string) $username) && password_verify((string) $password, $admin['password_hash']);
    admin_record_attempt($db, $clientHash, $valid);
    if (!$valid) return array(false, 'Неверный логин или пароль.');

    $token = admin_random_token();
    $ttl = isset($admin['session_ttl']) ? max(900, min(86400, (int) $admin['session_ttl'])) : 28800;
    $expires = time() + $ttl;
    $statement = $db->prepare('INSERT INTO admin_sessions (session_hash, username, expires_at) VALUES (:hash, :username, :expires)');
    $statement->execute(array(':hash' => hash('sha256', $token), ':username' => $admin['username'], ':expires' => date('Y-m-d H:i:s', $expires)));
    admin_set_cookie($token, $expires);
    return array(true, '');
}

function admin_session($db)
{
    $db->exec('DELETE FROM admin_sessions WHERE expires_at <= CURRENT_TIMESTAMP');
    $name = admin_cookie_name();
    if (empty($_COOKIE[$name]) || !preg_match('/^[a-f0-9]{64}$/', $_COOKIE[$name])) return null;
    $token = $_COOKIE[$name];
    $hash = hash('sha256', $token);
    $statement = $db->prepare('SELECT username, expires_at FROM admin_sessions WHERE session_hash = :hash AND expires_at > CURRENT_TIMESTAMP LIMIT 1');
    $statement->execute(array(':hash' => $hash));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        admin_clear_cookie();
        return null;
    }
    $update = $db->prepare('UPDATE admin_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE session_hash = :hash');
    $update->execute(array(':hash' => $hash));
    return array('username' => $row['username'], 'token' => $token, 'hash' => $hash);
}

function admin_logout($db, $session)
{
    if ($session) {
        $statement = $db->prepare('DELETE FROM admin_sessions WHERE session_hash = :hash');
        $statement->execute(array(':hash' => $session['hash']));
    }
    admin_clear_cookie();
}

function admin_csrf($session, $config)
{
    return hash_hmac('sha256', 'admin-csrf|' . $session['token'], $config['admin']['password_hash']);
}

function admin_csrf_valid($session, $config, $token)
{
    return is_string($token) && hash_equals(admin_csrf($session, $config), $token);
}

function admin_redirect($notice)
{
    header('Location: admin.php?notice=' . rawurlencode($notice), true, 303);
    exit;
}
