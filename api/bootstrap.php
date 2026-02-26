<?php
/**
 * api/bootstrap.php — Lightweight API bootstrap
 *
 * Defines API_MODE before loading config.php so that:
 *   - Sessions are NOT started (API is stateless / JWT-based)
 *   - Inline DDL migrations are NOT run (only web pages run those)
 *
 * After this file runs, ALL existing helper functions are available:
 *   get_db(), school_where(), school_param(), current_school_id(),
 *   audit_log(), app_log(), getSetting(), saveSetting(), sanitizeInput(), etc.
 */

define('API_MODE', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/middleware.php';

// API tables (api_tokens, device_tokens) are created by migrate.php
