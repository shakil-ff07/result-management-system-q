<?php
include('auth.php');
include('../includes/db_config.php');
include('../includes/lang_helper.php');

$class_id = $_GET['class_id'] ?? null;
$subject_id = $_GET['subject_id'] ?? null;
$exam_type = $_GET['exam_type'] ?? 'Half Yearly';

if (!$class_id || !$subject_id) {
    die("Invalid request. Class and Subject are required.");
}

// Fetch class details
$stmt = $conn->prepare("SELECT class_name, section FROM classes WHERE id = ?");
$stmt->execute([$class_id]);
$class = $stmt->fetch();

// Fetch subject name and practical status
$stmt = $conn->prepare("SELECT subject_name, has_practical FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

// Fetch students in this class who are eligible for this subject
$stmt = $conn->prepare("SELECT s.id, s.roll_number, s.name 
                      FROM students s 
                      JOIN class_subjects cs ON s.class_id = cs.class_id 
                      WHERE s.class_id = ? AND cs.subject_id = ?
                      AND (cs.student_group = 'None' OR cs.student_group = s.student_group)
                      ORDER BY s.roll_number");
$stmt->execute([$class_id, $subject_id]);
$students = $stmt->fetchAll();

// Set headers for download
$filename = "marks_template_{$class['class_name']}_{$class['section']}_{$subject['subject_name']}.csv";
$filename = str_replace(' ', '_', $filename);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Logic for practical & SQ marks
$show_practical = (bool) $subject['has_practical'];
$subj_dist = get_subject_marks_distribution($class['class_name'], $subject['subject_name']);
$show_sq = isset($subj_dist['written']['sq']) && $subj_dist['written']['sq'] > 0;

// Header row
$headers = ['Roll Number', 'CQ Marks', 'MCQ Marks'];
if ($show_sq) {
    $headers[] = 'SQ Marks';
}
if ($show_practical) {
    $headers[] = 'Practical Marks';
}
fputcsv($output, $headers);

foreach ($students as $student) {
    // Fetch existing marks if any
    $stmt_marks = $conn->prepare("SELECT cq_marks, mcq_marks, sq_marks, practical_marks FROM marks WHERE student_id = ? AND subject_id = ? AND exam_type = ?");
    $stmt_marks->execute([$student['id'], $subject_id, $exam_type]);
    $m = $stmt_marks->fetch();

    // Clean decimals by adding 0 (converts 23.00 to 23)
    $cq = $m ? ($m['cq_marks'] + 0) : '0';
    $mcq = $m ? ($m['mcq_marks'] + 0) : '0';

    $row = [
        $student['roll_number'],
        $cq,
        $mcq
    ];
    if ($show_sq) {
        $sq = $m ? ($m['sq_marks'] + 0) : '0';
        $row[] = $sq;
    }
    if ($show_practical) {
        $prac = $m ? ($m['practical_marks'] + 0) : '0';
        $row[] = $prac;
    }
    fputcsv($output, $row);
}

fclose($output);
exit();
