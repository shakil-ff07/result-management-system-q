<?php
include('auth.php');
include('../includes/db_config.php');
include('../includes/lang_helper.php');

$teacher_id = $_SESSION['admin_id'];
$assignment_id = $_GET['assignment_id'] ?? null;
$exam_type = $_GET['exam_type'] ?? 'Half Yearly';

$assignment_info = null;
$students_status = [];
$show_practical = false;
$show_sq = false;

if ($assignment_id) {
    // Fetch specific assignment details (Admin can see all, Teachers only theirs)
    $query = "
        SELECT ta.*, c.class_name, c.section, c.academic_year, s.subject_name, s.has_practical, 
               adm.full_name, adm.username
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        LEFT JOIN admins adm ON ta.teacher_id = adm.id
        WHERE ta.id = ? " . (is_admin() ? "" : "AND ta.teacher_id = ?");
    
    $stmt = $conn->prepare($query);
    $params = is_admin() ? [$assignment_id] : [$assignment_id, $teacher_id];
    $stmt->execute($params);
    $assignment_info = $stmt->fetch();

    if ($assignment_info) {
        $teacher_display_name = !empty($assignment_info['full_name']) 
            ? $assignment_info['full_name'] . " (" . $assignment_info['username'] . ")"
            : $assignment_info['username'] ?? 'Not Assigned';
        
        $class_id = $assignment_info['class_id'];
        $subject_id = $assignment_info['subject_id'];
        $class_name = $assignment_info['class_name'];
        $subject_has_practical = (bool)($assignment_info['has_practical'] ?? false);

        // Show practical column when the subject has practical component.
        // Keep behavior consistent with marks-entry.php which uses the
        // subject's `has_practical` flag directly.
        $show_practical = $subject_has_practical;

        // Do not show SQ column on the status overview page — keep it compact.
        // The entry page (`marks-entry.php`) still uses the subject distribution
        // to render SQ when needed. For this overview we intentionally hide it.
        $show_sq = false;

        // Fetch students and their marks for this assignment
        // Using a more resilient query that ensures students show up if they belong to the class
        $stmt = $conn->prepare("SELECT 1 FROM class_subjects WHERE class_id = ? AND subject_id = ? AND (is_optional = 1 OR is_school_based = 1) LIMIT 1");
        $stmt->execute([$class_id, $subject_id]);
        $subject_is_opt_like = (bool) $stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND (main_elective_id = ? OR optional_subject_id = ?)");
        $stmt->execute([$class_id, $subject_id, $subject_id]);
        $assigned_students = (int) $stmt->fetchColumn();

        if ($subject_is_opt_like || $assigned_students > 0) {
            $stmt = $conn->prepare("
                SELECT s.roll_number, s.name, s.student_group, 
                       m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks, m.total_marks
                FROM students s
                LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_type = ?
                WHERE s.class_id = ? 
                  AND (s.main_elective_id = ? OR s.optional_subject_id = ?)
                ORDER BY s.roll_number
            ");
            $stmt->execute([$subject_id, $exam_type, $class_id, $subject_id, $subject_id]);
        } else {
            $stmt = $conn->prepare("
                SELECT s.roll_number, s.name, s.student_group, 
                       m.cq_marks, m.mcq_marks, m.sq_marks, m.practical_marks, m.total_marks
                FROM students s
                LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_type = ?
                WHERE s.class_id = ? 
                ORDER BY s.roll_number
            ");
            $stmt->execute([$subject_id, $exam_type, $class_id]);
        }
        $students_status = $stmt->fetchAll();
    }
}

// Fetch assignments list
if (is_admin()) {
    // Admin sees all assignments + teacher names
    $stmt = $conn->prepare("
        SELECT ta.id, c.class_name, c.section, c.academic_year, s.subject_name, 
               adm.full_name, adm.username
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        LEFT JOIN admins adm ON ta.teacher_id = adm.id
        ORDER BY c.academic_year ASC, c.class_name, c.section
    ");
    $stmt->execute();
} else {
    // Teacher sees only theirs
    $stmt = $conn->prepare("
        SELECT ta.id, c.class_name, c.section, c.academic_year, s.subject_name 
        FROM teacher_assignments ta
        JOIN classes c ON ta.class_id = c.id
        JOIN subjects s ON ta.subject_id = s.id
        WHERE ta.teacher_id = ?
        ORDER BY c.academic_year ASC, c.class_name, c.section
    ");
    $stmt->execute([$teacher_id]);
}
$all_assignments = $stmt->fetchAll();

$assignment_years = array_values(array_unique(array_column($all_assignments, 'academic_year')));
$assignment_classes = array_values(array_unique(array_column($all_assignments, 'class_name')));
$assignment_sections = array_values(array_unique(array_column($all_assignments, 'section')));
$assignment_teachers = [];

if (is_admin()) {
    foreach ($all_assignments as $item) {
        $teacher_label = !empty($item['full_name'])
            ? $item['full_name'] . ' (' . ($item['username'] ?? '') . ')'
            : ($item['username'] ?? 'Not Assigned');
        $assignment_teachers[$teacher_label] = $teacher_label;
    }
}

$student_groups = array_values(array_unique(array_filter(array_map(function ($item) {
    return trim((string)($item['student_group'] ?? ''));
}, $students_status))));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Marks Entry Status - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .status-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .status-given {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .status-missing {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .drill-card {
            transition: all 0.25s ease;
            border: 1px solid var(--prestige-border);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none !important;
            display: block;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
            border-left: 4px solid var(--prestige-gold);
        }
        .drill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08) !important;
            border-color: var(--prestige-gold);
        }
        .drill-icon { font-size: 2rem; color: var(--prestige-gold); margin-bottom: 0.5rem; }

        /* Prestige table header */
        .table thead th { 
            background: var(--prestige-navy) !important; 
            color: white !important; 
            border: none !important;
            text-transform: uppercase; 
            font-size: 0.75rem; 
            letter-spacing: 1px; 
            padding: 14px 16px; 
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .drill-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
        }

        [data-theme="dark"] .drill-card:hover {
            background: #1e293b !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .card-header.bg-white, 
        [data-theme="dark"] .card-footer.bg-white,
        [data-theme="dark"] .bg-white {
            background-color: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
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

        [data-theme="dark"] .badge {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .drill-icon {
            color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .status-given {
            background: rgba(16, 185, 129, 0.1) !important;
            color: #10b981 !important;
        }

        [data-theme="dark"] .status-missing {
            background: rgba(239, 68, 68, 0.1) !important;
            color: #ef4444 !important;
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

        /* Professional dynamic search/filter styles */
        .search-filter-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1.5px solid #e5e7eb;
            border-radius: 16px;
            padding: 1rem 1.1rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .search-filter-box {
            display: flex;
            align-items: center;
            min-height: 48px;
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
            padding: 0 0.9rem;
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
            padding: 2.5rem 1rem;
            color: #6b7280;
            display: none;
        }

        .search-filter-empty.visible {
            display: block;
        }

        .search-filter-empty i {
            font-size: 2rem;
            color: #d1d5db;
            margin-bottom: 0.75rem;
            display: block;
        }

        .drill-card-wrapper.hidden {
            display: none;
        }

        .table-row-hidden {
            display: none;
        }

        .status-filter-card {
            background: #fff;
            border: 1px solid var(--prestige-border);
            border-radius: 14px;
            padding: 1rem;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
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

        [data-theme="dark"] .search-filter-result-info,
        [data-theme="dark"] .search-filter-empty {
            color: #9ca3af;
        }

        [data-theme="dark"] .status-filter-card {
            background: #111827;
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        }
    </style>
</head>
<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Marks Entry Status</h1>
                        <p class="mb-0">
                            <?php echo is_admin() 
                                ? "Monitor marks entry progress for all teachers across every section." 
                                : "Check if you have entered marks for all students class-wise."; ?>
                        </p>
                    </div>
                </div>
                <?php if ($assignment_id): ?>
                <div class="header-actions">
                    <a href="teacher-marks-status.php" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Assignments
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$assignment_id): ?>
                <!-- Assignment List -->
                <div class="status-filter-card mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h5 class="mb-0 fw-bold" style="color: var(--prestige-text);">Filters</h5>
                        <button type="button" id="resetAssignmentFilters" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; font-weight: 600;">
                            <i class="fas fa-undo me-1"></i> Reset
                        </button>
                    </div>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label small fw-bold text-uppercase text-muted mb-1">Session</label>
                            <select id="assignmentYearFilter" class="form-select form-select-sm" style="border-radius: 10px;">
                                <option value="all">All sessions</option>
                                <?php foreach ($assignment_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label small fw-bold text-uppercase text-muted mb-1">Class</label>
                            <select id="assignmentClassFilter" class="form-select form-select-sm" style="border-radius: 10px;">
                                <option value="all">All classes</option>
                                <?php foreach ($assignment_classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label small fw-bold text-uppercase text-muted mb-1">Section</label>
                            <select id="assignmentSectionFilter" class="form-select form-select-sm" style="border-radius: 10px;">
                                <option value="all">All sections</option>
                                <?php foreach ($assignment_sections as $section): ?>
                                    <option value="<?php echo htmlspecialchars($section); ?>">Section <?php echo htmlspecialchars($section); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (is_admin()): ?>
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label small fw-bold text-uppercase text-muted mb-1">Teacher</label>
                            <select id="assignmentTeacherFilter" class="form-select form-select-sm" style="border-radius: 10px;">
                                <option value="all">All teachers</option>
                                <?php foreach ($assignment_teachers as $teacher): ?>
                                    <option value="<?php echo htmlspecialchars($teacher); ?>"><?php echo htmlspecialchars($teacher); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row g-4 mt-2" id="assignmentCardsContainer">
                    <?php if (empty($all_assignments)): ?>
                        <div class="col-12">
                            <div class="alert alert-info shadow-sm">
                                <i class="fas fa-info-circle me-2"></i> No subjects assigned to you yet. Please contact the administrator.
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($all_assignments as $a): ?>
                            <?php
                                $teacher_label = !empty($a['full_name'])
                                    ? $a['full_name'] . ' (' . ($a['username'] ?? '') . ')'
                                    : ($a['username'] ?? 'Not Assigned');
                            ?>
                            <div class="col-xl-4 col-md-6 drill-card-wrapper"
                                 data-year="<?php echo htmlspecialchars($a['academic_year']); ?>"
                                 data-class="<?php echo htmlspecialchars($a['class_name']); ?>"
                                 data-section="<?php echo htmlspecialchars($a['section']); ?>"
                                 data-teacher="<?php echo htmlspecialchars($teacher_label); ?>"
                                 data-search="<?php echo strtolower(htmlspecialchars($a['subject_name'] . ' ' . $a['class_name'] . ' ' . $a['section'] . ' ' . $a['academic_year'] . ' ' . $teacher_label)); ?>">
                                <a href="?assignment_id=<?php echo $a['id']; ?>" class="drill-card p-4">
                                    <div class="text-center">
                                        <div class="drill-icon"><i class="fas fa-book"></i></div>
                                        <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                             <?php echo htmlspecialchars($a['subject_name']); ?>
                                         </div>
                                        <?php if (is_admin()): ?>
                                            <div class="mt-2 small text-muted">
                                                <?php 
                                                    $t_display = !empty($a['full_name']) 
                                                        ? $a['full_name'] . " (" . $a['username'] . ")"
                                                        : ($a['username'] ?? 'Not Assigned');
                                                ?>
                                                <i class="fas fa-user-tie me-1"></i> Teacher: <strong><?php echo htmlspecialchars($t_display); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <div class="text-muted small mb-2 mt-2">
                                            <?php echo htmlspecialchars($a['class_name']); ?> &mdash; Section <?php echo htmlspecialchars($a['section']); ?>
                                        </div>
                                        <div class="badge">
                                             Session: <?php echo htmlspecialchars($a['academic_year']); ?>
                                         </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div id="assignmentSearchEmpty" class="search-filter-empty">
                    <i class="fas fa-filter"></i>
                    <h5 class="fw-bold mb-2">No assignments match the selected filters</h5>
                    <p class="mb-0 text-muted">Adjust one of the dropdown selections to broaden the results.</p>
                </div>
            <?php else: ?>
                <!-- Status Detail -->
                <?php if (!$assignment_info): ?>
                    <div class="alert alert-danger shadow-sm">Assignment not found or unauthorized access.</div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid var(--prestige-border);">
                            <h5 class="mb-0" style="color: var(--prestige-text); font-family: 'Playfair Display', serif; font-weight: 700;">
                                 <i class="fas fa-list-ol me-2 text-gold"></i>
                                 <?php echo htmlspecialchars($assignment_info['class_name']); ?> &mdash; <?php echo htmlspecialchars($assignment_info['subject_name']); ?>
                                <?php if (is_admin()): ?>
                                    <span class="ms-2 fs-6 fw-normal text-muted" style="font-family: 'Inter', sans-serif;">
                                        (Teacher: <?php echo htmlspecialchars($teacher_display_name); ?>)
                                    </span>
                                <?php endif; ?>
                            </h5>
                            <form method="GET" class="d-flex align-items-center">
                                <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                                <label class="me-2 small fw-bold text-muted text-uppercase">Exam:</label>
                                <select name="exam_type" class="form-select form-select-sm" onchange="this.form.submit()" style="width: 150px; border-radius: 8px;">
                                    <option value="Half Yearly" <?php echo $exam_type == 'Half Yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                                    <option value="Final" <?php echo $exam_type == 'Final' ? 'selected' : ''; ?>>Final</option>
                                </select>
                            </form>
                        </div>
                        <div class="card-body p-0">
                            <div class="status-filter-card mb-3 mx-3 mt-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                    <h5 class="mb-0 fw-bold" style="color: var(--prestige-text);">Student Filters</h5>
                                    <button type="button" id="resetStudentFilters" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; font-weight: 600;">
                                        <i class="fas fa-undo me-1"></i> Reset
                                    </button>
                                </div>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-uppercase text-muted mb-1">Status</label>
                                        <select id="studentStatusFilter" class="form-select" style="border-radius: 10px;">
                                            <option value="all">All status</option>
                                            <option value="given">Given</option>
                                            <option value="missing">Missing</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-uppercase text-muted mb-1">Group</label>
                                        <select id="studentGroupFilter" class="form-select" style="border-radius: 10px;">
                                            <option value="all">All groups</option>
                                            <?php foreach ($student_groups as $group): ?>
                                                <option value="<?php echo htmlspecialchars($group); ?>"><?php echo htmlspecialchars($group); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div id="studentStatusInfo" class="search-filter-result-info visible"></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="studentStatusTable">
                                    <thead>
                                        <tr>
                                            <th class="ps-4">Roll</th>
                                            <th>Student Name</th>
                                            <th class="text-center">CQ</th>
                                            <th class="text-center">MCQ</th>
                                            <?php if ($show_sq): ?>
                                                <th class="text-center">SQ</th>
                                            <?php endif; ?>
                                            <?php if ($show_practical): ?>
                                                <th class="text-center">Practical</th>
                                            <?php endif; ?>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($students_status)): ?>
                                            <tr>
                                                <?php 
                                                $total_cols = 6;
                                                if ($show_sq) $total_cols++;
                                                if ($show_practical) $total_cols++;
                                                ?>
                                                <td colspan="<?php echo $total_cols; ?>" class="text-center py-5 text-muted">
                                                    No students found in this section.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($students_status as $s): ?>
                                                <?php 
                                                    $is_missing = ($s['cq_marks'] === null || $s['mcq_marks'] === null || ($show_sq && $s['sq_marks'] === null) || ($show_practical && $s['practical_marks'] === null));
                                                    $status_label = $is_missing ? 'missing' : 'given';
                                                ?>
                                                <tr data-search="<?php echo strtolower(htmlspecialchars($s['roll_number'] . ' ' . $s['name'] . ' ' . ($s['student_group'] ?? ''))); ?>"
                                                    data-status="<?php echo $status_label; ?>"
                                                    data-group="<?php echo htmlspecialchars((string)($s['student_group'] ?? '')); ?>">
                                                    <td class="ps-4">
                                                        <span class="badge-prestige">
                                                             <?php echo $s['roll_number']; ?>
                                                         </span>
                                                    </td>
                                                     <td class="fw-bold" style="color: var(--prestige-text);"><?php echo htmlspecialchars($s['name']); ?></td>
                                                    <td class="text-center"><?php echo $s['cq_marks'] !== null ? (float)$s['cq_marks'] : '<span class="text-danger">&mdash;</span>'; ?></td>
                                                    <td class="text-center"><?php echo $s['mcq_marks'] !== null ? (float)$s['mcq_marks'] : '<span class="text-danger">&mdash;</span>'; ?></td>
                                                    <?php if ($show_sq): ?>
                                                        <td class="text-center"><?php echo $s['sq_marks'] !== null ? (float)$s['sq_marks'] : '<span class="text-danger">&mdash;</span>'; ?></td>
                                                    <?php endif; ?>
                                                    <?php if ($show_practical): ?>
                                                        <td class="text-center"><?php echo $s['practical_marks'] !== null ? (float)$s['practical_marks'] : '<span class="text-danger">&mdash;</span>'; ?></td>
                                                    <?php endif; ?>
                                                    <td class="text-center fw-bold">
                                                        <?php echo $s['total_marks'] !== null ? (float)$s['total_marks'] : '<span class="text-danger">&mdash;</span>'; ?>
                                                    </td>
                                                     <td class="text-center">
                                                        <?php if ($is_missing): ?>
                                                            <span class="status-badge status-missing">
                                                                <i class="fas fa-times-circle"></i>
                                                                <span class="d-none d-md-inline ms-1">Missing</span>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-given">
                                                                <i class="fas fa-check-circle"></i>
                                                                <span class="d-none d-md-inline ms-1">Given</span>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                        </div>
                        <div class="card-footer bg-white py-3" style="border-top: 1px solid var(--prestige-border);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="small text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Showing <strong id="studentCountLabel"><?php echo count($students_status); ?></strong> of <strong><?php echo count($students_status); ?></strong> students.
                                </div>
                                <a href="marks-entry.php?step=marks&class_id=<?php echo $assignment_info['class_id']; ?>&subject_id=<?php echo $assignment_info['subject_id']; ?>&academic_year=<?php echo urlencode($assignment_info['academic_year']); ?>&exam_type=<?php echo urlencode($exam_type); ?>" 
                                   class="btn btn-primary btn-sm px-4" style="border-radius: 8px; font-weight: 600;">
                                    <i class="fas fa-edit me-1"></i> Go to Entry Page
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setupAssignmentDropdownFilters() {
            const yearFilter = document.getElementById('assignmentYearFilter');
            const classFilter = document.getElementById('assignmentClassFilter');
            const sectionFilter = document.getElementById('assignmentSectionFilter');
            const teacherFilter = document.getElementById('assignmentTeacherFilter');
            const resetBtn = document.getElementById('resetAssignmentFilters');
            const emptyState = document.getElementById('assignmentSearchEmpty');
            const cards = document.querySelectorAll('#assignmentCardsContainer .drill-card-wrapper');

            if (!cards.length) return;

            const updateFilters = () => {
                const year = yearFilter ? yearFilter.value : 'all';
                const className = classFilter ? classFilter.value : 'all';
                const section = sectionFilter ? sectionFilter.value : 'all';
                const teacher = teacherFilter ? teacherFilter.value : 'all';
                let visibleCount = 0;

                cards.forEach(card => {
                    const matchesYear = year === 'all' || (card.dataset.year || '') === year;
                    const matchesClass = className === 'all' || (card.dataset.class || '') === className;
                    const matchesSection = section === 'all' || (card.dataset.section || '') === section;
                    const matchesTeacher = teacher === 'all' || (card.dataset.teacher || '') === teacher;
                    const matches = matchesYear && matchesClass && matchesSection && matchesTeacher;

                    card.classList.toggle('hidden', !matches);
                    if (matches) visibleCount++;
                });

                if (emptyState) {
                    emptyState.classList.toggle('visible', visibleCount === 0);
                }
            };

            [yearFilter, classFilter, sectionFilter, teacherFilter].filter(Boolean).forEach(el => {
                el.addEventListener('change', updateFilters);
            });

            resetBtn?.addEventListener('click', () => {
                [yearFilter, classFilter, sectionFilter, teacherFilter].filter(Boolean).forEach(el => {
                    el.value = 'all';
                });
                updateFilters();
            });

            updateFilters();
        }

        function setupStudentStatusDropdownFilters() {
            const statusFilter = document.getElementById('studentStatusFilter');
            const groupFilter = document.getElementById('studentGroupFilter');
            const resetBtn = document.getElementById('resetStudentFilters');
            const resultInfo = document.getElementById('studentStatusInfo');
            const rows = document.querySelectorAll('#studentStatusTable tbody tr[data-search]');
            const countLabel = document.getElementById('studentCountLabel');

            if (!rows.length) return;

            const updateFilter = () => {
                const status = statusFilter ? statusFilter.value : 'all';
                const group = groupFilter ? groupFilter.value : 'all';
                let visibleCount = 0;

                rows.forEach(row => {
                    const rowStatus = (row.dataset.status || '').toLowerCase();
                    const rowGroup = (row.dataset.group || '').trim();
                    const matchesStatus = status === 'all' || rowStatus === status;
                    const matchesGroup = group === 'all' || rowGroup === group;
                    const shouldShow = matchesStatus && matchesGroup;

                    row.classList.toggle('table-row-hidden', !shouldShow);
                    if (shouldShow) visibleCount++;
                });

                if (resultInfo) {
                    resultInfo.textContent = `Showing ${visibleCount} of ${rows.length} student record(s)`;
                    resultInfo.classList.toggle('visible', true);
                }

                if (countLabel) {
                    countLabel.textContent = String(visibleCount);
                }
            };

            [statusFilter, groupFilter].filter(Boolean).forEach(el => {
                el.addEventListener('change', updateFilter);
            });

            resetBtn?.addEventListener('click', () => {
                [statusFilter, groupFilter].filter(Boolean).forEach(el => {
                    el.value = 'all';
                });
                updateFilter();
            });

            updateFilter();
        }

        document.addEventListener('DOMContentLoaded', function () {
            setupAssignmentDropdownFilters();
            setupStudentStatusDropdownFilters();
        });
    </script>
</body>
</html>
