<?php

define('ORDER_API_LIBRARY_ONLY', true);
require __DIR__ . '/public_html/order.php';
require __DIR__ . '/public_html/onec-lib.php';
require __DIR__ . '/public_html/admin-lib.php';

function expect_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$expectedHeaders = array(
    '📆 ДАТА', '#️⃣ ЗАКАЗА', '🚦 СТАТУС', '🧑🏻 ФИО', '☎️ ТЕЛЕФОН',
    '📩 EMAIL', '🏙️ ГОРОД', '📍 АДРЕС', '📦 ВИД ДОСТАВКИ', '💬 КОММЕНТ.',
    '🎁 ТОВАР', '💵 СУММА', '🔢 ПРОМОКОД', '🚚 ТРЕК СДЭК', '💸 СКИДКА'
);
expect_true(sheet_headers() === $expectedHeaders, 'Google Sheets headers must match exactly.');
expect_true(!in_array('#️⃣ ТОВАРА', sheet_headers(), true), 'The forbidden product-number column must not exist.');

$config = array(
    'product' => array('name' => 'Револьвер Бульдог KURS кал.5.6/16 КСОИ', 'sku' => '00000050201', 'price' => 55000),
    'promo_codes' => array('TEST10' => array('percent' => 10)),
    'cdek' => array(
        'create_shipments' => false,
        'package' => array('weight_g' => 1480, 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 10)
    ),
    'one_c' => array('export_orders' => false),
    'google' => array('webhook_secret' => 'test-order-rate-secret')
);
$input = array(
    'name' => 'Иван Григорян Олегович',
    'phone' => '+7 (989) 771-49-23',
    'email' => 'Test@example.com',
    'city' => 'Абинск',
    'address' => 'Краснодарский край, г. Абинск, ул. Серова, д. 16',
    'delivery' => 'ПВЗ',
    'comment' => '',
    'promo_code' => 'test10',
    'consent' => '1',
    'website' => '',
    'delivery_quote' => '1234567890abcdef1234567890abcdef1234567890abcdef',
    'idempotency_key' => '12345678-1234-1234-1234-123456789012'
);
$quote = array(
    'quote_token' => $input['delivery_quote'],
    'city' => $input['city'],
    'address' => $input['address'],
    'delivery_type' => 'ПВЗ',
    'used_order_id' => null,
    'delivery_amount' => 600,
    'period_min' => 3,
    'period_max' => 5,
    'pvz_code' => 'ABN1',
    'pvz_address' => 'г. Абинск, ул. Серова, д. 16',
    'city_code' => 123,
    'tariff_code' => 136,
    'tariff_name' => 'Посылка склад-склад'
);
$validated = validate_order_input($input, $config, $quote);
expect_true($validated['status'] === 'Новый', 'New orders must have the New status.');
expect_true($validated['phone'] === '+79897714923', 'Phone must be normalized.');
expect_true($validated['email'] === 'test@example.com', 'Email must be normalized.');
expect_true($validated['discount_amount'] === 5500 && $validated['total'] === 50100, 'Promo and delivery total must be calculated server-side.');
expect_true($validated['delivery_amount'] === 600 && $validated['pvz_code'] === 'ABN1', 'The signed CDEK quote must populate the order.');
expect_true($validated['cdek_status'] === 'disabled', 'Shipment creation must remain disabled by the feature flag.');
expect_true($validated['onec_status'] === 'disabled', '1C order export must remain disabled by the feature flag.');
$shipment = cdek_create_shipment($config, sys_get_temp_dir(), $validated);
expect_true(!$shipment['created'] && $shipment['reason'] === 'feature_flag_disabled', 'The feature flag must block POST /orders.');
$shipmentPayload = cdek_build_shipment_payload($config, $validated);
expect_true($shipmentPayload['delivery_point'] === 'ABN1' && !isset($shipmentPayload['to_location']), 'A pickup-point order must use delivery_point without a conflicting to_location.');
expect_true((int) $shipmentPayload['packages'][0]['items'][0]['payment']['value'] === 49500, 'CDEK COD must equal the discounted product price.');
expect_true((int) $shipmentPayload['delivery_recipient_cost']['value'] === 600, 'CDEK delivery must be charged to the recipient separately.');
expect_true((int) $shipmentPayload['packages'][0]['weight'] === 1480 && (int) $shipmentPayload['packages'][0]['length'] === 35 && (int) $shipmentPayload['packages'][0]['width'] === 25 && (int) $shipmentPayload['packages'][0]['height'] === 10, 'CDEK shipment must use the configured package dimensions.');

$databasePath = tempnam(sys_get_temp_dir(), 'bulldog-order-test-');
$db = open_database($databasePath);
$commerceConfig = load_commerce_config($db, $config);
expect_true((int) $commerceConfig['product']['price'] === 55000, 'Database-backed product price must be seeded from config.');
expect_true(isset($commerceConfig['promo_codes']['TEST10']), 'Legacy promo config must be migrated into the database.');
$db->prepare('UPDATE product_settings SET price = 57000 WHERE sku = :sku')->execute(array(':sku' => $config['product']['sku']));
$commerceConfig = load_commerce_config($db, $config);
expect_true((int) $commerceConfig['product']['price'] === 57000, 'Admin price changes must override the static config.');
$adminConfig = $config;
$adminConfig['admin'] = array('username' => 'Admin', 'password_hash' => password_hash('test-password', PASSWORD_BCRYPT), 'session_ttl' => 3600);
list($adminLoggedIn, $adminError) = admin_login($db, $adminConfig, 'Admin', 'test-password');
expect_true($adminLoggedIn && $adminError === '', 'Admin login must accept a valid bcrypt-protected credential.');
expect_true((int) $db->query('SELECT COUNT(*) FROM admin_sessions')->fetchColumn() === 1, 'Admin login must create a server-side session.');
$inventoryUnavailableBlocked = false;
try {
    insert_order($db, $validated);
} catch (InventoryUnavailableException $exception) {
    $inventoryUnavailableBlocked = true;
}
expect_true($inventoryUnavailableBlocked, 'An unavailable 1C balance must fail closed.');
ensure_inventory_row($db, $validated['sku']);
$db->prepare("UPDATE product_inventory SET quantity = 3, sync_state = 'synced', synced_at = CURRENT_TIMESTAMP WHERE sku = :sku")
    ->execute(array(':sku' => $validated['sku']));
$first = insert_order($db, $validated);
expect_true($first['order_number'] === '000001', 'First internal order number must be 000001.');
expect_true(find_order($db, $validated['idempotency_key'])['id'] === $first['id'], 'Idempotency lookup must return the existing order.');
$inventory = inventory_row($db, $validated['sku'], false);
expect_true((int) $inventory['site_reserved'] === 1, 'A submitted order must reserve one product locally.');
$emptyXml = onec_orders_xml($db, $config, array('current_batch' => '', 'session_hash' => str_repeat('0', 64)));
expect_true(strpos($emptyXml, '<Документ>') === false, 'The 1C feature flag must suppress real order export.');
$db->prepare("UPDATE orders SET onec_status = 'sent', onec_export_batch = 'test-batch' WHERE id = :id")
    ->execute(array(':id' => $first['id']));
$enabledConfig = $config;
$enabledConfig['one_c']['export_orders'] = true;
$orderXml = onec_orders_xml($db, $enabledConfig, array('current_batch' => 'test-batch', 'session_hash' => str_repeat('1', 64)));
expect_true(strpos($orderXml, '<Артикул>00000050201</Артикул>') !== false, 'CommerceML order must contain the exact SKU.');
expect_true(strpos($orderXml, '<Наименование>Резервировать товар</Наименование>') !== false, 'CommerceML order must request reservation.');
expect_true(strpos($orderXml, '<Ид>ORDER_DELIVERY</Ид>') !== false, 'CommerceML order must contain delivery as a service.');
$db->prepare("UPDATE orders SET onec_status = 'disabled', onec_export_batch = '' WHERE id = :id")
    ->execute(array(':id' => $first['id']));
$commerceMlPath = tempnam(sys_get_temp_dir(), 'bulldog-1c-test-');
$commerceMl = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<КоммерческаяИнформация ВерсияСхемы="2.10">'
    . '<Каталог><Товары><Товар><Ид>onec-product-guid</Ид><Артикул>00000050201</Артикул></Товар></Товары></Каталог>'
    . '<ПакетПредложений><Предложения><Предложение><Ид>onec-product-guid</Ид><Количество>3</Количество></Предложение></Предложения></ПакетПредложений>'
    . '</КоммерческаяИнформация>';
file_put_contents($commerceMlPath, $commerceMl);
$imported = onec_import_catalog($db, $config, $commerceMlPath, 'offers.xml');
expect_true($imported['mapped'] === 1 && $imported['stock_updates'] === 1, 'CommerceML import must map SKU and update stock.');
$syncedInventory = inventory_row($db, $validated['sku'], false);
expect_true((int) $syncedInventory['quantity'] === 3 && (int) $syncedInventory['available'] === 2, 'Imported stock must account for the local reservation.');
@unlink($commerceMlPath);
$db->prepare("UPDATE product_inventory SET quantity = 1, sync_state = 'synced' WHERE sku = :sku")
    ->execute(array(':sku' => $validated['sku']));
$second = $validated;
$second['idempotency_key'] = '22345678-1234-1234-1234-123456789012';
$outOfStockBlocked = false;
try {
    insert_order($db, $second);
} catch (OutOfStockException $exception) {
    $outOfStockBlocked = true;
}
expect_true($outOfStockBlocked, 'A synced zero available balance must block the next order atomically.');
$row = sheet_row($first);
expect_true(count($row) === 15, 'Google Sheets row must contain exactly 15 fields.');
expect_true(strpos($row[10], '00000050201') !== false, 'Product field must include SKU.');
$message = telegram_message($first);
expect_true(strpos($message, 'Оформлен новый заказ!') !== false, 'Telegram message title is missing.');
expect_true(strpos($message, '000001') !== false, 'Telegram message order number is missing.');
expect_true(strpos($message, '600 ₽') !== false, 'Telegram message must include the delivery amount.');
$db->prepare("UPDATE product_inventory SET quantity = 10, sync_state = 'synced', synced_at = CURRENT_TIMESTAMP WHERE sku = :sku")
    ->execute(array(':sku' => $validated['sku']));
$secondAllowed = $validated;
$secondAllowed['idempotency_key'] = '32345678-1234-1234-1234-123456789012';
insert_order($db, $secondAllowed);
$samePhone = $validated;
$samePhone['idempotency_key'] = '42345678-1234-1234-1234-123456789012';
$samePhone['_rate_ip_hash'] = order_rate_hash($config, 'ip', '198.51.100.2');
$samePhone['_rate_email_hash'] = order_rate_hash($config, 'email', 'another@example.com');
$phoneLimitBlocked = false;
try {
    insert_order($db, $samePhone);
} catch (OrderRateLimitException $exception) {
    $phoneLimitBlocked = true;
}
expect_true($phoneLimitBlocked, 'A changed IP and email must not bypass the daily phone limit.');
$sameEmail = $validated;
$sameEmail['idempotency_key'] = '52345678-1234-1234-1234-123456789012';
$sameEmail['_rate_ip_hash'] = order_rate_hash($config, 'ip', '198.51.100.3');
$sameEmail['_rate_phone_hash'] = order_rate_hash($config, 'phone', '+79990000000');
$emailLimitBlocked = false;
try {
    insert_order($db, $sameEmail);
} catch (OrderRateLimitException $exception) {
    $emailLimitBlocked = true;
}
expect_true($emailLimitBlocked, 'A changed IP and phone must not bypass the daily email limit.');

$db = null;
@unlink($databasePath);
@unlink($databasePath . '-wal');
@unlink($databasePath . '-shm');
echo "BACKEND_SMOKE_OK\n";
