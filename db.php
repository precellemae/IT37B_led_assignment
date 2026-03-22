<?php
$host = "localhost";
$user = "root";
$pass = "";           // default XAMPP has no password
$db   = "led_dashboard";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die(json_encode(["error" => "DB connection failed: " . $conn->connect_error]));
}