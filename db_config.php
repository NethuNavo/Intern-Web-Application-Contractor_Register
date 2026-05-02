<?php
// Simple DB config used by standalone scripts.
// Uses environment variables for deployment on Vercel or other hosts.

function get_db_connection(): mysqli {
    $host = getenv('DB_HOST');
    $username = getenv('DB_USER');
    $password = getenv('DB_PASS');
    $database = getenv('DB_NAME');
    $port = intval(getenv('DB_PORT') ?: 3306);

    if (!$host || !$username || !$database) {
        die("Database connection is not configured. Please set DB_HOST, DB_USER, DB_PASS, and DB_NAME as environment variables.");
    }

    if ($host === 'localhost') {
        $host = '127.0.0.1';
    }

    $conn = new mysqli($host, $username, $password, '', $port);
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

