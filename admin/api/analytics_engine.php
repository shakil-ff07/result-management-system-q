<?php
include('../auth.php');
require_admin();
include('../../includes/db_config.php');

header('Content-Type: application/json');

$response = [
    'status' => 'success',
    'data' => []
];

try {
    $year = $_GET['year'] ?? date('Y');
    $exam_type = $_GET['exam'] ?? 'Final'; // Default to Final if not 'all'
    $class_name = $_GET['class_name'] ?? 'all';
    $section = $_GET['section'] ?? 'all';

    $where_clauses = ["c.academic_year = ?"];
    $params = [$year];

    if ($exam_type !== 'all' && $exam_type !== '') {
        $where_clauses[] = "fr.exam_type = ?";
        $params[] = $exam_type;
    }
    if ($class_name !== 'all' && $class_name !== '') {
        $where_clauses[] = "c.class_name = ?";
        $params[] = $class_name;
    }
    if ($section !== 'all' && $section !== '') {
        $where_clauses[] = "c.section = ?";
        $params[] = $section;
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Get total students matching session, class, section (independent of exam type)
    $student_where_clauses = ["c.academic_year = ?"];
    $student_params = [$year];

    if ($class_name !== 'all' && $class_name !== '') {
        $student_where_clauses[] = "c.class_name = ?";
        $student_params[] = $class_name;
    }
    if ($section !== 'all' && $section !== '') {
        $student_where_clauses[] = "c.section = ?";
        $student_params[] = $section;
    }

    $student_where_sql = implode(" AND ", $student_where_clauses);
    $stmt_students = $conn->prepare("
        SELECT COUNT(s.id) 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE $student_where_sql
    ");
    $stmt_students->execute($student_params);
    $total_students = (int)$stmt_students->fetchColumn();

    // 1. KPIs
    $stmt = $conn->prepare("SELECT COUNT(*) FROM final_results fr JOIN students s ON fr.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE $where_sql");
    $stmt->execute($params);
    $total_results = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM final_results fr JOIN students s ON fr.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE fr.status = 'Pass' AND $where_sql");
    $stmt->execute($params);
    $total_passed = $stmt->fetchColumn();

    $pass_rate = $total_results > 0 ? round(($total_passed / $total_results) * 100, 1) : 0;

    $stmt = $conn->prepare("SELECT AVG(total_gpa) FROM final_results fr JOIN students s ON fr.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE $where_sql");
    $stmt->execute($params);
    $avg_gpa = round($stmt->fetchColumn() ?? 0, 2);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM final_results fr JOIN students s ON fr.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE fr.final_grade = 'A+' AND $where_sql");
    $stmt->execute($params);
    $aplus_count = $stmt->fetchColumn();

    // 3. Class Performance (Average GPA per class or section)
    $group_by = "c.class_name";
    $select_field = "c.class_name as category_name";
    $order_by = "LENGTH(c.class_name), c.class_name";

    if ($class_name !== 'all' && $class_name !== '') {
        $group_by = "c.section";
        $select_field = "CONCAT('Section ', c.section) as category_name";
        $order_by = "c.section";
    }

    $stmt = $conn->prepare("
        SELECT $select_field, ROUND(AVG(fr.total_gpa), 2) as avg_gpa,
        SUM(CASE WHEN fr.status = 'Pass' THEN 1 ELSE 0 END) / COUNT(*) * 100 as pass_rate
        FROM final_results fr
        JOIN students s ON fr.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE $where_sql
        GROUP BY $group_by
        ORDER BY $order_by
    ");
    $stmt->execute($params);
    $class_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Pass vs Fail Rate (Donut Chart)
    $pass_fail = [
        ['name' => 'Passed', 'value' => (int)$total_passed],
        ['name' => 'Failed', 'value' => (int)($total_results - $total_passed)]
    ];

    $response['data'] = [
        'kpis' => [
            'total_students' => $total_students,
            'total_results' => $total_results,
            'pass_rate' => $pass_rate,
            'avg_gpa' => $avg_gpa,
            'aplus_count' => $aplus_count,
            'fail_count' => (int)($total_results - $total_passed)
        ],
        'class_performance' => $class_performance,
        'pass_fail' => $pass_fail
    ];

} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
