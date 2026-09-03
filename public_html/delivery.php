<?php

define('ORDER_API_LIBRARY_ONLY', true);
require_once __DIR__ . '/order.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail_response(405, 'Допустим только POST-запрос.', array());
}
if (empty($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== 0) {
    fail_response(415, 'Ожидается JSON.', array());
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

    $raw = file_get_contents('php://input', false, null, 0, 8193);
    $input = $raw === false ? null : json_decode($raw, true);
    if (!is_array($input)) {
        fail_response(400, 'Некорректный JSON.', array());
    }
    $city = text_value($input, 'city', 120);
    $address = text_value($input, 'address', 250);
    $deliveryType = text_value($input, 'delivery', 20);
    $errors = array();
    if (mb_strlen($city, 'UTF-8') < 2) $errors['city'] = 'Укажите город.';
    if (mb_strlen($address, 'UTF-8') < 5) $errors['address'] = 'Укажите полный адрес.';
    if ($deliveryType !== 'ПВЗ') $errors['delivery'] = 'Доступна доставка только до ПВЗ СДЭК.';
    if (!empty($errors)) {
        fail_response(422, 'Уточните данные для расчёта доставки.', $errors);
    }

    $db = open_database($config['database']);
    $config = load_commerce_config($db, $config);
    $config['cdek']['insurance_value'] = (int) $config['product']['price'];
    $clientSource = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . '|'
        . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') . '|'
        . $config['google']['webhook_secret'];
    $clientHash = hash('sha256', $clientSource);

    $rate = $db->prepare(
        'SELECT COUNT(*) FROM delivery_quotes WHERE client_hash = :client_hash AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $rate->execute(array(':client_hash' => $clientHash));
    if ((int) $rate->fetchColumn() >= 8) {
        fail_response(429, 'Слишком много расчётов. Подождите минуту и повторите.', array());
    }

    $cached = $db->prepare(
        'SELECT * FROM delivery_quotes
         WHERE client_hash = :client_hash AND city = :city AND address = :address AND delivery_type = :delivery_type
           AND used_order_id IS NULL AND expires_at > DATE_ADD(NOW(), INTERVAL 10 MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $cached->execute(array(
        ':client_hash' => $clientHash, ':city' => $city, ':address' => $address, ':delivery_type' => $deliveryType
    ));
    $quote = $cached->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        $calculation = cdek_calculate_quote($config, $secretRoot, $city, $address, $deliveryType);
        $token = cdek_random_token();
        $expires = new DateTimeImmutable('+30 minutes', new DateTimeZone('Europe/Moscow'));
        $insert = $db->prepare(
            'INSERT INTO delivery_quotes (
                quote_token, client_hash, city, address, delivery_type, city_code,
                latitude, longitude, location_precision, pvz_code, pvz_address, pvz_distance_m,
                tariff_code, tariff_name, delivery_amount, period_min, period_max,
                raw_response_json, expires_at, used_order_id
             ) VALUES (
                :quote_token, :client_hash, :city, :address, :delivery_type, :city_code,
                :latitude, :longitude, :location_precision, :pvz_code, :pvz_address, :pvz_distance_m,
                :tariff_code, :tariff_name, :delivery_amount, :period_min, :period_max,
                :raw_response_json, :expires_at, NULL
             )'
        );
        $insert->execute(array(
            ':quote_token' => $token,
            ':client_hash' => $clientHash,
            ':city' => $city,
            ':address' => $address,
            ':delivery_type' => $deliveryType,
            ':city_code' => $calculation['city_code'],
            ':latitude' => $calculation['latitude'],
            ':longitude' => $calculation['longitude'],
            ':location_precision' => $calculation['location_precision'],
            ':pvz_code' => $calculation['pvz_code'],
            ':pvz_address' => $calculation['pvz_address'],
            ':pvz_distance_m' => $calculation['pvz_distance_m'],
            ':tariff_code' => $calculation['tariff_code'],
            ':tariff_name' => $calculation['tariff_name'],
            ':delivery_amount' => $calculation['delivery_amount'],
            ':period_min' => $calculation['period_min'],
            ':period_max' => $calculation['period_max'],
            ':raw_response_json' => json_encode($calculation['raw_response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':expires_at' => $expires->format('Y-m-d H:i:s')
        ));
        $quote = find_delivery_quote($db, $token);
    }

    json_response(200, array(
        'ok' => true,
        'quote_token' => $quote['quote_token'],
        'delivery_type' => $quote['delivery_type'],
        'delivery_amount' => (int) $quote['delivery_amount'],
        'period_min' => (int) $quote['period_min'],
        'period_max' => (int) $quote['period_max'],
        'location_precision' => $quote['location_precision'],
        'pvz' => $quote['delivery_type'] === 'ПВЗ' ? array(
            'code' => $quote['pvz_code'],
            'address' => $quote['pvz_address'],
            'distance_m' => (int) $quote['pvz_distance_m']
        ) : null
    ));
} catch (Exception $exception) {
    error_log('Delivery API error: ' . $exception->getMessage());
    $message = strpos($exception->getMessage(), 'Город не найден') !== false
        ? 'Город не найден в справочнике СДЭК. Проверьте написание.'
        : 'Не удалось рассчитать доставку. Уточните адрес или попробуйте позже.';
    fail_response(503, $message, array());
}
