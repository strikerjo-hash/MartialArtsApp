<?php
session_start();
unset($_SESSION['student_id']);
unset($_SESSION['student_name']);
unset($_SESSION['is_student']);
unset($_SESSION['user_type']);
unset($_SESSION['role']);
session_destroy();
header('Location: login.php?type=student');
exit;
?>
