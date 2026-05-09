<?php
$host = "127.0.0.1"; // force TCP
$user = "root";
$pass = "";
$db   = "pricing_system";
$port = 3307;

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);

if (!mysqli_real_connect($conn, $host, $user, $pass, $db, $port)) {
    die("❌ DB Connection Failed: " . mysqli_connect_error());
}
