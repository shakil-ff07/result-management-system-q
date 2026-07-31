<?php
include('auth.php');
include('../includes/db_config.php');
include('../includes/lang_helper.php');

$message = "";
$error = "";

// -----------------------------

// --- ADMIN SYSTEM CONTROLS (SIMPLIFIED) ---
if (is_admin() && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_marks_status'])) {
    $marks_enabled = isset($_POST['marks_entry_enabled']) ? '1' : '0';
    $default_lock_msg = "Marks entry is currently closed. Please contact the administrator for access.";
    
    try {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('marks_entry_enabled', :m), ('lock_message', :l) 
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['m' => $marks_enabled, 'l' => $default_lock_msg]);
    } catch (PDOException $e) {
        $error = "Error updating status: " . $e->getMessage();
    }
}

// Always fetch settings for use in the page
$stmt_sys = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$sys_settings = $stmt_sys->fetchAll(PDO::FETCH_KEY_PAIR);
$marks_entry_permitted = ($sys_settings['marks_entry_enabled'] ?? '1') === '1';
$lock_notice = $sys_settings['lock_message'] ?? "Marks entry is currently closed. Please contact the administrator.";

// Admin always has bypass permission for testing/emergencies
if (is_admin()) {
    $marks_entry_permitted = true;
}
// -----------------------------

// Get step and identifiers
$academic_year = $_GET['academic_year'] ?? null;
$step = $_GET['step'] ?? ($academic_year ? 'classes' : 'years'); // Default to years if no year selected

$class_name = $_GET['class_name'] ?? null;
$class_id = $_GET['class_id'] ?? null;
$subject_id = $_GET['subject_id'] ?? null;
$exam_type = $_GET['exam_type'] ?? 'Half Yearly';

// --- SMART REDIRECT LOGIC ---
// If landing on years step without a specific intent to view all, 
// auto-redirect to the current session classes if it exists.
if ($step == 'years' && !isset($_GET['view_all'])) {
    $current_cal_year = date('Y');
    $check_stmt = $conn->prepare("SELECT DISTINCT academic_year FROM classes WHERE academic_year = ?");
    $check_stmt->execute([$current_cal_year]);
    if ($check_stmt->fetch()) {
        header("Location: marks-entry.php?step=classes&academic_year=" . urlencode($current_cal_year));
        exit();
    }
}
// ----------------------------

// Data fetching based on step
$years_list = [];
$classes_list = [];
$sections_list = [];
$subjects_list = [];
$students = [];
$show_practical = false;
$current_class_info = null;
$subject_info = null;

if ($step == 'years') {
    if (is_teacher()) {
        $stmt = $conn->prepare("SELECT DISTINCT c.academic_year 
                              FROM classes c 
                              JOIN teacher_assignments ta ON c.id = ta.class_id 
                              WHERE ta.teacher_id = ? 
                              ORDER BY c.academic_year ASC");
        $stmt->execute([$_SESSION['admin_id']]);
        $years_list = $stmt->fetchAll();
    } else {
        $years_list = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC")->fetchAll();
    }
} elseif ($step == 'classes' && $academic_year) {
    if (is_teacher()) {
        $stmt = $conn->prepare("SELECT DISTINCT c.class_name 
                              FROM classes c 
                              JOIN teacher_assignments ta ON c.id = ta.class_id 
                              WHERE ta.teacher_id = ? AND c.academic_year = ?
                              ORDER BY LENGTH(c.class_name), c.class_name");
        $stmt->execute([$_SESSION['admin_id'], $academic_year]);
        $classes_list = $stmt->fetchAll();
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT class_name FROM classes WHERE academic_year = ? ORDER BY LENGTH(class_name), class_name");
        $stmt->execute([$academic_year]);
        $classes_list = $stmt->fetchAll();
    }
} elseif ($step == 'sections' && $class_name && $academic_year) {
    if (is_teacher()) {
        $stmt = $conn->prepare("SELECT DISTINCT c.* 
                              FROM classes c 
                              JOIN teacher_assignments ta ON c.id = ta.class_id 
                              WHERE c.class_name = ? AND ta.teacher_id = ? AND c.academic_year = ?
                              ORDER BY c.section");
        $stmt->execute([$class_name, $_SESSION['admin_id'], $academic_year]);
        $sections_list = $stmt->fetchAll();
    } else {
        $stmt = $conn->prepare("SELECT * FROM classes WHERE class_name = ? AND academic_year = ? ORDER BY section");
        $stmt->execute([$class_name, $academic_year]);
        $sections_list = $stmt->fetchAll();
    }
} elseif ($step == 'subjects' && $class_id) {
    // Get class info
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $current_class_info = $stmt->fetch();
    $academic_year = $current_class_info['academic_year']; // Sync with class

    // Fetch subjects for this class
    if (is_teacher()) {
        $stmt = $conn->prepare("SELECT DISTINCT s.* FROM subjects s 
                              JOIN teacher_assignments ta ON s.id = ta.subject_id 
                              WHERE ta.class_id = ? AND ta.teacher_id = ?");
        $stmt->execute([$class_id, $_SESSION['admin_id']]);
        $subjects_list = $stmt->fetchAll();
    } else {
        $stmt = $conn->prepare("SELECT s.*, cs.is_optional, cs.is_school_based FROM subjects s 
                              JOIN class_subjects cs ON s.id = cs.subject_id 
                              WHERE cs.class_id = ?
                              GROUP BY s.id
                              ORDER BY cs.is_optional ASC, cs.is_school_based ASC, s.id ASC");
        $stmt->execute([$class_id]);
        $subjects_list = $stmt->fetchAll();
    }
} elseif ($step == 'marks' && $class_id && $subject_id) {
    // Security check for teachers
    if (is_teacher()) {
        $stmt = $conn->prepare("SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?");
        $stmt->execute([$_SESSION['admin_id'], $class_id, $subject_id]);
        if (!$stmt->fetch()) {
            header("Location: marks-entry.php?error=unauthorized_assignment");
            exit();
        }
    }

    // Get class info
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $current_class_info = $stmt->fetch();
    $current_class_name = $current_class_info['class_name'];
    $academic_year = $current_class_info['academic_year'];

    // Get subject info
    $stmt = $conn->prepare("SELECT * FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subject_info = $stmt->fetch();
    // Check if subject has practical
    $show_practical = (bool) ($subject_info['has_practical'] ?? false);

    // Get subject marks distribution from JSON
    $subj_dist = get_subject_marks_distribution($current_class_name, $subject_info['subject_name']);
    $show_sq = isset($subj_dist['written']['sq']) && $subj_dist['written']['sq'] > 0;

    // Determine whether this subject should be restricted to assigned students
    $stmt = $conn->prepare("SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ? AND (is_optional = 1 OR is_school_based = 1) LIMIT 1");
    $stmt->execute([$class_id, $subject_id]);
    $subject_is_opt_like = (bool) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND (main_elective_id = ? OR optional_subject_id = ?)");
    $stmt->execute([$class_id, $subject_id, $subject_id]);
    $assigned_students = (int) $stmt->fetchColumn();

    if ($subject_is_opt_like || $assigned_students > 0) {
        $stmt = $conn->prepare("SELECT s.*, m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks 
                              FROM students s 
                              LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_type = ?
                              WHERE s.class_id = ?
                                AND (s.main_elective_id = ? OR s.optional_subject_id = ?)
                              ORDER BY s.roll_number");
        $stmt->execute([$subject_id, $exam_type, $class_id, $subject_id, $subject_id]);
    } else {
        $stmt = $conn->prepare("SELECT s.*, m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks 
                              FROM students s 
                              LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_type = ?
                              WHERE s.class_id = ?
                              ORDER BY s.roll_number");
        $stmt->execute([$subject_id, $exam_type, $class_id]);
    }
    $students = $stmt->fetchAll();

    // Get assigned teacher info
    $stmt_t = $conn->prepare("
        SELECT a.full_name, a.username 
        FROM teacher_assignments ta 
        LEFT JOIN admins a ON ta.teacher_id = a.id 
        WHERE ta.class_id = ? AND ta.subject_id = ?
    ");
    $stmt_t->execute([$class_id, $subject_id]);
    $t_info = $stmt_t->fetch();
    
    $assigned_teacher_display = "";
    if ($t_info) {
        $assigned_teacher_display = !empty($t_info['full_name']) 
            ? $t_info['full_name'] . " (" . $t_info['username'] . ")"
            : ($t_info['username'] ?? 'Not Assigned');
    }
}

// Save Marks
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_marks'])) {
    if (!$marks_entry_permitted) {
        $error = $lock_notice;
    } else {
        // Re-fetch details for logging and practical logic
    $stmt = $conn->prepare("SELECT subject_name, has_practical FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subj_data = $stmt->fetch();
    $subject_name_post = $subj_data['subject_name'] ?? 'Unknown';
    $subject_has_practical_post = (bool) ($subj_data['has_practical'] ?? false);

    $stmt = $conn->prepare("SELECT class_name, section FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_data_post = $stmt->fetch();
    $current_class_name_post = $class_data_post['class_name'] ?? 'Unknown';
    $current_section_post = $class_data_post['section'] ?? '';
    $show_practical_post = $subject_has_practical_post;

    // Get subject marks distribution from JSON
    $subj_dist_post = get_subject_marks_distribution($current_class_name_post, $subject_name_post);
    $show_sq_post = isset($subj_dist_post['written']['sq']) && $subj_dist_post['written']['sq'] > 0;

    $marks_data = $_POST['marks'] ?? [];
    try {
        $conn->beginTransaction();
        // IMPORTANT: Only save raw marks here. Grade/GPA are computed by the
        // "Compile & Finalize Results" engine (generate-results.php) to ensure
        // the marksheet is NOT updated prematurely before the admin finalizes results.
        $stmt = $conn->prepare("INSERT INTO marks (student_id, subject_id, exam_type, cq_marks, mcq_marks, sq_marks, practical_marks, total_marks) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE cq_marks = VALUES(cq_marks), mcq_marks = VALUES(mcq_marks), sq_marks = VALUES(sq_marks), practical_marks = VALUES(practical_marks), total_marks = VALUES(total_marks)");

        $affected_student_ids = [];
        foreach ($marks_data as $s_id => $m) {
            $p = $show_practical_post ? ($m['practical'] ?: 0) : 0;
            $cqsq = $m['cqsq'] ?: 0;
            $cq = floatval($cqsq);
            $mcq = $m['mcq'] ?: 0;
            $sq = 0;
            $sub_total = floatval($cq) + floatval($mcq) + floatval($sq) + floatval($p);

            $stmt->execute([$s_id, $subject_id, $exam_type, $cq, $mcq, $sq, $p, $sub_total]);
            $affected_student_ids[] = intval($s_id);
        }

        // CRITICAL: Invalidate compiled results for all affected students.
        // This forces the marksheet to show 'Result Not Published' until the
        // admin re-runs 'Compile & Finalize Results' to publish official grades.
        if (!empty($affected_student_ids)) {
            $placeholders = implode(',', array_fill(0, count($affected_student_ids), '?'));
            $del_params = array_merge($affected_student_ids, [$exam_type]);
            $conn->prepare("DELETE FROM final_results WHERE student_id IN ($placeholders) AND exam_type = ?")
                 ->execute($del_params);
        }

        // Log the activity
        $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
        $log_details = "Subject: $subject_name_post | Class: $current_class_name_post | Section: $current_section_post | Exam: $exam_type";
        $stmt_log->execute([$_SESSION['admin_id'], "Updated Marks", $log_details]);

        $conn->commit();
        header("Location: marks-entry.php?step=marks&class_id=$class_id&subject_id=$subject_id&exam_type=$exam_type&msg=success");
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
    }
}

// Bulk Import
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['import_marks'])) {
    if (!$marks_entry_permitted) {
        $error = $lock_notice;
    } else {
        // Re-fetch show_practical and subject details for POST request context
    $stmt = $conn->prepare("SELECT subject_name, has_practical FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subj_data = $stmt->fetch();
    $subject_name_post = $subj_data['subject_name'] ?? 'Unknown';
    $subject_has_practical_post = (bool) ($subj_data['has_practical'] ?? false);

    $stmt = $conn->prepare("SELECT class_name, section FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_data_post = $stmt->fetch();
    $current_class_name_post = $class_data_post['class_name'] ?? 'Unknown';
    $current_section_post = $class_data_post['section'] ?? '';
    $show_practical_post = $subject_has_practical_post;

    // Get subject marks distribution from JSON
    $subj_dist_post = get_subject_marks_distribution($current_class_name_post, $subject_name_post);
    $show_sq_post = isset($subj_dist_post['written']['sq']) && $subj_dist_post['written']['sq'] > 0;

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $filename = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($filename, "r");
        fgetcsv($handle); // Skip header

        try {
            $conn->beginTransaction();
            $lookup_stmt = $conn->prepare("SELECT id FROM students WHERE roll_number = ? AND class_id = ?");
            $stmt = $conn->prepare("INSERT INTO marks (student_id, subject_id, exam_type, cq_marks, mcq_marks, sq_marks, practical_marks, total_marks) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE cq_marks = VALUES(cq_marks), mcq_marks = VALUES(mcq_marks), sq_marks = VALUES(sq_marks), practical_marks = VALUES(practical_marks), total_marks = VALUES(total_marks)");

            $csv_student_ids = [];
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (count($data) >= 1) {
                    $roll = $data[0];
                    $col_idx = 1;
                    $cq = isset($data[$col_idx]) ? $data[$col_idx] : 0;
                    $col_idx++;
                    $mcq = isset($data[$col_idx]) ? $data[$col_idx] : 0;
                    $col_idx++;
                    $sq = ($show_sq_post && isset($data[$col_idx])) ? $data[$col_idx] : 0;
                    if ($show_sq_post) $col_idx++;
                    $p = ($show_practical_post && isset($data[$col_idx])) ? $data[$col_idx] : 0;
                    if ($show_practical_post) $col_idx++;
                    
                    $sub_total = floatval($cq) + floatval($mcq) + floatval($sq) + floatval($p);

                    $lookup_stmt->execute([$roll, $class_id]);
                    $student_res = $lookup_stmt->fetch();

                    if ($student_res) {
                        $stmt->execute([$student_res['id'], $subject_id, $exam_type, $cq, $mcq, $sq, $p, $sub_total]);
                        $csv_student_ids[] = intval($student_res['id']);
                    }
                }
            }

            // Invalidate compiled results for all imported students.
            if (!empty($csv_student_ids)) {
                $placeholders = implode(',', array_fill(0, count($csv_student_ids), '?'));
                $del_params = array_merge($csv_student_ids, [$exam_type]);
                $conn->prepare("DELETE FROM final_results WHERE student_id IN ($placeholders) AND exam_type = ?")
                     ->execute($del_params);
            }

            $conn->commit();
            fclose($handle);
            header("Location: marks-entry.php?step=marks&class_id=$class_id&subject_id=$subject_id&exam_type=$exam_type&msg=imported");
            exit();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Import Error: " . $e->getMessage();
        }
    } else {
        $error = "Please upload a valid CSV file.";
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Marks Entry - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        /* Drill-down navigation cards */
        .drill-card {
            transition: all 0.25s ease;
            border: 1px solid var(--prestige-border);
            border-left: 4px solid transparent;
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none !important;
            display: block;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }
        .drill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08) !important;
            border-color: var(--prestige-gold);
            border-left-color: var(--prestige-gold);
            text-decoration: none;
        }

        [data-theme="dark"] .drill-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.22) !important;
            color: #e5e7eb;
        }

        [data-theme="dark"] .drill-card:hover {
            background: #172235 !important;
            border-color: var(--prestige-gold) !important;
            border-left-color: var(--prestige-gold) !important;
        }
        .drill-card .card-body { padding: 2rem 1.5rem; }
        .drill-icon { font-size: 2.2rem; margin-bottom: 0.75rem; }

        .year-drill-card {
            background: var(--prestige-navy);
            border-color: transparent;
        }
        .year-drill-card:hover { background: #1e293b; border-color: var(--prestige-gold) !important; }

        .class-drill-card { border-left: 4px solid var(--prestige-navy); }
        .section-drill-card { border-left: 4px solid var(--prestige-gold); }
        .subject-drill-card { border-left: 4px solid #0ea5e9; }

        /* Breadcrumb */
        .breadcrumb-item a { text-decoration: none; color: var(--prestige-gold); font-weight: 600; }
        .breadcrumb-item.active { color: #64748b; font-weight: 600; }
        .breadcrumb-item + .breadcrumb-item::before { color: #cbd5e1; }

        /* Exam selector */
        .exam-selector-card {
            background: var(--prestige-navy);
            color: white;
            border: none;
            border-radius: 14px;
            border-left: 5px solid var(--prestige-gold);
        }
        .exam-selector-card .form-select {
            background-color: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-size: 12px;
            cursor: pointer;
            border-radius: 8px;
        }
        .exam-selector-card .form-select option { color: #333; }

        /* Tab styling */
        .nav-tabs .nav-link {
            border: none;
            font-weight: 600;
            padding: 1rem 1.5rem;
            color: #64748b;
        }
        .nav-tabs .nav-link.active {
            border-bottom: 3px solid var(--prestige-gold);
            color: var(--prestige-navy);
            background: transparent;
        }

        /* Mark inputs */
        .mark-input { width: 80px; text-align: center; border-radius: 8px !important; }
        .mark-input::-webkit-outer-spin-button,
        .mark-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .mark-input[type=number] {
            -moz-appearance: textfield;
        }

        /* Save bar */
        .save-marks-bar {
            background: var(--prestige-slate);
            border-top: 1px solid var(--prestige-border);
            border-radius: 0 0 12px 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .save-marks-bar .hint-text {
            font-size: 0.85rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-save-marks {
            background: var(--prestige-navy);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.2);
            padding: 12px 28px;
        }
        .btn-save-marks:hover {
            background: #0f172a;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.3);
        }

        /* Marks table head override */
        .marks-table thead th { 
            background: var(--prestige-navy) !important; 
            color: white !important; 
            border-bottom: none !important; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            letter-spacing: 1px; 
            padding: 14px 16px; 
        }

        @media (max-width: 768px) {
            .mark-input { width: 65px; padding: 5px; }
            .nav-tabs .nav-link { padding: 0.75rem 1rem; font-size: 0.9rem; }
            .btn-save-marks { padding: 12px !important; font-size: 1rem; }
        }

        /* Enhanced Search Filter */
        .search-filter-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1.5px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .search-filter-box {
            display: flex;
            align-items: center;
            height: 48px;
            background: #ffffff;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            padding-right: 0.5rem;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
            transition: all 0.2s ease;
        }

        .search-filter-box:focus-within {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 3px rgba(180, 83, 9, 0.08);
        }

        .search-filter-icon {
            display: flex;
            align-items: center;
            padding: 0 1rem;
            color: var(--prestige-gold);
            font-size: 1rem;
            flex-shrink: 0;
        }

        .search-filter-input {
            flex: 1;
            border: none;
            outline: none;
            background: transparent;
            padding: 0 0.75rem;
            font-size: 0.95rem;
            color: var(--prestige-navy);
        }

        .search-filter-input::placeholder {
            color: #9ca3af;
        }

        .search-filter-clear {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: #f3f4f6;
            color: #6b7280;
            flex-shrink: 0;
            cursor: pointer;
            transition: all 0.15s ease;
            margin-right: 0.25rem;
        }

        .search-filter-clear:hover {
            background: #e5e7eb;
            color: var(--prestige-navy);
        }

        .search-filter-clear.visible {
            display: flex;
        }

        .search-filter-result-info {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.75rem;
            display: none;
        }

        .search-filter-result-info.visible {
            display: block;
        }

        .search-filter-empty {
            text-align: center;
            padding: 3rem 2rem;
            color: #6b7280;
            display: none;
        }

        .search-filter-empty.visible {
            display: block;
        }

        .search-filter-empty i {
            font-size: 2.5rem;
            color: #d1d5db;
            margin-bottom: 1rem;
            display: block;
        }

        .drill-card-wrapper {
            transition: all 0.2s ease;
        }

        .drill-card-wrapper.hidden {
            display: none;
        }

        [data-theme="dark"] .search-filter-container {
            background: linear-gradient(135deg, #111827 0%, #0f172a 100%);
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        [data-theme="dark"] .search-filter-box {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        [data-theme="dark"] .search-filter-box:focus-within {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 3px rgba(180, 83, 9, 0.15);
        }

        [data-theme="dark"] .search-filter-input {
            color: #e2e8f0;
        }

        [data-theme="dark"] .search-filter-input::placeholder {
            color: #6b7280;
        }

        [data-theme="dark"] .search-filter-clear {
            background: rgba(255, 255, 255, 0.08);
            color: #9ca3af;
        }

        [data-theme="dark"] .search-filter-clear:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #e2e8f0;
        }

        [data-theme="dark"] .search-filter-result-info {
            color: #9ca3af;
        }

        [data-theme="dark"] .search-filter-empty {
            color: #6b7280;
        }

        [data-theme="dark"] .search-filter-empty i {
            color: rgba(255, 255, 255, 0.1);
        }

        @media (max-width: 768px) {
            .search-filter-box {
                height: 44px;
            }
            .search-filter-input {
                font-size: 0.9rem;
            }
        }


        [data-theme="dark"] .drill-card:hover {
            background: #1e293b !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .year-drill-card {
            background: #0a1020 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .breadcrumb-item.active {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .card-header.bg-white, [data-theme="dark"] .bg-white {
            background-color: #111827 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .nav-tabs .nav-link {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .nav-tabs .nav-link.active {
            color: var(--prestige-gold) !important;
            background: transparent !important;
        }

        [data-theme="dark"] .table {
            background-color: transparent !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .table tbody, [data-theme="dark"] .table tr, [data-theme="dark"] .table td, [data-theme="dark"] .table th {
            background-color: transparent !important;
            color: #cbd5e1 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .marks-table thead th {
            background: #0a1020 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .mark-input {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .save-marks-bar {
            background: #0a1020 !important;
            border-top-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .badge {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .alert-warning {
            background: rgba(251, 191, 36, 0.05) !important;
            border-color: rgba(251, 191, 36, 0.2) !important;
        }

        [data-theme="dark"] .drill-icon {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .class-drill-card .drill-icon,
        [data-theme="dark"] .section-drill-card .drill-icon {
            color: var(--prestige-gold) !important;
        }

        /* Prestige Badges */
        .badge-prestige {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 700;
            border: 1px solid rgba(15, 23, 42, 0.1);
            font-size: 0.75rem;
            display: inline-block;
        }
        
        [data-theme="dark"] .badge-prestige {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        /* Enhanced Controls Card */
        .controls-card {
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-left: 5px solid var(--prestige-gold) !important;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        [data-theme="dark"] .controls-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3) !important;
        }
        .premium-switch.form-check-input {
            width: 3.2rem;
            height: 1.6rem;
            cursor: pointer;
            background-color: #e2e8f0;
            border-color: #cbd5e1;
        }
        .premium-switch.form-check-input:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        [data-theme="dark"] .premium-switch.form-check-input {
            background-color: rgba(255, 255, 255, 0.1) !important;
            border-color: rgba(180, 83, 9, 0.4) !important;
        }
        [data-theme="dark"] .premium-switch.form-check-input:checked {
            background-color: var(--prestige-gold) !important;
            border-color: var(--prestige-gold) !important;
        }
        .control-label {
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
        }
        .btn-update-controls {
            background: var(--prestige-navy);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.15);
        }
        .btn-update-controls:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25);
            color: white;
        }

        /* Dark Mode Fixes */
        [data-theme="dark"] .btn-outline-prestige {
            color: #94a3b8 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }
        [data-theme="dark"] .btn-outline-prestige:hover {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #f59e0b !important;
            border-color: #f59e0b !important;
        }
        [data-theme="dark"] .breadcrumb-item a {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .breadcrumb-item.active {
            color: #f59e0b !important;
        }
        [data-theme="dark"] .input-group-text {
            background-color: #0f172a !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #64748b !important;
        }
        [data-theme="dark"] .form-control.bg-light {
            background-color: #050a14 !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        /* Mobile optimized styling for Marks Entry table */
        @media (max-width: 768px) {
            .marks-table thead {
                display: none !important;
            }
            .marks-table, .marks-table tbody, .student-mark-row, .student-mark-row td {
                display: block !important;
                width: 100% !important;
            }
            .student-mark-row {
                background: #ffffff !important;
                border: 1px solid var(--prestige-border) !important;
                border-radius: 12px !important;
                margin-bottom: 12px !important;
                padding: 12px !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02) !important;
            }
            [data-theme="dark"] .student-mark-row {
                background: #111827 !important;
                border-color: rgba(255, 255, 255, 0.07) !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
            }
            .student-mark-row td {
                padding: 6px 0 !important;
                border: none !important;
                display: flex !important;
                align-items: center;
                justify-content: space-between;
            }
            
            .student-mark-row .cell-roll {
                font-size: 0.8rem;
                font-weight: 700;
                display: flex !important;
                align-items: center;
                justify-content: space-between;
            }
            .student-mark-row .cell-roll::before {
                content: "Roll Number";
                font-weight: 600;
                font-size: 0.75rem;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .student-mark-row .cell-name {
                font-size: 0.95rem;
                font-weight: 700 !important;
                color: var(--prestige-navy);
                border-bottom: 1px dashed var(--prestige-border) !important;
                padding-bottom: 10px !important;
                margin-bottom: 6px;
                display: flex !important;
                align-items: center;
                justify-content: space-between;
                text-align: right;
            }
            .student-mark-row .cell-name::before {
                content: "Student";
                font-weight: 600;
                font-size: 0.75rem;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                text-align: left;
            }
            [data-theme="dark"] .student-mark-row .cell-name {
                color: #cbd5e1 !important;
                border-bottom-color: rgba(255, 255, 255, 0.08) !important;
            }

            /* If name does NOT exist (teacher view), roll cell becomes the divider */
            .student-mark-row:not(.has-name) .cell-roll {
                border-bottom: 1px dashed var(--prestige-border) !important;
                padding-bottom: 10px !important;
                margin-bottom: 6px;
            }
            [data-theme="dark"] .student-mark-row:not(.has-name) .cell-roll {
                border-bottom-color: rgba(255, 255, 255, 0.08) !important;
            }
            
            .student-mark-row .cell-mark::before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 0.75rem;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .student-mark-row .cell-mark {
                justify-content: space-between !important;
                border-bottom: 1px solid rgba(0, 0, 0, 0.03) !important;
            }
            [data-theme="dark"] .student-mark-row .cell-mark {
                border-bottom-color: rgba(255, 255, 255, 0.05) !important;
            }
            .student-mark-row .cell-mark:last-child {
                border-bottom: none !important;
            }
            
            .student-mark-row .mark-input {
                width: 90px !important;
                height: 38px !important;
                font-size: 1rem !important;
                font-weight: 600;
                margin: 0 !important;
                padding: 4px 8px !important;
            }

            /* Stack save bar elements on mobile and expand button */
            .save-marks-bar {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 16px !important;
                padding: 16px !important;
                text-align: center;
            }
            .save-marks-bar .hint-text {
                justify-content: center;
                font-size: 0.8rem !important;
            }
            .btn-save-marks {
                width: 100% !important;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 14px !important;
                font-size: 1rem !important;
                border-radius: 12px !important;
            }
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">

            <!-- Header -->
            <div class="page-header mb-4">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Marks Entry</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb bg-transparent p-0 mb-0 small">
                                <li class="breadcrumb-item <?php echo ($step == 'years' ? 'active' : ''); ?>">
                                    <a href="marks-entry.php?view_all=1">Academic Year</a>
                                </li>
                                <?php if ($academic_year): ?>
                                    <li class="breadcrumb-item <?php echo ($step == 'classes' ? 'active' : ''); ?>">
                                        <a href="marks-entry.php?step=classes&academic_year=<?php echo urlencode($academic_year); ?>"><?php echo $academic_year; ?></a>
                                    </li>
                                <?php endif; ?>
                                <?php 
                                    // Determine class name from info if not in GET
                                    $breadcrumb_class = $class_name ?: ($current_class_info['class_name'] ?? null);
                                ?>
                                <?php if (($step == 'sections' || $step == 'subjects' || $step == 'marks') && $breadcrumb_class): ?>
                                    <li class="breadcrumb-item <?php echo ($step == 'sections' ? 'active' : ''); ?>">
                                        <a href="marks-entry.php?step=sections&class_name=<?php echo urlencode($breadcrumb_class); ?>&academic_year=<?php echo urlencode($academic_year); ?>"><?php echo $breadcrumb_class; ?></a>
                                    </li>
                                <?php endif; ?>
                                <?php if (($step == 'subjects' || $step == 'marks') && $current_class_info): ?>
                                    <li class="breadcrumb-item <?php echo ($step == 'subjects' ? 'active' : ''); ?>">
                                        <a href="marks-entry.php?step=subjects&class_id=<?php echo $current_class_info['id']; ?>&academic_year=<?php echo urlencode($academic_year); ?>">Section <?php echo $current_class_info['section']; ?></a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($step == 'marks' && $subject_info): ?>
                                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($subject_info['subject_name']); ?></li>
                                <?php endif; ?>
                            </ol>
                        </nav>
                    </div>
                </div>
                <?php if (is_admin()): ?>
                <div class="header-controls">
                    <form method="POST" class="admit-status-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="small fw-800 text-muted text-uppercase" style="letter-spacing: 1px; font-size: 0.65rem;">
                                Entry Status:
                            </div>
                            <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                                <input class="form-check-input premium-switch" type="checkbox" name="marks_entry_enabled" id="entryToggle" <?php echo ($sys_settings['marks_entry_enabled'] ?? '1') === '1' ? 'checked' : ''; ?> onchange="this.form.submit()" style="width: 2.8rem; height: 1.4rem; cursor: pointer;">
                                <label class="form-check-label ms-1 fw-bold small" for="entryToggle" style="color: <?php echo ($sys_settings['marks_entry_enabled'] ?? '1') === '1' ? '#10b981' : '#ef4444'; ?>; min-width: 65px;">
                                    <?php echo ($sys_settings['marks_entry_enabled'] ?? '1') === '1' ? '<i class="fas fa-unlock me-1"></i> OPEN' : '<i class="fas fa-lock me-1"></i> CLOSED'; ?>
                                </label>
                            </div>
                        </div>
                        <input type="hidden" name="update_marks_status" value="1">
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$marks_entry_permitted && is_teacher()): ?>
                <!-- FULL LOCKDOWN VIEW FOR TEACHERS (COMPACT) -->
                <div class="row justify-content-center mt-4">
                    <div class="col-xl-5 col-lg-7">
                        <div class="card border-0 shadow-sm text-center py-4 px-4" style="border-radius: 20px; background: white;">
                            <div class="mb-3">
                                <div class="mx-auto d-flex align-items-center justify-content-center" 
                                     style="width: 70px; height: 70px; background: #fff7ed; color: #f97316; border-radius: 50%; font-size: 2rem;">
                                    <i class="fas fa-lock"></i>
                                </div>
                            </div>
                            <h3 class="serif-font fw-bold mb-2" style="color: var(--prestige-text);">Grading Portal Closed</h3>
                            <p class="text-muted mb-4 small mx-auto" style="max-width: 380px;">
                                <?php echo htmlspecialchars($lock_notice); ?>
                            </p>
                            <div class="pt-3 border-top">
                                <a href="dashboard.php" class="btn btn-sm btn-outline-secondary px-4" style="border-radius: 8px;">
                                    <i class="fas fa-arrow-left me-2"></i> Return to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>

            <!-- Context label -->
            <?php if ($current_class_info && ($step == 'subjects' || $step == 'marks')): ?>
                <p class="text-muted small mb-4" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-chevron-right me-2 opacity-50" style="font-size: 0.7rem;"></i>
                    <?php echo htmlspecialchars($current_class_info['class_name']); ?> &mdash; Section <?php echo htmlspecialchars($current_class_info['section']); ?>
                    <?php if (is_admin() && $step == 'marks' && $assigned_teacher_display): ?>
                        <span class="ms-2 opacity-75">(Teacher: <?php echo htmlspecialchars($assigned_teacher_display); ?>)</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (isset($_GET['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo ($_GET['msg'] == 'success') ? 'Marks saved successfully!' : 'Bulk marks imported successfully!'; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- ===================== STEP: YEARS ===================== -->
            <?php if ($step == 'years'): ?>
                
                <?php 
                    // Fetch assignments for shortcuts (Teachers see theirs, Admins see all)
                    $query_ta = "
                        SELECT ta.id, c.id as class_id, c.class_name, c.section, c.academic_year, s.id as subject_id, s.subject_name, 
                               adm.full_name, adm.username 
                        FROM teacher_assignments ta
                        JOIN classes c ON ta.class_id = c.id
                        JOIN subjects s ON ta.subject_id = s.id
                        JOIN admins adm ON ta.teacher_id = adm.id
                        " . (is_admin() ? "" : "WHERE ta.teacher_id = ?") . "
                        ORDER BY c.academic_year ASC, c.class_name, c.section
                        LIMIT 12"; // Limit for a clean dashboard view
                    
                    $stmt_ta = $conn->prepare($query_ta);
                    if (!is_admin()) {
                        $stmt_ta->execute([$_SESSION['admin_id']]);
                    } else {
                        $stmt_ta->execute();
                    }
                    $shortcuts = $stmt_ta->fetchAll();
                ?>

                <!-- Professional Search Filter -->
                <div class="search-filter-container">
                    <div class="search-filter-box">
                        <div class="search-filter-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="marksEntrySearch" class="search-filter-input" 
                               placeholder="Search by subject, class, teacher, or year..."
                               autocomplete="off"
                               aria-label="Search marks entry">
                        <button type="button" id="clearSearchBtn" class="search-filter-clear" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="searchResultInfo" class="search-filter-result-info"></div>
                </div>
                
                <?php if (!empty($shortcuts)): ?>
                    <h4 class="serif-font mb-4" style="color: var(--prestige-text); font-weight: 700;">
                        <?php echo is_admin() ? "Academic Assignments" : "Your Responsibilities"; ?>
                    </h4>
                    <div class="row g-4 mb-5" id="shortcutsContainer">
                        <?php foreach ($shortcuts as $ts): ?>
                            <div class="col-xl-4 col-md-6 drill-card-wrapper" 
                                 data-search="<?php echo strtolower(htmlspecialchars($ts['subject_name'] . ' ' . $ts['class_name'] . ' ' . $ts['section'] . ' ' . $ts['academic_year'] . ' ' . ($ts['full_name'] ?? '') . ' ' . ($ts['username'] ?? ''))); ?>">
                                <a href="marks-entry.php?step=marks&class_id=<?php echo $ts['class_id']; ?>&subject_id=<?php echo $ts['subject_id']; ?>&academic_year=<?php echo urlencode($ts['academic_year']); ?>" 
                                   class="drill-card p-4" style="border-left: 4px solid var(--prestige-gold);">
                                    <div class="text-center">
                                        <div class="drill-icon" style="font-size: 2rem; color: var(--prestige-gold); margin-bottom: 0.5rem;">
                                            <i class="fas fa-book-open"></i>
                                        </div>
                                        <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                            <?php echo htmlspecialchars($ts['subject_name']); ?>
                                        </div>
                                        <div class="text-muted small mb-2">
                                            <?php echo htmlspecialchars($ts['class_name']); ?> &mdash; Section <?php echo htmlspecialchars($ts['section']); ?>
                                        </div>
                                        <div class="badge mb-2" style="font-size: 0.7rem; padding: 5px 10px; border-radius: 6px;">
                                            Session: <?php echo htmlspecialchars($ts['academic_year']); ?>
                                        </div>
                                        <?php if (is_admin()): ?>
                                            <div class="small text-muted border-top pt-2 mt-1">
                                                <?php 
                                                    $t_display = !empty($ts['full_name']) 
                                                        ? $ts['full_name'] . " (@" . $ts['username'] . ")"
                                                        : "@" . ($ts['username'] ?? 'Not Assigned');
                                                ?>
                                                <i class="fas fa-user-tie me-1"></i> Teacher: <strong><?php echo htmlspecialchars($t_display); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex align-items-center mb-4">
                        <hr class="flex-grow-1 opacity-10">
                        <span class="mx-3 small text-uppercase fw-bold text-muted" style="letter-spacing: 2px;">Or browse manually</span>
                        <hr class="flex-grow-1 opacity-10">
                    </div>
                <?php endif; ?>

                <div class="row g-4" id="yearsContainer">
                    <?php foreach ($years_list as $y): ?>
                        <div class="col-xl-2 col-md-3 col-sm-6 drill-card-wrapper" 
                             data-search="<?php echo strtolower('year ' . htmlspecialchars($y['academic_year']) . ' session'); ?>">
                            <a href="marks-entry.php?step=classes&academic_year=<?php echo urlencode($y['academic_year']); ?>"
                                class="drill-card year-drill-card">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: var(--prestige-gold-light);">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="h4 fw-bold text-white mb-1" style="font-family: 'Playfair Display', serif;">
                                        <?php echo $y['academic_year']; ?>
                                    </div>
                                    <div class="text-white-50" style="font-size: 0.75rem;">Select Session</div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Empty State -->
                <div id="searchEmpty" class="search-filter-empty">
                    <i class="fas fa-search"></i>
                    <h5 class="fw-bold mb-2">No results found</h5>
                    <p class="mb-0 text-muted">Try searching with different keywords or browse manually.</p>
                </div>

            <!-- ===================== STEP: CLASSES ===================== -->
            <?php elseif ($step == 'classes'): ?>
                <!-- Search Filter for Classes -->
                <div class="search-filter-container">
                    <div class="search-filter-box">
                        <div class="search-filter-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="classSearch" class="search-filter-input" 
                               placeholder="Search classes..."
                               autocomplete="off"
                               aria-label="Search classes">
                        <button type="button" id="clearClassSearchBtn" class="search-filter-clear" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="classSearchInfo" class="search-filter-result-info"></div>
                </div>

                <div class="row g-4" id="classesContainer">
                    <?php foreach ($classes_list as $c): ?>
                        <div class="col-xl-3 col-md-4 col-sm-6 drill-card-wrapper" 
                             data-search="<?php echo strtolower(htmlspecialchars($c['class_name'])); ?>">
                            <a href="marks-entry.php?step=sections&class_name=<?php echo urlencode($c['class_name']); ?>&academic_year=<?php echo urlencode($academic_year); ?>"
                                class="drill-card class-drill-card">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: var(--prestige-navy);">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </div>
                                    <div class="small text-muted">Manage Sections &amp; Subjects</div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="classSearchEmpty" class="search-filter-empty">
                    <i class="fas fa-search"></i>
                    <h5 class="fw-bold mb-2">No classes found</h5>
                    <p class="mb-0 text-muted">Try a different search term.</p>
                </div>

            <!-- ===================== STEP: SUBJECTS ===================== -->
            <?php elseif ($step == 'subjects'): ?>
                <!-- Search Filter for Subjects -->
                <div class="search-filter-container">
                    <div class="search-filter-box">
                        <div class="search-filter-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="subjectSearch" class="search-filter-input" 
                               placeholder="Search subjects..."
                               autocomplete="off"
                               aria-label="Search subjects">
                        <button type="button" id="clearSubjectSearchBtn" class="search-filter-clear" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="subjectSearchInfo" class="search-filter-result-info"></div>
                </div>

                <div class="row g-4" id="subjectsContainer">
                    <?php foreach ($subjects_list as $sub): ?>
                        <div class="col-xl-3 col-md-4 drill-card-wrapper" 
                             data-search="<?php echo strtolower(htmlspecialchars($sub['subject_name'])); ?>">
                            <a href="marks-entry.php?step=marks&class_id=<?php echo $class_id; ?>&subject_id=<?php echo $sub['id']; ?>&academic_year=<?php echo urlencode($academic_year); ?>"
                                class="drill-card subject-drill-card">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: #0ea5e9;">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="fw-bold" style="color: var(--prestige-text); font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($sub['subject_name']); ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="subjectSearchEmpty" class="search-filter-empty">
                    <i class="fas fa-search"></i>
                    <h5 class="fw-bold mb-2">No subjects found</h5>
                    <p class="mb-0 text-muted">Try a different search term.</p>
                </div>

            <!-- ===================== STEP: SECTIONS ===================== -->
            <?php elseif ($step == 'sections'): ?>
                <!-- Search Filter for Sections -->
                <div class="search-filter-container">
                    <div class="search-filter-box">
                        <div class="search-filter-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" id="sectionSearch" class="search-filter-input" 
                               placeholder="Search sections..."
                               autocomplete="off"
                               aria-label="Search sections">
                        <button type="button" id="clearSectionSearchBtn" class="search-filter-clear" title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="sectionSearchInfo" class="search-filter-result-info"></div>
                </div>

                <div class="row g-4" id="sectionsContainer">
                    <?php foreach ($sections_list as $s): ?>
                        <div class="col-xl-3 col-md-4 drill-card-wrapper" 
                             data-search="<?php echo strtolower('section ' . htmlspecialchars($s['section'])); ?>">
                            <a href="marks-entry.php?step=subjects&class_id=<?php echo $s['id']; ?>&academic_year=<?php echo urlencode($academic_year); ?>"
                                class="drill-card section-drill-card">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: var(--prestige-gold);">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                    <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                        Section <?php echo htmlspecialchars($s['section']); ?>
                                    </div>
                                    <div class="small text-muted">Academic Year: <?php echo htmlspecialchars($s['academic_year']); ?></div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="sectionSearchEmpty" class="search-filter-empty">
                    <i class="fas fa-search"></i>
                    <h5 class="fw-bold mb-2">No sections found</h5>
                    <p class="mb-0 text-muted">Try a different search term.</p>
                </div>

            <!-- ===================== STEP: MARKS ENTRY ===================== -->
            <?php elseif ($step == 'marks'): ?>

                <!-- Exam Type Selector -->
                <div class="card exam-selector-card mb-4 shadow-sm">
                    <div class="card-body py-3">
                        <form method="GET" action="" class="row align-items-center g-3">
                            <input type="hidden" name="step" value="marks">
                            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                            <div class="col-auto">
                                <h6 class="mb-0 fw-bold text-white">
                                    <i class="fas fa-filter me-2" style="color: var(--prestige-gold-light);"></i>Exam Type:
                                </h6>
                            </div>
                            <div class="col-sm-3">
                                <select name="exam_type" class="form-select" onchange="this.form.submit()">
                                    <option value="Half Yearly" <?php echo ($exam_type == 'Half Yearly') ? 'selected' : ''; ?>>Half Yearly</option>
                                    <option value="Final" <?php echo ($exam_type == 'Final') ? 'selected' : ''; ?>>Final</option>
                                </select>
                            </div>
                            <div class="col-auto ms-auto">
                                <span class="badge py-2 px-3 fw-bold" style="background: rgba(255,255,255,0.12); color: white; border: 1px solid rgba(255,255,255,0.2);">
                                    <i class="fas fa-book-open me-1" style="color: var(--prestige-gold-light);"></i>
                                    <?php echo htmlspecialchars($subject_info['subject_name']); ?>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Marks Card with Tabs -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white p-0" style="border-bottom: 1px solid var(--prestige-border);">
                        <ul class="nav nav-tabs border-0" id="marksTab" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#manual" type="button">
                                    <i class="fas fa-pen me-1"></i> Manual Entry
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#bulk" type="button">
                                    <i class="fas fa-file-csv me-1"></i> Bulk Import
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body p-0">
                        <div class="tab-content">
                            <!-- Manual Entry Tab -->
                            <div class="tab-pane fade show active" id="manual" role="tabpanel">
                                <form method="POST" action="">
                                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                                    <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                                    <input type="hidden" name="exam_type" value="<?php echo $exam_type; ?>">

                                    <?php if (!$marks_entry_permitted): ?>
                                        <div class="alert alert-warning m-4 border-0 shadow-sm d-flex align-items-center" style="background: #fffbeb; border-left: 5px solid var(--prestige-gold) !important; border-radius: 12px;">
                                            <div class="me-3 fs-3" style="color: var(--prestige-gold);">
                                                <i class="fas fa-lock"></i>
                                            </div>
                                            <div>
                                                <h6 class="fw-bold mb-1" style="color: var(--prestige-text);">Section Locked</h6>
                                                <p class="mb-0 text-muted small"><?php echo htmlspecialchars($lock_notice); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 marks-table">
                                            <thead>
                                                <tr>
                                                    <th>Roll No.</th>
                                                    <?php if (!is_teacher()): ?>
                                                        <th>Student Name</th>
                                                    <?php endif; ?>
                                                    <th class="text-center"><?php echo $show_sq ? 'CQ + SQ Marks' : 'CQ Marks'; ?></th>
                                                    <th class="text-center">MCQ Marks</th>
                                                    <?php if ($show_practical): ?>
                                                        <th class="text-center">Practical</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($students)): ?>
                                                    <tr>
                                                        <?php 
                                                        $total_cols = 3; // Roll, CQ, MCQ
                                                        if (!is_teacher()) $total_cols++;
                                                        if ($show_sq) $total_cols++;
                                                        if ($show_practical) $total_cols++;
                                                        ?>
                                                        <td colspan="<?php echo $total_cols; ?>" class="text-center py-5">
                                                            <i class="fas fa-search fa-3x d-block mb-3" style="color: var(--prestige-border);"></i>
                                                            <span class="text-muted">No students found for this class and subject.</span>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($students as $student): ?>
                                                        <tr class="student-mark-row <?php echo !is_teacher() ? 'has-name' : ''; ?>">
                                                            <td class="cell-roll">
                                                                <span class="badge-prestige">
                                                                    <?php echo $student['roll_number']; ?>
                                                                </span>
                                                            </td>
                                                            <?php if (!is_teacher()): ?>
                                                                <td class="cell-name fw-bold" style="color: var(--prestige-text);"><?php echo htmlspecialchars($student['name']); ?></td>
                                                            <?php endif; ?>
                                                            <td class="cell-mark text-center" data-label="<?php echo $show_sq ? 'CQ + SQ Marks' : 'CQ Marks'; ?>">
                                                                <input type="number" name="marks[<?php echo $student['id']; ?>][cqsq]"
                                                                    class="form-control mark-input mx-auto"
                                                                    value="<?php echo isset($student['cq_marks']) || isset($student['sq_marks']) ? (float)((float)$student['cq_marks'] + (float)$student['sq_marks']) : ''; ?>"
                                                                    min="0" max="100" <?php echo $marks_entry_permitted ? '' : 'readonly'; ?>>
                                                            </td>
                                                            <td class="cell-mark text-center" data-label="MCQ Marks">
                                                                <input type="number" name="marks[<?php echo $student['id']; ?>][mcq]"
                                                                    class="form-control mark-input mx-auto"
                                                                    value="<?php echo isset($student['mcq_marks']) ? (float)$student['mcq_marks'] : ''; ?>"
                                                                    min="0" max="100" <?php echo $marks_entry_permitted ? '' : 'readonly'; ?>>
                                                            </td>
                                                            <?php if ($show_practical): ?>
                                                                <td class="cell-mark text-center" data-label="Practical Marks">
                                                                    <input type="number" name="marks[<?php echo $student['id']; ?>][practical]"
                                                                        class="form-control mark-input mx-auto"
                                                                        value="<?php echo isset($student['practical_marks']) ? (float)$student['practical_marks'] : ''; ?>"
                                                                        min="0" max="100" <?php echo $marks_entry_permitted ? '' : 'readonly'; ?>>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="save-marks-bar">
                                        <div class="hint-text">
                                            <i class="fas fa-circle-info" style="color: var(--prestige-gold);"></i>
                                            Review all marks above, then click Save.
                                        </div>
                                        <button type="submit" name="save_marks" class="btn btn-save-marks" <?php echo $marks_entry_permitted ? '' : 'disabled'; ?>>
                                            <i class="fas fa-<?php echo $marks_entry_permitted ? 'check-circle' : 'lock'; ?> me-2"></i> 
                                            <?php echo $marks_entry_permitted ? 'Save All Marks' : 'Section Locked'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Bulk Import Tab -->
                            <div class="tab-pane fade" id="bulk" role="tabpanel">
                                <?php if (!$marks_entry_permitted): ?>
                                    <div class="alert alert-warning m-4 border-0 shadow-sm d-flex align-items-center" style="background: #fffbeb; border-left: 5px solid var(--prestige-gold) !important; border-radius: 12px;">
                                        <div class="me-3 fs-3" style="color: var(--prestige-gold);">
                                            <i class="fas fa-lock"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1" style="color: var(--prestige-navy);">Import Disabled</h6>
                                            <p class="mb-0 text-muted small"><?php echo htmlspecialchars($lock_notice); ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="p-4">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="p-4 border rounded-3 text-center h-100" style="border-color: var(--prestige-border) !important;">
                                                <div class="drill-icon mb-3" style="color: var(--prestige-navy);"><i class="fas fa-file-export"></i></div>
                                                <h6 class="fw-bold" style="color: var(--prestige-navy);">Step 1: Download Template</h6>
                                                <p class="text-muted small">Get the CSV template pre-filled with student roll numbers for this class.</p>
                                                <a href="download-marks-template.php?class_id=<?php echo $class_id; ?>&subject_id=<?php echo $subject_id; ?>&exam_type=<?php echo $exam_type; ?>"
                                                    class="btn btn-primary" style="border-radius: 8px; font-weight: 600;">
                                                    <i class="fas fa-download me-1"></i> Download CSV
                                                </a>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-4 border rounded-3 text-center h-100" style="border-color: var(--prestige-border) !important;">
                                                <div class="drill-icon mb-3" style="color: var(--prestige-gold);"><i class="fas fa-file-import"></i></div>
                                                <h6 class="fw-bold" style="color: var(--prestige-navy);">Step 2: Upload Filled CSV</h6>
                                                <p class="text-muted small">Fill in the marks column and upload below to import in bulk.</p>
                                                <form method="POST" action="" enctype="multipart/form-data">
                                                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                                                    <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                                                    <input type="hidden" name="exam_type" value="<?php echo $exam_type; ?>">
                                                    <input type="file" name="csv_file" class="form-control mb-3" accept=".csv" required>
                                                    <button type="submit" name="import_marks" class="btn btn-primary w-100" style="border-radius: 8px; font-weight: 600;">
                                                        <i class="fas fa-upload me-1"></i> Import CSV
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="alert mt-4" style="background: rgba(180,83,9,0.05); border: 1px dashed rgba(180,83,9,0.2); border-radius: 10px;">
                                        <h6 class="fw-bold mb-2" style="color: var(--prestige-navy);"><i class="fas fa-info-circle me-2" style="color: var(--prestige-gold);"></i>Instructions</h6>
                                        <ul class="mb-0 small text-muted">
                                            <li>Do not modify the first row (header).</li>
                                            <li>Only <b>Roll Number</b> and <b>Marks</b> columns are required.</li>
                                            <li>Names are auto-matched by roll number.</li>
                                            <li>Valid marks are between 0 and 100.</li>
                                            <?php if ($show_sq): ?>
                                                <li class="text-success fw-bold">CQ + SQ marks are required for this subject/class.</li>
                                            <?php endif; ?>
                                            <?php if (!$show_practical): ?>
                                                <li class="text-danger fw-bold">Practical marks are not required for this subject/class.</li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; // End of marks_entry_permitted check ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Professional Search/Filter System for Marks Entry
        function setupSearchFilter(searchInputId, containerIds, resultInfoId, emptyStateId) {
            const searchInput = document.getElementById(searchInputId);
            const clearBtn = document.getElementById('clear' + searchInputId.charAt(0).toUpperCase() + searchInputId.slice(1) + 'Btn');
            const resultInfo = document.getElementById(resultInfoId);
            const emptyState = document.getElementById(emptyStateId);
            
            if (!searchInput) return;

            // Handle search input
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                let visibleCount = 0;
                let totalCount = 0;

                // Show/hide clear button
                clearBtn?.classList.toggle('visible', query.length > 0);

                // Get containers and filter
                const containers = Array.isArray(containerIds) ? containerIds : [containerIds];
                
                containers.forEach(containerId => {
                    const container = document.getElementById(containerId);
                    if (!container) return;

                    const cards = container.querySelectorAll('.drill-card-wrapper');
                    totalCount += cards.length;

                    cards.forEach(card => {
                        const searchText = card.getAttribute('data-search') || '';
                        const matches = query === '' || searchText.includes(query);
                        
                        if (matches) {
                            card.classList.remove('hidden');
                            visibleCount++;
                        } else {
                            card.classList.add('hidden');
                        }
                    });
                });

                // Update result info
                if (query.length > 0) {
                    resultInfo.textContent = `Found ${visibleCount} of ${totalCount} items`;
                    resultInfo.classList.add('visible');
                } else {
                    resultInfo.classList.remove('visible');
                }

                // Show/hide empty state
                if (emptyState) {
                    emptyState.classList.toggle('visible', query.length > 0 && visibleCount === 0);
                }
            });

            // Handle clear button
            clearBtn?.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.focus();
                searchInput.dispatchEvent(new Event('input'));
            });

            // Allow Enter key to work naturally
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                }
            });
        }

        // Initialize search filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            const step = new URLSearchParams(window.location.search).get('step') || 'years';

            if (step === 'years') {
                setupSearchFilter('marksEntrySearch', 
                    ['shortcutsContainer', 'yearsContainer'], 
                    'searchResultInfo', 
                    'searchEmpty');
            } else if (step === 'classes') {
                setupSearchFilter('classSearch', 
                    'classesContainer', 
                    'classSearchInfo', 
                    'classSearchEmpty');
            } else if (step === 'subjects') {
                setupSearchFilter('subjectSearch', 
                    'subjectsContainer', 
                    'subjectSearchInfo', 
                    'subjectSearchEmpty');
            } else if (step === 'sections') {
                setupSearchFilter('sectionSearch', 
                    'sectionsContainer', 
                    'sectionSearchInfo', 
                    'sectionSearchEmpty');
            }
        });
    </script>
</body>
