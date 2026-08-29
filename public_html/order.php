<?php

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Moscow');

function json_response($status, $payload)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_response($status, $message, $errors)
{
    $payload = array('ok' => false, 'message' => $message);
    if (!empty($errors)) {
        $payload['errors'] = $errors;
    }
    json_response($status, $payload);
}

function text_value($input, $key, $maxLength)
{
    if (!isset($input[$key]) || is_array($input[$key]) || is_object($input[$key])) {
        return '';
    }
    $value = trim((string) $input[$key]);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return $value;
}

function assert_same_origin($allowedHosts)
{
    $source = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $source = $_SERVER['HTTP_ORIGIN'];
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
        $source = $_SERVER['HTTP_REFERER'];
    }
    if ($source === '') {
        return;
    }
    $host = strtolower((string) parse_url($source, PHP_URL_HOST));
    if ($host === '' || !in_array($host, $allowedHosts, true)) {
        fail_response(403, 'Источник запроса не разрешён.', array());
    }
}

function require_config($configPath)
{
    if (!is_file($configPath)) {
        throw new RuntimeException('Order configuration is missing.');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('Order configuration is invalid.');
    }
    $required = array(
        array('product', 'name'),
        array('product', 'sku'),
        array('database', 'host'),
        array('database', 'name'),
        array('database', 'user'),
        array('database', 'password'),
        array('google', 'webhook_url'),
        array('google', 'webhook_secret'),
        array('telegram', 'bot_token'),
        array('telegram', 'chat_id')
    );
    foreach ($required as $path) {
        if (empty($config[$path[0]][$path[1]])) {
            throw new RuntimeException('Order integration is not configured: ' . implode('.', $path));
        }
    }
    return $config;
}

function open_database($target)
{
    if (is_array($target)) {
        $port = empty($target['port']) ? 3306 : (int) $target['port'];
        $dsn = 'mysql:host=' . $target['host'] . ';port=' . $port . ';dbname=' . $target['name'] . ';charset=utf8mb4';
        $db = new PDO($dsn, $target['user'], $target['password'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        ));
        $db->exec("SET time_zone = '+03:00'");
        $db->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number VARCHAR(20) NULL,
                idempotency_key VARCHAR(100) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                created_msk VARCHAR(32) NOT NULL,
                status VARCHAR(64) NOT NULL,
                customer_name VARCHAR(190) NOT NULL,
                phone VARCHAR(32) NOT NULL,
                email VARCHAR(190) NOT NULL,
                city VARCHAR(190) NOT NULL,
                address VARCHAR(500) NOT NULL,
                delivery_type VARCHAR(32) NOT NULL,
                comment TEXT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                subtotal INT UNSIGNED NOT NULL,
                discount_percent DECIMAL(7,2) NOT NULL,
                discount_amount INT UNSIGNED NOT NULL,
                total INT UNSIGNED NOT NULL,
                promo_code VARCHAR(64) NOT NULL,
                cdek_track VARCHAR(128) NOT NULL,
                sheet_status VARCHAR(20) NOT NULL,
                telegram_status VARCHAR(20) NOT NULL,
                last_error TEXT NOT NULL,
                payload_json JSON NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_orders_order_number (order_number),
                UNIQUE KEY uq_orders_idempotency_key (idempotency_key),
                KEY idx_orders_created_at (created_at),
                KEY idx_orders_status (status),
                KEY idx_orders_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return $db;
    }

    $db = new PDO('sqlite:' . $target);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_number TEXT UNIQUE,
            idempotency_key TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            created_msk TEXT NOT NULL,
            status TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            phone TEXT NOT NULL,
            email TEXT NOT NULL,
            city TEXT NOT NULL,
            address TEXT NOT NULL,
            delivery_type TEXT NOT NULL,
            comment TEXT NOT NULL,
            product_name TEXT NOT NULL,
            sku TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            subtotal INTEGER NOT NULL,
            discount_percent REAL NOT NULL,
            discount_amount INTEGER NOT NULL,
            total INTEGER NOT NULL,
            promo_code TEXT NOT NULL,
            cdek_track TEXT NOT NULL,
            sheet_status TEXT NOT NULL,
            telegram_status TEXT NOT NULL,
            last_error TEXT NOT NULL,
            payload_json TEXT NOT NULL
        )'
    );
    if (is_file($target)) {
        @chmod($target, 0600);
    }
    return $db;
}

function find_order($db, $key)
{
    $statement = $db->prepare('SELECT * FROM orders WHERE idempotency_key = :key LIMIT 1');
    $statement->execute(array(':key' => $key));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function insert_order($db, $order)
{
    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO orders (
                order_number, idempotency_key, created_at, created_msk, status,
                customer_name, phone, email, city, address, delivery_type, comment,
                product_name, sku, quantity, subtotal, discount_percent, discount_amount,
                total, promo_code, cdek_track, sheet_status, telegram_status, last_error, payload_json
            ) VALUES (
                NULL, :idempotency_key, :created_at, :created_msk, :status,
                :customer_name, :phone, :email, :city, :address, :delivery_type, :comment,
                :product_name, :sku, :quantity, :subtotal, :discount_percent, :discount_amount,
                :total, :promo_code, :cdek_track, :sheet_status, :telegram_status, :last_error, :payload_json
            )'
        );
        $params = array();
        foreach ($order as $key => $value) {
            if ($key !== 'order_number') {
                $params[':' . $key] = $value;
            }
        }
        $statement->execute($params);
        $id = (int) $db->lastInsertId();
        $number = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $update = $db->prepare('UPDATE orders SET order_number = :number WHERE id = :id');
        $update->execute(array(':number' => $number, ':id' => $id));
        $db->commit();
        return find_order($db, $order['idempotency_key']);
    } catch (Exception $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function update_delivery_state($db, $id, $column, $state, $error)
{
    if (!in_array($column, array('sheet_status', 'telegram_status'), true)) {
        throw new InvalidArgumentException('Invalid delivery state column.');
    }
    if ($error === '') {
        $statement = $db->prepare('UPDATE orders SET ' . $column . ' = :state WHERE id = :id');
        $statement->execute(array(':state' => $state, ':id' => $id));
    } else {
        $statement = $db->prepare('UPDATE orders SET ' . $column . ' = :state, last_error = :error WHERE id = :id');
        $statement->execute(array(':state' => $state, ':error' => $error, ':id' => $id));
    }
}

function http_request($method, $url, $headers, $body)
{
    $curl = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
    } elseif ($method === 'GET') {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    }
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, 25);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Remote service returned HTTP ' . $status . ': ' . mb_substr($response, 0, 500, 'UTF-8'));
    }
    return $response;
}

function sheet_headers()
{
    return array(
        '📆 ДАТА', '#️⃣ ЗАКАЗА', '🚦 СТАТУС', '🧑🏻 ФИО', '☎️ ТЕЛЕФОН',
        '📩 EMAIL', '🏙️ ГОРОД', '📍 АДРЕС', '📦 ВИД ДОСТАВКИ', '💬 КОММЕНТ.',
        '🎁 ТОВАР', '💵 СУММА', '🔢 ПРОМОКОД', '🚚 ТРЕК СДЭК', '💸 СКИДКА'
    );
}

function sheet_row($order)
{
    $comment = $order['comment'] === '' ? '—' : $order['comment'];
    $promo = $order['promo_code'] === '' ? '—' : $order['promo_code'];
    $track = $order['cdek_track'] === '' ? '—' : $order['cdek_track'];
    $discount = $order['discount_amount'] > 0
        ? rtrim(rtrim(number_format((float) $order['discount_percent'], 2, '.', ''), '0'), '.') . '% / ' . money_text($order['discount_amount'])
        : '0% / 0 ₽';
    return array(
        $order['created_msk'], $order['order_number'], $order['status'], $order['customer_name'],
        $order['phone'], $order['email'], $order['city'], $order['address'], $order['delivery_type'],
        $comment, $order['product_name'] . ' | SKU: ' . $order['sku'], money_text($order['total']),
        $promo, $track, $discount
    );
}

function send_to_google_sheet($order, $config)
{
    $google = $config['google'];
    $payload = json_encode(array(
        'secret' => $google['webhook_secret'],
        'headers' => sheet_headers(),
        'row' => sheet_row($order),
        'order_number' => $order['order_number']
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response = http_request('POST', $google['webhook_url'], array('Content-Type: application/json; charset=utf-8'), $payload);
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $message = is_array($decoded) && !empty($decoded['error']) ? $decoded['error'] : 'invalid Apps Script response';
        throw new RuntimeException('Google Apps Script rejected the order: ' . $message);
    }
}

function html_value($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money_text($amount)
{
    return number_format((int) $amount, 0, ',', ' ') . ' ₽';
}

function telegram_message($order)
{
    $comment = $order['comment'] === '' ? '—' : $order['comment'];
    $promo = $order['promo_code'] === '' ? '—' : $order['promo_code'];
    $discount = $order['discount_amount'] > 0
        ? rtrim(rtrim(number_format((float) $order['discount_percent'], 2, '.', ''), '0'), '.') . '% / ' . money_text($order['discount_amount'])
        : '—';
    $delivery = $order['delivery_type'] === 'Курьер' ? 'Курьер СДЭК' : 'До ПВЗ СДЭК';
    $product = $order['product_name'] . ' (SKU: ' . $order['sku'] . ') ×1 — ' . money_text($order['total']);
    return "<b>Оформлен новый заказ!</b>\n\n"
        . "⌚ <b>Дата и время:</b> " . html_value($order['created_msk']) . "\n"
        . "#️⃣ <b>Номер заказа:</b> " . html_value($order['order_number']) . "\n"
        . "🚦 <b>Статус:</b> Новый\n"
        . "🙍🏻‍♂️ <b>ФИО:</b> " . html_value($order['customer_name']) . "\n"
        . "📞 <b>Телефон:</b> " . html_value($order['phone']) . "\n"
        . "✉️ <b>Email:</b> " . html_value($order['email']) . "\n"
        . "🏢 <b>Город:</b> " . html_value($order['city']) . "\n\n"
        . "📍 <b>Адрес/ПВЗ:</b> " . html_value($order['address']) . "\n"
        . "🚚 <b>Доставка:</b> " . html_value($delivery) . "\n"
        . "💬 <b>Комментарий:</b> " . html_value($comment) . "\n\n"
        . "📦 <b>Товары:</b>\n\n" . html_value($product) . "\n\n"
        . "🧾 <b>Сумма:</b> " . html_value(money_text($order['total'])) . "\n"
        . "🎁 <b>Промокод:</b> " . html_value($promo) . "\n"
        . "💸 <b>Скидка:</b> " . html_value($discount);
}

function send_to_telegram($order, $config)
{
    $telegram = $config['telegram'];
    $url = 'https://api.telegram.org/bot' . $telegram['bot_token'] . '/sendMessage';
    $body = http_build_query(array(
        'chat_id' => $telegram['chat_id'],
        'text' => telegram_message($order),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true'
    ), '', '&');
    http_request('POST', $url, array('Content-Type: application/x-www-form-urlencoded'), $body);
}

function validate_order_input($input, $config)
{
    $errors = array();
    $name = text_value($input, 'name', 120);
    $phoneRaw = text_value($input, 'phone', 30);
    $email = mb_strtolower(text_value($input, 'email', 190), 'UTF-8');
    $city = text_value($input, 'city', 120);
    $address = text_value($input, 'address', 250);
    $delivery = text_value($input, 'delivery', 20);
    $comment = text_value($input, 'comment', 1000);
    $promoInput = mb_strtoupper(text_value($input, 'promo_code', 40), 'UTF-8');
    $key = text_value($input, 'idempotency_key', 100);
    $consent = text_value($input, 'consent', 5);
    $honeypot = text_value($input, 'website', 250);

    if ($honeypot !== '') {
        fail_response(200, 'Заявка принята.', array());
    }
    if (mb_strlen($name, 'UTF-8') < 5) $errors['name'] = 'Укажите ФИО.';
    $phone = preg_replace('/[^0-9+]/', '', $phoneRaw);
    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) $errors['phone'] = 'Проверьте номер телефона.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Проверьте email.';
    if (mb_strlen($city, 'UTF-8') < 2) $errors['city'] = 'Укажите город.';
    if (mb_strlen($address, 'UTF-8') < 5) $errors['address'] = 'Укажите адрес или ПВЗ.';
    if (!in_array($delivery, array('Курьер', 'ПВЗ'), true)) $errors['delivery'] = 'Выберите способ доставки.';
    if ($consent !== '1') $errors['consent'] = 'Необходимо подтвердить возраст и согласие.';
    if (!preg_match('/^[A-Za-z0-9-]{20,100}$/', $key)) $errors['idempotency_key'] = 'Обновите страницу и повторите отправку.';

    $price = (int) $config['product']['price'];
    $discountPercent = 0.0;
    $discountAmount = 0;
    $promoCode = '';
    if ($promoInput !== '') {
        $promos = isset($config['promo_codes']) && is_array($config['promo_codes']) ? $config['promo_codes'] : array();
        if (!isset($promos[$promoInput]) || !is_array($promos[$promoInput])) {
            $errors['promo_code'] = 'Промокод не найден.';
        } else {
            $promoCode = $promoInput;
            $rule = $promos[$promoInput];
            if (!empty($rule['percent'])) {
                $discountPercent = max(0, min(100, (float) $rule['percent']));
                $discountAmount = (int) round($price * $discountPercent / 100);
            } elseif (!empty($rule['amount'])) {
                $discountAmount = max(0, min($price, (int) $rule['amount']));
                $discountPercent = $price > 0 ? round($discountAmount / $price * 100, 2) : 0;
            }
        }
    }
    if (!empty($errors)) {
        fail_response(422, 'Проверьте заполнение формы.', $errors);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    return array(
        'order_number' => null,
        'idempotency_key' => $key,
        'created_at' => $now->format(DateTime::ATOM),
        'created_msk' => $now->format('d.m.Y — H:i'),
        'status' => 'Новый',
        'customer_name' => $name,
        'phone' => $phone,
        'email' => $email,
        'city' => $city,
        'address' => $address,
        'delivery_type' => $delivery,
        'comment' => $comment,
        'product_name' => $config['product']['name'],
        'sku' => $config['product']['sku'],
        'quantity' => 1,
        'subtotal' => $price,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'total' => max(0, $price - $discountAmount),
        'promo_code' => $promoCode,
        'cdek_track' => '',
        'sheet_status' => 'pending',
        'telegram_status' => 'pending',
        'last_error' => '',
        'payload_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

if (defined('ORDER_API_LIBRARY_ONLY') && ORDER_API_LIBRARY_ONLY) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail_response(405, 'Допустим только POST-запрос.', array());
}
if (empty($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== 0) {
    fail_response(415, 'Ожидается JSON.', array());
}
$length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($length > 32768) {
    fail_response(413, 'Слишком большой запрос.', array());
}

$secretRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secret';
$configPath = getenv('ORDER_CONFIG_PATH');
if (!$configPath) {
    $configPath = $secretRoot . DIRECTORY_SEPARATOR . 'order-config.php';
}

try {
    $config = require_config($configPath);
    $allowedHosts = isset($config['allowed_hosts']) && is_array($config['allowed_hosts'])
        ? array_map('strtolower', $config['allowed_hosts'])
        : array('origate-tactic.ru', 'www.origate-tactic.ru');
    assert_same_origin($allowedHosts);

    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) {
        fail_response(413, 'Слишком большой запрос.', array());
    }
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        fail_response(400, 'Некорректный JSON.', array());
    }
    $validated = validate_order_input($input, $config);
    $db = open_database($config['database']);
    $order = find_order($db, $validated['idempotency_key']);
    if (!$order) {
        $order = insert_order($db, $validated);
    }

    $failures = array();
    if ($order['sheet_status'] !== 'sent') {
        try {
            send_to_google_sheet($order, $config);
            update_delivery_state($db, $order['id'], 'sheet_status', 'sent', '');
            $order['sheet_status'] = 'sent';
        } catch (Exception $exception) {
            $message = 'Google Sheets: ' . $exception->getMessage();
            update_delivery_state($db, $order['id'], 'sheet_status', 'failed', $message);
            $failures[] = $message;
        }
    }
    if ($order['telegram_status'] !== 'sent') {
        try {
            send_to_telegram($order, $config);
            update_delivery_state($db, $order['id'], 'telegram_status', 'sent', '');
            $order['telegram_status'] = 'sent';
        } catch (Exception $exception) {
            $message = 'Telegram: ' . $exception->getMessage();
            update_delivery_state($db, $order['id'], 'telegram_status', 'failed', $message);
            $failures[] = $message;
        }
    }

    if (!empty($failures)) {
        error_log('Order ' . $order['order_number'] . ' integration error: ' . implode(' | ', $failures));
        fail_response(502, 'Заказ сохранён, но уведомление не доставлено. Повторите отправку через минуту.', array());
    }
    $clearError = $db->prepare('UPDATE orders SET last_error = :error WHERE id = :id');
    $clearError->execute(array(':error' => '', ':id' => $order['id']));
    json_response(201, array('ok' => true, 'order_number' => $order['order_number'], 'status' => 'Новый'));
} catch (Exception $exception) {
    error_log('Order API error: ' . $exception->getMessage());
    fail_response(503, 'Сервис оформления временно недоступен. Попробуйте позже.', array());
}
