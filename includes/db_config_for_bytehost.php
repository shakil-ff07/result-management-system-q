<?php
// Database Configuration
define('DB_HOST', 'sql201.byethost13.com');
define('DB_NAME', 'b13_41812829_result_management');
define('DB_USER', 'b13_41812829');
define('DB_PASS', '01716488107Ss@');

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    // Set PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>