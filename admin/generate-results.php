<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

// Fetch all class data for mapping
$stmt = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, LENGTH(class_name), class_name, section");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extract unique years for the initial dropdown
$years = array_unique(array_column($all_classes, 'academic_year'));
$current_year = date('Y');
if (!in_array($current_year, $years)) {
    array_unshift($years, $current_year);
}
sort($years); // Sort ascending so the lowest year is first

// Variables to retain state after submission
$sel_year_post = $current_year;
$sel_class_post = 'all';
$exam_type_post = 'all';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate'])) {
    $sel_year_post = $_POST['year'];
    $sel_class_post = $_POST['class_name'];
    $exam_type_post = $_POST['exam_type'];

    try {
        $conn->beginTransaction();

        // Determine which exam types to process
        $exam_types_to_process = ($exam_type_post === 'all') ? ['Half Yearly', 'Final'] : [$exam_type_post];

        $total_students_processed = 0;
        $total_marks_updated = 0;

        // Build a map of subject total marks from JSON
        $subject_totals = [];
        $subject_totals_flat = [];
        $json_file = '../json/all_classes_subject_V6.json';
        if (file_exists($json_file)) {
            $json_data = json_decode(file_get_contents($json_file), true);
            if (isset($json_data['classes'])) {
                foreach ($json_data['classes'] as $cls) {
                    $json_cname = trim($cls['class_name']);
                    foreach ($cls['groups'] as $grp) {
                        foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
                            if (isset($grp['subjects'][$type])) {
                                foreach ($grp['subjects'][$type] as $sub) {
                                    $sname = trim($sub['name_en']);
                                    $marks = $sub['marks_distribution']['total_marks'] ?? 100;
                                    $subject_totals[$json_cname][$sname] = $marks;

                                    // Build a flat fallback just in case the class doesn't perfectly match
                                    // We only set this if it's not already set, or if we want the lowest common denominator
                                    $subject_totals_flat[$sname] = $marks;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($exam_types_to_process as $exam_type) {
            $classes_to_process = [];

            // Build query based on selections
            $query = "SELECT id FROM classes WHERE academic_year = ?";
            $params = [$sel_year_post];

            if ($sel_class_post !== 'all' && $sel_class_post !== '') {
                $query .= " AND class_name = ?";
                $params[] = $sel_class_post;
            }

            $stmt_find = $conn->prepare($query);
            $stmt_find->execute($params);
            $classes_to_process = $stmt_find->fetchAll(PDO::FETCH_COLUMN);

            if (empty($classes_to_process)) {
                if ($exam_type_post !== 'all') {
                    throw new Exception("No classes found matching your selection.");
                }
                continue;
            }

            foreach ($classes_to_process as $class_id) {
                // Fetch class info inline — pre-fetched before this loop for efficiency
                // (We build a $class_info_map before the loop; fall back to a single query if missing)
                if (!isset($class_info_map)) {
                    $stmt_all_c = $conn->query("SELECT id, class_name FROM classes");
                    $class_info_map = [];
                    foreach ($stmt_all_c->fetchAll() as $row) {
                        $class_info_map[$row['id']] = $row['class_name'];
                    }
                }
                $db_class_name = $class_info_map[$class_id] ?? '';

                $lookup_class = $db_class_name;
                if (strpos($db_class_name, 'Class 9') !== false || strpos($db_class_name, 'Class 10') !== false) {
                    $lookup_class = 'Class 9 & 10 (SSC)';
                }

                // 1. Fetch all students in the class
                $stmt = $conn->prepare("SELECT id, roll_number, name, class_id, student_group, main_elective_id, optional_subject_id FROM students WHERE class_id = ?");
                $stmt->execute([$class_id]);
                $students = $stmt->fetchAll();

                foreach ($students as $student) {
                    $student_id = $student['id'];

                    // Join starting from class_subjects to ensure we check ALL required subjects
                    $stmt_marks = $conn->prepare("SELECT cs.subject_id, s.subject_name, cs.is_optional, cs.is_school_based,
                                               m.id as mark_id, m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks,
                                               std.optional_subject_id, std.main_elective_id, std.student_group
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
                                                 )");
                    $stmt_marks->execute([$exam_type, $student_id]);
                    $student_marks = $stmt_marks->fetchAll();

                    $compulsory_gps = [];
                    $optional_gps = [];
                    $total_marks = 0;
                    $failed_compulsory = false;

                    foreach ($student_marks as $m) {
                        if ($m['mark_id']) {
                            // Marks exist - calculate as normal
                            $sub_total = floatval($m['cq_marks']) + floatval($m['mcq_marks']) + floatval($m['sq_marks']) + floatval($m['practical_marks']);

                            $sname = trim($m['subject_name']);
                            $lname = trim($lookup_class);

                            // Try exact class match first, then fallback to any class, then default to 100
                            $max_marks = $subject_totals[$lname][$sname] ?? $subject_totals_flat[$sname] ?? 100;

                            // Fallback for known 50-mark subjects if exact match fails
                            if ($max_marks == 100) {
                                if (stripos($sname, 'Physical Education') !== false || stripos($sname, 'ICT') !== false || stripos($sname, 'Information & Communication') !== false) {
                                    $max_marks = 50;
                                }
                            }

                            $gp = 0;
                            $grade = 'F';

                            if ($max_marks == 50) {
                                // 50-mark scale (percentage-aligned with 100-mark)
                                // A+ >= 40 (80%), A >= 35 (70%), A- >= 30 (60%), B >= 25 (50%), C >= 20 (40%), D >= 16 (32%)
                                if ($sub_total >= 40) {
                                    $gp = 5.0;
                                    $grade = 'A+';
                                } elseif ($sub_total >= 35) {
                                    $gp = 4.0;
                                    $grade = 'A';
                                } elseif ($sub_total >= 30) {
                                    $gp = 3.5;
                                    $grade = 'A-';
                                } elseif ($sub_total >= 25) {
                                    $gp = 3.0;
                                    $grade = 'B';
                                } elseif ($sub_total >= 20) {
                                    $gp = 2.0;
                                    $grade = 'C';
                                } elseif ($sub_total >= 16) {
                                    $gp = 1.0;
                                    $grade = 'D';
                                }
                            } else {
                                // Default 100-mark scale
                                if ($sub_total >= 80) {
                                    $gp = 5.0;
                                    $grade = 'A+';
                                } elseif ($sub_total >= 70) {
                                    $gp = 4.0;
                                    $grade = 'A';
                                } elseif ($sub_total >= 60) {
                                    $gp = 3.5;
                                    $grade = 'A-';
                                } elseif ($sub_total >= 50) {
                                    $gp = 3.0;
                                    $grade = 'B';
                                } elseif ($sub_total >= 40) {
                                    $gp = 2.0;
                                    $grade = 'C';
                                } elseif ($sub_total >= 33) {
                                    $gp = 1.0;
                                    $grade = 'D';
                                }
                            }

                            // Update marks table
                            $upd = $conn->prepare("UPDATE marks SET total_marks = ?, grade = ?, gpa = ? WHERE id = ?");
                            $upd->execute([$sub_total, $grade, $gp, $m['mark_id']]);
                            $total_marks_updated++;
                        } else {
                            // Marks missing - treat as ABSENT / FAIL
                            $sub_total = 0;
                            $gp = 0;
                            $grade = 'F';
                        }

                        $total_marks += $sub_total;

                        $is_class_9_10 = (strpos($db_class_name, 'Class 9') !== false || strpos($db_class_name, 'Class 10') !== false);

                        // BD Grading System Logic
                        // is_school_based = 1 -> School internal subject (PE, Career). Does not count in GPA.
                        // Also exclude known non-GPA optional subjects
                        
                        $is_actually_optional = ($m['subject_id'] == $m['optional_subject_id']);
                        $is_school_based = ($m['is_school_based'] ?? 0);
                        $sname_trim = trim($m['subject_name']);
                        $is_non_gpa_optional = ($is_actually_optional && in_array(
                            $sname_trim,
                            ['Physical Education & Health', 'Information & Communication Technology (ICT)', 'Work and Life-Oriented Education', 'Arts & Crafts']
                        ));

                        // Only add to GPA if it's not school-based and not a non-GPA optional subject
                        if (!$is_school_based && !$is_non_gpa_optional) {
                            if (!$is_actually_optional || !$is_class_9_10) {
                                $compulsory_gps[] = $gp;
                            } else {
                                // Optional subjects (Class 9/10 only) that DO count in GPA
                                $optional_gps[] = $gp;
                            }
                        }

                        if ($gp == 0 && !$is_school_based && !$is_non_gpa_optional) {
                            $failed_compulsory = true;
                        }
                    }

                    // Calculate Base GPA from Compulsory Subjects
                    $subject_count = count($compulsory_gps);
                    $compulsory_sum = array_sum($compulsory_gps);
                    
                    // APPLY STANDARD BD FORMULA: Optional GP > 2.0 adds to total GP
                    $optional_bonus = 0;
                    if (!empty($optional_gps)) {
                        foreach ($optional_gps as $opt_gp) {
                            if ($opt_gp > 2.0) {
                                $optional_bonus += ($opt_gp - 2.0);
                            }
                        }
                    }

                    // Calculate Final GPA
                    if ($subject_count > 0) {
                        $final_gpa = ($compulsory_sum + $optional_bonus) / $subject_count;
                    } else {
                        $final_gpa = 0;
                    }

                    $final_gpa = min(5.0, $final_gpa);

                    // Strictly enforce failure condition
                    if ($failed_compulsory) {
                        $final_gpa = 0;
                    }

                    $final_grade = 'F';
                    if ($final_gpa >= 5.0)
                        $final_grade = 'A+';
                    elseif ($final_gpa >= 4.0)
                        $final_grade = 'A';
                    elseif ($final_gpa >= 3.5)
                        $final_grade = 'A-';
                    elseif ($final_gpa >= 3.0)
                        $final_grade = 'B';
                    elseif ($final_gpa >= 2.0)
                        $final_grade = 'C';
                    elseif ($final_gpa >= 1.0)
                        $final_grade = 'D';

                    $status = ($final_gpa > 0) ? 'Pass' : 'Fail';

                    // Insert/Update final results. The UNIQUE KEY ensures we overwrite properly.
                    // NEW: Results are compiled but NOT published by default (is_published = 0)
                    $stmt_res = $conn->prepare("INSERT INTO final_results 
                                                (student_id, exam_type, total_marks, total_gpa, final_grade, status, is_published) 
                                                VALUES (?, ?, ?, ?, ?, ?, 0) 
                                                ON DUPLICATE KEY UPDATE 
                                                    total_marks = VALUES(total_marks), 
                                                    total_gpa = VALUES(total_gpa), 
                                                    final_grade = VALUES(final_grade), 
                                                    status = VALUES(status),
                                                    is_published = 0");
                    $stmt_res->execute([$student_id, $exam_type, $total_marks, $final_gpa, $final_grade, $status]);
                    $total_students_processed++;
                }
            }

            // 3. Calculate Global Positions
            // Pre-build a map of class_id -> {class_name, academic_year} to avoid N+1 queries
            $placeholders = implode(',', array_fill(0, count($classes_to_process), '?'));
            $stmt_bulk_c = $conn->prepare("SELECT id, class_name, academic_year FROM classes WHERE id IN ($placeholders)");
            $stmt_bulk_c->execute($classes_to_process);
            $class_bulk_map = [];
            foreach ($stmt_bulk_c->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $class_bulk_map[$row['id']] = $row;
            }

            $processed_groups = [];
            foreach ($classes_to_process as $cid) {
                $c_info = $class_bulk_map[$cid] ?? null;
                if ($c_info) {
                    $key = $c_info['academic_year'] . '_' . $c_info['class_name'];
                    if (!isset($processed_groups[$key])) {
                        $processed_groups[$key] = $c_info;
                    }
                }
            }

            foreach ($processed_groups as $group) {
                $year = $group['academic_year'];
                $cname = $group['class_name'];

                $rank_sql = "
                    SELECT fr.id, fr.total_gpa, fr.total_marks, fr.status 
                    FROM final_results fr
                    JOIN students s ON fr.student_id = s.id
                    JOIN classes c ON s.class_id = c.id
                    WHERE fr.exam_type = ? 
                    AND c.academic_year = ? 
                    AND c.class_name = ?
                    ORDER BY 
                        CASE WHEN fr.status = 'Pass' THEN 1 ELSE 0 END DESC,
                        fr.total_gpa DESC, 
                        fr.total_marks DESC
                ";

                $stmt_rank = $conn->prepare($rank_sql);
                $stmt_rank->execute([$exam_type, $year, $cname]);
                $ranked_students = $stmt_rank->fetchAll(PDO::FETCH_ASSOC);

                $rank = 1;
                $update_stmt = $conn->prepare("UPDATE final_results SET position = ? WHERE id = ?");

                foreach ($ranked_students as $rs) {
                    $update_stmt->execute([$rank, $rs['id']]);
                    $rank++;
                }
            }
        }

        // Log the activity
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $log_details = "Session: $sel_year_post | Target: " . ($sel_class_post ?: 'All Classes') . " | Exam: $exam_type_post";
        $stmt_log->execute([$_SESSION['admin_id'], "Compiled Results", $log_details]);

        $conn->commit();
        $evaluations = $total_students_processed;
        $unique_students = count(array_unique($processed_student_ids ?? []));
        // Fallback if we didn't track unique IDs in this run
        if ($unique_students == 0 && $total_students_processed > 0) {
            $unique_students = ($exam_type_post === 'all') ? ($total_students_processed / 2) : $total_students_processed;
        }

        $message = "Synthesis Complete! Processed <b>" . ceil($unique_students) . "</b> students across <b>$evaluations</b> exam evaluations and <b>$total_marks_updated</b> subject entries. <br><strong style='color: #f59e0b;'>⚠️ Results are compiled but NOT published yet.</strong> Use the toggle button below to publish when ready.";
    } catch (Exception $e) {
        if ($conn->inTransaction())
            $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Process Results & Analytics - SRMS Admin</title>
    <?php include('header.php'); ?>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7fc;
        }

        /* Hero Banner */
        .results-hero {
            background: linear-gradient(135deg, var(--prestige-navy) 0%, #1e293b 100%);
            border-radius: 20px;
            padding: 2.5rem;
            color: white;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.2);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            border-left: 5px solid var(--prestige-gold);
        }

        .results-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            filter: blur(40px);
        }

        .hero-title {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 2.2rem;
            letter-spacing: -0.5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }

        .hero-subtitle {
            font-weight: 300;
            opacity: 0.9;
            font-size: 1.05rem;
        }

        .hero-icon-container {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            backdrop-filter: blur(10px);
            color: var(--prestige-gold-light);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Glassmorphism Card */
        .config-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 24px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.04);
            padding: 2.5rem;
            transition: all 0.3s ease;
        }

        .form-label-custom {
            font-weight: 600;
            color: var(--prestige-navy);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
        }

        .form-label-custom i {
            color: var(--prestige-gold);
            margin-right: 8px;
            font-size: 1.1rem;
        }

        .select-premium {
            appearance: none;
            background-color: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 500;
            color: #2d3748;
            transition: all 0.2s ease;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%234a5568' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1.2rem center;
            background-size: 12px;
        }

        .select-premium:focus {
            outline: none;
            border-color: #4e73df;
            box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.15);
            background-color: white;
        }

        .select-premium:hover {
            border-color: #cbd5e1;
        }

        /* Premium Buttons */
        .btn-launch {
            background: linear-gradient(135deg, #f59e0b, #d97706) !important;
            border: 2px solid transparent !important;
            /* Match border width of export button */
            border-radius: 12px;
            color: #0f172a !important;
            font-weight: 700;
            font-size: 1rem;
            padding: 12px 24px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
            box-shadow: 0 10px 30px rgba(180, 83, 9, 0.2) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        @keyframes pulse-launch {
            0% {
                box-shadow: 0 10px 25px rgba(217, 119, 6, 0.3);
            }

            50% {
                box-shadow: 0 15px 45px rgba(217, 119, 6, 0.5);
                transform: translateY(-2px) scale(1.01);
            }

            100% {
                box-shadow: 0 10px 25px rgba(217, 119, 6, 0.3);
            }
        }

        .btn-launch:hover {
            transform: translateY(-3px) !important;
            box-shadow: 0 12px 30px rgba(180, 83, 9, 0.3) !important;
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            color: #000 !important;
        }

        .btn-launch:active {
            transform: translateY(0);
        }

        .btn-export-pdf {
            background: white;
            border: 2px solid var(--prestige-gold);
            color: var(--prestige-gold);
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            padding: 12px 24px;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-export-pdf:hover {
            background: var(--prestige-gold);
            color: white;
            box-shadow: 0 8px 25px rgba(180, 83, 9, 0.2);
            transform: translateY(-3px);
        }

        /* Intelligent Tips Section */
        .ai-tip-box {
            background: rgba(180, 83, 9, 0.05);
            border: 1px dashed rgba(180, 83, 9, 0.2);
            border-radius: 20px;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .ai-tip-icon {
            font-size: 2rem;
            color: var(--prestige-gold);
            background: white;
            padding: 12px;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(180, 83, 9, 0.1);
        }

        .ai-tip-text h6 {
            font-weight: 700;
            color: var(--prestige-navy);
            margin-bottom: 0.5rem;
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem;
        }

        .ai-tip-text p {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 0;
            line-height: 1.6;
        }

        /* Animation for spinner */
        @keyframes spinPulse {
            0% {
                transform: rotate(0deg) scale(1);
            }

            50% {
                transform: rotate(180deg) scale(1.1);
            }

            100% {
                transform: rotate(360deg) scale(1);
            }
        }

        .spin-pulse {
            animation: spinPulse 1.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) infinite;
        }

        .pulse-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            z-index: 10;
            border-radius: 24px;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        @media (max-width: 768px) {
            .results-hero {
                padding: 1.5rem;
            }

            .config-card {
                padding: 1.5rem;
            }
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .config-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2) !important;
        }

        [data-theme="dark"] .form-label-custom {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .select-premium {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23cbd5e1' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e") !important;
        }

        [data-theme="dark"] .select-premium:focus {
            background-color: #0f172a !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .ai-tip-box {
            background: rgba(180, 83, 9, 0.05) !important;
            border-color: rgba(180, 83, 9, 0.1) !important;
        }

        [data-theme="dark"] .ai-tip-icon {
            background: #1e293b !important;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2) !important;
        }

        [data-theme="dark"] .ai-tip-text h6 {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .ai-tip-text p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .pulse-overlay {
            background: rgba(10, 16, 32, 0.9) !important;
        }

        [data-theme="dark"] .pulse-overlay h4 {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .btn-export-pdf {
            background: transparent !important;
            color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .btn-export-pdf:hover {
            background: var(--prestige-gold) !important;
            color: white !important;
        }

        [data-theme="dark"] .page-header h1 {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .page-header p.text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .btn-launch {
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            color: #000 !important;
            box-shadow: 0 10px 40px rgba(245, 158, 11, 0.25) !important;
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">

            <!-- Standard Page Header (Matching Marks Entry Status) -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Result Processing Engine</h1>
                        <p class="mb-0">Compile marks and calculate global rankings.</p>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Synthesis Complete!',
                        html: '<?php echo $message; ?>',
                        confirmButtonColor: '#10ac84',
                        background: '#ffffff',
                        customClass: {
                            popup: 'rounded-4 shadow-lg'
                        }
                    });
                </script>
            <?php endif; ?>

            <?php if ($error): ?>
                <script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Synthesis Failed',
                        text: '<?php echo addslashes($error); ?>',
                        confirmButtonColor: '#e74a3b',
                        customClass: {
                            popup: 'rounded-4 shadow-lg'
                        }
                    });
                </script>
            <?php endif; ?>

            <!-- Premium Config Card -->
            <div class="card config-card position-relative">
                <div class="pulse-overlay" id="loadingOverlay">
                    <div class="text-center">
                        <i class="fas fa-cog spin-pulse text-primary fa-4x mb-3"></i>
                        <h4 class="fw-bold text-gray-800">Processing Data Matrix...</h4>
                        <p class="text-muted">Aggregating marks and calculating positions</p>
                    </div>
                </div>

                <form action="" method="POST" id="processForm">
                    <div class="row g-4">
                        <!-- Year Selection -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label-custom">
                                <i class="fas fa-calendar-check"></i> Academic Session
                            </label>
                            <select name="year" id="year" class="form-select select-premium" required
                                onchange="updateClasses()">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == $sel_year_post ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Class Selection -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label-custom">
                                <i class="fas fa-graduation-cap"></i> Target Class
                            </label>
                            <select name="class_name" id="class_name" class="form-select select-premium" required
                                onchange="updateSections()">
                                <option value="all">All Classes</option>
                                <!-- Populated dynamically by JS -->
                            </select>
                        </div>

                        <!-- Section Selection -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label-custom">
                                <i class="fas fa-layer-group"></i> Target Section
                            </label>
                            <select name="section" id="section" class="form-select select-premium" required>
                                <option value="all">All Sections</option>
                                <!-- Populated dynamically by JS -->
                            </select>
                        </div>

                        <!-- Exam Selection -->
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label-custom">
                                <i class="fas fa-clipboard-list"></i> Evaluation Type
                            </label>
                            <select name="exam_type" id="exam_type" class="form-select select-premium" required>
                                <option value="all" <?php echo $exam_type_post == 'all' ? 'selected' : ''; ?>>All Exams
                                </option>
                                <option value="Half Yearly" <?php echo $exam_type_post == 'Half Yearly' ? 'selected' : ''; ?>>Half Yearly Evaluation</option>
                                <option value="Final" <?php echo $exam_type_post == 'Final' ? 'selected' : ''; ?>>Final
                                    Year Assessment</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-5 g-3">
                        <div class="col-md-6">
                            <button type="submit" name="generate" class="btn btn-launch">
                                <i class="fas fa-rocket me-2"></i> Compile & Finalize Results
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" onclick="exportPDF()" class="btn btn-export-pdf">
                                <i class="fas fa-file-pdf me-2"></i> Export Master Sheet
                            </button>
                        </div>
                    </div>

                    <!-- Publish Control Section -->
                    <div class="row mt-4 g-3" id="publishControlSection" style="display: none;">
                        <div class="col-12">
                            <div class="card border-warning shadow-sm">
                                <div class="card-header bg-warning text-dark">
                                    <i class="fas fa-eye me-2"></i><strong>Result Publishing Control</strong>
                                </div>
                                <div class="card-body">
                                    <p class="mb-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            Results are compiled but hidden from students. Use the toggle below to publish/hide results instantly.
                                        </small>
                                    </p>
                                    <div class="d-flex align-items-center gap-3">
                                        <button type="button" id="btnTogglePublish" class="btn btn-outline-primary" onclick="togglePublish()">
                                            <i class="fas fa-toggle-off me-2"></i> <span id="publishBtnText">Publish Results</span>
                                        </button>
                                        <span id="publishStatusBadge" class="badge bg-secondary">Checking status...</span>
                                        <span id="publishCountInfo" class="text-muted small ms-2"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- AI Tip Box (Compact) -->
            <div class="ai-tip-box py-3 px-4 mt-3 rounded-4">
                <div class="ai-tip-icon" style="font-size: 1.2rem; padding: 8px;">
                    <i class="fas fa-sparkles"></i>
                </div>
                <div class="ai-tip-text">
                    <h6 class="mb-1" style="font-size: 0.95rem;">Intelligent Ranking Architecture</h6>
                    <p class="small">The engine automatically detects students across all sections. Rankings are
                        determined by Status &rarr; GPA &rarr; Gross Marks.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Initialization Script -->
    <script>
        const allData = <?php echo json_encode($all_classes); ?>;

        // PHP state retention variables
        const retainedClass = "<?php echo addslashes($sel_class_post); ?>";

        function updateClasses() {
            const year = document.getElementById('year').value;
            const classSelect = document.getElementById('class_name');

            // Get unique classes for this year
            const filteredClasses = [...new Set(allData
                .filter(c => c.academic_year == year)
                .map(c => c.class_name))];

            classSelect.innerHTML = '<option value="all" ' + (retainedClass === "all" ? "selected" : "") + '>Analyze All Classes</option>';
            filteredClasses.forEach(cls => {
                const opt = document.createElement('option');
                opt.value = cls;
                opt.textContent = cls;
                if (cls === retainedClass) {
                    opt.selected = true;
                }
                classSelect.appendChild(opt);
            });
            updateSections();
        }

        function updateSections() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const sectionSelect = document.getElementById('section');

            if (className === 'all') {
                sectionSelect.innerHTML = '<option value="all">All Sections</option>';
                return;
            }

            const filteredSections = [...new Set(allData
                .filter(c => c.academic_year == year && c.class_name == className)
                .map(c => c.section))];

            sectionSelect.innerHTML = '<option value="all">All Sections</option>';
            filteredSections.forEach(sec => {
                const opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = 'Section ' + sec;
                sectionSelect.appendChild(opt);
            });
        }

        function exportPDF() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const examType = document.getElementById('exam_type').value;
            const sectionName = document.getElementById('section').value;

            if (className === 'all' || !className) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Class Selection Required',
                    text: 'Master Sheets are massive. Please select a specific Target Class to generate the combined PDF.',
                    confirmButtonColor: '#4e73df',
                    customClass: { popup: 'rounded-4 shadow-lg' }
                });
                return;
            }

            if (examType === 'all') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Exam Selection Required',
                    text: 'Please select either "Half Yearly" or "Final" to export a Master Sheet.',
                    confirmButtonColor: '#4e73df',
                    customClass: { popup: 'rounded-4 shadow-lg' }
                });
                return;
            }

            window.open(`view-section-results.php?class_name=${encodeURIComponent(className)}&academic_year=${encodeURIComponent(year)}&exam_type=${encodeURIComponent(examType)}&section=${encodeURIComponent(sectionName)}`, '_blank');
        }

        // Add loading state on submit
        document.getElementById('processForm').addEventListener('submit', function (e) {
            document.getElementById('loadingOverlay').style.display = 'flex';
        });

        // Initialize classes on page load
        window.addEventListener('DOMContentLoaded', (event) => {
            updateClasses();
        });

        // ============================================
        // RESULT PUBLISH CONTROL FUNCTIONS
        // ============================================
        
        // Show publish control section after compilation
        <?php if ($message): ?>
        document.getElementById('publishControlSection').style.display = 'flex';
        checkPublishStatus();
        <?php endif; ?>

        async function checkPublishStatus() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const examType = document.getElementById('exam_type').value;

            if (!className || className === 'all' || !examType || examType === 'all') {
                document.getElementById('publishStatusBadge').textContent = 'Select specific class & exam';
                return;
            }

            try {
                // First get class_id from class_name
                const classData = allData.find(c => 
                    c.class_name === className && 
                    c.academic_year == year
                );

                if (!classData) {
                    document.getElementById('publishStatusBadge').textContent = 'Class not found';
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'get_status');
                formData.append('class_id', classData.id);
                formData.append('exam_type', examType);

                const response = await fetch('api/toggle-result-publish.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success && result.data) {
                    const isPublished = result.data.is_published == 1;
                    const totalStudents = result.data.total_students || 0;
                    const publishedCount = result.data.published_count || 0;

                    updatePublishUI(isPublished, totalStudents, publishedCount);
                } else {
                    document.getElementById('publishStatusBadge').textContent = 'Status unavailable';
                }
            } catch (error) {
                console.error('Error checking publish status:', error);
                document.getElementById('publishStatusBadge').textContent = 'Error checking status';
            }
        }

        function updatePublishUI(isPublished, totalStudents, publishedCount) {
            const badge = document.getElementById('publishStatusBadge');
            const btnText = document.getElementById('publishBtnText');
            const btnIcon = document.querySelector('#btnTogglePublish i');
            const countInfo = document.getElementById('publishCountInfo');

            if (isPublished) {
                badge.className = 'badge bg-success';
                badge.textContent = '✓ Published';
                btnText.textContent = 'Hide Results';
                btnIcon.className = 'fas fa-toggle-on me-2';
                document.getElementById('btnTogglePublish').className = 'btn btn-outline-success';
            } else {
                badge.className = 'badge bg-warning text-dark';
                badge.textContent = '✗ Hidden';
                btnText.textContent = 'Publish Results';
                btnIcon.className = 'fas fa-toggle-off me-2';
                document.getElementById('btnTogglePublish').className = 'btn btn-outline-primary';
            }

            countInfo.textContent = `(${publishedCount}/${totalStudents} students visible)`;
        }

        async function togglePublish() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const examType = document.getElementById('exam_type').value;

            if (!className || className === 'all' || !examType || examType === 'all') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Selection Required',
                    text: 'Please select a specific class and exam type first.',
                    confirmButtonColor: '#4e73df'
                });
                return;
            }

            // Get current status to determine action
            const classData = allData.find(c => 
                c.class_name === className && 
                c.academic_year == year
            );

            if (!classData) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Class not found in database.',
                    confirmButtonColor: '#dc3545'
                });
                return;
            }

            const btn = document.getElementById('btnTogglePublish');
            const wasDisabled = btn.disabled;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';

            try {
                const formData = new FormData();
                formData.append('action', 'toggle_publish');
                formData.append('class_id', classData.id);
                formData.append('exam_type', examType);

                const response = await fetch('api/toggle-result-publish.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    const isPublished = result.data.is_published == 1;
                    
                    Swal.fire({
                        icon: isPublished ? 'success' : 'info',
                        title: isPublished ? 'Results Published!' : 'Results Hidden',
                        text: result.message,
                        confirmButtonColor: isPublished ? '#28a745' : '#ffc107',
                        customClass: { popup: 'rounded-4 shadow-lg' }
                    });

                    // Update UI
                    updatePublishUI(isPublished, result.data.affected_students, isPublished ? result.data.affected_students : 0);
                } else {
                    throw new Error(result.message || 'Unknown error occurred');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message || 'Failed to toggle publish status. Please try again.',
                    confirmButtonColor: '#dc3545',
                    customClass: { popup: 'rounded-4 shadow-lg' }
                });
            } finally {
                btn.disabled = wasDisabled;
                // Refresh status
                setTimeout(() => checkPublishStatus(), 1000);
            }
        }
    </script>
</body>

</html>