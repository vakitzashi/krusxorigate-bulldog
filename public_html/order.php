<?php

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/cdek-lib.php';

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
        array('cdek', 'api_base'),
        array('cdek', 'client_id'),
        array('cdek', 'client_secret'),
        array('cdek', 'origin'),
        array('cdek', 'package'),
        array('one_c', 'username'),
        array('one_c', 'password'),
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

function mysql_ensure_column($db, $table, $column, $definition)
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $statement->execute(array(':table' => $table, ':column' => $column));
    if ((int) $statement->fetchColumn() === 0) {
        $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
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
                delivery_amount INT UNSIGNED NOT NULL DEFAULT 0,
                delivery_quote_token VARCHAR(64) NOT NULL DEFAULT \'\',
                delivery_period_min INT UNSIGNED NOT NULL DEFAULT 0,
                delivery_period_max INT UNSIGNED NOT NULL DEFAULT 0,
                pvz_code VARCHAR(64) NOT NULL DEFAULT \'\',
                pvz_address VARCHAR(500) NOT NULL DEFAULT \'\',
                cdek_city_code INT UNSIGNED NOT NULL DEFAULT 0,
                cdek_tariff_code INT UNSIGNED NOT NULL DEFAULT 0,
                cdek_tariff_name VARCHAR(190) NOT NULL DEFAULT \'\',
                cdek_uuid VARCHAR(64) NOT NULL DEFAULT \'\',
                cdek_status VARCHAR(20) NOT NULL DEFAULT \'disabled\',
                onec_status VARCHAR(20) NOT NULL DEFAULT \'disabled\',
                onec_export_batch VARCHAR(64) NOT NULL DEFAULT \'\',
                onec_exported_at DATETIME NULL,
                onec_acknowledged_at DATETIME NULL,
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
        $columns = array(
            'delivery_amount' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `promo_code`',
            'delivery_quote_token' => 'VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `delivery_amount`',
            'delivery_period_min' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_quote_token`',
            'delivery_period_max' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `delivery_period_min`',
            'pvz_code' => 'VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `delivery_period_max`',
            'pvz_address' => 'VARCHAR(500) NOT NULL DEFAULT \'\' AFTER `pvz_code`',
            'cdek_city_code' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pvz_address`',
            'cdek_tariff_code' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cdek_city_code`',
            'cdek_tariff_name' => 'VARCHAR(190) NOT NULL DEFAULT \'\' AFTER `cdek_tariff_code`',
            'cdek_uuid' => 'VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `cdek_tariff_name`',
            'cdek_status' => 'VARCHAR(20) NOT NULL DEFAULT \'disabled\' AFTER `cdek_uuid`',
            'onec_status' => 'VARCHAR(20) NOT NULL DEFAULT \'disabled\' AFTER `cdek_status`',
            'onec_export_batch' => 'VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `onec_status`',
            'onec_exported_at' => 'DATETIME NULL AFTER `onec_export_batch`',
            'onec_acknowledged_at' => 'DATETIME NULL AFTER `onec_exported_at`'
        );
        foreach ($columns as $column => $definition) {
            mysql_ensure_column($db, 'orders', $column, $definition);
        }
        $db->exec(
            'CREATE TABLE IF NOT EXISTS delivery_quotes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_token VARCHAR(64) NOT NULL,
                client_hash CHAR(64) NOT NULL,
                city VARCHAR(190) NOT NULL,
                address VARCHAR(500) NOT NULL,
                delivery_type VARCHAR(32) NOT NULL,
                city_code INT UNSIGNED NOT NULL,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                location_precision VARCHAR(20) NOT NULL,
                pvz_code VARCHAR(64) NOT NULL,
                pvz_address VARCHAR(500) NOT NULL,
                pvz_distance_m INT UNSIGNED NOT NULL,
                tariff_code INT UNSIGNED NOT NULL,
                tariff_name VARCHAR(190) NOT NULL,
                delivery_amount INT UNSIGNED NOT NULL,
                period_min INT UNSIGNED NOT NULL,
                period_max INT UNSIGNED NOT NULL,
                raw_response_json JSON NOT NULL,
                expires_at DATETIME NOT NULL,
                used_order_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_delivery_quotes_token (quote_token),
                KEY idx_delivery_quotes_lookup (client_hash, city, delivery_type, created_at),
                KEY idx_delivery_quotes_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS product_inventory (
                sku VARCHAR(64) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 0,
                site_reserved INT UNSIGNED NOT NULL DEFAULT 0,
                sync_state VARCHAR(20) NOT NULL DEFAULT \'unknown\',
                source_file VARCHAR(255) NOT NULL DEFAULT \'\',
                source_hash CHAR(64) NOT NULL DEFAULT \'\',
                synced_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS inventory_reservations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                sku VARCHAR(64) NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'local\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at DATETIME NULL,
                transferred_at DATETIME NULL,
                released_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_inventory_reservations_order (order_id),
                KEY idx_inventory_reservations_sku_status (sku, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS onec_product_map (
                external_id VARCHAR(190) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (external_id),
                KEY idx_onec_product_map_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS onec_exchange_sessions (
                session_hash CHAR(64) NOT NULL,
                exchange_type VARCHAR(20) NOT NULL,
                current_batch VARCHAR(64) NOT NULL DEFAULT \'\',
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (session_hash),
                KEY idx_onec_exchange_sessions_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS onec_exchange_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                exchange_type VARCHAR(20) NOT NULL,
                mode_name VARCHAR(20) NOT NULL,
                filename VARCHAR(255) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL,
                details TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_onec_exchange_log_created (created_at)
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
            delivery_amount INTEGER NOT NULL,
            delivery_quote_token TEXT NOT NULL,
            delivery_period_min INTEGER NOT NULL,
            delivery_period_max INTEGER NOT NULL,
            pvz_code TEXT NOT NULL,
            pvz_address TEXT NOT NULL,
            cdek_city_code INTEGER NOT NULL,
            cdek_tariff_code INTEGER NOT NULL,
            cdek_tariff_name TEXT NOT NULL,
            cdek_uuid TEXT NOT NULL,
            cdek_status TEXT NOT NULL,
            onec_status TEXT NOT NULL,
            onec_export_batch TEXT NOT NULL,
            onec_exported_at TEXT,
            onec_acknowledged_at TEXT,
            cdek_track TEXT NOT NULL,
            sheet_status TEXT NOT NULL,
            telegram_status TEXT NOT NULL,
            last_error TEXT NOT NULL,
            payload_json TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS product_inventory (
            sku TEXT PRIMARY KEY,
            quantity INTEGER NOT NULL DEFAULT 0,
            site_reserved INTEGER NOT NULL DEFAULT 0,
            sync_state TEXT NOT NULL DEFAULT \'unknown\',
            source_file TEXT NOT NULL DEFAULT \'\',
            source_hash TEXT NOT NULL DEFAULT \'\',
            synced_at TEXT
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS inventory_reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL UNIQUE,
            sku TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'local\',
            created_at TEXT,
            acknowledged_at TEXT,
            transferred_at TEXT,
            released_at TEXT
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS onec_product_map (
            external_id TEXT PRIMARY KEY,
            sku TEXT NOT NULL,
            updated_at TEXT
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

function find_delivery_quote($db, $token)
{
    if ($token === '') return null;
    $statement = $db->prepare(
        'SELECT * FROM delivery_quotes WHERE quote_token = :token AND expires_at > CURRENT_TIMESTAMP LIMIT 1'
    );
    $statement->execute(array(':token' => $token));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

class OutOfStockException extends RuntimeException
{
}

function ensure_inventory_row($db, $sku)
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $statement = $db->prepare(
            'INSERT IGNORE INTO product_inventory (sku, quantity, site_reserved, sync_state) VALUES (:sku, 0, 0, \'unknown\')'
        );
    } else {
        $statement = $db->prepare(
            'INSERT OR IGNORE INTO product_inventory (sku, quantity, site_reserved, sync_state) VALUES (:sku, 0, 0, \'unknown\')'
        );
    }
    $statement->execute(array(':sku' => $sku));
}

function inventory_row($db, $sku, $forUpdate)
{
    ensure_inventory_row($db, $sku);
    $sql = 'SELECT *, CASE WHEN quantity > site_reserved THEN quantity - site_reserved ELSE 0 END AS available FROM product_inventory WHERE sku = :sku';
    if ($forUpdate && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $statement = $db->prepare($sql);
    $statement->execute(array(':sku' => $sku));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function mark_delivery_quote_used($db, $token, $orderId)
{
    $statement = $db->prepare(
        'UPDATE delivery_quotes SET used_order_id = :order_id WHERE quote_token = :token AND (used_order_id IS NULL OR used_order_id = :order_id)'
    );
    $statement->execute(array(':order_id' => $orderId, ':token' => $token));
}

function insert_order($db, $order)
{
    $db->beginTransaction();
    try {
        $inventory = inventory_row($db, $order['sku'], true);
        if ($inventory['sync_state'] === 'synced' && (int) $inventory['available'] < (int) $order['quantity']) {
            throw new OutOfStockException('Product is out of stock.');
        }
        $statement = $db->prepare(
            'INSERT INTO orders (
                order_number, idempotency_key, created_at, created_msk, status,
                customer_name, phone, email, city, address, delivery_type, comment,
                product_name, sku, quantity, subtotal, discount_percent, discount_amount,
                total, promo_code, delivery_amount, delivery_quote_token, delivery_period_min, delivery_period_max,
                pvz_code, pvz_address, cdek_city_code, cdek_tariff_code, cdek_tariff_name,
                cdek_uuid, cdek_status, onec_status, onec_export_batch, onec_exported_at, onec_acknowledged_at,
                cdek_track, sheet_status, telegram_status, last_error, payload_json
            ) VALUES (
                NULL, :idempotency_key, :created_at, :created_msk, :status,
                :customer_name, :phone, :email, :city, :address, :delivery_type, :comment,
                :product_name, :sku, :quantity, :subtotal, :discount_percent, :discount_amount,
                :total, :promo_code, :delivery_amount, :delivery_quote_token, :delivery_period_min, :delivery_period_max,
                :pvz_code, :pvz_address, :cdek_city_code, :cdek_tariff_code, :cdek_tariff_name,
                :cdek_uuid, :cdek_status, :onec_status, :onec_export_batch, :onec_exported_at, :onec_acknowledged_at,
                :cdek_track, :sheet_status, :telegram_status, :last_error, :payload_json
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
        $reserve = $db->prepare(
            'UPDATE product_inventory SET site_reserved = site_reserved + :quantity WHERE sku = :sku'
        );
        $reserve->execute(array(':quantity' => (int) $order['quantity'], ':sku' => $order['sku']));
        $reservation = $db->prepare(
            'INSERT INTO inventory_reservations (order_id, sku, quantity, status, created_at)
             VALUES (:order_id, :sku, :quantity, \'local\', :created_at)'
        );
        $reservation->execute(array(
            ':order_id' => $id,
            ':sku' => $order['sku'],
            ':quantity' => (int) $order['quantity'],
            ':created_at' => date('Y-m-d H:i:s')
        ));
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

function update_cdek_state($db, $id, $status, $uuid, $track, $error)
{
    $statement = $db->prepare(
        'UPDATE orders SET cdek_status = :status, cdek_uuid = :uuid, cdek_track = :track, last_error = :error WHERE id = :id'
    );
    $statement->execute(array(
        ':status' => $status, ':uuid' => $uuid, ':track' => $track, ':error' => $error, ':id' => $id
    ));
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
    $destination = $order['delivery_type'] === 'ПВЗ' && $order['pvz_address'] !== ''
        ? $order['pvz_address'] . ' (код ' . $order['pvz_code'] . ')'
        : $order['address'];
    return array(
        $order['created_msk'], $order['order_number'], $order['status'], $order['customer_name'],
        $order['phone'], $order['email'], $order['city'], $destination, $order['delivery_type'],
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
    $destination = $order['delivery_type'] === 'ПВЗ' && $order['pvz_address'] !== ''
        ? $order['pvz_address'] . ' (код ' . $order['pvz_code'] . ')'
        : $order['address'];
    $productTotal = max(0, (int) $order['subtotal'] - (int) $order['discount_amount']);
    $product = $order['product_name'] . ' (SKU: ' . $order['sku'] . ') ×1 — ' . money_text($productTotal);
    return "<b>Оформлен новый заказ!</b>\n\n"
        . "⌚ <b>Дата и время:</b> " . html_value($order['created_msk']) . "\n"
        . "#️⃣ <b>Номер заказа:</b> " . html_value($order['order_number']) . "\n"
        . "🚦 <b>Статус:</b> Новый\n"
        . "🙍🏻‍♂️ <b>ФИО:</b> " . html_value($order['customer_name']) . "\n"
        . "📞 <b>Телефон:</b> " . html_value($order['phone']) . "\n"
        . "✉️ <b>Email:</b> " . html_value($order['email']) . "\n"
        . "🏢 <b>Город:</b> " . html_value($order['city']) . "\n\n"
        . "📍 <b>Адрес/ПВЗ:</b> " . html_value($destination) . "\n"
        . "🚚 <b>Доставка:</b> " . html_value($delivery) . "\n"
        . "💳 <b>Стоимость доставки:</b> " . html_value(money_text($order['delivery_amount'])) . "\n"
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

function validate_order_input($input, $config, $quote)
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
    $quoteToken = text_value($input, 'delivery_quote', 64);

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
    if (!is_array($quote) || $quoteToken === '' || !hash_equals((string) $quote['quote_token'], $quoteToken)) {
        $errors['delivery_quote'] = 'Рассчитайте доставку заново.';
    } elseif (trim($quote['city']) !== $city || trim($quote['address']) !== $address || $quote['delivery_type'] !== $delivery) {
        $errors['delivery_quote'] = 'Адрес изменился. Рассчитайте доставку заново.';
    } elseif (!empty($quote['used_order_id'])) {
        $errors['delivery_quote'] = 'Расчёт доставки уже использован. Выполните новый расчёт.';
    }

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

    $deliveryAmount = (int) $quote['delivery_amount'];
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
        'total' => max(0, $price - $discountAmount) + $deliveryAmount,
        'promo_code' => $promoCode,
        'delivery_amount' => $deliveryAmount,
        'delivery_quote_token' => $quoteToken,
        'delivery_period_min' => (int) $quote['period_min'],
        'delivery_period_max' => (int) $quote['period_max'],
        'pvz_code' => $quote['pvz_code'],
        'pvz_address' => $quote['pvz_address'],
        'cdek_city_code' => (int) $quote['city_code'],
        'cdek_tariff_code' => (int) $quote['tariff_code'],
        'cdek_tariff_name' => $quote['tariff_name'],
        'cdek_uuid' => '',
        'cdek_status' => empty($config['cdek']['create_shipments']) ? 'disabled' : 'pending',
        'onec_status' => empty($config['one_c']['export_orders']) ? 'disabled' : 'pending',
        'onec_export_batch' => '',
        'onec_exported_at' => null,
        'onec_acknowledged_at' => null,
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
    $db = open_database($config['database']);
    $key = text_value($input, 'idempotency_key', 100);
    $order = preg_match('/^[A-Za-z0-9-]{20,100}$/', $key) ? find_order($db, $key) : null;
    if (!$order) {
        $quoteToken = text_value($input, 'delivery_quote', 64);
        $quote = find_delivery_quote($db, $quoteToken);
        $validated = validate_order_input($input, $config, $quote);
        $order = insert_order($db, $validated);
        mark_delivery_quote_used($db, $quoteToken, $order['id']);
    }

    $failures = array();
    if (!empty($config['cdek']['create_shipments']) && $order['cdek_status'] !== 'created') {
        try {
            $shipment = cdek_create_shipment($config, $secretRoot, $order);
            if (!empty($shipment['created'])) {
                update_cdek_state($db, $order['id'], 'created', $shipment['uuid'], $shipment['cdek_number'], '');
                $order['cdek_status'] = 'created';
                $order['cdek_uuid'] = $shipment['uuid'];
                $order['cdek_track'] = $shipment['cdek_number'];
            }
        } catch (Exception $exception) {
            $message = 'CDEK: ' . $exception->getMessage();
            update_cdek_state($db, $order['id'], 'failed', $order['cdek_uuid'], $order['cdek_track'], $message);
            $failures[] = $message;
        }
    }
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
    json_response(201, array(
        'ok' => true,
        'order_number' => $order['order_number'],
        'status' => 'Новый',
        'delivery_amount' => (int) $order['delivery_amount'],
        'total' => (int) $order['total']
    ));
} catch (OutOfStockException $exception) {
    fail_response(409, 'Товар закончился. Отправка заявки временно недоступна.', array('stock' => 'Нет в наличии.'));
} catch (Exception $exception) {
    error_log('Order API error: ' . $exception->getMessage());
    fail_response(503, 'Сервис оформления временно недоступен. Попробуйте позже.', array());
}
