<?php
session_start();

unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);
unset($_SESSION['account_status']);

header("Location: login.php");
exit();
