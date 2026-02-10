<?php
/**
 * MartialArtsApp - Main Configuration
 *
 * Database credentials and application settings.
 * Copy this file to config.php and update the values for your environment.
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'martial_arts_app');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Application paths
define('BASE_URL', '/MartialArtsApp');
define('APP_ROOT', __DIR__);
