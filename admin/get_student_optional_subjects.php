<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

header('Content-Type: application/json');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT s.id, s.class_id, s.student_group, s.optional_subject_id, sub.subject_name AS current_subject_name FROM students s LEFT JOIN subjects sub ON s.optional_subject_id = sub.id WHERE s.id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student not found.']);
    exit;
}

$class_id = (int) $student['class_id'];
$group = $student['student_group'] ?? 'None';

$subjectOptions = [];
$subjectOptions[] = ['id' => '', 'name' => 'None'];

if ($class_id) {
    // Dynamically resolve selective subject IDs by name
    $selective_names = [];
    if ($group === 'Science') {
        $selective_names = ['Biology', 'Higher Mathematics'];
    } elseif ($group === 'Arts') {
        $selective_names = ['Geography & Environment', 'Economics'];
    }

    $selective_ids = [];
    if (!empty($selective_names)) {
        $pl = implode(',', array_fill(0, count($selective_names), '?'));
        $id_stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name IN ($pl)");
        $id_stmt->execute($selective_names);
        $selective_ids = $id_stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $id_clause = '';
    if (!empty($selective_ids)) {
        $id_clause = 'OR s.id IN (' . implode(',', $selective_ids) . ')';
    }

    // Determine whether this is a lower secondary class with school-based optional subjects
    $class_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_stmt->execute([$class_id]);
    $class_row = $class_stmt->fetch(PDO::FETCH_ASSOC);
    $class_name = strtolower(trim($class_row['class_name'] ?? ''));
    $is_lower_class = (strpos($class_name, 'class 6') !== false || strpos($class_name, 'class 7') !== false || strpos($class_name, 'class 8') !== false);

    $rows = [];
    if ($is_lower_class) {
        $lower_optional_names = [
            'Work and Life-Oriented Education',
            'Physical Education & Health',
            'Arts & Crafts',
            'Agriculture Studies / Home Science'
        ];
        $pl = implode(',', array_fill(0, count($lower_optional_names), '?'));
        $stmt_opt = $conn->prepare("SELECT s.id, s.subject_name
                                FROM subjects s
                                JOIN class_subjects cs ON s.id = cs.subject_id
                                WHERE cs.class_id = ?
                                  AND (cs.is_optional = 1 OR s.subject_name = 'Agriculture Studies / Home Science')
                                  AND s.subject_name IN ($pl)
                                ORDER BY FIELD(s.subject_name, 'Work and Life-Oriented Education', 'Physical Education & Health', 'Arts & Crafts', 'Agriculture Studies / Home Science') ASC");
        $stmt_opt->execute(array_merge([$class_id], $lower_optional_names));
        $rows = $stmt_opt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($rows)) {
        $stmt_opt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name
                                FROM subjects s
                                JOIN class_subjects cs ON s.id = cs.subject_id
                                WHERE cs.class_id = ?
                                  AND (cs.student_group = ? OR cs.student_group = 'None')
                                  AND (cs.is_optional = 1 $id_clause)
                                ORDER BY s.subject_name ASC");
        $stmt_opt->execute([$class_id, $group]);
        $rows = $stmt_opt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $row) {
        $subjectOptions[] = ['id' => (string) $row['id'], 'name' => $row['subject_name']];
    }
}

echo json_encode([
    'success' => true,
    'student_id' => (int) $student['id'],
    'current_subject_id' => $student['optional_subject_id'] ? (string) $student['optional_subject_id'] : '',
    'current_subject_name' => $student['current_subject_name'] ?: 'None',
    'options' => $subjectOptions,
]);
