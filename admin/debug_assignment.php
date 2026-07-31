<?php
include 'includes/db_config.php';
header('Content-Type: text/plain');

$assignment_id = 4;
echo "--- Assignment Check ---\n";
$stmt = $conn->prepare("SELECT ta.*, c.class_name, s.subject_name FROM teacher_assignments ta JOIN classes c ON ta.class_id = c.id JOIN subjects s ON ta.subject_id = s.id WHERE ta.id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($assignment);

if ($assignment) {
    $class_id = $assignment['class_id'];
    $subject_id = $assignment['subject_id'];

    echo "\n--- Class Subjects (Curriculum) Check ---\n";
    $stmt = $conn->prepare("SELECT * FROM class_subjects WHERE class_id = ? AND subject_id = ?");
    $stmt->execute([$class_id, $subject_id]);
    $cs = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($cs);

    echo "\n--- Sample Students in Class ---\n";
    $stmt = $conn->prepare("SELECT id, name, roll_number, student_group, optional_subject_id, main_elective_id FROM students WHERE class_id = ? LIMIT 5");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($students);

    echo "\n--- Counting Students who SHOULD match ---\n";
    // Base join count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM students s JOIN class_subjects cs ON s.class_id = cs.class_id WHERE s.class_id = ? AND cs.subject_id = ?");
    $stmt->execute([$class_id, $subject_id]);
    echo "Total matching class and subject: " . $stmt->fetchColumn() . "\n";
}
?>
