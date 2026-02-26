<?php
/**
 * config.local.example.php — Local configuration template
 *
 * Copy this file to config.local.php and fill in your values.
 * config.local.php is excluded from version control via .gitignore.
 *
 * Generate a new encryption key with:
 *   php -r "echo bin2hex(random_bytes(32));"
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'martial_arts_app');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// 64-char hex string = 256-bit AES key for payment data encryption.
// If you lose this key, stored payment tokens become unrecoverable.
define('ENCRYPTION_KEY', 'REPLACE_WITH_OUTPUT_OF_bin2hex_random_bytes_32');
