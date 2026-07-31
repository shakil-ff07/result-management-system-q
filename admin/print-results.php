<?php
include('auth.php'); // Ensure admin is logged in
include('../includes/db_config.php');
include('../includes/token_helper.php');
include('../includes/lang_helper.php');

$class_id = $_GET['class_id'] ?? '';
$student_id = $_GET['student_id'] ?? '';
$exam_type = $_GET['exam_type'] ?? '';
$lang = $_GET['lang'] ?? 'en';

// Multi-section variables
$class_name = $_GET['class_name'] ?? '';
$academic_year = $_GET['academic_year'] ?? '';

if (!$exam_type || (!$class_id && !$student_id && (!$class_name || !$academic_year))) {
    die("Invalid request parameters.");
}

$students_to_print = [];
$class_info = null;

if ($student_id) {
    // Single student print
    $stmt = $conn->prepare("
        SELECT s.*, c.class_name, c.section, c.academic_year 
        FROM students s 
        JOIN classes c ON s.class_id = c.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student)
        die("Student not found.");

    $students_to_print[] = $student;
    $class_info = [
        'class_name' => $student['class_name'],
        'section' => $student['section'],
        'academic_year' => $student['academic_year'],
        'id' => $student['class_id']
    ];
} else if ($class_id) {
    // Bulk class print
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch();

    if (!$class_info)
        die("Class not found.");

    // Fetch all students in this class who have a final result for this exam
    $stmt = $conn->prepare("
        SELECT s.*, c.class_name, c.section, c.academic_year 
        FROM students s 
        JOIN classes c ON s.class_id = c.id
        JOIN final_results fr ON s.id = fr.student_id AND fr.exam_type = ?
        WHERE s.class_id = ?
        ORDER BY CAST(s.roll_number AS UNSIGNED) ASC
    ");
    $stmt->execute([$exam_type, $class_id]);
    $students_to_print = $stmt->fetchAll();

    if (empty($students_to_print))
        die("No published results found for this class and exam type.");
} else if ($class_name && $academic_year) {
    // Bulk class print (all sections)
    $stmt = $conn->prepare("SELECT id, class_name, section, academic_year FROM classes WHERE class_name = ? AND academic_year = ? ORDER BY section");
    $stmt->execute([$class_name, $academic_year]);
    $classes = $stmt->fetchAll();

    if (empty($classes))
        die("Classes not found.");

    $class_info = [
        'class_name' => $class_name,
        'section' => 'All Sections',
        'academic_year' => $academic_year,
        'id' => $classes[0]['id']
    ];

    $class_ids = array_column($classes, 'id');
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));

    $query = "
        SELECT s.*, c.class_name, c.section, c.academic_year 
        FROM students s 
        JOIN classes c ON s.class_id = c.id
        JOIN final_results fr ON s.id = fr.student_id AND fr.exam_type = ?
        WHERE s.class_id IN ($placeholders)
        ORDER BY c.section ASC, CAST(s.roll_number AS UNSIGNED) ASC
    ";

    $params = array_merge([$exam_type], $class_ids);
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $students_to_print = $stmt->fetchAll();

    if (empty($students_to_print))
        die("No published results found for this class and exam type.");
}

// Don't hide the practical column by default; show it when any subject has practical marks.
$hide_practical = false;

function load_subject_max_marks() {
    $json_file = __DIR__ . '/../json/all_classes_subject_V6.json';
    $subject_totals = ['flat' => []];

    if (!file_exists($json_file)) {
        return $subject_totals;
    }

    $json_data = json_decode(file_get_contents($json_file), true);
    if (!is_array($json_data['classes'] ?? null)) {
        return $subject_totals;
    }

    foreach ($json_data['classes'] as $class_data) {
        $class_name = trim($class_data['class_name'] ?? '');
        if ($class_name === '') {
            continue;
        }

        foreach ($class_data['groups'] ?? [] as $group) {
            foreach (['compulsory', 'compulsory_school', 'optional'] as $section) {
                foreach ($group['subjects'][$section] ?? [] as $subject) {
                    $subject_name = trim($subject['name_en'] ?? '');
                    if ($subject_name === '') {
                        continue;
                    }
                    $max_marks = intval($subject['marks_distribution']['total_marks'] ?? 100);
                    
                    if (strpos($class_name, 'Class 9 & 10') !== false) {
                        $subject_totals['Class 9'][$subject_name] = $max_marks;
                        $subject_totals['Class 10'][$subject_name] = $max_marks;
                    }
                    
                    if (!isset($subject_totals[$class_name][$subject_name])) {
                        $subject_totals[$class_name][$subject_name] = $max_marks;
                    }
                    if (!isset($subject_totals['flat'][$subject_name])) {
                        $subject_totals['flat'][$subject_name] = $max_marks;
                    }
                }
            }
        }
    }

    return $subject_totals;
}

function calculate_grade_gpa($score, $max_marks) {
    $score = floatval($score);
    $max_marks = intval($max_marks) ?: 100;
    $grade = 'F';
    $gpa = 0.0;

    if ($max_marks === 50) {
        // Percentage-aligned with 100-mark scale:
        // 80% of 50 = 40 (A+), 70% of 50 = 35 (A), 60% of 50 = 30 (A-), etc.
        if ($score >= 40) {
            $grade = 'A+';
            $gpa = 5.0;
        } elseif ($score >= 35) {
            $grade = 'A';
            $gpa = 4.0;
        } elseif ($score >= 30) {
            $grade = 'A-';
            $gpa = 3.5;
        } elseif ($score >= 25) {
            $grade = 'B';
            $gpa = 3.0;
        } elseif ($score >= 20) {
            $grade = 'C';
            $gpa = 2.0;
        } elseif ($score >= 16) {
            $grade = 'D';
            $gpa = 1.0;
        }
    } else {
        if ($score >= 80) {
            $grade = 'A+';
            $gpa = 5.0;
        } elseif ($score >= 70) {
            $grade = 'A';
            $gpa = 4.0;
        } elseif ($score >= 60) {
            $grade = 'A-';
            $gpa = 3.5;
        } elseif ($score >= 50) {
            $grade = 'B';
            $gpa = 3.0;
        } elseif ($score >= 40) {
            $grade = 'C';
            $gpa = 2.0;
        } elseif ($score >= 33) {
            $grade = 'D';
            $gpa = 1.0;
        }
    }

    return [$grade, $gpa];
}

function compute_final_result_from_marks($marks, $class_name, $subject_max_marks) {
    $compulsory_gps = [];
    $optional_gps = [];
    $total_marks = 0;
    $max_possible_marks = 0;
    $failed_compulsory = false;
    $is_class_9_10 = (strpos($class_name, 'Class 9') !== false || strpos($class_name, 'Class 10') !== false);

    foreach ($marks as $m) {
        $subject_total_score = 0;
        $subject_total_score += isset($m['cq_marks']) ? floatval($m['cq_marks']) : 0;
        $subject_total_score += isset($m['sq_marks']) ? floatval($m['sq_marks']) : 0;
        $subject_total_score += isset($m['mcq_marks']) ? floatval($m['mcq_marks']) : 0;
        if ($m['has_practical'] == 1) {
            $subject_total_score += isset($m['practical_marks']) ? floatval($m['practical_marks']) : 0;
        }

        $subject_name = $m['subject_name'];
        $max_marks = $subject_max_marks[$class_name][$subject_name] ?? ($subject_max_marks['flat'][$subject_name] ?? 100);
        list($grade, $gp) = calculate_grade_gpa($subject_total_score, $max_marks);

        $total_marks += $subject_total_score;
        $max_possible_marks += $max_marks;

        // EXCLUDE subjects that are school-based (PE, ICT, Career, Arts) from GPA calculation
        // EXCLUDE subjects that don't count in GPA based on JSON definition
        $is_school_based = ($m['is_school_based'] ?? 0);
        $is_non_gpa_optional = ($m['effective_optional'] && in_array(
            $subject_name,
            ['Physical Education & Health', 'Information & Communication Technology (ICT)', 'Work and Life-Oriented Education', 'Arts & Crafts']
        ));

        if ($is_school_based || $is_non_gpa_optional) {
            // Skip - don't add to GPA calculation
            continue;
        }

        if (!($m['effective_optional'] && $is_class_9_10)) {
            $compulsory_gps[] = $gp;
            if ($gp == 0) {
                $failed_compulsory = true;
            }
        } else {
            $optional_gps[] = $gp;
        }
    }

    $subject_count = count($compulsory_gps);
    $compulsory_sum = array_sum($compulsory_gps);
    $optional_bonus = 0;
    foreach ($optional_gps as $opt_gp) {
        if ($opt_gp > 2.0) {
            $optional_bonus += ($opt_gp - 2.0);
        }
    }

    if ($subject_count > 0) {
        $final_gpa = ($compulsory_sum + $optional_bonus) / $subject_count;
    } else {
        $final_gpa = 0;
    }
    $final_gpa = min(5.0, $final_gpa);
    if ($failed_compulsory) {
        $final_gpa = 0;
    }

    $final_grade = 'F';
    if ($final_gpa >= 5.0) {
        $final_grade = 'A+';
    } elseif ($final_gpa >= 4.0) {
        $final_grade = 'A';
    } elseif ($final_gpa >= 3.5) {
        $final_grade = 'A-';
    } elseif ($final_gpa >= 3.0) {
        $final_grade = 'B';
    } elseif ($final_gpa >= 2.0) {
        $final_grade = 'C';
    } elseif ($final_gpa >= 1.0) {
        $final_grade = 'D';
    }

    return [
        'total_marks' => $total_marks,
        'max_possible_marks' => $max_possible_marks,
        'total_gpa' => $final_gpa,
        'final_grade' => $final_grade,
    ];
}

$subject_max_marks = load_subject_max_marks();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Results - <?php echo $exam_type; ?></title>
    <link rel="icon" type="image/png" href="../logo/logo.png">


    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Playfair+Display:wght@700;900&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary-navy: #0f172a;
            --accent-gold: #b45309;
            --border-light: #e2e8f0;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #f8fafc;
            font-family: 'Outfit', sans-serif;
            color: var(--primary-navy);
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 700;
            z-index: 1000;
        }

        .print-controls {
            display: none;
        }

        .marksheet-wrapper {
            width: 100%;
            overflow: visible;
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: center;
            align-items: flex-start;
            gap: 40px;
            padding-bottom: 100px;
            transition: transform 0.2s ease-in-out;
            transform-origin: top center;
        }

        .marksheet-container {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 10mm;
            margin: 20px auto 30px auto;
            position: relative;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-light);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        @media (max-width: 768px) {
            .marksheet-wrapper {
                transform-origin: top left !important;
                padding-left: 10px !important;
            }

            .marksheet-container {
                margin: 0 0 30px 0 !important;
            }
        }

        .marksheet-container::before {
            content: '';
            position: absolute;
            top: 8mm;
            left: 8mm;
            right: 8mm;
            bottom: 8mm;
            border: 1px solid #f1f5f9;
            pointer-events: none;
            z-index: 1;
        }

        .watermark-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            opacity: 0.06;
            pointer-events: none;
            z-index: 0;
            filter: grayscale(100%);
        }

        .school-header {
            text-align: center;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
        }

        .school-logo {
            width: 70px;
            height: auto;
            margin-bottom: 2px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.05));
        }

        .school-name {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            font-weight: 900;
            color: var(--primary-navy);
            letter-spacing: -1px;
            margin-bottom: 0;
        }

        .transcript-title {
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--accent-gold);
            font-size: 0.75rem;
            margin-top: 2px;
        }

        .student-info-grid {
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: #fcfcfd;
            border-radius: 10px;
            border: 1px solid #f1f5f9;
            position: relative;
            z-index: 2;
        }

        .info-column {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .info-row {
            display: flex;
            align-items: center;
            padding: 2px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-label {
            color: var(--text-muted);
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            width: 130px;
            display: flex;
            align-items: center;
        }

        .info-label i {
            width: 16px;
            color: var(--accent-gold);
            opacity: 0.8;
        }

        .info-value {
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--primary-navy);
        }

        .table-premium {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: .8rem;
            position: relative;
            z-index: 2;
        }

        .table-premium th {
            background: var(--primary-navy);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 1px;
            padding: 10px;
            text-align: center;
        }

        .table-premium th:first-child {
            border-top-left-radius: 6px;
            text-align: left;
        }

        .table-premium th:last-child {
            border-top-right-radius: 6px;
        }

        .table-premium td {
            padding: 6px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.85rem;
            text-align: center;
        }

        .table-premium td:first-child {
            text-align: left;
            font-weight: 600;
        }

        .table-premium tr:nth-child(even) {
            background: rgba(248, 250, 252, 0.5); /* Semi-transparent for watermark */
        }

        .grade-badge {
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 4px;
        }

        .grade-f {
            color: #ef4444;
        }

        .summary-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 18px;
            display: flex;
            justify-content: space-around;
            align-items: stretch;
            gap: 0.75rem;
            margin-top: 0.6rem;
            position: relative;
            z-index: 2;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
            border: 1px solid rgba(15, 23, 42, 0.06);
            flex-wrap: wrap;
        }

        .summary-item {
            position: relative;
            text-align: center;
            flex: 1 1 150px;
            max-width: 190px;
            min-width: 140px;
            padding: 1rem 1rem 0.9rem;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05);
            border: 1px solid rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .summary-item::before {
            content: '';
            position: absolute;
            top: 0.65rem;
            left: 1rem;
            width: 32px;
            height: 3px;
            border-radius: 999px;
            background: var(--accent, #38bdf8);
        }

        .summary-item:hover {
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }

        .summary-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.8px;
            margin-bottom: 0.4rem;
            font-weight: 700;
        }

        .summary-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }

        .summary-value span {
            font-size: 0.8rem !important;
            color: #94a3b8 !important;
        }

        .signature-section {
            margin-top: 2rem;
            display: flex;
            justify-content: flex-end;
            position: relative;
            z-index: 2;
        }

        .sig-box {
            width: 140px;
            text-align: center;
        }

        .sig-line {
            border-top: 1px solid var(--primary-navy);
            margin-bottom: 6px;
        }

        .sig-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
        }

        .footer-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            margin-top: auto;
            flex-wrap: wrap;
        }

        .qr-box {
            margin-left: 0;
        }

        .qr-section {
            text-align: center;
            margin-top: 0;
            position: relative;
            z-index: 2;
        }

        .signature-section {
            margin: 0;
            justify-content: flex-end;
        }

        .qr-box {
            display: inline-block;
            background: white;
            padding: 6px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.03);
        }

        @media print {

            html,
            body {
                background: none;
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-controls,
            .zoom-controls,
            .btn-print {
                display: none !important;
            }

            .marksheet-container {
                margin: 0 auto !important;
                box-shadow: none !important;
                border: none !important;
                width: 210mm !important;
                height: 297mm !important;
                padding: 10mm !important;
                border-radius: 0 !important;
                page-break-after: always;
            }

            .marksheet-container:last-child {
                page-break-after: auto;
            }

            .marksheet-container::before {
                border-color: #f8f9fa;
            }

            .marksheet-wrapper {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
                transform: none !important;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }

        /* --- Floating Zoom Controls (Matching result.php) --- */
        .zoom-controls {
            position: fixed;
            top: 20px;
            left: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 1100;
        }

        .zoom-btn {
            width: 44px;
            height: 44px;
            background: white;
            border: 1px solid var(--border-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-navy);
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }

        .zoom-btn:hover {
            background: var(--primary-navy);
            color: white;
            transform: translateY(-2px);
        }

        .zoom-display {
            width: 44px;
            text-align: center;
            font-size: 0.65rem;
            font-weight: 800;
            background: rgba(15, 23, 42, 0.05);
            padding: 4px 0;
            border-radius: 8px;
            color: var(--primary-navy);
        }

        @media (max-width: 768px) {
            .print-controls {
                padding: 15px !important;
                margin-bottom: 15px !important;
                flex-direction: row !important;
                /* Keep horizontal on mobile if compact */
                flex-wrap: wrap;
                gap: 10px !important;
                justify-content: center !important;
            }

            .print-controls>div:first-child {
                width: 100%;
                text-align: center;
                border-bottom: 1px solid #f1f5f9;
                padding-bottom: 10px;
            }

            .print-controls .btn {
                flex: 1;
                font-size: 0.75rem !important;
                padding: 10px 5px !important;
            }

            .zoom-controls {
                top: 15px !important;
                bottom: auto !important;
                left: 15px !important;
                flex-direction: row;
            }

            .summary-card {
                flex-direction: column;
                gap: 12px;
                padding: 12px;
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            }

            .summary-item {
                width: 100%;
                padding: 1rem;
                border: none;
            }

            .summary-item:last-child {
                border-bottom: none;
            }

            .summary-label {
                font-size: 0.6rem !important;
            }

            .summary-value {
                font-size: 1.25rem !important;
            }
        }
    </style>
</head>

<body>

    <button onclick="window.print()" class="btn btn-dark btn-print no-print">
        <i class="fas fa-print me-2"></i> Download Official Transcript
    </button>

    <div class="zoom-controls no-print">
        <select class="form-select form-select-sm me-1" id="langSelect" onchange="changeLanguage(this.value)" style="width: auto; height: 44px; border-radius: 22px; font-weight: 700; padding: 0 35px 0 15px; background-color: white; border: 1px solid var(--border-light); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); cursor: pointer; display: inline-block;">
            <option value="en" <?php echo $lang === 'en' ? 'selected' : ''; ?>>English</option>
            <option value="bn" <?php echo $lang === 'bn' ? 'selected' : ''; ?>>বাংলা</option>
        </select>
        <button onclick="changeZoom(0.1)" class="zoom-btn" title="Zoom In"><i class="fas fa-plus"></i></button>
        <button onclick="changeZoom(-0.1)" class="zoom-btn" title="Zoom Out"><i class="fas fa-minus"></i></button>
        <button onclick="resetZoom()" class="zoom-btn" title="Reset"><i class="fas fa-expand"></i></button>
    </div>

    <div id="printWrapper" class="marksheet-wrapper">

        <?php foreach ($students_to_print as $std):
            $current_student_id = $std['id'];
            $show_sq_column = in_array($std['class_name'], ['Class 6', 'Class 7', 'Class 8']);

            // Fetch Marks
            $stmt_marks = $conn->prepare("
            SELECT cs.subject_id, s.subject_name, cs.is_optional, cs.is_school_based, s.has_practical,
                   m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks, m.total_marks, m.grade, m.gpa,
                   std.optional_subject_id, std.main_elective_id, std.student_group,
                   (CASE
                        WHEN cs.subject_id = std.optional_subject_id THEN 1
                        ELSE 0
                    END) as effective_optional
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.id
            JOIN students std ON cs.class_id = std.class_id 
                AND (cs.student_group = std.student_group OR cs.student_group = 'None')
            LEFT JOIN marks m ON cs.subject_id = m.subject_id 
                AND m.student_id = std.id 
                AND m.exam_type = ?
            WHERE std.id = ?
              AND (
                    (cs.is_optional = 0 AND cs.subject_id NOT IN (124, 125, 684, 132))
                    OR cs.subject_id = std.main_elective_id
                    OR cs.subject_id = std.optional_subject_id
                  )
            ORDER BY effective_optional ASC, cs.is_school_based ASC, s.id ASC
        ");
            $stmt_marks->execute([$exam_type, $current_student_id]);
            $marks = $stmt_marks->fetchAll();

            $computed_summary = compute_final_result_from_marks($marks, $std['class_name'], $subject_max_marks);

            // Fetch Result Summary
            $stmt_summary = $conn->prepare("SELECT * FROM final_results WHERE student_id = ? AND exam_type = ?");
            $stmt_summary->execute([$current_student_id, $exam_type]);
            $summary = $stmt_summary->fetch();

            // Verification Token
            $verification_token = get_or_create_token($conn, $current_student_id, $std['class_id'], $exam_type);
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base_dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
            $verify_url = $scheme . "://" . $_SERVER['HTTP_HOST'] . $base_dir . "/verify.php?t=" . $verification_token;
            $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verify_url);
            ?>

            <div class="marksheet-container">
                <img src="../logo/atn.png" alt="" class="watermark-logo">

                <div class="school-header">
                    <img src="../logo/atn.png" alt="School Logo" class="school-logo">
                    <h1 class="school-name">ATN GIRLS HIGH SCHOOL</h1>
                    <div class="transcript-title">Academic Achievement Record</div>
                    <p class="text-muted small mt-2 mb-0">
                        <?php echo htmlspecialchars($exam_type) . ' Examination • Session ' . htmlspecialchars($class_info['academic_year']); ?>
                    </p>
                </div>

                <div class="student-info-grid">
                    <div class="info-column">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user-graduate me-2"></i>Student</span>
                            <span class="info-value"><?php echo strtoupper(htmlspecialchars($std['name'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user-tie me-2"></i>Father's Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($std['father_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user-friends me-2"></i>Mother's Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($std['mother_name']); ?></span>
                        </div>
                    </div>
                    <div class="info-column">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-school me-2"></i>Class / Sec</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($std['class_name']) . ' (' . htmlspecialchars($std['section']) . ')'; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-id-badge me-2"></i>Roll Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($std['roll_number']); ?></span>
                        </div>
                        <?php if ($std['student_group'] && $std['student_group'] !== 'None'): ?>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-layer-group me-2"></i>Group</span>
                                <span class="info-value"><?php echo htmlspecialchars($std['student_group']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <table class="table-premium">
                    <thead>
                        <tr>
                            <th width="40">SL</th>
                            <th class="text-start">Subject</th>
                            <th><?php echo $show_sq_column ? 'CQ + SQ' : 'CQ'; ?></th>
                            <th>MCQ</th>
                            <?php if (!$hide_practical): ?>
                                <th>PRAC</th>
                            <?php endif; ?>
                            <th>TOTAL</th>
                            <th>GRADE</th>
                            <th>GPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sl = 1;
                        foreach ($marks as $m):
                            $subject_total_score = 0;
                            $subject_total_score += isset($m['cq_marks']) ? floatval($m['cq_marks']) : 0;
                            $subject_total_score += isset($m['sq_marks']) ? floatval($m['sq_marks']) : 0;
                            $subject_total_score += isset($m['mcq_marks']) ? floatval($m['mcq_marks']) : 0;
                            if ($m['has_practical'] == 1) {
                                $subject_total_score += isset($m['practical_marks']) ? floatval($m['practical_marks']) : 0;
                            }
                            $subject_total_score = round($subject_total_score, 2);

                            $subject_name = $m['subject_name'];
                            $max_marks = $subject_max_marks[$std['class_name']][$subject_name] ?? ($subject_max_marks['flat'][$subject_name] ?? 100);
                            list($computed_grade, $computed_gpa) = calculate_grade_gpa($subject_total_score, $max_marks);
                        ?>
                            <tr>
                                <td><?php echo str_pad($sl++, 2, '0', STR_PAD_LEFT); ?></td>
                                <td class="text-start">
                                    <?php echo htmlspecialchars(translate_subject($m['subject_name'], $std['class_name'], $lang)); ?>
                                    <?php if (isset($m['effective_optional']) && $m['effective_optional']): ?>
                                        <span style="font-size: 0.65rem; color: #94a3b8; font-style: italic; margin-left: 6px;">(Optional)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)((isset($m['cq_marks']) ? $m['cq_marks'] : 0) + (isset($m['sq_marks']) ? $m['sq_marks'] : 0)); ?></td>
                                <td><?php echo isset($m['mcq_marks']) ? (int)$m['mcq_marks'] : '0'; ?></td>
                                <?php if (!$hide_practical): ?>
                                    <td><?php echo ($m['has_practical'] == 1) ? (isset($m['practical_marks']) ? (int)$m['practical_marks'] : '0') : '-'; ?></td>
                                <?php endif; ?>
                                <td class="fw-bold"><?php echo (int)$subject_total_score; ?></td>
                                <td>
                                    <span class="grade-badge <?php echo $computed_grade == 'F' ? 'grade-f' : ''; ?>">
                                        <?php echo $computed_grade; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($computed_gpa, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($summary): ?>
                    <div class="summary-card">
                        <div class="summary-item" style="--accent:#38bdf8;">
                            <div class="summary-label">Total Marks</div>
                            <div class="summary-value"><?php echo (int)$computed_summary['total_marks']; ?><span>/<?php echo (int)$computed_summary['max_possible_marks']; ?></span></div>
                        </div>
                        <div class="summary-item" style="--accent:#f59e0b;">
                            <div class="summary-label">Position</div>
                            <div class="summary-value" style="color: #f59e0b;">
                                <?php echo htmlspecialchars($summary['position']); ?>
                            </div>
                        </div>
                        <div class="summary-item" style="--accent:<?php echo ($computed_summary['final_grade'] == 'F') ? '#ef4444' : '#059669'; ?>;">
                            <div class="summary-label">Final Grade</div>
                            <div class="summary-value" style="color: <?php echo ($computed_summary['final_grade'] == 'F') ? '#ef4444' : '#059669'; ?>;">
                                <?php echo htmlspecialchars($computed_summary['final_grade']); ?>
                            </div>
                        </div>
                        <div class="summary-item" style="--accent:#8a38ff;">
                            <div class="summary-label">GPA Score</div>
                            <div class="summary-value" style="color: #8a38ff;">
                                <?php echo number_format($computed_summary['total_gpa'], 2); ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning text-center mt-3 py-2">
                        Final results not published yet.
                    </div>
                <?php endif; ?>

                <div class="footer-row">
                    <div class="qr-section">
                        <div class="qr-box">
                            <img src="<?php echo $qr_api_url; ?>" alt="Verification" width="100">
                        </div>
                        <div class="mt-1 small fw-bold text-uppercase" style="font-size: 0.6rem; letter-spacing: 1px;">
                            <i class="fas fa-shield-check text-success"></i> Scan to Verify Original Document
                        </div>
                    </div>

                    <div class="signature-section">
                        <div class="sig-box">
                            <div class="sig-line"></div>
                            <div class="sig-label">Headmaster</div>
                        </div>
                    </div>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

    <script>
        let currentZoom = 1;
        const wrapper = document.getElementById('printWrapper');

        // Set initial zoom based on screen width
        function setInitialZoom() {
            if (window.innerWidth < 1000) {
                currentZoom = (window.innerWidth - 40) / 840; // 840 is 210mm approx
                updateZoom();
            }
        }

        function changeZoom(delta) {
            currentZoom = Math.max(0.3, Math.min(2, currentZoom + delta));
            updateZoom();
        }

        function resetZoom() {
            currentZoom = 1;
            updateZoom();
        }

        function updateZoom() {
            wrapper.style.transform = `scale(${currentZoom})`;

            // Adjust margin to handle the scaled space
            if (currentZoom < 1) {
                wrapper.style.marginBottom = `-${(1 - currentZoom) * wrapper.offsetHeight}px`;
            } else {
                wrapper.style.marginBottom = '30px';
            }
        }

        window.addEventListener('load', setInitialZoom);

        function changeLanguage(lang) {
            var queryParts = [];
            var currentQuery = window.location.search.substring(1).split('&');

            for (var i = 0; i < currentQuery.length; i++) {
                var part = currentQuery[i];
                if (!part || part.indexOf('lang=') === 0) {
                    continue;
                }
                queryParts.push(part);
            }

            queryParts.push('lang=' + encodeURIComponent(lang));
            window.location.href = window.location.pathname + '?' + queryParts.join('&');
        }
    </script>
</body>

</html>