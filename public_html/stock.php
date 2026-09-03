<?php

define('ORDER_API_LIBRARY_ONLY', true);
require_once __DIR__ . '/order.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    fail_response(405, 'Допустим только GET-запрос.', array());
}

$secretRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secret';
$configPath = getenv('ORDER_CONFIG_PATH');
if (!$configPath) $configPath = $secretRoot . DIRECTORY_SEPARATOR . 'order-config.php';

try {
    $config = require_config($configPath);
    $db = open_database($config['database']);
    $config = load_commerce_config($db, $config);
    $inventory = inventory_row($db, $config['product']['sku'], false);
    $synced = $inventory && $inventory['sync_state'] === 'synced' && $inventory['synced_at'] !== null;
    json_response(200, array(
        'ok' => true,
        'sku' => $config['product']['sku'],
        'price' => (int) $config['product']['price'],
        'synced' => $synced,
        'quantity' => $synced ? (int) $inventory['quantity'] : null,
        'reserved' => $synced ? (int) $inventory['site_reserved'] : null,
        'available' => $synced ? (int) $inventory['available'] : null,
        'in_stock' => $synced ? (int) $inventory['available'] > 0 : null,
        'updated_at' => $synced ? $inventory['synced_at'] : null
    ));
} catch (Exception $exception) {
    error_log('Stock API error: ' . $exception->getMessage());
    fail_response(503, 'Не удалось проверить остаток. Попробуйте позже.', array());
}
