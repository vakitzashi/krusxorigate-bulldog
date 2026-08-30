<?php

define('ORDER_API_LIBRARY_ONLY', true);
require_once __DIR__ . '/order.php';
require_once __DIR__ . '/onec-lib.php';

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Moscow');

$secretRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secret';
$configPath = getenv('ORDER_CONFIG_PATH');
if (!$configPath) $configPath = $secretRoot . DIRECTORY_SEPARATOR . 'order-config.php';

try {
    $config = require_config($configPath);
    $db = open_database($config['database']);
    $type = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : '';
    $mode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';
    if (!in_array($type, array('catalog', 'sale'), true)) onec_failure('Unsupported exchange type.', 400);

    if ($mode === 'checkauth') {
        if (!onec_check_basic_auth($config)) {
            header('WWW-Authenticate: Basic realm="ORIGATE 1C CommerceML"');
            onec_failure('Authentication failed.', 401);
        }
        $token = onec_start_session($db, $config, $type);
        onec_log($db, $type, $mode, '', 'success', 'Exchange session created.');
        onec_plain_response("success\nORIGATE1CSESSID\n" . $token, 200);
    }

    $session = onec_current_session($db, $type);
    if (!$session) onec_failure('Exchange session is missing or expired. Run checkauth again.', 401);

    if ($mode === 'init') {
        $limit = !empty($config['one_c']['file_limit']) ? (int) $config['one_c']['file_limit'] : 5242880;
        onec_log($db, $type, $mode, '', 'success', 'zip=no; file_limit=' . $limit);
        onec_plain_response("zip=no\nfile_limit=" . $limit, 200);
    }

    if ($mode === 'file') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') onec_failure('The file mode requires POST.', 405);
        $filename = isset($_GET['filename']) ? onec_safe_filename($_GET['filename']) : '';
        if ($filename === '') onec_failure('Filename is required.', 400);
        $path = onec_upload_path($secretRoot, $session, $filename);
        onec_append_upload($path, $config);
        $details = 'Chunk accepted; current bytes=' . filesize($path);
        if ($type === 'sale' && onec_xml_load($path) !== null) {
            $updates = onec_import_order_statuses($db, $path);
            $details .= '; status_updates=' . $updates;
            @unlink($path);
        }
        onec_log($db, $type, $mode, $filename, 'success', $details);
        onec_plain_response('success', 200);
    }

    if ($type === 'catalog' && $mode === 'import') {
        $filename = isset($_GET['filename']) ? onec_safe_filename($_GET['filename']) : '';
        if ($filename === '') onec_failure('Filename is required.', 400);
        $path = onec_upload_path($secretRoot, $session, $filename);
        if (!is_file($path)) onec_failure('Uploaded file was not found.', 404);
        $result = onec_import_catalog($db, $config, $path, $filename);
        onec_log(
            $db, $type, $mode, $filename, 'success',
            'product_mappings=' . $result['mapped'] . '; stock_updates=' . $result['stock_updates']
        );
        @unlink($path);
        onec_plain_response('success', 200);
    }

    if ($type === 'sale' && $mode === 'query') {
        $xml = onec_orders_xml($db, $config, $session);
        onec_log(
            $db, $type, $mode, '', 'success',
            empty($config['one_c']['export_orders']) ? 'Order export is disabled by feature flag.' : 'Order batch returned.'
        );
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: no-store');
        echo $xml;
        exit;
    }

    if ($type === 'sale' && $mode === 'success') {
        $count = empty($config['one_c']['export_orders']) ? 0 : onec_acknowledge_batch($db, $session);
        onec_log($db, $type, $mode, '', 'success', 'Acknowledged orders=' . $count);
        onec_plain_response('success', 200);
    }

    if ($type === 'sale' && $mode === 'import') {
        $filename = isset($_GET['filename']) ? onec_safe_filename($_GET['filename']) : '';
        if ($filename === '') onec_failure('Filename is required.', 400);
        $path = onec_upload_path($secretRoot, $session, $filename);
        if (!is_file($path)) onec_failure('Uploaded file was not found.', 404);
        $updates = onec_import_order_statuses($db, $path);
        onec_log($db, $type, $mode, $filename, 'success', 'Status updates=' . $updates);
        @unlink($path);
        onec_plain_response('success', 200);
    }

    onec_failure('Unsupported exchange mode.', 400);
} catch (Exception $exception) {
    error_log('1C exchange error: ' . $exception->getMessage());
    if (isset($db) && $db instanceof PDO && isset($type, $mode)) {
        try {
            onec_log($db, $type, $mode, isset($filename) ? $filename : '', 'failure', $exception->getMessage());
        } catch (Exception $ignored) {
        }
    }
    onec_failure('Server exchange error. See server log.', 500);
}

