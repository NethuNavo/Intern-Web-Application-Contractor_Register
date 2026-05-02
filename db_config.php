<?php
// Simple DB config used by standalone scripts.
// Uses environment variables for deployment on Vercel or other hosts.

function get_db_connection(): mysqli {
    $isVercel = getenv('VERCEL') !== false || getenv('VERCEL_ENV') !== false;
    $host = getenv('DB_HOST') ?: ($isVercel ? null : '127.0.0.1');
    $username = getenv('DB_USER') ?: ($isVercel ? null : 'root');
    $password = getenv('DB_PASS') ?: ($isVercel ? null : '');
    $database = getenv('DB_NAME') ?: ($isVercel ? null : 'contractor_management');
    $port = intval(getenv('DB_PORT') ?: 3306);

    if ($isVercel && (!$host || !$username || !$database)) {
        die("Database connection is not configured for Vercel. Please set DB_HOST, DB_USER, DB_PASS, and DB_NAME as environment variables.");
    }

    if (!$host || !$username || !$database) {
        // Local fallback for XAMPP / default development environment
        $host = $host ?: '127.0.0.1';
        $username = $username ?: 'root';
        $password = $password ?: '';
        $database = $database ?: 'contractor_management';
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

