<?php

return array(
    'allowed_hosts' => array('origate-tactic.ru', 'www.origate-tactic.ru'),
    'product' => array(
        'name' => 'КСОИ «БУЛЬДОГ»',
        'sku' => 'УКАЖИТЕ-SKU',
        'price' => 55000
    ),
    'google' => array(
        'spreadsheet_id' => 'ID_ТАБЛИЦЫ_ИЗ_GOOGLE_SHEETS_URL',
        'sheet_name' => 'Заказы',
        'credentials_file' => 'google-service-account.json'
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
