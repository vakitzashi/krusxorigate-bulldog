<?php

function onec_plain_response($body, $status)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

function onec_failure($message, $status)
{
    onec_plain_response("failure\n" . $message, $status);
}

function onec_random_token($bytes)
{
    $strong = false;
    $value = openssl_random_pseudo_bytes($bytes, $strong);
    if ($value === false || !$strong) throw new RuntimeException('Secure random generator is unavailable.');
    return bin2hex($value);
}

function onec_basic_credentials()
{
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return array((string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']);
    }
    $header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $header = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (stripos($header, 'Basic ') !== 0) return array('', '');
    $decoded = base64_decode(substr($header, 6), true);
    if ($decoded === false || strpos($decoded, ':') === false) return array('', '');
    return explode(':', $decoded, 2);
}

function onec_check_basic_auth($config)
{
    list($username, $password) = onec_basic_credentials();
    return $username !== ''
        && hash_equals((string) $config['one_c']['username'], $username)
        && hash_equals((string) $config['one_c']['password'], $password);
}

function onec_start_session($db, $config, $exchangeType)
{
    $token = onec_random_token(32);
    $hash = hash('sha256', $token);
    $ttl = !empty($config['one_c']['session_ttl']) ? (int) $config['one_c']['session_ttl'] : 3600;
    $expires = date('Y-m-d H:i:s', time() + max(300, min(86400, $ttl)));
    $statement = $db->prepare(
        'INSERT INTO onec_exchange_sessions (session_hash, exchange_type, current_batch, expires_at)
         VALUES (:hash, :type, \'\', :expires)'
    );
    $statement->execute(array(':hash' => $hash, ':type' => $exchangeType, ':expires' => $expires));
    header('Set-Cookie: ORIGATE1CSESSID=' . rawurlencode($token) . '; Path=/; Secure; HttpOnly; SameSite=Strict');
    return $token;
}

function onec_current_session($db, $exchangeType)
{
    $token = isset($_COOKIE['ORIGATE1CSESSID']) ? (string) $_COOKIE['ORIGATE1CSESSID'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $hash = hash('sha256', $token);
    $statement = $db->prepare(
        'SELECT * FROM onec_exchange_sessions
         WHERE session_hash = :hash AND exchange_type = :type AND expires_at > NOW() LIMIT 1'
    );
    $statement->execute(array(':hash' => $hash, ':type' => $exchangeType));
    $session = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$session) return null;
    $touch = $db->prepare('UPDATE onec_exchange_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE session_hash = :hash');
    $touch->execute(array(':hash' => $hash));
    return $session;
}

function onec_log($db, $type, $mode, $filename, $status, $details)
{
    $statement = $db->prepare(
        'INSERT INTO onec_exchange_log (exchange_type, mode_name, filename, status, details)
         VALUES (:type, :mode, :filename, :status, :details)'
    );
    $statement->execute(array(
        ':type' => $type, ':mode' => $mode, ':filename' => $filename,
        ':status' => $status, ':details' => mb_substr($details, 0, 4000, 'UTF-8')
    ));
}

function onec_safe_filename($filename)
{
    $filename = basename(str_replace('\\', '/', (string) $filename));
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    if ($filename === '' || !preg_match('/\.xml$/i', $filename)) {
        throw new InvalidArgumentException('Only CommerceML XML files are accepted.');
    }
    return mb_substr($filename, 0, 180, 'UTF-8');
}

function onec_upload_path($secretRoot, $session, $filename)
{
    return $secretRoot . DIRECTORY_SEPARATOR . 'onec-upload-'
        . substr($session['session_hash'], 0, 16) . '-' . onec_safe_filename($filename);
}

function onec_append_upload($path, $config)
{
    $limit = !empty($config['one_c']['file_limit']) ? (int) $config['one_c']['file_limit'] : 5242880;
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) throw new RuntimeException('Exchange chunk exceeds file_limit.');
    $existing = is_file($path) ? filesize($path) : 0;
    if ($existing + strlen($raw) > 26214400) throw new RuntimeException('Exchange file exceeds 25 MB.');
    if (file_put_contents($path, $raw, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to save the exchange file.');
    }
    @chmod($path, 0600);
}

function onec_xml_load($path)
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $xml === false ? null : $xml;
}

function onec_child_text($node, $name)
{
    $items = $node->xpath('./*[local-name()="' . $name . '"]');
    return empty($items) ? '' : trim((string) $items[0]);
}

function onec_quantity_from_offer($offer)
{
    $direct = $offer->xpath('./*[local-name()="Количество"]');
    if (!empty($direct)) return max(0, (float) str_replace(',', '.', (string) $direct[0]));
    $paths = array(
        './*[local-name()="Склады"]/*[local-name()="Склад"]/*[local-name()="Количество"]',
        './*[local-name()="ОстаткиПоСкладу"]/*[local-name()="КоличествоНаСкладе"]',
        './*[local-name()="Остатки"]/*[local-name()="Остаток"]/*[local-name()="Количество"]'
    );
    foreach ($paths as $path) {
        $nodes = $offer->xpath($path);
        if (empty($nodes)) continue;
        $sum = 0.0;
        foreach ($nodes as $node) $sum += max(0, (float) str_replace(',', '.', (string) $node));
        return $sum;
    }
    return null;
}

function onec_external_id_sku($db, $externalId)
{
    if ($externalId === '') return '';
    $ids = array($externalId);
    if (strpos($externalId, '#') !== false) $ids[] = explode('#', $externalId, 2)[0];
    foreach ($ids as $id) {
        $statement = $db->prepare('SELECT sku FROM onec_product_map WHERE external_id = :id LIMIT 1');
        $statement->execute(array(':id' => $id));
        $sku = $statement->fetchColumn();
        if ($sku !== false) return (string) $sku;
    }
    return '';
}

function onec_import_catalog($db, $config, $path, $filename)
{
    $xml = onec_xml_load($path);
    if ($xml === null) throw new RuntimeException('CommerceML XML is incomplete or invalid.');
    $targetSku = (string) $config['product']['sku'];
    $mapped = 0;
    $products = $xml->xpath('//*[local-name()="Каталог"]//*[local-name()="Товар"]');
    foreach ($products as $product) {
        $externalId = onec_child_text($product, 'Ид');
        $article = onec_child_text($product, 'Артикул');
        if ($externalId === '' || $article !== $targetSku) continue;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $statement = $db->prepare(
                'INSERT INTO onec_product_map (external_id, sku) VALUES (:id, :sku)
                 ON DUPLICATE KEY UPDATE sku = VALUES(sku)'
            );
        } else {
            $statement = $db->prepare(
                'INSERT OR REPLACE INTO onec_product_map (external_id, sku, updated_at) VALUES (:id, :sku, :updated_at)'
            );
        }
        $parameters = array(':id' => $externalId, ':sku' => $targetSku);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') $parameters[':updated_at'] = date('Y-m-d H:i:s');
        $statement->execute($parameters);
        $mapped++;
    }

    $totals = array();
    $offers = $xml->xpath('//*[local-name()="Предложение"]');
    foreach ($offers as $offer) {
        $externalId = onec_child_text($offer, 'Ид');
        $article = onec_child_text($offer, 'Артикул');
        $sku = $article !== '' ? $article : onec_external_id_sku($db, $externalId);
        if ($sku === '' && ($externalId === $targetSku || strpos($externalId, $targetSku . '#') === 0)) $sku = $targetSku;
        if ($sku !== $targetSku) continue;
        $quantity = onec_quantity_from_offer($offer);
        if ($quantity === null) continue;
        if (!isset($totals[$sku])) $totals[$sku] = 0.0;
        $totals[$sku] += $quantity;
    }

    if (!empty($totals)) {
        $db->beginTransaction();
        try {
            foreach ($totals as $sku => $quantity) {
                ensure_inventory_row($db, $sku);
                inventory_row($db, $sku, true);
                $transfer = $db->prepare(
                    'UPDATE inventory_reservations SET status = \'transferred\', transferred_at = :now
                     WHERE sku = :sku AND status = \'acknowledged\''
                );
                $transfer->execute(array(':sku' => $sku, ':now' => date('Y-m-d H:i:s')));
                $held = $db->prepare(
                    'SELECT COALESCE(SUM(quantity), 0) FROM inventory_reservations WHERE sku = :sku AND status = \'local\''
                );
                $held->execute(array(':sku' => $sku));
                $siteReserved = (int) $held->fetchColumn();
                $update = $db->prepare(
                    'UPDATE product_inventory SET quantity = :quantity, site_reserved = :reserved,
                     sync_state = \'synced\', source_file = :file, source_hash = :hash, synced_at = :synced_at
                     WHERE sku = :sku'
                );
                $update->execute(array(
                    ':quantity' => (int) floor($quantity), ':reserved' => $siteReserved,
                    ':file' => $filename, ':hash' => hash_file('sha256', $path),
                    ':synced_at' => date('Y-m-d H:i:s'), ':sku' => $sku
                ));
            }
            $db->commit();
        } catch (Exception $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }
    return array('mapped' => $mapped, 'stock_updates' => count($totals));
}

function onec_xml_add($document, $parent, $name, $value)
{
    $element = $document->createElement($name);
    if ($value !== null) $element->appendChild($document->createTextNode((string) $value));
    $parent->appendChild($element);
    return $element;
}

function onec_order_destination($order)
{
    if ($order['delivery_type'] === 'ПВЗ' && $order['pvz_address'] !== '') {
        return $order['pvz_address'] . ' (ПВЗ ' . $order['pvz_code'] . ')';
    }
    return $order['address'];
}

function onec_add_requisite($document, $parent, $name, $value)
{
    $item = onec_xml_add($document, $parent, 'ЗначениеРеквизита', null);
    onec_xml_add($document, $item, 'Наименование', $name);
    onec_xml_add($document, $item, 'Значение', $value);
}

function onec_add_order_product($document, $products, $id, $sku, $name, $unitPrice, $quantity, $type)
{
    $product = onec_xml_add($document, $products, 'Товар', null);
    onec_xml_add($document, $product, 'Ид', $id);
    if ($sku !== '') onec_xml_add($document, $product, 'Артикул', $sku);
    onec_xml_add($document, $product, 'Наименование', $name);
    $unit = onec_xml_add($document, $product, 'БазоваяЕдиница', 'шт');
    $unit->setAttribute('Код', '796');
    $unit->setAttribute('НаименованиеПолное', 'Штука');
    $unit->setAttribute('МеждународноеСокращение', 'PCE');
    onec_xml_add($document, $product, 'ЦенаЗаЕдиницу', number_format($unitPrice, 2, '.', ''));
    onec_xml_add($document, $product, 'Количество', number_format($quantity, 2, '.', ''));
    onec_xml_add($document, $product, 'Сумма', number_format($unitPrice * $quantity, 2, '.', ''));
    $requisites = onec_xml_add($document, $product, 'ЗначенияРеквизитов', null);
    onec_add_requisite($document, $requisites, 'ВидНоменклатуры', $type);
    onec_add_requisite($document, $requisites, 'ТипНоменклатуры', $type);
}

function onec_orders_xml($db, $config, $session)
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->createElement('КоммерческаяИнформация');
    $root->setAttribute('ВерсияСхемы', '2.10');
    $root->setAttribute('ДатаФормирования', date('Y-m-d\TH:i:s'));
    $document->appendChild($root);

    if (empty($config['one_c']['export_orders'])) return $document->saveXML();

    $batch = $session['current_batch'];
    $db->beginTransaction();
    try {
        if ($batch === '') {
            $batch = onec_random_token(16);
            $select = $db->query(
                "SELECT id FROM orders WHERE onec_status = 'pending' ORDER BY id ASC LIMIT 100 FOR UPDATE"
            );
            $ids = $select->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $db->prepare(
                    "UPDATE orders SET onec_status = 'sent', onec_export_batch = ?, onec_exported_at = NOW()
                     WHERE id IN (" . $placeholders . ")"
                );
                $update->execute(array_merge(array($batch), $ids));
                $sessionUpdate = $db->prepare(
                    'UPDATE onec_exchange_sessions SET current_batch = :batch WHERE session_hash = :hash'
                );
                $sessionUpdate->execute(array(':batch' => $batch, ':hash' => $session['session_hash']));
            } else {
                $batch = '';
            }
        }
        $db->commit();
    } catch (Exception $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }

    if ($batch === '') return $document->saveXML();
    $statement = $db->prepare(
        'SELECT o.*, COALESCE(
            (SELECT m.external_id FROM onec_product_map m WHERE m.sku = o.sku ORDER BY m.updated_at DESC LIMIT 1),
            o.sku
         ) AS onec_product_id
         FROM orders o WHERE o.onec_export_batch = :batch AND o.onec_status = \'sent\' ORDER BY o.id ASC'
    );
    $statement->execute(array(':batch' => $batch));
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $node = onec_xml_add($document, $root, 'Документ', null);
        onec_xml_add($document, $node, 'Ид', 'ORIGATE-' . $order['order_number']);
        onec_xml_add($document, $node, 'Номер', $order['order_number']);
        onec_xml_add($document, $node, 'Дата', substr($order['created_at'], 0, 10));
        onec_xml_add($document, $node, 'ХозОперация', 'Заказ товара');
        onec_xml_add($document, $node, 'Роль', 'Продавец');
        onec_xml_add($document, $node, 'Валюта', 'RUB');
        onec_xml_add($document, $node, 'Курс', '1');
        onec_xml_add($document, $node, 'Сумма', number_format((int) $order['total'], 2, '.', ''));
        $parties = onec_xml_add($document, $node, 'Контрагенты', null);
        $party = onec_xml_add($document, $parties, 'Контрагент', null);
        onec_xml_add($document, $party, 'Ид', 'SITE-CUSTOMER-' . hash('sha256', $order['phone'] . '|' . $order['email']));
        onec_xml_add($document, $party, 'Наименование', $order['customer_name']);
        onec_xml_add($document, $party, 'Роль', 'Покупатель');
        onec_xml_add($document, $party, 'ПолноеНаименование', $order['customer_name']);
        $address = onec_xml_add($document, $party, 'АдресРегистрации', null);
        onec_xml_add($document, $address, 'Представление', onec_order_destination($order));
        $contacts = onec_xml_add($document, $party, 'Контакты', null);
        $phone = onec_xml_add($document, $contacts, 'Контакт', null);
        onec_xml_add($document, $phone, 'Тип', 'Телефон мобильный');
        onec_xml_add($document, $phone, 'Значение', $order['phone']);
        $email = onec_xml_add($document, $contacts, 'Контакт', null);
        onec_xml_add($document, $email, 'Тип', 'Почта');
        onec_xml_add($document, $email, 'Значение', $order['email']);
        onec_xml_add($document, $node, 'Время', substr($order['created_at'], 11, 8));
        onec_xml_add($document, $node, 'Комментарий', $order['comment']);
        $products = onec_xml_add($document, $node, 'Товары', null);
        $productAmount = max(0, (int) $order['subtotal'] - (int) $order['discount_amount']);
        onec_add_order_product(
            $document, $products, $order['onec_product_id'], $order['sku'], $order['product_name'],
            $productAmount, (int) $order['quantity'], 'Товар'
        );
        if ((int) $order['delivery_amount'] > 0) {
            onec_add_order_product(
                $document, $products, 'ORDER_DELIVERY', '', 'Доставка СДЭК',
                (int) $order['delivery_amount'], 1, 'Услуга'
            );
        }
        $requisites = onec_xml_add($document, $node, 'ЗначенияРеквизитов', null);
        onec_add_requisite($document, $requisites, 'Статус заказа', '[N] Новый');
        onec_add_requisite($document, $requisites, 'Заказ с сайта', 'true');
        onec_add_requisite($document, $requisites, 'Резервировать товар', 'true');
        onec_add_requisite($document, $requisites, 'Способ резервирования', 'Резервировать на складе');
        onec_add_requisite($document, $requisites, 'Способ доставки', $order['delivery_type'] . ' СДЭК');
        onec_add_requisite($document, $requisites, 'Адрес доставки', onec_order_destination($order));
        onec_add_requisite($document, $requisites, 'Промокод', $order['promo_code']);
    }
    return $document->saveXML();
}

function onec_acknowledge_batch($db, $session)
{
    $batch = $session['current_batch'];
    if ($batch === '') return 0;
    $db->beginTransaction();
    try {
        $orders = $db->prepare(
            "UPDATE orders SET onec_status = 'acknowledged', onec_acknowledged_at = NOW()
             WHERE onec_export_batch = :batch AND onec_status = 'sent'"
        );
        $orders->execute(array(':batch' => $batch));
        $reservations = $db->prepare(
            "UPDATE inventory_reservations r INNER JOIN orders o ON o.id = r.order_id
             SET r.status = 'acknowledged', r.acknowledged_at = NOW()
             WHERE o.onec_export_batch = :batch AND r.status = 'local'"
        );
        $reservations->execute(array(':batch' => $batch));
        $clear = $db->prepare('UPDATE onec_exchange_sessions SET current_batch = \'\' WHERE session_hash = :hash');
        $clear->execute(array(':hash' => $session['session_hash']));
        $db->commit();
        return $orders->rowCount();
    } catch (Exception $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function onec_import_order_statuses($db, $path)
{
    $xml = onec_xml_load($path);
    if ($xml === null) return 0;
    $count = 0;
    $documents = $xml->xpath('//*[local-name()="Документ"]');
    foreach ($documents as $document) {
        $number = onec_child_text($document, 'Номер');
        $id = onec_child_text($document, 'Ид');
        if ($number === '' && preg_match('/ORIGATE-([0-9]+)/', $id, $matches)) $number = $matches[1];
        if ($number === '') continue;
        $status = '';
        $cancelled = false;
        $requisites = $document->xpath('./*[local-name()="ЗначенияРеквизитов"]/*[local-name()="ЗначениеРеквизита"]');
        foreach ($requisites as $requisite) {
            $name = onec_child_text($requisite, 'Наименование');
            $value = onec_child_text($requisite, 'Значение');
            if ($name === 'Статус заказа') $status = $value;
            if ($name === 'Отменен' && in_array(mb_strtolower($value, 'UTF-8'), array('true', 'да', '1'), true)) $cancelled = true;
        }
        if ($cancelled) $status = 'Отменён';
        if ($status === '') continue;
        $update = $db->prepare('UPDATE orders SET status = :status WHERE order_number = :number');
        $update->execute(array(':status' => $status, ':number' => str_pad($number, 6, '0', STR_PAD_LEFT)));
        if ($cancelled && $update->rowCount() > 0) {
            $release = $db->prepare(
                "UPDATE inventory_reservations r INNER JOIN orders o ON o.id = r.order_id
                 INNER JOIN product_inventory i ON i.sku = r.sku
                 SET i.site_reserved = GREATEST(0, i.site_reserved - r.quantity),
                     r.status = 'released', r.released_at = NOW()
                 WHERE o.order_number = :number AND r.status IN ('local', 'acknowledged')"
            );
            $release->execute(array(':number' => str_pad($number, 6, '0', STR_PAD_LEFT)));
        }
        $count += $update->rowCount();
    }
    return $count;
}
