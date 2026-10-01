<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Драйвер отправки SMS
    |--------------------------------------------------------------------------
    |
    | 'log'      — ничего не отправляет, пишет сообщение в лог. Локальная разработка.
    | 'stub'     — ничего не отправляет, используется демо-режимом OTP.
    | 'tcell'    — боевой шлюз Tcell (TcellSmsGateway), настройки в 'tcell' ниже.
    | 'provider' — заготовка под другого провайдера (ProviderSmsGateway),
    |              дописать нужно только тело метода sendRequest().
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    'sender_name' => env('SMS_SENDER_NAME', 'Garage'),

    'provider' => [
        'url'     => env('SMS_API_URL'),
        'key'     => env('SMS_API_KEY'),
        'timeout' => (int) env('SMS_TIMEOUT', 10),
        'retries' => (int) env('SMS_RETRIES', 2),
    ],

    'tcell' => [
        'url'     => env('TCELL_SMS_URL'),
        'api_key' => env('TCELL_SMS_API_KEY'),
        // Альфа-имя, зарегистрированное в Tcell
        'sender'  => env('TCELL_SMS_SENDER', 'Gram'),
        'timeout' => (int) env('TCELL_SMS_TIMEOUT', 10),
    ],

    'templates' => [
        'otp'          => 'Код подтверждения: :code. Никому его не сообщайте.',
        'credentials'  => 'Ваши данные для входа: логин :login, пароль :password',
        'approved'     => 'Ваше объявление «:title» опубликовано.',
        'rejected'     => 'Объявление «:title» отклонено. Причина: :reason',
        'application'  => 'Новая заявка на «:title». Телефон: :phone',
    ],

];
