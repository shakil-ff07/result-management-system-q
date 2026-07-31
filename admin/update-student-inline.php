<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Student ID is missing.']);
    exit;
}

$roll_number = trim($_POST['roll_number'] ?? '');
$name = trim($_POST['name'] ?? '');
$father_name = trim($_POST['father_name'] ?? '');
$mother_name = trim($_POST['mother_name'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$optional_subject_id_raw = $_POST['optional_subject_id'] ?? null;

if ($roll_number === '' || $name === '' || $dob === '') {
    echo json_encode(['success' => false, 'message' => 'Roll number, name, and date of birth are required.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, optional_subject_id, phone FROM students WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

if ($optional_subject_id_raw === '') {
    $optional_subject_id = null;
} elseif ($optional_subject_id_raw !== null) {
    $optional_subject_id = (int) $optional_subject_id_raw;
} else {
    $optional_subject_id = $existing['optional_subject_id'];
}

$phone = $phone === '' ? null : $phone;

try {
    $stmt = $conn->prepare("UPDATE students SET roll_number = ?, name = ?, father_name = ?, mother_name = ?, dob = ?, phone = ?, optional_subject_id = ? WHERE id = ?");
    $stmt->execute([$roll_number, $name, $father_name, $mother_name, $dob, $phone, $optional_subject_id, $id]);

    $stmt = $conn->prepare("SELECT s.id, s.roll_number, s.name, s.father_name, s.mother_name, s.dob, s.phone, opt_sub.subject_name AS optional_subject_name FROM students s LEFT JOIN subjects opt_sub ON s.optional_subject_id = opt_sub.id WHERE s.id = ?");
    $stmt->execute([$id]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'student_id' => (int) $id,
        'student' => [
            'roll_number' => (string) $updated['roll_number'],
            'name' => (string) $updated['name'],
            'father_name' => (string) $updated['father_name'],
            'mother_name' => (string) $updated['mother_name'],
            'dob_display' => $updated['dob'] ? date('d-m-Y', strtotime($updated['dob'])) : '—',
            'phone_display' => $updated['phone'] ? (string) $updated['phone'] : '—',
            'optional_subject_name' => $updated['optional_subject_name'] ? html_entity_decode($updated['optional_subject_name'], ENT_QUOTES, 'UTF-8') : '—',
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save the student details. Please try again.'
    ]);
}
