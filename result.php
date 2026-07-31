<?php
include('includes/db_config.php');
include('includes/token_helper.php');
include('includes/lang_helper.php');

$roll = $_GET['roll'] ?? '';
$dob = $_GET['dob'] ?? '';
$class_name = $_GET['class_name'] ?? '';
$section = $_GET['section'] ?? '';
$year = $_GET['year'] ?? '';
$exam_type = $_GET['exam_type'] ?? '';
$lang = $_GET['lang'] ?? 'en';

if (!$roll || !$dob || !$class_name || !$section || !$year || !$exam_type) {
    die("Invalid access.");
}

// 1. Fetch Class ID
$stmt = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND section = ? AND academic_year = ?");
$stmt->execute([$class_name, $section, $year]);
$class = $stmt->fetch();

if (!$class) {
    display_error("No Result Found", "We couldn't locate any records matching the selected session, class, or section. Please verify your selection and try again.", "fa-search");
}

$class_id = $class['id'];

// 2. Fetch Student Details (with DOB verification)
$stmt = $conn->prepare("SELECT * FROM students WHERE roll_number = ? AND class_id = ? AND dob = ?");
$stmt->execute([$roll, $class_id, $dob]);
$student = $stmt->fetch();

if (!$student) {
    display_error("Access Denied", "The Roll Number or Date of Birth provided does not match our records for this enrollment period.", "fa-user-lock");
}

$student_id = $student['id'];

// 3. Fetch Marks (Ensuring we show ALL compulsory subjects, even if marks are missing)
// ... [Query logic remains same] ...
$stmt = $conn->prepare("SELECT cs.subject_id, s.subject_name, cs.is_optional, cs.is_school_based, s.has_practical,
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
                      ORDER BY effective_optional ASC, cs.is_school_based ASC, s.id ASC");
$stmt->execute([$exam_type, $student_id]);
$marks = $stmt->fetchAll();

// Calculate max possible marks from the JSON config
$json_path = 'json/all_classes_subject_V6.json';
$student_class_name = $student['class_name'] ?? $class['class_name'] ?? $class_name;
$max_possible_marks = 0;
if (file_exists($json_path)) {
    $json_data = json_decode(file_get_contents($json_path), true);
    if ($json_data && isset($json_data['classes'])) {
        foreach ($marks as $m) {
            $subject_name = $m['subject_name'];
            $current_class_name = $student_class_name;
            
            // Find in JSON
            foreach ($json_data['classes'] as $class_data) {
                $json_class = strtolower(trim($class_data['class_name']));
                $target_class = strtolower(trim($class_name));
                
                $class_matches = ($json_class === $target_class) || 
                                 (($target_class === 'class 9' || $target_class === 'class 10') && strpos($json_class, 'class 9 & 10') !== false);
                                 
                if ($class_matches) {
                    $student_group = strtolower(trim($student['student_group'] ?? 'none'));
                    foreach ($class_data['groups'] as $group) {
                        $group_id = strtolower(trim($group['group_id']));
                        // Match group for Class 9 & 10
                        if (($target_class === 'class 9' || $target_class === 'class 10') && $group_id !== 'general' && $student_group !== 'none' && $student_group !== '' && $group_id !== $student_group) {
                            continue;
                        }
                        
                        foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
                            if (isset($group['subjects'][$type])) {
                                foreach ($group['subjects'][$type] as $subject) {
                                    if (strtolower(trim($subject['name_en'])) === strtolower(trim($subject_name))) {
                                        $max_possible_marks += intval($subject['marks_distribution']['total_marks'] ?? 100);
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                    break;
                }
            }
        }
    }
}
if ($max_possible_marks === 0) {
    $max_possible_marks = count($marks) * 100; // Fallback if calculation failed
}

// 4. Fetch Result Summary
$stmt = $conn->prepare("SELECT * FROM final_results WHERE student_id = ? AND exam_type = ?");
$stmt->execute([$student_id, $exam_type]);
$summary = $stmt->fetch();

if (!$summary) {
    display_error("Result Not Published", "The official results for this examination cycle have not been finalized or published yet. Please check back later.", "fa-clock");
}

// Helper function for professional errors
function display_error($title, $message, $icon)
{
    $theme = isset($_COOKIE['srms-theme']) ? $_COOKIE['srms-theme'] : 'light';
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="<?php echo $theme; ?>">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title; ?> | SRMS</title>
        <link rel="icon" type="image/png" href="logo/logo.png">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link
            href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;600&display=swap"
            rel="stylesheet">
        <style>
            :root {
                --navy: #0f172a;
                --gold: #b45309;
                --slate: #f8fafc;
            }

            [data-theme="dark"] {
                --navy: #0f172a;
                --gold: #f59e0b;
                --slate: #060c18;
            }

            body {
                font-family: 'Inter', sans-serif;
                background-color: var(--slate);
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #1e293b;
            }

            [data-theme="dark"] body {
                color: #cbd5e1;
            }

            .error-card {
                background: white;
                max-width: 500px;
                padding: 3rem;
                border-radius: 20px;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.05);
                text-align: center;
                border: 1px solid #e2e8f0;
                animation: fadeIn 0.5s ease-out;
            }

            [data-theme="dark"] .error-card {
                background: #111827;
                border-color: rgba(255, 255, 255, 0.07);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .icon-box {
                width: 80px;
                height: 80px;
                background: rgba(180, 83, 9, 0.1);
                color: var(--gold);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                border-radius: 20px;
                margin: 0 auto 1.5rem;
            }

            h1 {
                font-family: 'Playfair Display', serif;
                font-weight: 700;
                color: var(--navy);
                margin-bottom: 1rem;
            }

            [data-theme="dark"] h1 {
                color: #f8fafc;
            }

            p {
                color: #64748b;
                line-height: 1.6;
                margin-bottom: 2rem;
            }

            .btn-back {
                background: var(--navy);
                color: white;
                padding: 12px 30px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 10px;
                transition: 0.3s;
            }

            .btn-back:hover {
                background: #1e293b;
                color: white;
                transform: translateY(-2px);
            }

            [data-theme="dark"] .btn-back {
                background: var(--gold);
                color: #000;
            }
        </style>
    </head>

    <body>
        <div class="error-card">
            <div class="icon-box"><i class="fas <?php echo $icon; ?>"></i></div>
            <h1><?php echo $title; ?></h1>
            <p><?php echo $message; ?></p>
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Return to Portal</a>
        </div>
        <script>
            // Sync theme from localStorage
            const t = localStorage.getItem('srms-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        </script>
    </body>

    </html>
    <?php
    exit();
}

// Logic to hide Practical column only if NO subject in this result has practical marks
$has_any_practical = false;
foreach ($marks as $m) {
    if ($m['has_practical'] == 1) {
        $has_any_practical = true;
        break;
    }
}
$hide_practical = !$has_any_practical;

// Check if class supports SQ marks (Class 6, 7, 8)
$show_sq_column = in_array($class_name, ['Class 6', 'Class 7', 'Class 8']);

// 5. Generate Verification Token
$verification_token = get_or_create_token($conn, $student_id, $class_id, $exam_type);
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$verify_url = $scheme . "://" . $_SERVER['HTTP_HOST'] . $base_dir . "/verify.php?t=" . $verification_token;
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verify_url);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php
    $pdf_name = str_replace(' ', '_', trim($student['name']));
    echo 'Academic_Transcript_' . $pdf_name . '_Roll_' . $roll;
    ?></title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&family=Playfair+Display:wght@700;900&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
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

        .marksheet-container {
            background: #fff;
            width: 210mm;
            min-height: 297mm;
            padding: 10mm;
            margin: 20px auto;
            position: relative;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-light);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Decorative Border */
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
            /* Slightly increased for better visibility through transparency */
            pointer-events: none;
            z-index: 0;
            filter: grayscale(100%);
        }

        .school-header {
            text-align: center;
            margin-bottom: 1.2rem;
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
            margin-bottom: 0.5rem;
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
            /* Fixed width for perfect vertical alignment */
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
            margin-bottom: 0.8rem;
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
            background: rgba(248, 250, 252, 0.5);
            /* Semi-transparent to let watermark show through */
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

        .qr-box {
            margin-left: 0;
        }

        .footer-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            margin-top: auto;
            flex-wrap: wrap;
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

        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 700;
            z-index: 1000;
        }

        /* --- ZOOM CONTROLS --- */
        .zoom-controls {
            position: fixed;
            top: 20px;
            left: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
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
        }

        .zoom-btn:hover {
            background: var(--primary-navy);
            color: white;
            transform: translateY(-2px);
        }

        .zoom-btn:active {
            transform: translateY(0);
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
        }

        .marksheet-container {
            transform-origin: top center;
            transition: transform 0.2s ease-out;
        }

        /* --- PRINT ENGINE (STRICT) --- */
        @media print {
            @page {
                margin: 0;
                size: A4;
            }

            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }

            .no-print,
            .zoom-controls,
            .btn-print {
                display: none !important;
            }

            .marksheet-wrapper {
                padding: 0 !important;
                margin: 0 !important;
                display: block !important;
            }

            .marksheet-container {
                margin: 0 auto !important;
                box-shadow: none !important;
                border: none !important;
                width: 210mm !important;
                height: 297mm !important;
                padding: 10mm !important;
                transform: none !important;
                /* Ensure zoom is 100% for print */
                position: relative !important;
                border-radius: 0 !important;
            }

            .marksheet-container::before {
                border-color: #f1f5f9 !important;
            }
        }

        /* --- MOBILE LAYOUT REFINEMENTS --- */
        @media screen and (max-width: 768px) {
            .zoom-controls {
                top: 15px;
                left: 15px;
                bottom: auto;
                right: auto;
            }

            .btn-print {
                width: calc(100% - 40px);
                left: 20px;
            }
        }

        /* --- MOBILE OPTIMIZATION --- */
        @media screen and (max-width: 768px) {
            body {
                background: #f8fafc;
                /* Restore slight gray background to show separation */
            }

            .marksheet-container {
                width: 95% !important;
                margin: 15px auto 25px auto !important;
                padding: 20px 15px !important;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06) !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 12px !important;
                min-height: auto !important;
                background: #fff !important;
            }

            .marksheet-container::before {
                display: none;
            }

            .watermark-logo {
                width: 250px;
                opacity: 0.02;
            }

            .school-name {
                font-size: 24px !important;
            }

            .transcript-title {
                font-size: 0.65rem;
            }

            .student-info-grid {
                grid-template-columns: 1fr !important;
                padding: 15px !important;
                gap: 0 !important;
            }

            .info-row {
                justify-content: space-between;
                padding: 12px 5px !important;
            }

            .info-label {
                width: auto !important;
                font-size: 0.65rem !important;
                white-space: nowrap;
            }

            .info-value {
                font-size: 0.8rem !important;
                text-align: right;
            }

            .table-premium th {
                font-size: 0.55rem;
                padding: 6px 4px;
            }

            .table-premium td {
                font-size: 0.7rem;
                padding: 6px 4px;
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

            .signature-section {
                flex-direction: column;
                gap: 40px;
                align-items: center;
                margin-top: 40px;
            }

            .sig-box {
                width: 200px;
            }

            .btn-print {
                width: calc(100% - 40px);
                left: 20px;
                bottom: 20px;
                border-radius: 8px;
            }

            .performance-insights {
                flex-direction: column !important;
                text-align: center;
                padding: 20px 10px !important;
            }

            .insight-text {
                padding-right: 0 !important;
                margin-bottom: 15px;
            }

            .page-divider-mobile {
                display: block !important;
            }
        }

        /* --- RADAR CHART STYLES --- */
        .performance-insights {
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 2;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #f1f5f9;
            padding: 30px 15px;
            text-align: center;
            overflow: visible;
            /* Crucial for labels */
        }

        .chart-container {
            width: 100%;
            height: 550px;
            margin-top: 15px;
            position: relative;
            overflow: visible;
        }

        #performanceRadar {
            width: 100%;
            height: 100%;
        }

        .insight-text {
            margin-bottom: 20px;
        }

        .insight-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            font-weight: 900;
            color: var(--primary-navy);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .insight-title i {
            color: var(--accent-gold);
            font-size: 1.1rem;
        }

        .insight-description {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Dark Mode Adjustments */
        [data-theme="dark"] .performance-insights {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .insight-title {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .insight-description {
            color: #94a3b8 !important;
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
        <button onclick="changeZoom(0.1)" class="zoom-btn" title="Zoom In">
            <i class="fas fa-plus"></i>
        </button>
        <button onclick="changeZoom(-0.1)" class="zoom-btn" title="Zoom Out">
            <i class="fas fa-minus"></i>
        </button>
        <button onclick="resetZoom()" class="zoom-btn" title="Reset">
            <i class="fas fa-expand"></i>
        </button>
    </div>

    <div class="marksheet-wrapper">
        <div class="marksheet-container" id="marksheet">
            <img src="logo/atn.png" alt="" class="watermark-logo">

            <div class="school-header">
                <img src="logo/atn.png" alt="School Logo" class="school-logo">
                <h1 class="school-name">ATN GIRLS HIGH SCHOOL</h1>
                <div class="transcript-title">Academic Achievement Record</div>
                <p class="text-muted small mt-2 mb-0">
                    <?php echo htmlspecialchars($exam_type) . ' Examination • Session ' . htmlspecialchars($year); ?>
                </p>
            </div>

            <div class="student-info-grid">
                <div class="info-column">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user-graduate me-2"></i>Student</span>
                        <span class="info-value"><?php echo strtoupper(htmlspecialchars($student['name'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user-tie me-2"></i>Father's Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['father_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-user-friends me-2"></i>Mother's Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($student['mother_name']); ?></span>
                    </div>
                </div>
                <div class="info-column">
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-school me-2"></i>Class / Sec</span>
                        <span class="info-value">
                            <?php echo htmlspecialchars($class_name) . ' (' . htmlspecialchars($section) . ')'; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-id-badge me-2"></i>Roll Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($roll); ?></span>
                    </div>
                    <?php if ($student['student_group'] && $student['student_group'] !== 'None'): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-layer-group me-2"></i>Group</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['student_group']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <table class="table-premium">
                <thead>
                    <tr>
                        <th>Subject</th>
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
                    <?php foreach ($marks as $m): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars(translate_subject($m['subject_name'], $class_name, $lang)); ?>
                                <?php if ($m['effective_optional']): ?>
                                    <span style="font-size: 0.65rem; color: #94a3b8; font-style: italic; margin-left: 6px;">(Optional)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)((isset($m['cq_marks']) ? $m['cq_marks'] : 0) + (isset($m['sq_marks']) ? $m['sq_marks'] : 0)); ?></td>
                            <td><?php echo isset($m['mcq_marks']) ? (int)$m['mcq_marks'] : '0'; ?></td>
                            <?php if (!$hide_practical): ?>
                                <td><?php echo ($m['has_practical'] == 1) ? (isset($m['practical_marks']) ? (int)$m['practical_marks'] : '0') : '-'; ?></td>
                            <?php endif; ?>
                            <td class="fw-bold"><?php echo isset($m['total_marks']) ? (int)$m['total_marks'] : '0'; ?></td>
                            <td>
                                <span class="grade-badge <?php echo ($m['grade'] ?? 'F') == 'F' ? 'grade-f' : ''; ?>">
                                    <?php echo $m['grade'] ?? 'F'; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($m['gpa'] ?? 0, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary-card">
                <div class="summary-item" style="--accent:#38bdf8;">
                    <div class="summary-label">Total Marks</div>
                    <div class="summary-value"><?php echo (int)$summary['total_marks']; ?><span>/<?php echo (int)$max_possible_marks; ?></span></div>
                </div>
                <div class="summary-item" style="--accent:#f59e0b;">
                    <div class="summary-label">Position</div>
                    <div class="summary-value" style="color: #f59e0b;"><?php echo htmlspecialchars($summary['position']); ?></div>
                </div>
                <div class="summary-item" style="--accent:<?php echo ($summary['final_grade'] == 'F') ? '#ef4444' : '#059669'; ?>;">
                    <div class="summary-label">Final Grade</div>
                    <div class="summary-value" style="color: <?php echo ($summary['final_grade'] == 'F') ? '#ef4444' : '#059669'; ?>;">
                        <?php echo htmlspecialchars($summary['final_grade']); ?></div>
                </div>
                <div class="summary-item" style="--accent:#8a38ff;">
                    <div class="summary-label">GPA Score</div>
                    <div class="summary-value" style="color: #8a38ff;">
                        <?php echo number_format($summary['total_gpa'], 2); ?>
                    </div>
                </div>
            </div>

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

        </div> <!-- End of Page 1 -->

        <script>
            let currentZoom = 1.0;
            const allPages = document.querySelectorAll('.marksheet-container');

            function changeZoom(delta) {
                currentZoom = Math.min(Math.max(0.5, currentZoom + delta), 2.0);
                updateZoom();
            }

            function resetZoom() {
                currentZoom = 1.0;
                updateZoom();
            }

            function updateZoom() {
                let totalHeight = 0;
                allPages.forEach(page => {
                    page.style.transform = `scale(${currentZoom})`;
                    totalHeight += page.offsetHeight * currentZoom;
                });

                // Adjust wrapper height for all pages combined
                const wrapper = document.querySelector('.marksheet-wrapper');
                if (wrapper) {
                    wrapper.style.minHeight = (totalHeight + 100) + 'px';
                }
            }

            // Initialize mobile default zoom if needed
            window.addEventListener('load', () => {
                if (window.innerWidth < 768) {
                    // If it's a very small screen, we might want to start slightly zoomed out
                    // but our existing CSS already handles responsive width.
                }
            });

            function changeLanguage(lang) {
                const url = new URL(window.location.href);
                url.searchParams.set('lang', lang);
                window.location.href = url.toString();
            }
        </script>
</body>

</html>