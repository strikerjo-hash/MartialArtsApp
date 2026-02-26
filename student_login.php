<?php
/**
 * student_login.php — Redirects to the main login page.
 *
 * The legacy email+DOB login has been removed for security reasons.
 * All student authentication now goes through login.php which requires
 * a proper password.
 */
header('Location: login.php');
exit;
