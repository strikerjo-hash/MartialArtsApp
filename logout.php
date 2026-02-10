<?php
/**
 * logout.php — Destroy session and redirect to login.
 */

require_once __DIR__ . '/includes/auth.php';

logout();

header('Location: login.php');
exit;
