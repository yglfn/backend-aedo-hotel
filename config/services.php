<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Payment Gateway — provider aktif saat ini: simulator (sandbox lokal)
    // Ganti value PG_PROVIDER di .env menjadi 'midtrans' setelah kunci sandbox diisi
    'payment_gateway' => [
        'provider' => env('PG_PROVIDER', 'simulator'),
    ],

    'pg_simulator' => [
        'secret' => env('PG_SIMULATOR_SECRET', 'simulator-secret-key'),
    ],

    /*
    | Midtrans (Snap API) — https://docs.midtrans.com
    | Midtrans memisahkan host (sudah diverifikasi langsung ke sandbox):
    |   Snap / halaman bayar : https://app.sandbox.midtrans.com  (prod: app.midtrans.com)
    |   Cek status transaksi : https://api.sandbox.midtrans.com  (prod: api.midtrans.com)
    | signature_key boleh dikosongkan: Midtrans menghitung notifikasi dengan
    | SHA512(order_id + status_code + gross_amount + server_key).
    */
    'midtrans' => [
        'server_key'    => env('PG_MIDTRANS_SERVER_KEY'),
        'client_key'    => env('PG_MIDTRANS_CLIENT_KEY'),
        'signature_key' => env('PG_MIDTRANS_SIGNATURE_KEY'),
        'is_production' => filter_var(env('PG_MIDTRANS_IS_PRODUCTION', 'false'), FILTER_VALIDATE_BOOLEAN),
        // Halaman hasil pembayaran di frontend. Dikirim sebagai callbacks.finish supaya Midtrans
        // tidak lagi memakai default "Finish Redirect URL" di dashboard (https://example.com).
        // Kosong = tidak mengirim callbacks.
        'finish_url'    => env('PG_MIDTRANS_FINISH_URL') ?: (env('FRONTEND_URL')
            ? rtrim((string) env('FRONTEND_URL'), '/') . '/payment-result'
            : null),
    ],

];
