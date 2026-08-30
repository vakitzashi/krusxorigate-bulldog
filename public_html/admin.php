<?php

define('ORDER_API_LIBRARY_ONLY', true);
require_once __DIR__ . '/order.php';
require_once __DIR__ . '/admin-lib.php';

admin_headers();
$secretRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secret';
$configPath = getenv('ORDER_CONFIG_PATH');
if (!$configPath) $configPath = $secretRoot . DIRECTORY_SEPARATOR . 'order-config.php';
$error = '';

try {
    $config = require_config($configPath);
    admin_config($config);
    $db = open_database($config['database']);
    $config = load_commerce_config($db, $config);
    $session = admin_session($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!admin_origin_valid($config)) throw new RuntimeException('Источник запроса не разрешён.');
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        if (!$session && $action === 'login') {
            list($ok, $message) = admin_login($db, $config, isset($_POST['username']) ? $_POST['username'] : '', isset($_POST['password']) ? $_POST['password'] : '');
            if ($ok) admin_redirect('login');
            $error = $message;
        } elseif (!$session) {
            $error = 'Сессия истекла. Войдите снова.';
        } elseif (!admin_csrf_valid($session, $config, isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            http_response_code(403);
            $error = 'Защитный токен устарел. Обновите страницу.';
        } elseif ($action === 'logout') {
            admin_logout($db, $session);
            admin_redirect('logout');
        } elseif ($action === 'save_price') {
            $priceRaw = isset($_POST['price']) ? preg_replace('/\D+/', '', (string) $_POST['price']) : '';
            $price = (int) $priceRaw;
            if ($price < 1 || $price > 10000000) throw new InvalidArgumentException('Укажите цену от 1 до 10 000 000 ₽.');
            $statement = $db->prepare('UPDATE product_settings SET price = :price WHERE sku = :sku');
            $statement->execute(array(':price' => $price, ':sku' => $config['product']['sku']));
            admin_redirect('price');
        } elseif ($action === 'save_promo') {
            $code = mb_strtoupper(trim(isset($_POST['code']) ? (string) $_POST['code'] : ''), 'UTF-8');
            $type = isset($_POST['discount_type']) ? (string) $_POST['discount_type'] : '';
            $valueText = str_replace(',', '.', trim(isset($_POST['discount_value']) ? (string) $_POST['discount_value'] : ''));
            $value = (float) $valueText;
            $active = isset($_POST['active']) ? 1 : 0;
            if (!preg_match('/^[A-ZА-ЯЁ0-9_-]{2,32}$/u', $code)) throw new InvalidArgumentException('Код: 2–32 символа, буквы, цифры, дефис или подчёркивание.');
            if (!in_array($type, array('percent', 'amount'), true)) throw new InvalidArgumentException('Выберите тип скидки.');
            if ($value <= 0 || ($type === 'percent' && $value > 100) || ($type === 'amount' && $value > 10000000)) throw new InvalidArgumentException('Некорректный размер скидки.');
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $statement = $db->prepare('INSERT INTO promo_codes (code, discount_type, discount_value, active) VALUES (:code, :type, :value, :active) ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), discount_value = VALUES(discount_value), active = VALUES(active)');
            } else {
                $statement = $db->prepare('INSERT OR REPLACE INTO promo_codes (code, discount_type, discount_value, active, created_at, updated_at) VALUES (:code, :type, :value, :active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            }
            $statement->execute(array(':code' => $code, ':type' => $type, ':value' => $value, ':active' => $active));
            admin_redirect('promo');
        } elseif ($action === 'toggle_promo' || $action === 'delete_promo') {
            $code = mb_strtoupper(trim(isset($_POST['code']) ? (string) $_POST['code'] : ''), 'UTF-8');
            if ($action === 'toggle_promo') {
                $statement = $db->prepare('UPDATE promo_codes SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE code = :code');
            } else {
                $statement = $db->prepare('DELETE FROM promo_codes WHERE code = :code');
            }
            $statement->execute(array(':code' => $code));
            admin_redirect($action === 'toggle_promo' ? 'promo-status' : 'promo-delete');
        }
        $session = admin_session($db);
        $config = load_commerce_config($db, $config);
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Exception $exception) {
    error_log('Admin error: ' . $exception->getMessage());
    $error = 'Админка временно недоступна. Повторите позже.';
}

$noticeMap = array(
    'login' => 'Вход выполнен.', 'logout' => 'Вы вышли из админки.', 'price' => 'Цена обновлена.',
    'promo' => 'Промокод сохранён.', 'promo-status' => 'Статус промокода изменён.', 'promo-delete' => 'Промокод удалён.'
);
$noticeKey = isset($_GET['notice']) ? (string) $_GET['notice'] : '';
$notice = isset($noticeMap[$noticeKey]) ? $noticeMap[$noticeKey] : '';

if (!isset($session) || !$session) {
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — ORIGATE TACTIC</title><link rel="icon" href="favicon.png"><link rel="stylesheet" href="admin.css?v=20260830-1"></head>
<body class="admin-login"><main class="login-card"><img src="kursXorigate.png" alt="KURS × ORIGATE"><p class="eyebrow">Закрытая зона</p><h1>Управление<br><em>БУЛЬДОГ</em></h1><p>Войдите, чтобы управлять продажами.</p>
<?php if ($notice): ?><div class="alert alert--ok"><?php echo admin_escape($notice); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error"><?php echo admin_escape($error); ?></div><?php endif; ?>
<form method="post" action="admin.php" autocomplete="on"><input type="hidden" name="action" value="login"><label>Логин<input name="username" autocomplete="username" required autofocus></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Войти <span>→</span></button></form><a class="back" href="index.html">← Вернуться на сайт</a></main></body></html><?php exit;
}

$csrf = admin_csrf($session, $config);
$inventory = inventory_row($db, $config['product']['sku'], false);
$promos = $db->query('SELECT code, discount_type, discount_value, active, updated_at FROM promo_codes ORDER BY active DESC, code')->fetchAll(PDO::FETCH_ASSOC);
$orders = $db->query('SELECT * FROM orders ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$available = $inventory ? max(0, (int) $inventory['available']) : 0;
$synced = $inventory && $inventory['sync_state'] === 'synced';
$price = (int) $config['product']['price'];
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Админка — ORIGATE TACTIC</title><link rel="icon" href="favicon.png"><link rel="stylesheet" href="admin.css?v=20260830-1"></head>
<body><header class="admin-header"><a href="index.html"><img src="kursXorigate.png" alt="KURS × ORIGATE"></a><div><span><?php echo admin_escape($session['username']); ?></span><form method="post" action="admin.php"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?php echo admin_escape($csrf); ?>"><button class="link-button" type="submit">Выйти</button></form></div></header>
<main class="dashboard"><section class="dashboard-title"><div><p class="eyebrow">Панель управления</p><h1>БУЛЬДОГ <em>KURS</em></h1><p>SKU <?php echo admin_escape($config['product']['sku']); ?></p></div><a class="site-link" href="index.html" target="_blank" rel="noopener">Открыть сайт ↗</a></section>
<?php if ($notice): ?><div class="alert alert--ok"><?php echo admin_escape($notice); ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert--error"><?php echo admin_escape($error); ?></div><?php endif; ?>
<section class="metrics"><article><span>Доступно</span><strong class="<?php echo $available > 0 ? 'positive' : 'danger'; ?>"><?php echo $synced ? $available : '—'; ?></strong><small><?php echo $synced ? 'актуальный остаток 1С' : '1С ещё не синхронизирована'; ?></small></article><article><span>Остаток 1С</span><strong><?php echo $synced ? (int) $inventory['quantity'] : '—'; ?></strong><small>в резерве сайта: <?php echo $synced ? (int) $inventory['site_reserved'] : '—'; ?></small></article><article><span>Цена</span><strong><?php echo number_format($price, 0, ',', ' '); ?> ₽</strong><small>на сайте и в новых заказах</small></article><article><span>Заказы</span><strong><?php echo count($orders); ?></strong><small>показано последних 200</small></article></section>

<section class="admin-grid"><article class="panel"><div class="panel-title"><div><p class="eyebrow">01 / Цена</p><h2>Стоимость товара</h2></div></div><form class="price-form" method="post" action="admin.php"><input type="hidden" name="action" value="save_price"><input type="hidden" name="csrf" value="<?php echo admin_escape($csrf); ?>"><label>Цена, ₽<input type="number" min="1" max="10000000" step="1" name="price" value="<?php echo $price; ?>" required></label><button type="submit">Сохранить цену →</button></form><p class="hint">Изменение сразу появится на лендинге и применяется сервером к новым заказам.</p></article>
<article class="panel"><div class="panel-title"><div><p class="eyebrow">02 / Промокоды</p><h2>Новая скидка</h2></div></div><form class="promo-form" method="post" action="admin.php"><input type="hidden" name="action" value="save_promo"><input type="hidden" name="csrf" value="<?php echo admin_escape($csrf); ?>"><label>Код<input name="code" maxlength="32" placeholder="BULLDOG10" required></label><label>Тип<select name="discount_type"><option value="percent">Процент</option><option value="amount">Сумма, ₽</option></select></label><label>Значение<input type="number" name="discount_value" min="0.01" max="10000000" step="0.01" required></label><label class="switch"><input type="checkbox" name="active" value="1" checked><span></span>Активен сразу</label><button type="submit">Сохранить промокод →</button></form></article></section>

<section class="panel promo-panel"><div class="panel-title"><div><p class="eyebrow">Активные правила</p><h2>Промокоды</h2></div><span><?php echo count($promos); ?> шт.</span></div><?php if (!$promos): ?><p class="empty">Промокодов пока нет.</p><?php else: ?><div class="promo-list"><?php foreach ($promos as $promo): ?><article class="promo-row <?php echo $promo['active'] ? '' : 'is-off'; ?>"><div><strong><?php echo admin_escape($promo['code']); ?></strong><span><?php echo $promo['discount_type'] === 'percent' ? admin_escape(rtrim(rtrim(number_format((float) $promo['discount_value'], 2, '.', ''), '0'), '.')) . '%' : number_format((float) $promo['discount_value'], 0, ',', ' ') . ' ₽'; ?></span><small><?php echo $promo['active'] ? 'Работает' : 'Отключён'; ?></small></div><div class="row-actions"><form method="post" action="admin.php"><input type="hidden" name="action" value="toggle_promo"><input type="hidden" name="csrf" value="<?php echo admin_escape($csrf); ?>"><input type="hidden" name="code" value="<?php echo admin_escape($promo['code']); ?>"><button type="submit"><?php echo $promo['active'] ? 'Отключить' : 'Включить'; ?></button></form><form method="post" action="admin.php"><input type="hidden" name="action" value="delete_promo"><input type="hidden" name="csrf" value="<?php echo admin_escape($csrf); ?>"><input type="hidden" name="code" value="<?php echo admin_escape($promo['code']); ?>"><button class="delete" type="submit">Удалить</button></form></div></article><?php endforeach; ?></div><?php endif; ?></section>

<section class="panel orders-panel"><div class="panel-title"><div><p class="eyebrow">03 / Журнал</p><h2>Заказы</h2></div><span><?php echo count($orders); ?> записей</span></div><?php if (!$orders): ?><p class="empty">Заказов пока нет.</p><?php else: ?><div class="orders-list"><?php foreach ($orders as $order): ?><details><summary><span class="order-number">#<?php echo admin_escape($order['order_number']); ?></span><span><?php echo admin_escape($order['created_msk']); ?></span><strong><?php echo admin_escape($order['customer_name']); ?></strong><b><?php echo number_format((int) $order['total'], 0, ',', ' '); ?> ₽</b><i><?php echo admin_escape($order['status']); ?></i></summary><div class="order-body"><dl><div><dt>Контакты</dt><dd><?php echo admin_escape($order['phone']); ?><br><?php echo admin_escape($order['email']); ?></dd></div><div><dt>Доставка</dt><dd><?php echo admin_escape($order['delivery_type']); ?> · <?php echo number_format((int) $order['delivery_amount'], 0, ',', ' '); ?> ₽<br><?php echo admin_escape($order['city'] . ', ' . $order['address']); ?></dd></div><div><dt>ПВЗ СДЭК</dt><dd><?php echo $order['pvz_code'] ? admin_escape($order['pvz_code'] . ' · ' . $order['pvz_address']) : '—'; ?></dd></div><div><dt>Товар</dt><dd><?php echo admin_escape($order['product_name']); ?> × <?php echo (int) $order['quantity']; ?><br>SKU <?php echo admin_escape($order['sku']); ?></dd></div><div><dt>Промокод / скидка</dt><dd><?php echo $order['promo_code'] ? admin_escape($order['promo_code']) : '—'; ?> / <?php echo number_format((int) $order['discount_amount'], 0, ',', ' '); ?> ₽</dd></div><div><dt>Интеграции</dt><dd>1С: <?php echo admin_escape($order['onec_status']); ?> · СДЭК: <?php echo admin_escape($order['cdek_status']); ?><br>Таблица: <?php echo admin_escape($order['sheet_status']); ?> · Telegram: <?php echo admin_escape($order['telegram_status']); ?></dd></div><div class="wide"><dt>Комментарий</dt><dd><?php echo $order['comment'] ? nl2br(admin_escape($order['comment'])) : '—'; ?></dd></div></dl></div></details><?php endforeach; ?></div><?php endif; ?></section>
</main><footer>ООО «ОРИГЕЙТ» · ИНН 9728153626 · защищённая панель</footer></body></html>
