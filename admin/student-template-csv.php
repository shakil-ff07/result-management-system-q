<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$class_id = $_GET['class_id'] ?? null;
$group = $_GET['group'] ?? 'None';

// Determine level
$is_9_10 = false;
$filename = "student_import_template";

if ($class_id) {
    $stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_name = $stmt->fetchColumn();
    
    if ($class_name && (strpos($class_name, '9') !== false || strpos($class_name, '10') !== false)) {
        $is_9_10 = true;
        $filename .= "_" . strtolower(str_replace(' ', '_', $class_name));
        if ($group !== 'None') {
            $filename .= "_" . strtolower($group);
        }
    }
}

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename . '.csv');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Define columns
$headers = ['Roll Number', 'Name', 'Father Name', 'Mother Name', 'DOB (YYYY-MM-DD)'];
$sample = ['52', 'Sajjatul Ferdous', 'Abdul Korim', 'Fatima', '2010-05-15'];

if ($is_9_10) {
    // Fetch optional subject options for this class & group
    $opt_names = [];
    if ($class_id) {
        $sel_names = [];
        if ($group === 'Science') {
            $sel_names = ['Biology', 'Higher Mathematics'];
        } elseif ($group === 'Arts') {
            $sel_names = ['Geography & Environment', 'Economics'];
        }
        $sel_ids = [];
        if (!empty($sel_names)) {
            $pl = implode(',', array_fill(0, count($sel_names), '?'));
            $s = $conn->prepare("SELECT id FROM subjects WHERE subject_name IN ($pl)");
            $s->execute($sel_names);
            $sel_ids = $s->fetchAll(PDO::FETCH_COLUMN);
        }
        $id_clause = !empty($sel_ids) ? 'OR s.id IN (' . implode(',', $sel_ids) . ')' : '';
        $stmt_opt = $conn->prepare("SELECT s.subject_name FROM subjects s
            JOIN class_subjects cs ON s.id = cs.subject_id
            WHERE cs.class_id = ?
              AND (cs.student_group = ? OR cs.student_group = 'None')
              AND (cs.is_optional = 1 $id_clause)
            ORDER BY s.subject_name ASC");
        $stmt_opt->execute([$class_id, $group]);
        $opt_names = $stmt_opt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($opt_names)) {
        if ($group === 'Science') {
            $opt_names = ['Biology', 'Higher Mathematics', 'Agriculture Studies', 'Home Science'];
        } elseif ($group === 'Arts') {
            $opt_names = ['Geography & Environment', 'Economics', 'Agriculture Studies', 'Home Science'];
        } else {
            $opt_names = ['Agriculture Studies', 'Home Science'];
        }
    }

    $headers[] = 'Optional Subject Name (' . implode(', ', $opt_names) . ')';

    if ($group === 'Science') {
        $sample[] = 'Biology'; // or Higher Mathematics
    } elseif ($group === 'Arts') {
        $sample[] = 'Geography & Environment'; // or Economics
    } else {
        $sample[] = 'Agriculture Studies';
    }
}

// Add Phone at the end
$headers[] = 'Phone';
$sample[] = '01700000000';

// Output the column headings
fputcsv($output, $headers);

// Add sample row
fputcsv($output, $sample);

fclose($output);
exit();
?>