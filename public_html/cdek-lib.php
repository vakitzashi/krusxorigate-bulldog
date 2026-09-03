<?php

function cdek_json_request($method, $url, $headers, $body)
{
    $curl = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
    } else {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    }
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException('CDEK network error: ' . $error);
    }
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $details = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : mb_substr($response, 0, 500, 'UTF-8');
        throw new RuntimeException('CDEK HTTP ' . $status . ': ' . $details);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('CDEK returned invalid JSON.');
    }
    return $decoded;
}

function cdek_access_token($config, $secretRoot)
{
    $cdek = $config['cdek'];
    $cachePath = $secretRoot . DIRECTORY_SEPARATOR . 'cdek-token-cache.json';
    $credentialHash = hash('sha256', $cdek['api_base'] . '|' . $cdek['client_id']);
    if (is_file($cachePath)) {
        $cached = json_decode(file_get_contents($cachePath), true);
        if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])
            && $cached['expires_at'] > time() + 60 && isset($cached['credential_hash'])
            && hash_equals($credentialHash, $cached['credential_hash'])) {
            return $cached['access_token'];
        }
    }

    $body = http_build_query(array(
        'grant_type' => 'client_credentials',
        'client_id' => $cdek['client_id'],
        'client_secret' => $cdek['client_secret']
    ), '', '&');
    $token = cdek_json_request(
        'POST',
        rtrim($cdek['api_base'], '/') . '/oauth/token',
        array('Content-Type: application/x-www-form-urlencoded'),
        $body
    );
    if (empty($token['access_token'])) {
        throw new RuntimeException('CDEK did not return an access token.');
    }
    $cache = array(
        'access_token' => $token['access_token'],
        'expires_at' => time() + (isset($token['expires_in']) ? (int) $token['expires_in'] : 3500),
        'credential_hash' => $credentialHash
    );
    $temporary = $cachePath . '.tmp';
    file_put_contents($temporary, json_encode($cache), LOCK_EX);
    @chmod($temporary, 0600);
    rename($temporary, $cachePath);
    return $token['access_token'];
}

function cdek_api_request($config, $secretRoot, $method, $path, $query, $payload)
{
    $token = cdek_access_token($config, $secretRoot);
    $url = rtrim($config['cdek']['api_base'], '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&');
    }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return cdek_json_request($method, $url, array(
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8'
    ), $body);
}

function cdek_geocode_address($config, $city, $address)
{
    $query = trim($city . ', ' . $address);
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(array(
        'format' => 'jsonv2',
        'countrycodes' => 'ru',
        'limit' => 1,
        'q' => $query
    ), '', '&');
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($curl, CURLOPT_TIMEOUT, 15);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Accept-Language: ru',
        'User-Agent: ' . $config['cdek']['geocoder_user_agent']
    ));
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false || $status !== 200) {
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded[0]['lat']) || empty($decoded[0]['lon'])) {
        return null;
    }
    return array('latitude' => (float) $decoded[0]['lat'], 'longitude' => (float) $decoded[0]['lon'], 'precision' => 'address');
}

function cdek_resolve_city($config, $secretRoot, $city)
{
    $cities = cdek_api_request($config, $secretRoot, 'GET', '/location/cities', array(
        'country_codes' => 'RU',
        'city' => $city,
        'size' => 20
    ), null);
    if (empty($cities)) {
        throw new RuntimeException('Город не найден в справочнике СДЭК.');
    }
    $needle = mb_strtolower(trim($city), 'UTF-8');
    foreach ($cities as $candidate) {
        if (!empty($candidate['city']) && mb_strtolower(trim($candidate['city']), 'UTF-8') === $needle) {
            return $candidate;
        }
    }
    return $cities[0];
}

function cdek_haversine($lat1, $lon1, $lat2, $lon2)
{
    $earth = 6371000;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $deltaPhi = deg2rad($lat2 - $lat1);
    $deltaLambda = deg2rad($lon2 - $lon1);
    $a = sin($deltaPhi / 2) * sin($deltaPhi / 2)
        + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) * sin($deltaLambda / 2);
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function cdek_nearest_pvz($config, $secretRoot, $cityCode, $coordinates)
{
    $points = cdek_api_request($config, $secretRoot, 'GET', '/deliverypoints', array(
        'city_code' => $cityCode,
        'type' => 'PVZ'
    ), null);
    $nearest = null;
    $nearestDistance = null;
    foreach ($points as $point) {
        if (isset($point['is_handout']) && !$point['is_handout']) continue;
        if (!empty($point['type']) && $point['type'] !== 'PVZ') continue;
        if (empty($point['location']['latitude']) || empty($point['location']['longitude'])) continue;
        $distance = cdek_haversine(
            $coordinates['latitude'], $coordinates['longitude'],
            (float) $point['location']['latitude'], (float) $point['location']['longitude']
        );
        if ($nearest === null || $distance < $nearestDistance) {
            $nearest = $point;
            $nearestDistance = $distance;
        }
    }
    if ($nearest === null) {
        throw new RuntimeException('В выбранном городе не найден доступный ПВЗ СДЭК.');
    }
    $nearest['calculated_distance_m'] = (int) round($nearestDistance);
    return $nearest;
}

function cdek_calculate_quote($config, $secretRoot, $city, $address, $deliveryType)
{
    $destination = cdek_resolve_city($config, $secretRoot, $city);
    $coordinates = cdek_geocode_address($config, $city, $address);
    if ($coordinates === null) {
        if (empty($destination['latitude']) || empty($destination['longitude'])) {
            throw new RuntimeException('Не удалось определить координаты адреса. Уточните адрес.');
        }
        $coordinates = array(
            'latitude' => (float) $destination['latitude'],
            'longitude' => (float) $destination['longitude'],
            'precision' => 'city'
        );
    }

    $pvz = null;
    if ($deliveryType === 'ПВЗ') {
        $pvz = cdek_nearest_pvz($config, $secretRoot, (int) $destination['code'], $coordinates);
    }

    $package = $config['cdek']['package'];
    $payload = array(
        'type' => 1,
        'currency' => 1,
        'lang' => 'rus',
        'from_location' => array(
            'code' => (int) $config['cdek']['origin']['city_code'],
            'city' => $config['cdek']['origin']['city'],
            'address' => $config['cdek']['origin']['address']
        ),
        'to_location' => array(
            'code' => (int) $destination['code'],
            'city' => isset($destination['city']) ? $destination['city'] : $city,
            'address' => $deliveryType === 'ПВЗ' ? $pvz['location']['address'] : $address
        ),
        'packages' => array(array(
            'weight' => (int) $package['weight_g'],
            'length' => (int) $package['length_cm'],
            'width' => (int) $package['width_cm'],
            'height' => (int) $package['height_cm']
        ))
    );
    if (!empty($config['cdek']['insurance_value'])) {
        $payload['services'] = array(array('code' => 'INSURANCE', 'parameter' => (string) (int) $config['cdek']['insurance_value']));
    }
    if ($pvz !== null) {
        $payload['delivery_point'] = $pvz['code'];
    }

    $response = cdek_api_request($config, $secretRoot, 'POST', '/calculator/tarifflist', array(), $payload);
    if (empty($response['tariff_codes'])) {
        throw new RuntimeException('СДЭК не вернул доступных тарифов для этого адреса.');
    }
    $requiredMode = $deliveryType === 'ПВЗ' ? 2 : 1;
    $best = null;
    foreach ($response['tariff_codes'] as $tariff) {
        if ((int) $tariff['delivery_mode'] !== $requiredMode) continue;
        $sum = isset($tariff['total_sum']) ? (float) $tariff['total_sum'] : (float) $tariff['delivery_sum'];
        if ($sum <= 0) continue;
        if ($best === null || $sum < $best['_sum']) {
            $tariff['_sum'] = $sum;
            $best = $tariff;
        }
    }
    if ($best === null) {
        throw new RuntimeException('СДЭК не вернул подходящего тарифа для выбранного способа доставки.');
    }

    return array(
        'city_code' => (int) $destination['code'],
        'city_name' => isset($destination['city']) ? $destination['city'] : $city,
        'latitude' => $coordinates['latitude'],
        'longitude' => $coordinates['longitude'],
        'location_precision' => $coordinates['precision'],
        'delivery_type' => $deliveryType,
        'delivery_amount' => (int) ceil($best['_sum']),
        'tariff_code' => (int) $best['tariff_code'],
        'tariff_name' => isset($best['tariff_name']) ? $best['tariff_name'] : '',
        'period_min' => isset($best['period_min']) ? (int) $best['period_min'] : 0,
        'period_max' => isset($best['period_max']) ? (int) $best['period_max'] : 0,
        'pvz_code' => $pvz !== null ? $pvz['code'] : '',
        'pvz_address' => $pvz !== null
            ? (isset($pvz['location']['address_full']) ? $pvz['location']['address_full'] : $pvz['location']['address'])
            : '',
        'pvz_distance_m' => $pvz !== null ? $pvz['calculated_distance_m'] : 0,
        'raw_response' => $response
    );
}

function cdek_random_token()
{
    $strong = false;
    $bytes = openssl_random_pseudo_bytes(24, $strong);
    if ($bytes === false || !$strong) {
        throw new RuntimeException('Unable to create a secure delivery quote token.');
    }
    return bin2hex($bytes);
}

function cdek_build_shipment_payload($config, $order)
{
    if ($order['delivery_type'] !== 'ПВЗ' || trim((string) $order['pvz_code']) === '') {
        throw new InvalidArgumentException('Production CDEK orders require a pickup point.');
    }
    $productAmount = max(0, (int) $order['subtotal'] - (int) $order['discount_amount']);
    $deliveryAmount = max(0, (int) $order['delivery_amount']);
    $package = $config['cdek']['package'];
    $payload = array(
        'type' => 1,
        'number' => $order['order_number'],
        'tariff_code' => (int) $order['cdek_tariff_code'],
        'comment' => $order['comment'],
        'shipper_name' => 'ООО «ОРИГЕЙТ»',
        'from_location' => array(
            'code' => (int) $config['cdek']['origin']['city_code'],
            'city' => $config['cdek']['origin']['city'],
            'address' => $config['cdek']['origin']['address']
        ),
        'delivery_point' => $order['pvz_code'],
        'delivery_recipient_cost' => array('value' => $deliveryAmount),
        'sender' => array(
            'company' => 'ООО «ОРИГЕЙТ»',
            'name' => 'ООО «ОРИГЕЙТ»',
            'email' => 'info@origate.com',
            'phones' => array(array('number' => '+78002502290'))
        ),
        'recipient' => array(
            'name' => $order['customer_name'],
            'email' => $order['email'],
            'phones' => array(array('number' => $order['phone']))
        ),
        'packages' => array(array(
            'number' => '1',
            'weight' => (int) $package['weight_g'],
            'length' => (int) $package['length_cm'],
            'width' => (int) $package['width_cm'],
            'height' => (int) $package['height_cm'],
            'items' => array(array(
                'name' => $order['product_name'],
                'ware_key' => $order['sku'],
                'payment' => array('value' => $productAmount),
                'cost' => $productAmount,
                'weight' => (int) $package['weight_g'],
                'amount' => 1
            ))
        ))
    );
    return $payload;
}

function cdek_create_shipment($config, $secretRoot, $order)
{
    if (empty($config['cdek']['create_shipments'])) {
        return array('created' => false, 'reason' => 'feature_flag_disabled');
    }
    $payload = cdek_build_shipment_payload($config, $order);
    $response = cdek_api_request($config, $secretRoot, 'POST', '/orders', array(), $payload);
    if (!empty($response['requests']) && is_array($response['requests'])) {
        foreach ($response['requests'] as $request) {
            if (!empty($request['errors']) || (isset($request['state']) && strtoupper((string) $request['state']) === 'INVALID')) {
                throw new RuntimeException('CDEK rejected the shipment: ' . json_encode($request, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    if (empty($response['entity']['uuid'])) {
        throw new RuntimeException('CDEK did not return a shipment UUID.');
    }
    return array(
        'created' => true,
        'uuid' => $response['entity']['uuid'],
        'cdek_number' => isset($response['entity']['cdek_number']) ? $response['entity']['cdek_number'] : '',
        'response' => $response
    );
}
