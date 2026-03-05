<?php
/**
 * parent_logout.php — Parent Logout
 *
 * If the admin is impersonating a parent, this ends the impersonation
 * and returns them to the admin dashboard instead of destroying the session.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/impersonation.php';

if (is_impersonating()) {
    stop_impersonation();
    header('Location: index.php');
    exit;
}

logout();
header('Location: login.php');
exit;
