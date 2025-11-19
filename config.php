<?php
// Basic configuration for Support System / ប្រព័ន្ធជំនួយគាំទ្រ
// Edit these values to match your Laragon (or XAMPP/WAMP) environment.
if (!defined('APP_2FA_ENCRYPTION_KEY')) {
    define('APP_2FA_ENCRYPTION_KEY', 'f4a3c1b0d6e8a2f5c7b4a9d1e0f3b6c8');
}
return [
  'app' => [
    'name_en' => 'Support System',
    'name_km' => 'ប្រព័ន្ធជំនួយគាំទ្រ',
    // Used in <title> and elsewhere
    'default_lang' => 'km', // 'km' or 'en'
    'base_url' => 'http://localhost/support-system/public' // Adjust to your local virtual host, e.g. http://support-system.test
  ],
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'support-system',
    'user' => 'root',
    'pass' => ''
  ]
];
