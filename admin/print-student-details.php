<?php
include('auth.php');
require_admin_or_assistant();

$academic_year = $_GET['academic_year'] ?? $_GET['year'] ?? '';
$class_name = $_GET['class_name'] ?? '';
$section_filter = $_GET['section'] ?? 'all';

if ($academic_year && $class_name) {
    $params = http_build_query([
        'year' => $academic_year,
        'class_name' => $class_name,
        'section' => $section_filter,
        'view' => '1'
    ]);
    header('Location: student-details.php?' . $params);
    exit;
}

header('Location: student-details.php');
exit;
