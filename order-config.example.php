<?php

return array(
    'allowed_hosts' => array('origate-tactic.ru', 'www.origate-tactic.ru'),
    'product' => array(
        'name' => 'Револьвер Бульдог KURS кал.5.6/16 КСОИ',
        'sku' => '00000050201',
        'price' => 55000
    ),
    'database' => array(
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ИМЯ_БАЗЫ',
        'user' => 'ИМЯ_ПОЛЬЗОВАТЕЛЯ',
        'password' => 'ПАРОЛЬ_БАЗЫ'
    ),
    'cdek' => array(
        'api_base' => 'https://api.cdek.ru/v2',
        'client_id' => 'ИДЕНТИФИКАТОР_API_СДЭК',
        'client_secret' => 'ПАРОЛЬ_API_СДЭК',
        'create_shipments' => false,
        'origin' => array(
            'city_code' => 44,
            'city' => 'Москва',
            'address' => 'ул. Бутлерова, 14к2'
        ),
        'package' => array('weight_g' => 1480, 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 10),
        'insurance_value' => 55000,
        'geocoder_user_agent' => 'ORIGATE-Tactic/1.0 (info@origate.com)'
    ),
    'one_c' => array(
        'username' => 'ЛОГИН_ОБМЕНА_1С',
        'password' => 'ДЛИННЫЙ_СЛУЧАЙНЫЙ_ПАРОЛЬ_ОБМЕНА_1С',
        'export_orders' => false,
        'file_limit' => 5242880,
        'session_ttl' => 3600
    ),
    'google' => array(
        'webhook_url' => 'https://script.google.com/macros/s/DEPLOYMENT_ID/exec',
        'webhook_secret' => 'ОТДЕЛЬНЫЙ_ДЛИННЫЙ_СЛУЧАЙНЫЙ_КЛЮЧ'
    ),
    'telegram' => array(
        'bot_token' => 'ТОКЕН_БОТА_ОТ_BOTFATHER',
        'chat_id' => '-1003925474353'
    ),
    'promo_codes' => array(
        // 'BULLDOG10' => array('percent' => 10),
        // 'SALE5000' => array('amount' => 5000)
    ),
    'admin' => array(
        'username' => 'Admin',
        'password_hash' => 'BCRYPT_HASH',
        'session_ttl' => 28800
    )
);
