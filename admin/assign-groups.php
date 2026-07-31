<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

// Get active academic year and class name from request
$academic_year = $_GET['academic_year'] ?? date('Y');
$class_name = $_GET['class_name'] ?? ''; // e.g., 'Class 9' or 'Class 10'

// Fetch all classes for Class 9 and 10 to check availability
$classes_query = $conn->query("SELECT DISTINCT class_name FROM classes WHERE class_name LIKE 'Class 9%' OR class_name LIKE 'Class 10%' ORDER BY LENGTH(class_name), class_name");
$class_names = $classes_query->fetchAll(PDO::FETCH_COLUMN);

// Fetch academic years
$years_query = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC");
$years = $years_query->fetchAll(PDO::FETCH_COLUMN);
if (!in_array(date('Y'), $years)) {
    array_unshift($years, date('Y'));
}

// Function to find previous class name
function get_previous_class_name($current_class)
{
    if (strpos($current_class, 'Class 10') !== false) {
        return 'Class 9';
    }
    if (strpos($current_class, 'Class 9') !== false) {
        return 'Class 8';
    }
    return null;
}

// Recalculation logic
function recalculate_section_rolls($conn, $current_class_name, $current_year)
{
    $prev_class_name = get_previous_class_name($current_class_name);
    $prev_year = intval($current_year) - 1;

    // Get all class IDs matching this class name and year
    $stmt_classes = $conn->prepare("SELECT id, section FROM classes WHERE class_name = ? AND academic_year = ?");
    $stmt_classes->execute([$current_class_name, $current_year]);
    $classes_list = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

    foreach ($classes_list as $cls) {
        $class_id = $cls['id'];

        // Fetch all students in this class ID with their previous year's merit
        $stmt_students = $conn->prepare("
            SELECT s.id, s.name, s.roll_number,
                   MAX(COALESCE(prev_fr.status, prev_arch_fr.status, 'Pass')) AS prev_status,
                   MAX(COALESCE(prev_fr.total_gpa, prev_arch_fr.total_gpa, 0)) AS prev_gpa,
                   MAX(COALESCE(prev_fr.total_marks, prev_arch_fr.total_marks, 0)) AS prev_marks
            FROM students s
            -- Active previous student join
            LEFT JOIN students prev_s ON prev_s.name = s.name AND prev_s.dob = s.dob
                 AND prev_s.class_id IN (
                     SELECT id FROM classes 
                     WHERE class_name = ? 
                       AND academic_year = ?
                 )
            LEFT JOIN final_results prev_fr ON prev_fr.student_id = prev_s.id AND prev_fr.exam_type = 'Final'
            -- Archived previous student join
            LEFT JOIN archived_students prev_arch_s ON prev_arch_s.name = s.name AND prev_arch_s.dob = s.dob
                 AND prev_arch_s.class_id IN (
                     SELECT id FROM classes 
                     WHERE class_name = ? 
                       AND academic_year = ?
                 )
            LEFT JOIN archived_final_results prev_arch_fr ON prev_arch_fr.student_id = prev_arch_s.id AND prev_arch_fr.exam_type = 'Final'
            WHERE s.class_id = ?
            GROUP BY s.id
            ORDER BY 
                (CASE WHEN MAX(COALESCE(prev_fr.status, prev_arch_fr.status, 'Pass')) = 'Pass' THEN 1 ELSE 0 END) DESC,
                MAX(COALESCE(prev_fr.total_gpa, prev_arch_fr.total_gpa, 0)) DESC,
                MAX(COALESCE(prev_fr.total_marks, prev_arch_fr.total_marks, 0)) DESC,
                CAST(COALESCE(MAX(prev_s.roll_number), MAX(prev_arch_s.roll_number), 999) AS UNSIGNED) ASC,
                s.id ASC
        ");
        $stmt_students->execute([$prev_class_name, $prev_year, $prev_class_name, $prev_year, $class_id]);
        $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            continue;
        }

        // Set rolls to negative IDs first to avoid unique key constraints collision
        $stmt_neg = $conn->prepare("UPDATE students SET roll_number = ? WHERE id = ?");
        foreach ($students as $student) {
            $stmt_neg->execute([-intval($student['id']), $student['id']]);
        }

        // Set new rolls sequentially
        $roll = 1;
        $stmt_pos = $conn->prepare("UPDATE students SET roll_number = ? WHERE id = ?");
        foreach ($students as $student) {
            $stmt_pos->execute([$roll, $student['id']]);
            $roll++;
        }
    }
}

// Handle POST actions for moving students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_students'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    $target_dest = $_POST['target_destination'] ?? ''; // e.g. 'Science', 'Arts', 'Commerce', 'temp_A', 'temp_B'

    if (empty($student_ids)) {
        $error = "Please select at least one student to move.";
    } elseif (empty($target_dest) || empty($class_name) || empty($academic_year)) {
        $error = "Invalid parameters for movement action.";
    } else {
        try {
            $conn->beginTransaction();

            $target_class_id = null;
            $target_group = 'None';

            if (in_array($target_dest, ['Science', 'Arts', 'Commerce'])) {
                $target_group = $target_dest;
                // Find or create final group class record
                $stmt_find = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND section = ? AND academic_year = ?");
                $stmt_find->execute([$class_name, $target_group, $academic_year]);
                $c_id = $stmt_find->fetchColumn();

                if (!$c_id) {
                    // Automatically create the final group class
                    $stmt_create = $conn->prepare("INSERT INTO classes (class_name, section, academic_year) VALUES (?, ?, ?)");
                    $stmt_create->execute([$class_name, $target_group, $academic_year]);
                    $target_class_id = $conn->lastInsertId();

                    // Also copy default subjects for this group from a previous template or setup
                    // (We query the class_subjects table for a template Class 9/10 A/B class to copy common subjects,
                    // and also assign group-specific subjects).
                    $stmt_temp_c = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? AND section IN ('A','B') LIMIT 1");
                    $stmt_temp_c->execute([$class_name, $academic_year]);
                    $template_class_id = $stmt_temp_c->fetchColumn();

                    if ($template_class_id) {
                        // Copy common subjects
                        $stmt_copy_subj = $conn->prepare("
                            INSERT IGNORE INTO class_subjects (class_id, subject_id, student_group, is_optional, is_school_based)
                            SELECT ?, subject_id, ?, is_optional, is_school_based
                            FROM class_subjects
                            WHERE class_id = ? AND (student_group = ? OR student_group = 'None')
                        ");
                        $stmt_copy_subj->execute([$target_class_id, $target_group, $template_class_id, $target_group]);
                    }
                } else {
                    $target_class_id = $c_id;
                }
            } elseif (strpos($target_dest, 'temp_') === 0) {
                $temp_section = substr($target_dest, 5); // 'A' or 'B'
                $target_group = 'None';

                $stmt_find = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND section = ? AND academic_year = ?");
                $stmt_find->execute([$class_name, $temp_section, $academic_year]);
                $target_class_id = $stmt_find->fetchColumn();

                if (!$target_class_id) {
                    throw new Exception("Temporary Section $temp_section does not exist in classes database. Please create it in Class Management.");
                }
            }

            if ($target_class_id) {
                // Update class_id, student_group and temporarily assign roll_number to negative ID
                // to prevent unique key constraint violations (roll_number, class_id) during bulk movement.
                $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                $stmt_update = $conn->prepare("UPDATE students SET class_id = ?, student_group = ?, roll_number = -id WHERE id IN ($placeholders)");
                $stmt_update->execute(array_merge([$target_class_id, $target_group], $student_ids));

                // Clean up any marks / final results for these students in this session to prevent leakages
                $conn->prepare("DELETE FROM final_results WHERE student_id IN ($placeholders)")->execute($student_ids);

                // Recalculate rolls for all sections of this class
                recalculate_section_rolls($conn, $class_name, $academic_year);

                // Log the bulk activity
                $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)");
                $log_details = "Moved " . count($student_ids) . " students of $class_name ($academic_year) to group/section $target_dest.";
                $stmt_log->execute([$_SESSION['admin_id'], "Group Assignment", $log_details]);

                $conn->commit();
                $message = "Students successfully moved to <b>$target_dest</b>. Roll numbers have been recalculated by merit.";
            } else {
                throw new Exception("Unable to find or create destination class.");
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Fetch all students for the selected class and year, along with their prior year final marks
$students_list = [];
if (!empty($class_name) && !empty($academic_year)) {
    $prev_class_name = get_previous_class_name($class_name);
    $prev_year = intval($academic_year) - 1;

    $stmt_fetch = $conn->prepare("
        SELECT s.id, s.name, s.roll_number, s.dob, s.student_group, c.section, c.id AS class_record_id,
               MAX(COALESCE(prev_fr.status, prev_arch_fr.status, 'Pass')) AS prev_status,
               MAX(COALESCE(prev_fr.total_gpa, prev_arch_fr.total_gpa, 0)) AS prev_gpa,
               MAX(COALESCE(prev_fr.total_marks, prev_arch_fr.total_marks, 0)) AS prev_marks
        FROM students s
        JOIN classes c ON s.class_id = c.id
        -- Active previous student join
        LEFT JOIN students prev_s ON prev_s.name = s.name AND prev_s.dob = s.dob
             AND prev_s.class_id IN (
                 SELECT id FROM classes 
                 WHERE class_name = ? 
                   AND academic_year = ?
             )
        LEFT JOIN final_results prev_fr ON prev_fr.student_id = prev_s.id AND prev_fr.exam_type = 'Final'
        -- Archived previous student join
        LEFT JOIN archived_students prev_arch_s ON prev_arch_s.name = s.name AND prev_arch_s.dob = s.dob
             AND prev_arch_s.class_id IN (
                 SELECT id FROM classes 
                 WHERE class_name = ? 
                   AND academic_year = ?
             )
        LEFT JOIN archived_final_results prev_arch_fr ON prev_arch_fr.student_id = prev_arch_s.id AND prev_arch_fr.exam_type = 'Final'
        WHERE c.class_name = ? AND c.academic_year = ?
        GROUP BY s.id
        ORDER BY 
            (CASE WHEN s.student_group = 'None' THEN 0 ELSE 1 END) ASC,
            s.student_group ASC,
            c.section ASC,
            CAST(s.roll_number AS UNSIGNED) ASC
    ");
    $stmt_fetch->execute([$prev_class_name, $prev_year, $prev_class_name, $prev_year, $class_name, $academic_year]);
    $students_list = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);
}

// Group students into lists for the columns
$temp_sec_a = [];
$temp_sec_b = [];
$group_science = [];
$group_arts = [];
$group_commerce = [];

foreach ($students_list as $student) {
    if ($student['student_group'] === 'None') {
        if ($student['section'] === 'A') {
            $temp_sec_a[] = $student;
        } elseif ($student['section'] === 'B') {
            $temp_sec_b[] = $student;
        }
    } elseif ($student['student_group'] === 'Science') {
        $group_science[] = $student;
    } elseif ($student['student_group'] === 'Arts') {
        $group_arts[] = $student;
    } elseif ($student['student_group'] === 'Commerce') {
        $group_commerce[] = $student;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Academic Group Assignment - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .student-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px dashed var(--prestige-border);
            transition: all 0.2s ease;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-item:hover {
            background: rgba(180, 83, 9, 0.04);
        }

        [data-theme="dark"] .student-item:hover {
            background: rgba(245, 158, 11, 0.06) !important;
        }

        .student-roll {
            min-width: 75px;
            font-weight: 700;
            color: var(--prestige-navy);
            background: rgba(15, 23, 42, 0.05);
            padding: 4px 10px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.8rem;
            margin-right: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            display: inline-block;
        }

        [data-theme="dark"] .student-roll {
            color: var(--prestige-gold) !important;
            background: rgba(245, 158, 11, 0.1) !important;
            border-color: rgba(245, 158, 11, 0.15) !important;
        }

        .student-name {
            font-weight: 600;
            color: var(--prestige-text);
            flex-grow: 1;
        }

        .student-merit-badge {
            font-size: 0.75rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        [data-theme="dark"] .student-merit-badge {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #94a3b8 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        .column-header {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--prestige-navy);
            border-bottom: 2px solid var(--prestige-gold);
            padding-bottom: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        [data-theme="dark"] .column-header {
            color: #e2e8f0 !important;
        }

        .action-bar {
            background: #f8fafc;
            border: 1px solid var(--prestige-border);
            border-radius: 12px;
            padding: 16px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        [data-theme="dark"] .action-bar {
            background: #0f172a !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        /* Modern Matrix Tabs Styling */
        .custom-matrix-tabs {
            border-bottom: 1px solid var(--prestige-border) !important;
            gap: 4px;
        }

        .custom-matrix-tabs .nav-link {
            font-weight: 600;
            color: #64748b !important;
            border: none !important;
            background: transparent !important;
            padding: 12px 20px;
            font-size: 0.925rem;
            position: relative;
            transition: all 0.25s ease;
            border-radius: 8px 8px 0 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .custom-matrix-tabs .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 3px;
            background: var(--prestige-gold);
            transition: all 0.25s ease;
            transform: translateX(-50%);
            border-radius: 3px 3px 0 0;
        }

        .custom-matrix-tabs .nav-link:hover {
            color: var(--prestige-navy) !important;
        }

        .custom-matrix-tabs .nav-link:hover::after {
            width: 50%;
        }

        .custom-matrix-tabs .nav-link.active {
            color: var(--prestige-navy) !important;
            font-weight: 700;
        }

        .custom-matrix-tabs .nav-link.active::after {
            width: 100%;
        }

        .tab-content {
            min-height: 520px; /* Prevents scroll-to-top jump during tab transition collapse */
        }

        .tab-badge {
            font-size: 0.75rem;
            font-weight: 750;
            padding: 3px 8px;
            border-radius: 20px;
            line-height: 1;
            transition: all 0.25s ease;
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .custom-matrix-tabs .nav-link.active .tab-badge {
            background-color: var(--prestige-gold);
            color: #ffffff;
            border-color: var(--prestige-gold);
        }

        /* Dark Mode Adjustments */
        [data-theme="dark"] .custom-matrix-tabs {
            border-bottom-color: rgba(255, 255, 255, 0.08) !important;
        }

        [data-theme="dark"] .custom-matrix-tabs .nav-link {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .custom-matrix-tabs .nav-link:hover {
            color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .custom-matrix-tabs .nav-link.active {
            color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .tab-badge {
            background-color: rgba(255, 255, 255, 0.05);
            color: #94a3b8;
            border-color: rgba(255, 255, 255, 0.08);
        }

        [data-theme="dark"] .custom-matrix-tabs .nav-link.active .tab-badge {
            background-color: var(--prestige-gold);
            color: #0f172a;
            border-color: var(--prestige-gold);
        }

        /* Responsive Matrix Tabs Layout */
        @media (max-width: 767.98px) {
            .custom-matrix-tabs {
                flex-wrap: wrap;
            }
            .custom-matrix-tabs .nav-item:first-child {
                flex-basis: 100%;
                text-align: center;
                margin-bottom: 8px;
            }
            .custom-matrix-tabs .nav-item:first-child .nav-link {
                width: 100%;
                justify-content: center;
            }
            .custom-matrix-tabs .nav-item:not(:first-child) {
                flex: 1 1 0%;
                text-align: center;
            }
            .custom-matrix-tabs .nav-item:not(:first-child) .nav-link {
                width: 100%;
                justify-content: center;
                padding: 10px 5px;
                font-size: 0.85rem;
            }
        }
        @media (min-width: 768px) {
            .custom-matrix-tabs .nav-item:nth-child(2) {
                margin-left: auto;
            }
        }

        .empty-list-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
            font-style: italic;
        }

        .scrollable-students-list {
            max-height: 480px;
            overflow-y: auto;
            border: 1px solid var(--prestige-border);
            border-radius: 12px;
        }

        [data-theme="dark"] .scrollable-students-list {
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        /* Custom Scrollbar for Student Lists */
        .scrollable-students-list::-webkit-scrollbar {
            width: 6px;
        }

        .scrollable-students-list::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.02);
            border-radius: 10px;
        }

        [data-theme="dark"] .scrollable-students-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        .scrollable-students-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        [data-theme="dark"] .scrollable-students-list::-webkit-scrollbar-thumb {
            background: var(--prestige-gold);
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>

    <div id="content">
        <?php include('topbar.php'); ?>

        <div class="container-fluid px-0 px-md-4">
            <!-- Header Section -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Group Assignment</h1>
                        <p class="mb-0">Move Class 9/10 students into Science, Arts, or Commerce and recalculate rolls
                            by merit.</p>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Year / Class Selector Card -->
            <div class="card mb-4">
                <div class="card-body p-4">
                    <form action="" method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Academic Session</label>
                            <select name="academic_year" class="form-select" required>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == $academic_year ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Target Class</label>
                            <select name="class_name" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($class_names as $name): ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $name == $class_name ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100 py-2">
                                <i class="fas fa-search me-2"></i> Load Students
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (empty($class_name)): ?>
                <!-- Empty State -->
                <div class="card mb-4">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-graduation-cap fa-4x mb-3 text-muted" style="opacity: 0.3;"></i>
                        <h5 class="fw-bold text-muted">Select Academic Year and Class to begin</h5>
                        <p class="text-muted small">Only Class 9 and Class 10 student group assignments are managed here.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <!-- Assignment Main Dashboard -->
                <div class="card mb-4">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-4 serif-font text-dark"><i class="fas fa-sliders-h me-2 text-warning"></i>
                            Assignment Matrix for <?php echo htmlspecialchars($class_name); ?>
                            (<?php echo htmlspecialchars($academic_year); ?>)</h5>

                        <!-- Tabs Header -->
                        <ul class="nav nav-tabs custom-matrix-tabs mb-4" id="groupTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="temp-tab" data-bs-toggle="tab"
                                    data-bs-target="#temp-pane" type="button" role="tab" aria-controls="temp-pane"
                                    aria-selected="true">
                                    <i class="fas fa-layer-group"></i> Temporary Sections (A &amp; B)
                                    <span
                                        class="tab-badge ms-1"><?php echo count($temp_sec_a) + count($temp_sec_b); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="science-tab" data-bs-toggle="tab"
                                    data-bs-target="#science-pane" type="button" role="tab" aria-controls="science-pane"
                                    aria-selected="false">
                                    <i class="fas fa-atom text-success"></i> Science
                                    <span class="tab-badge ms-1"><?php echo count($group_science); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="arts-tab" data-bs-toggle="tab" data-bs-target="#arts-pane"
                                    type="button" role="tab" aria-controls="arts-pane" aria-selected="false">
                                    <i class="fas fa-palette text-info"></i> Arts
                                    <span class="tab-badge ms-1"><?php echo count($group_arts); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="commerce-tab" data-bs-toggle="tab"
                                    data-bs-target="#commerce-pane" type="button" role="tab" aria-controls="commerce-pane"
                                    aria-selected="false">
                                    <i class="fas fa-briefcase text-warning"></i> Commerce
                                    <span class="tab-badge ms-1"><?php echo count($group_commerce); ?></span>
                                </button>
                            </li>
                        </ul>

                        <!-- Tabs Content -->
                        <div class="tab-content" id="groupTabsContent">

                            <!-- TAB 1: Temporary Sections -->
                            <div class="tab-pane fade show active" id="temp-pane" role="tabpanel"
                                aria-labelledby="temp-tab">
                                <form action="" method="POST" class="move-form">
                                    <input type="hidden" name="move_students" value="1">
                                    <div class="row g-4">
                                        <!-- Temporary Section A -->
                                        <div class="col-lg-6">
                                            <div class="column-header">
                                                <span>Section A (Temp)</span>
                                                <button type="button"
                                                    class="btn btn-xs btn-outline-secondary select-all-btn"
                                                    data-target="chk-temp-a">Select All</button>
                                            </div>
                                            <div class="scrollable-students-list bg-white">
                                                <?php if (empty($temp_sec_a)): ?>
                                                    <div class="empty-list-placeholder">No students in Section A (Temp)</div>
                                                <?php else: ?>
                                                    <?php foreach ($temp_sec_a as $s): ?>
                                                        <div class="student-item">
                                                            <input type="checkbox" name="student_ids[]"
                                                                value="<?php echo $s['id']; ?>"
                                                                class="chk-temp-a form-check-input me-3">
                                                            <span class="student-roll">#<?php echo $s['roll_number']; ?></span>
                                                            <span
                                                                class="student-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                                            <span class="student-merit-badge">
                                                                GPA: <?php echo number_format($s['prev_gpa'], 2); ?> | Marks:
                                                                <?php echo number_format($s['prev_marks'], 0); ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Temporary Section B -->
                                        <div class="col-lg-6">
                                            <div class="column-header">
                                                <span>Section B (Temp)</span>
                                                <button type="button"
                                                    class="btn btn-xs btn-outline-secondary select-all-btn"
                                                    data-target="chk-temp-b">Select All</button>
                                            </div>
                                            <div class="scrollable-students-list bg-white">
                                                <?php if (empty($temp_sec_b)): ?>
                                                    <div class="empty-list-placeholder">No students in Section B (Temp)</div>
                                                <?php else: ?>
                                                    <?php foreach ($temp_sec_b as $s): ?>
                                                        <div class="student-item">
                                                            <input type="checkbox" name="student_ids[]"
                                                                value="<?php echo $s['id']; ?>"
                                                                class="chk-temp-b form-check-input me-3">
                                                            <span class="student-roll">#<?php echo $s['roll_number']; ?></span>
                                                            <span
                                                                class="student-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                                            <span class="student-merit-badge">
                                                                GPA: <?php echo number_format($s['prev_gpa'], 2); ?> | Marks:
                                                                <?php echo number_format($s['prev_marks'], 0); ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action controls -->
                                    <div class="action-bar">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-share-square fa-lg text-warning"></i>
                                            <span class="fw-bold text-dark small text-uppercase">Move selected to
                                                group:</span>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <select name="target_destination" class="form-select w-auto" required>
                                                <option value="">Choose Destination...</option>
                                                <option value="Science">Science Group</option>
                                                <option value="Arts">Arts Group</option>
                                                <option value="Commerce">Commerce Group</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="fas fa-exchange-alt me-2"></i> Move Students
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- TAB 2: Science Group -->
                            <div class="tab-pane fade" id="science-pane" role="tabpanel" aria-labelledby="science-tab">
                                <form action="" method="POST" class="move-form">
                                    <input type="hidden" name="move_students" value="1">
                                    <div class="column-header">
                                        <span>Science Students (Rolls order by merit)</span>
                                        <button type="button" class="btn btn-xs btn-outline-secondary select-all-btn"
                                            data-target="chk-sci">Select All</button>
                                    </div>
                                    <div class="scrollable-students-list bg-white mb-3">
                                        <?php if (empty($group_science)): ?>
                                            <div class="empty-list-placeholder">No students assigned to Science yet.</div>
                                        <?php else: ?>
                                            <?php foreach ($group_science as $s): ?>
                                                <div class="student-item">
                                                    <input type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>"
                                                        class="chk-sci form-check-input me-3">
                                                    <span class="student-roll">Roll <?php echo $s['roll_number']; ?></span>
                                                    <span class="student-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                                    <span class="student-merit-badge">
                                                        GPA: <?php echo number_format($s['prev_gpa'], 2); ?> | Marks:
                                                        <?php echo number_format($s['prev_marks'], 0); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="action-bar">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-share-square fa-lg text-success"></i>
                                            <span class="fw-bold text-dark small text-uppercase">Move selected to:</span>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <select name="target_destination" class="form-select w-auto" required>
                                                <option value="">Choose Destination...</option>
                                                <option value="Arts">Arts Group</option>
                                                <option value="Commerce">Commerce Group</option>
                                                <option value="temp_A">Section A (Temporary)</option>
                                                <option value="temp_B">Section B (Temporary)</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="fas fa-exchange-alt me-2"></i> Move Students
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- TAB 3: Arts Group -->
                            <div class="tab-pane fade" id="arts-pane" role="tabpanel" aria-labelledby="arts-tab">
                                <form action="" method="POST" class="move-form">
                                    <input type="hidden" name="move_students" value="1">
                                    <div class="column-header">
                                        <span>Arts Students (Rolls order by merit)</span>
                                        <button type="button" class="btn btn-xs btn-outline-secondary select-all-btn"
                                            data-target="chk-arts">Select All</button>
                                    </div>
                                    <div class="scrollable-students-list bg-white mb-3">
                                        <?php if (empty($group_arts)): ?>
                                            <div class="empty-list-placeholder">No students assigned to Arts yet.</div>
                                        <?php else: ?>
                                            <?php foreach ($group_arts as $s): ?>
                                                <div class="student-item">
                                                    <input type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>"
                                                        class="chk-arts form-check-input me-3">
                                                    <span class="student-roll">Roll <?php echo $s['roll_number']; ?></span>
                                                    <span class="student-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                                    <span class="student-merit-badge">
                                                        GPA: <?php echo number_format($s['prev_gpa'], 2); ?> | Marks:
                                                        <?php echo number_format($s['prev_marks'], 0); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="action-bar">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-share-square fa-lg text-info"></i>
                                            <span class="fw-bold text-dark small text-uppercase">Move selected to:</span>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <select name="target_destination" class="form-select w-auto" required>
                                                <option value="">Choose Destination...</option>
                                                <option value="Science">Science Group</option>
                                                <option value="Commerce">Commerce Group</option>
                                                <option value="temp_A">Section A (Temporary)</option>
                                                <option value="temp_B">Section B (Temporary)</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="fas fa-exchange-alt me-2"></i> Move Students
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- TAB 4: Commerce Group -->
                            <div class="tab-pane fade" id="commerce-pane" role="tabpanel" aria-labelledby="commerce-tab">
                                <form action="" method="POST" class="move-form">
                                    <input type="hidden" name="move_students" value="1">
                                    <div class="column-header">
                                        <span>Commerce Students (Rolls order by merit)</span>
                                        <button type="button" class="btn btn-xs btn-outline-secondary select-all-btn"
                                            data-target="chk-com">Select All</button>
                                    </div>
                                    <div class="scrollable-students-list bg-white mb-3">
                                        <?php if (empty($group_commerce)): ?>
                                            <div class="empty-list-placeholder">No students assigned to Commerce yet.</div>
                                        <?php else: ?>
                                            <?php foreach ($group_commerce as $s): ?>
                                                <div class="student-item">
                                                    <input type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>"
                                                        class="chk-com form-check-input me-3">
                                                    <span class="student-roll">Roll <?php echo $s['roll_number']; ?></span>
                                                    <span class="student-name"><?php echo htmlspecialchars($s['name']); ?></span>
                                                    <span class="student-merit-badge">
                                                        GPA: <?php echo number_format($s['prev_gpa'], 2); ?> | Marks:
                                                        <?php echo number_format($s['prev_marks'], 0); ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="action-bar">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-share-square fa-lg text-primary"></i>
                                            <span class="fw-bold text-dark small text-uppercase">Move selected to:</span>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <select name="target_destination" class="form-select w-auto" required>
                                                <option value="">Choose Destination...</option>
                                                <option value="Science">Science Group</option>
                                                <option value="Arts">Arts Group</option>
                                                <option value="temp_A">Section A (Temporary)</option>
                                                <option value="temp_B">Section B (Temporary)</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="fas fa-exchange-alt me-2"></i> Move Students
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Select All toggles
            document.querySelectorAll('.select-all-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const targetClass = this.getAttribute('data-target');
                    const checkboxes = document.querySelectorAll('.' + targetClass);
                    const allChecked = Array.from(checkboxes).every(chk => chk.checked);

                    checkboxes.forEach(chk => chk.checked = !allChecked);
                    this.textContent = allChecked ? 'Select All' : 'Deselect All';
                });
            });

            // Prevent empty submissions and show visual confirm
            document.querySelectorAll('.move-form').forEach(form => {
                form.addEventListener('submit', function (e) {
                    const selected = this.querySelectorAll('input[type="checkbox"]:checked');
                    if (selected.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one student to perform this action.');
                    }
                });
            });
        });
    </script>
</body>

</html>