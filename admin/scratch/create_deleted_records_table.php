<?php
// Migration: Create deleted_records table for Undo Deletion feature
include '../includes/db_config.php';

$sql = "CREATE TABLE IF NOT EXISTS `deleted_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'student, students_bulk, or class',
    `entity_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable name for toast message',
    `serialized_data` LONGTEXT NOT NULL COMMENT 'JSON payload of all deleted rows',
    `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

try {
    $conn->exec($sql);
    echo "✅ Table `deleted_records` created successfully (or already exists).\n";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
