<?php
// Simple DB config used by standalone scripts.
// Matches the settings in index.php.

function get_db_connection(): mysqli {
    $host = "localhost";
    $username = "root";
    $password = "";
    $database = "contractor_management";

    $conn = new mysqli($host, $username, $password);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Create/select database to mirror index.php behavior.
    $conn->query("CREATE DATABASE IF NOT EXISTS `$database`");
    $conn->select_db($database);

    // Set timezone to Sri Lanka (UTC+5:30) like index.php
    $conn->query("SET time_zone = '+05:30'");

    return $conn;
}

