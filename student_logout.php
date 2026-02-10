<?php
session_start();
unset($_SESSION['student_id']);
unset($_SESSION['student_name']);
unset($_SESSION['is_student']);
header('Location: student_login.php');
exit;
?>
