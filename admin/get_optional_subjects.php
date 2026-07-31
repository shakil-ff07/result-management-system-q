<?php
include('auth.php');
include('../includes/db_config.php');

$class_id = $_GET['class_id'] ?? null;
$group    = $_GET['group']    ?? 'None';
$type     = $_GET['type']     ?? 'optional';

header('Content-Type: application/json');

if ($class_id) {

    // Determine whether this is a lower secondary class with school-based optional subjects
    $class_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_stmt->execute([$class_id]);
    $class_row = $class_stmt->fetch(PDO::FETCH_ASSOC);
    $class_name = strtolower(trim($class_row['class_name'] ?? ''));
    $is_lower_class = (strpos($class_name, 'class 6') !== false || strpos($class_name, 'class 7') !== false || strpos($class_name, 'class 8') !== false);

    /**
     * "Selective" subjects are those that appear in BOTH the compulsory and optional
     * sections of the JSON (e.g. Biology & Higher Math for Science).
     * Because INSERT IGNORE stores only the compulsory row (is_optional = 0),
     * we must fetch their IDs dynamically by name so a re-sync never breaks the list.
     */
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

    if ($type === 'main_elective') {
        // Return the selective subjects (Biology / Higher Math etc.) for this class & group
        if (!empty($selective_ids)) {
            $placeholders = implode(',', array_fill(0, count($selective_ids), '?'));
            $stmt = $conn->prepare("SELECT s.id, s.subject_name
                                    FROM subjects s
                                    JOIN class_subjects cs ON s.id = cs.subject_id
                                    WHERE cs.class_id    = ?
                                      AND cs.student_group = ?
                                      AND s.id IN ($placeholders)
                                    ORDER BY s.subject_name ASC");
            $stmt->execute(array_merge([$class_id, $group], $selective_ids));
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            echo json_encode([]);
        }
    } else {
        // Lower secondary classes 6-8 use school-based optional choices, including the combined Agriculture/Home Science entry
        if ($is_lower_class && $type === 'optional') {
            $lower_optional_names = [
                'Work and Life-Oriented Education',
                'Physical Education & Health',
                'Arts & Crafts',
                'Agriculture Studies / Home Science'
            ];
            $pl = implode(',', array_fill(0, count($lower_optional_names), '?'));
            $stmt = $conn->prepare("SELECT s.id, s.subject_name
                                    FROM subjects s
                                    JOIN class_subjects cs ON s.id = cs.subject_id
                                    WHERE cs.class_id = ?
                                      AND cs.student_group = 'None'
                                      AND (cs.is_optional = 1 OR s.subject_name = 'Agriculture Studies / Home Science')
                                      AND s.subject_name IN ($pl)
                                    ORDER BY FIELD(s.subject_name, 'Work and Life-Oriented Education', 'Physical Education & Health', 'Arts & Crafts', 'Agriculture Studies / Home Science') ASC");
            $stmt->execute(array_merge([$class_id], $lower_optional_names));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($results)) {
                echo json_encode($results);
                exit;
            }
        }

        // Return subjects marked is_optional = 1  PLUS  the selective (swap) subjects
        $id_clause = '';
        if (!empty($selective_ids)) {
            $id_clause = 'OR s.id IN (' . implode(',', $selective_ids) . ')';
        }

        $stmt = $conn->prepare("SELECT s.id, s.subject_name
                                FROM subjects s
                                JOIN class_subjects cs ON s.id = cs.subject_id
                                WHERE cs.class_id = ?
                                  AND (cs.student_group = ? OR cs.student_group = 'None')
                                  AND (cs.is_optional = 1 $id_clause)
                                ORDER BY s.subject_name ASC");
        $stmt->execute([$class_id, $group]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} else {
    echo json_encode([]);
}
?>