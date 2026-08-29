<?php

return array(
    'allowed_hosts' => array('origate-tactic.ru', 'www.origate-tactic.ru'),
    'product' => array(
        'name' => 'Револьвер Бульдог KURS кал.5.6/16 КСОИ',
        'sku' => '00-00002209',
        'price' => 55000
    ),
    'database' => array(
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ИМЯ_БАЗЫ',
        'user' => 'ИМЯ_ПОЛЬЗОВАТЕЛЯ',
        'password' => 'ПАРОЛЬ_БАЗЫ'
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
    )
);
