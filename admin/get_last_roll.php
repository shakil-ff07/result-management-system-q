<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

header('Content-Type: application/json');

$class_id = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

if (!$class_id) {
    echo json_encode(['last_roll' => null, 'count' => 0]);
    exit;
}

$stmt = $conn->prepare("
    SELECT MAX(roll_number) AS last_roll, COUNT(*) AS total
    FROM students
    WHERE class_id = ?
");
$stmt->execute([$class_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'last_roll'  => $row['last_roll'] ? (int) $row['last_roll'] : null,
    'next_roll'  => $row['last_roll'] ? (int) $row['last_roll'] + 1 : 1,
    'count'      => (int) $row['total'],
]);
