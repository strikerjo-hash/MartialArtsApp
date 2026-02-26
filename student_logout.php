<?php
/**
 * student_logout.php — Student Logout
 *
 * Uses the proper logout() function from auth.php which also
 * records the logout in the audit log before clearing the session.
 */
require_once 'config.php';

// audit_log is called inside logout() before session is cleared
logout();

header('Location: login.php');
exit;
