<?php

define('ORDER_API_LIBRARY_ONLY', true);
require __DIR__ . '/public_html/order.php';

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
    'product' => array('name' => 'КСОИ «БУЛЬДОГ»', 'sku' => 'TEST-SKU', 'price' => 55000),
    'promo_codes' => array('TEST10' => array('percent' => 10))
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
    'idempotency_key' => '12345678-1234-1234-1234-123456789012'
);
$validated = validate_order_input($input, $config);
expect_true($validated['status'] === 'Новый', 'New orders must have the New status.');
expect_true($validated['phone'] === '+79897714923', 'Phone must be normalized.');
expect_true($validated['email'] === 'test@example.com', 'Email must be normalized.');
expect_true($validated['discount_amount'] === 5500 && $validated['total'] === 49500, 'Promo discount must be calculated server-side.');

$databasePath = tempnam(sys_get_temp_dir(), 'bulldog-order-test-');
$db = open_database($databasePath);
$first = insert_order($db, $validated);
expect_true($first['order_number'] === '000001', 'First internal order number must be 000001.');
expect_true(find_order($db, $validated['idempotency_key'])['id'] === $first['id'], 'Idempotency lookup must return the existing order.');
$row = sheet_row($first);
expect_true(count($row) === 15, 'Google Sheets row must contain exactly 15 fields.');
expect_true(strpos($row[10], 'TEST-SKU') !== false, 'Product field must include SKU.');
$message = telegram_message($first);
expect_true(strpos($message, 'Оформлен новый заказ!') !== false, 'Telegram message title is missing.');
expect_true(strpos($message, '000001') !== false, 'Telegram message order number is missing.');

$db = null;
@unlink($databasePath);
@unlink($databasePath . '-wal');
@unlink($databasePath . '-shm');
echo "BACKEND_SMOKE_OK\n";

