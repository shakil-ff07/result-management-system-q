<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

// Fetch all classes for JS filtering
$all_classes = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, LENGTH(class_name), class_name, section")->fetchAll(PDO::FETCH_ASSOC);
$years = array_unique(array_column($all_classes, 'academic_year'));
$current_year = date('Y');

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['promote'])) {
    // From Data
    $from_year = $_POST['from_year'] ?? '';
    $from_class = $_POST['from_class_name'] ?? '';
    $from_section = $_POST['from_section'] ?? 'All';

    // To Data
    $to_year = $_POST['to_year'] ?? '';
    $to_class = $_POST['to_class_name'] ?? '';
    $to_section = $_POST['to_section'] ?? '';

    $auto_distribute = isset($_POST['auto_distribute']) && $_POST['auto_distribute'] == '1';
    $reset_rolls = isset($_POST['reset_rolls']) && $_POST['reset_rolls'] == '1';

    try {
        $from_class_id = null;
        $to_class_id = null;

        if ($from_section !== 'All') {
            $stmt_f = $conn->prepare("SELECT id FROM classes WHERE academic_year = ? AND class_name = ? AND section = ?");
            $stmt_f->execute([$from_year, $from_class, $from_section]);
            $f_data = $stmt_f->fetch();
            if (!$f_data) {
                $error = "Source class/section was not found.";
            } else {
                $from_class_id = $f_data['id'];
            }
        }

        if (!$error && !$auto_distribute) {
            $stmt_t = $conn->prepare("SELECT id FROM classes WHERE academic_year = ? AND class_name = ? AND section = ?");
            $stmt_t->execute([$to_year, $to_class, $to_section]);
            $t_data = $stmt_t->fetch();
            if (!$t_data) {
                $error = "Destination class/section was not found.";
            } else {
                $to_class_id = $t_data['id'];
            }
        }

        if (!$error) {
            if ($from_section === 'All') {
                if ($from_class === 'Class 9') {
                    // For Class 9, only fetch students from Science, Arts, and Commerce sections
                    $stmt_students = $conn->prepare("
                        SELECT s.*, c.section, c.class_name, 
                               COALESCE(fr.status, 'Pass') as final_status,
                               COALESCE(fr.total_gpa, 0) as final_gpa,
                               COALESCE(fr.total_marks, 0) as final_total_marks
                        FROM students s
                        JOIN classes c ON s.class_id = c.id
                        LEFT JOIN final_results fr ON s.id = fr.student_id AND fr.exam_type = 'Final'
                        WHERE c.academic_year = ? AND c.class_name = ? AND c.section IN ('Science', 'Arts', 'Commerce')
                        ORDER BY 
                            (CASE WHEN fr.status = 'Pass' THEN 1 ELSE 0 END) DESC,
                            final_gpa DESC,
                            final_total_marks DESC,
                            CAST(s.roll_number AS UNSIGNED) ASC
                    ");
                } else {
                    $stmt_students = $conn->prepare("
                        SELECT s.*, c.section, c.class_name, 
                               COALESCE(fr.status, 'Pass') as final_status,
                               COALESCE(fr.total_gpa, 0) as final_gpa,
                               COALESCE(fr.total_marks, 0) as final_total_marks
                        FROM students s
                        JOIN classes c ON s.class_id = c.id
                        LEFT JOIN final_results fr ON s.id = fr.student_id AND fr.exam_type = 'Final'
                        WHERE c.academic_year = ? AND c.class_name = ?
                        ORDER BY 
                            (CASE WHEN fr.status = 'Pass' THEN 1 ELSE 0 END) DESC,
                            final_gpa DESC,
                            final_total_marks DESC,
                            CAST(s.roll_number AS UNSIGNED) ASC
                    ");
                }
                $stmt_students->execute([$from_year, $from_class]);
            } else {
                $stmt_students = $conn->prepare("
                    SELECT s.*, c.section, c.class_name, 
                           COALESCE(fr.status, 'Pass') as final_status,
                           COALESCE(fr.total_gpa, 0) as final_gpa,
                           COALESCE(fr.total_marks, 0) as final_total_marks
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    LEFT JOIN final_results fr ON s.id = fr.student_id AND fr.exam_type = 'Final'
                    WHERE c.academic_year = ? AND c.class_name = ? AND c.section = ?
                    ORDER BY 
                        (CASE WHEN fr.status = 'Pass' THEN 1 ELSE 0 END) DESC,
                        final_gpa DESC,
                        final_total_marks DESC,
                        CAST(s.roll_number AS UNSIGNED) ASC
                ");
                $stmt_students->execute([$from_year, $from_class, $from_section]);
            }
            $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                $error = "No students found in the selected source class/section.";
            } else {
                $is_class_9_to_10 = (strpos($from_class, 'Class 9') !== false && strpos($to_class, 'Class 10') !== false);
                $target_sections = [];

                // Retrieve or dynamically create target classes & sections if missing
                if ($is_class_9_to_10) {
                    $required_groups = ['Science', 'Arts', 'Commerce'];
                    foreach ($required_groups as $group) {
                        $stmt_find = $conn->prepare("SELECT id FROM classes WHERE academic_year = ? AND class_name = ? AND section = ?");
                        $stmt_find->execute([$to_year, $to_class, $group]);
                        $c_id = $stmt_find->fetchColumn();

                        if (!$c_id) {
                            $stmt_create = $conn->prepare("INSERT INTO classes (class_name, section, academic_year) VALUES (?, ?, ?)");
                            $stmt_create->execute([$to_class, $group, $to_year]);
                            $c_id = $conn->lastInsertId();
                        }
                        $target_sections[] = ['id' => $c_id, 'section' => $group];
                    }
                } elseif ($auto_distribute) {
                    // Fetch all existing sections for this target class/year
                    $stmt_sections = $conn->prepare("SELECT id, section FROM classes WHERE academic_year = ? AND class_name = ? ORDER BY section ASC");
                    $stmt_sections->execute([$to_year, $to_class]);
                    $target_sections = $stmt_sections->fetchAll(PDO::FETCH_ASSOC);

                    // If no sections exist yet, we auto-create defaults 'A' and 'B'
                    if (empty($target_sections)) {
                        foreach (['A', 'B'] as $sec) {
                            $stmt_create = $conn->prepare("INSERT INTO classes (class_name, section, academic_year) VALUES (?, ?, ?)");
                            $stmt_create->execute([$to_class, $sec, $to_year]);
                            $target_sections[] = ['id' => $conn->lastInsertId(), 'section' => $sec];
                        }
                    }
                } else {
                    // Single target class selected
                    $stmt_t = $conn->prepare("SELECT id FROM classes WHERE academic_year = ? AND class_name = ? AND section = ?");
                    $stmt_t->execute([$to_year, $to_class, $to_section]);
                    $to_class_id = $stmt_t->fetchColumn();

                    if (!$to_class_id) {
                        $stmt_create = $conn->prepare("INSERT INTO classes (class_name, section, academic_year) VALUES (?, ?, ?)");
                        $stmt_create->execute([$to_class, $to_section, $to_year]);
                        $to_class_id = $conn->lastInsertId();
                    }
                }

                if (!$error) {
                    $num_sections = count($target_sections);
                    $num_students = count($students);
                    $section_capacity = ($auto_distribute && $num_sections > 0) ? ceil($num_students / $num_sections) : $num_students;

                    $section_index = 0;
                    $count_in_section = 0;

                    $conn->beginTransaction();
                    $promoted_count = 0;
                    $roll_counters = [];
                    $existing_rolls_by_class = [];
                    $stmt_existing_rolls = $conn->prepare("SELECT roll_number FROM students WHERE class_id = ?");

                    if ($is_class_9_to_10) {
                        $target_class_ids = array_column($target_sections, 'id');
                    } else {
                        $target_class_ids = $auto_distribute ? array_column($target_sections, 'id') : [$to_class_id];
                    }

                    foreach ($target_class_ids as $class_id) {
                        $stmt_existing_rolls->execute([$class_id]);
                        $existing_rolls_by_class[$class_id] = array_fill_keys($stmt_existing_rolls->fetchAll(PDO::FETCH_COLUMN), true);
                    }

                    $stmt_insert = $conn->prepare("INSERT INTO students (roll_number, name, father_name, mother_name, dob, class_id, student_group, main_elective_id, optional_subject_id, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    // Map target groups to their corresponding class IDs
                    $group_class_map = [];
                    if ($is_class_9_to_10) {
                        foreach ($target_sections as $ts) {
                            $group_class_map[$ts['section']] = $ts['id'];
                        }
                    }

                    foreach ($students as $student) {
                        if ($is_class_9_to_10) {
                            $s_group = $student['student_group'];
                            // Default to Arts if no group is specified or it is 'None'
                            if (empty($s_group) || $s_group === 'None') {
                                $s_group = 'Arts';
                            }
                            if (!isset($group_class_map[$s_group])) {
                                // Fallback to whatever first group class is available if the specific one is missing
                                $target_class_id = !empty($target_sections) ? $target_sections[0]['id'] : null;
                            } else {
                                $target_class_id = $group_class_map[$s_group];
                            }
                        } elseif ($auto_distribute) {
                            $target_class_id = $target_sections[$section_index]['id'];
                            $count_in_section++;
                            if ($count_in_section >= $section_capacity && $section_index < $num_sections - 1) {
                                        $section_index++;
                                        $count_in_section = 0;
                            }
                        } else {
                            $target_class_id = $to_class_id;
                        }

                        if (!$target_class_id) {
                            continue;
                        }

                        if (!isset($existing_rolls_by_class[$target_class_id])) {
                            $existing_rolls_by_class[$target_class_id] = [];
                        }

                        if ($reset_rolls || $is_class_9_to_10) {
                            if (!isset($roll_counters[$target_class_id])) {
                                $roll_counters[$target_class_id] = 1;
                            }
                            $new_roll = $roll_counters[$target_class_id]++;
                        } else {
                            $new_roll = $student['roll_number'];
                        }

                        while (isset($existing_rolls_by_class[$target_class_id][$new_roll]) || $new_roll <= 0) {
                            $new_roll++;
                        }
                        $existing_rolls_by_class[$target_class_id][$new_roll] = true;

                        $stmt_insert->execute([
                            $new_roll,
                            $student['name'],
                            $student['father_name'],
                            $student['mother_name'],
                            $student['dob'],
                            $target_class_id,
                            $is_class_9_to_10 ? ($student['student_group'] === 'None' || empty($student['student_group']) ? 'Arts' : $student['student_group']) : $student['student_group'],
                            $student['main_elective_id'] ?? null,
                            $student['optional_subject_id'] ?? null,
                            $student['phone'] ?? null,
                        ]);
                        $promoted_count++;
                    }

                    $conn->commit();
                    $message = "Successfully copied $promoted_count students into the target class with collision-safe roll assignment.";
                }
            }
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Error during promotion: " . $e->getMessage();
    }
}
?>

<head>
    <title>Promote Students - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .arrow-container {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #f1f5f9;
            border: 1px solid var(--prestige-border);
            color: var(--prestige-gold);
            font-size: 1.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        [data-theme="dark"] .arrow-container {
            background: #1e293b;
            border-color: rgba(255, 255, 255, 0.12) !important;
            color: var(--prestige-gold) !important;
        }

        .arrow-container:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(180, 83, 9, 0.15) !important;
        }

        /* ── All Sections fixed badge (From side) ── */
        #fromSectionBadge {
            min-height: 42px;
        }
        [data-theme="dark"] #fromSectionBadge {
            background: linear-gradient(135deg, #052e16, #14532d) !important;
            border-color: #166534 !important;
            color: #4ade80 !important;
        }
        [data-theme="dark"] #fromSectionBadge .badge {
            background: rgba(74,222,128,0.15) !important;
            color: #4ade80 !important;
        }

        /* ── Column headings ── */
        .promo-heading-from {
            color: var(--prestige-navy);
            font-family: 'Playfair Display', serif;
            font-weight: 700;
        }
        [data-theme="dark"] .promo-heading-from {
            color: #f1f5f9 !important;
        }
        .promo-heading-to {
            color: var(--prestige-gold);
            font-family: 'Playfair Display', serif;
            font-weight: 700;
        }
        [data-theme="dark"] .promo-heading-to {
            color: var(--prestige-gold) !important;
        }

        /* ── Auto-fill display badges (To side) ── */
        .promo-auto-badge {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 1.5px dashed #cbd5e1;
            color: #94a3b8;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.35s ease;
            min-height: 42px;
        }
        .promo-auto-badge.badge-found {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1.5px solid #86efac;
            border-style: solid;
            color: #15803d;
        }
        .promo-auto-badge.badge-blue {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border: 1.5px solid #93c5fd;
            border-style: solid;
            color: #1d4ed8;
        }
        [data-theme="dark"] .promo-auto-badge {
            background: linear-gradient(135deg, #1e293b, #0f172a) !important;
            border-color: rgba(255,255,255,0.12) !important;
            color: #64748b !important;
        }
        [data-theme="dark"] .promo-auto-badge.badge-found {
            background: linear-gradient(135deg, #052e16, #14532d) !important;
            border-color: #166534 !important;
            color: #4ade80 !important;
        }
        [data-theme="dark"] .promo-auto-badge.badge-blue {
            background: linear-gradient(135deg, #0c1a35, #0f2654) !important;
            border-color: #1e40af !important;
            color: #60a5fa !important;
        }
        /* Status message cards */
        .to-status-found {
            display:flex; align-items:center; gap:0.6rem;
            padding: 0.65rem 1rem; border-radius:10px;
            background:#f0fdf4; border:1px solid #86efac;
            font-size:0.83rem; color:#15803d; font-weight:600;
        }
        .to-status-missing {
            padding: 1rem 1.1rem; border-radius:12px;
            background:#fff7ed; border:1.5px solid #fed7aa;
        }
        [data-theme="dark"] .to-status-found {
            background:#052e16 !important; border-color:#166534 !important; color:#4ade80 !important;
        }
        [data-theme="dark"] .to-status-missing {
            background:#1c0a00 !important; border-color:#7c2d12 !important;
        }

        /* ── Promotion Confirm Modal ── */
        #promotionModal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        #promotionModal.show {
            display: flex;
        }
        .pm-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(6px);
            animation: pmFadeIn 0.25s ease;
        }
        .pm-box {
            position: relative;
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem 2rem 2rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 32px 80px rgba(0,0,0,0.25);
            animation: pmSlideUp 0.3s cubic-bezier(0.34,1.56,0.64,1);
            text-align: center;
        }
        [data-theme="dark"] .pm-box {
            background: #1a2234;
            color: #e2e8f0;
        }
        .pm-icon-ring {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            box-shadow: 0 8px 24px rgba(251,191,36,0.35);
        }
        [data-theme="dark"] .pm-icon-ring {
            background: linear-gradient(135deg, #451a03 0%, #78350f 100%);
            box-shadow: 0 8px 24px rgba(251,191,36,0.2);
        }
        .pm-icon-ring i {
            font-size: 2rem;
            color: #b45309;
        }
        [data-theme="dark"] .pm-icon-ring i {
            color: #fbbf24;
        }
        .pm-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-family: 'Playfair Display', serif;
            color: #1e293b;
        }
        [data-theme="dark"] .pm-title { color: #f1f5f9; }
        .pm-subtitle {
            font-size: 0.92rem;
            color: #64748b;
            margin-bottom: 1.25rem;
        }
        [data-theme="dark"] .pm-subtitle { color: #94a3b8; }
        .pm-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            text-align: left;
            font-size: 0.88rem;
        }
        [data-theme="dark"] .pm-summary {
            background: #0f172a;
            border-color: rgba(255,255,255,0.1);
        }
        .pm-summary-row {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            padding: 0.35rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        [data-theme="dark"] .pm-summary-row { border-color: rgba(255,255,255,0.07); }
        .pm-summary-row:last-child { border-bottom: none; }
        .pm-summary-row i { color: #b45309; margin-top: 2px; min-width: 16px; }
        [data-theme="dark"] .pm-summary-row i { color: #fbbf24; }
        .pm-summary-row span { color: #475569; line-height: 1.4; }
        [data-theme="dark"] .pm-summary-row span { color: #cbd5e1; }
        .pm-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }
        .pm-btn {
            flex: 1;
            max-width: 180px;
            padding: 0.65rem 1.25rem;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }
        .pm-btn-cancel {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        [data-theme="dark"] .pm-btn-cancel {
            background: #1e293b;
            color: #94a3b8;
            border-color: rgba(255,255,255,0.1);
        }
        .pm-btn-cancel:hover {
            background: #e2e8f0;
            color: #475569;
        }
        [data-theme="dark"] .pm-btn-cancel:hover {
            background: #334155;
            color: #e2e8f0;
        }
        .pm-btn-confirm {
            background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(180,83,9,0.4);
        }
        .pm-btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(180,83,9,0.5);
        }
        .pm-btn-confirm:active { transform: translateY(0); }
        .pm-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            font-size: 1.1rem;
            color: #94a3b8;
            cursor: pointer;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .pm-close:hover { background: #f1f5f9; color: #475569; }
        [data-theme="dark"] .pm-close:hover { background: #334155; color: #e2e8f0; }

        @keyframes pmFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes pmSlideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
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
                        <i class="fas fa-rocket"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Promote Students</h1>
                        <p class="mb-0">Bulk move students from one class/section to another (e.g., Class 6 to Class 7).
                        </p>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <form action="" method="POST" id="promotionForm">
                        <div class="row align-items-center">
                            <!-- From Column -->
                            <div class="col-md-5">
                                <h5 class="mb-4 promo-heading-from">Promote From (Old Class)</h5>
                                <div class="mb-3">
                                    <label class="form-label">Academic Year</label>
                                    <select name="from_year" id="from_year" class="form-select" required>
                                        <option value="">Select Year</option>
                                        <?php foreach ($years as $y): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $y == $current_year ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Class</label>
                                    <select name="from_class_name" id="from_class_name" class="form-select" required
                                        disabled>
                                        <option value="">Select Class</option>
                                    </select>
                                </div>
                                <!-- Section is always "All Sections" for the From side -->
                                <input type="hidden" name="from_section" id="from_section" value="All">
                                <div class="mb-0">
                                    <label class="form-label">Section</label>
                                    <div id="fromSectionBadge" class="d-flex align-items-center gap-2 px-3 py-2 rounded-3"
                                         style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;color:#16a34a;font-weight:700;font-size:0.9rem;opacity:0.45;transition:all 0.3s;">
                                         <i class="fas fa-layer-group"></i>
                                         <span id="fromSectionLabel">All Sections</span>
                                         <span class="ms-auto badge" style="background:#16a34a22;color:#16a34a;font-size:0.7rem;padding:3px 8px;border-radius:20px;" id="fromSectionCount">—</span>
                                     </div>
                                    <div class="mt-1" style="font-size:0.75rem;color:#94a3b8;"><i class="fas fa-info-circle me-1"></i>All sections are promoted together</div>
                                </div>
                            </div>

                            <!-- Arrow Column -->
                            <div class="col-md-2 text-center py-4 d-flex align-items-center justify-content-center">
                                <div class="arrow-container shadow-sm">
                                    <i class="fas fa-arrow-right d-none d-md-block"></i>
                                    <i class="fas fa-arrow-down d-md-none"></i>
                                </div>
                            </div>

                            <!-- To Column (auto-filled) -->
                            <div class="col-md-5">
                                <h5 class="mb-4 promo-heading-to">Promote To (Next Class)</h5>

                                <!-- Hidden inputs submitted with the form -->
                                <input type="hidden" name="to_year"       id="to_year"       value="">
                                <input type="hidden" name="to_class_name" id="to_class_name" value="">
                                <input type="hidden" name="to_section"    id="to_section"    value="Auto">

                                <div class="mb-3">
                                    <label class="form-label">Academic Year</label>
                                    <div id="toYearBadge" class="promo-auto-badge">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span id="toYearText">Auto-filled after selecting source</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Class</label>
                                    <div id="toClassBadge" class="promo-auto-badge">
                                        <i class="fas fa-graduation-cap"></i>
                                        <span id="toClassText">Auto-filled after selecting source</span>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Section</label>
                                    <div class="promo-auto-badge badge-blue">
                                        <i class="fas fa-shuffle"></i>
                                        <span>Auto-Distribute by Merit</span>
                                    </div>
                                    <div class="mt-1" style="font-size:0.75rem;color:#94a3b8;">
                                        <i class="fas fa-info-circle me-1"></i>Students ranked by Final Exam result &amp; distributed across all sections
                                    </div>
                                </div>

                                <!-- Status Message -->
                                <div id="toStatusMsg" class="mt-3" style="display:none;"></div>
                            </div>

                            <!-- Promotion Settings -->
                            <div class="col-12 mt-4">
                                <div class="card bg-light border-0 p-3 mx-auto" style="max-width: 600px; border-radius: 12px;">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-cog me-2"></i>Promotion Settings</h6>
                                    <div class="form-check mb-2" id="autoDistributeContainer" style="display: none;">
                                        <input class="form-check-input" type="checkbox" name="auto_distribute" id="auto_distribute" value="1">
                                        <label class="form-check-label" for="auto_distribute">
                                            Auto-distribute students across target sections based on Final Exam merit
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="reset_rolls" id="reset_rolls" value="1" checked>
                                        <label class="form-check-label" for="reset_rolls">
                                            Reset and assign new roll numbers starting from 1 for each target section (ordered by merit)
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Action -->
                            <div class="col-12 mt-5 text-center">
                                <button type="button" id="openPromoteModal"
                                    class="btn btn-primary btn-lg px-5 font-weight-bold shadow-sm">
                                    <i class="fas fa-rocket me-2"></i> PROMOTE STUDENTS
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const allClasses = <?php echo json_encode($all_classes); ?>;

                    function setupCascadedDropdowns(side) {
                        const yearSel = document.getElementById(`${side}_year`);
                        const classSel = document.getElementById(`${side}_class_name`);
                        const sectionSel = document.getElementById(`${side}_section`);

                        function updateClasses() {
                            const year = yearSel.value;
                            classSel.innerHTML = '<option value="">Select Class</option>';
                            if (sectionSel) {
                                sectionSel.innerHTML = '<option value="">Select Section</option>';
                                sectionSel.disabled = true;
                            }

                            if (year) {
                                const classes = [...new Set(allClasses
                                    .filter(c => c.academic_year == year)
                                    .map(c => c.class_name))];

                                classes.forEach(c => {
                                    const opt = document.createElement('option');
                                    opt.value = c;
                                    opt.textContent = c;
                                    classSel.appendChild(opt);
                                });
                                classSel.disabled = false;
                            } else {
                                classSel.disabled = true;
                            }
                        }

                        function updateSections() {
                            const year = yearSel.value;
                            const className = classSel.value;

                            if (side === 'from') {
                                const badge  = document.getElementById('fromSectionBadge');
                                const countEl = document.getElementById('fromSectionCount');
                                const labelEl = document.getElementById('fromSectionLabel');
                                if (year && className) {
                                    let sections = [...new Set(allClasses
                                        .filter(c => c.academic_year == year && c.class_name == className)
                                        .map(c => c.section))];
                                    
                                    if (className === 'Class 9') {
                                        sections = sections.filter(s => ['Science', 'Arts', 'Commerce'].includes(s));
                                        if (labelEl) labelEl.textContent = 'Academic Groups';
                                        countEl.textContent = sections.length + ' group' + (sections.length !== 1 ? 's' : '');
                                    } else {
                                        if (labelEl) labelEl.textContent = 'All Sections';
                                        countEl.textContent = sections.length + ' section' + (sections.length !== 1 ? 's' : '');
                                    }
                                    
                                    badge.style.opacity = '1';
                                    activateAllSectionsMode();
                                } else {
                                    if (labelEl) labelEl.textContent = 'All Sections';
                                    countEl.textContent = '—';
                                    badge.style.opacity = '0.45';
                                }
                                return;
                            }
                        }

                        yearSel.addEventListener('change', updateClasses);
                        classSel.addEventListener('change', updateSections);

                        if (yearSel.value) updateClasses();
                    }
                    function activateAllSectionsMode() {
                        document.getElementById('autoDistributeContainer').style.display = 'block';
                        document.getElementById('auto_distribute').checked = true;
                        autoFillToColumn();
                    }

                    function autoFillToColumn() {
                        const fromYear  = document.getElementById('from_year').value;
                        const fromClass = document.getElementById('from_class_name').value;
                        const yearBadge  = document.getElementById('toYearBadge');
                        const classBadge = document.getElementById('toClassBadge');
                        const yearText   = document.getElementById('toYearText');
                        const classText  = document.getElementById('toClassText');
                        const statusMsg  = document.getElementById('toStatusMsg');
                        const toYearIn   = document.getElementById('to_year');
                        const toClassIn  = document.getElementById('to_class_name');

                        [yearBadge, classBadge].forEach(b => b.className = 'promo-auto-badge');
                        toYearIn.value  = '';
                        toClassIn.value = '';
                        statusMsg.style.display = 'none';
                        statusMsg.innerHTML = '';

                        if (!fromYear || !fromClass) {
                            yearText.textContent  = 'Auto-filled after selecting source';
                            classText.textContent = 'Auto-filled after selecting source';
                            return;
                        }

                        const nextYear = parseInt(fromYear) + 1;
                        let nextClass = null;

                        // Parse the current class number
                        const currentNum = parseInt(fromClass.replace(/\D/g, ''));
                        if (!isNaN(currentNum)) {
                            nextClass = 'Class ' + (currentNum + 1);
                        }

                        yearText.textContent = nextYear;
                        yearBadge.className  = 'promo-auto-badge badge-found';
                        toYearIn.value = nextYear;

                        if (!nextClass) {
                            classText.textContent = 'No next class found';
                            classBadge.className  = 'promo-auto-badge';
                            return;
                        }

                        classText.textContent = nextClass;
                        classBadge.className  = 'promo-auto-badge badge-found';
                        toClassIn.value = nextClass;

                        const targetExists = allClasses.some(
                            c => c.academic_year == nextYear && c.class_name === nextClass
                        );

                        statusMsg.style.display = 'block';
                        if (targetExists) {
                            statusMsg.innerHTML = `<div class="to-status-found">
                                <i class="fas fa-check-circle fa-lg"></i>
                                <span>Target <strong>${nextClass} &mdash; ${nextYear}</strong> found. Ready to promote!</span>
                            </div>`;
                        } else {
                            statusMsg.innerHTML = `<div class="to-status-found" style="background:#eff6ff; border-color:#93c5fd; color:#1e40af;">
                                <i class="fas fa-info-circle fa-lg" style="color:#2563eb;"></i>
                                <span>Target <strong>${nextClass} &mdash; ${nextYear}</strong> does not exist yet. It will be <strong>automatically created</strong> during promotion.</span>
                            </div>`;
                        }
                    }

                    setupCascadedDropdowns('from');

                    document.getElementById('from_year').addEventListener('change', function() {
                        document.getElementById('from_class_name').value = '';
                        document.getElementById('toStatusMsg').style.display = 'none';
                        document.getElementById('toYearText').textContent  = 'Auto-filled after selecting source';
                        document.getElementById('toClassText').textContent = 'Auto-filled after selecting source';
                        ['toYearBadge','toClassBadge'].forEach(id => {
                            document.getElementById(id).className = 'promo-auto-badge';
                        });
                        document.getElementById('to_year').value  = '';
                        document.getElementById('to_class_name').value = '';
                    });

                    const promotionModal = document.getElementById('promotionModal');
                    const pmClose       = document.getElementById('pmClose');
                    const pmBackdrop    = document.getElementById('pmBackdrop');
                    const pmCancel      = document.getElementById('pmCancel');
                    const pmConfirm     = document.getElementById('pmConfirm');
                    const openModalBtn  = document.getElementById('openPromoteModal');
                    const promotionForm = document.getElementById('promotionForm');

                    function buildSummary() {
                        const fromYear    = document.getElementById('from_year').value    || '—';
                        const fromClass   = document.getElementById('from_class_name').value || '—';
                        const fromSection = document.getElementById('from_section').value  || '—';
                        const toYear      = document.getElementById('to_year').value       || '—';
                        const toClass     = document.getElementById('to_class_name').value  || '—';
                        const toSection   = 'Auto-Distribute by Merit';
                        const autoDist    = document.getElementById('auto_distribute')?.checked;
                        const resetRolls  = document.getElementById('reset_rolls')?.checked;

                        let rows = [
                            { icon: 'fa-graduation-cap', text: `<b>From:</b> ${fromClass} – Section ${fromSection} (${fromYear})` },
                            { icon: 'fa-arrow-right',    text: `<b>To:</b> ${toClass} – ${toSection} (${toYear})` },
                        ];
                        if (autoDist) rows.push({ icon: 'fa-chart-bar', text: 'Students will be <b>auto-distributed by Final Exam merit</b>' });
                        if (resetRolls) rows.push({ icon: 'fa-hashtag', text: 'Roll numbers will be <b>reset from 1</b> for each section' });

                        const box = document.getElementById('pmSummaryBox');
                        box.innerHTML = rows.map(r =>
                            `<div class="pm-summary-row"><i class="fas ${r.icon}"></i><span>${r.text}</span></div>`
                        ).join('');
                    }

                    function openModal() {
                        const fromClassVal = document.getElementById('from_class_name').value;
                        const toYear      = document.getElementById('to_year').value;
                        const toClass     = document.getElementById('to_class_name').value;

                        if (!fromClassVal) {
                            const cl = document.getElementById('from_class_name');
                            cl.style.boxShadow = '0 0 0 3px rgba(234,88,12,0.35)';
                            setTimeout(() => cl.style.boxShadow = '', 2000);
                            return;
                        }
                        if (!toYear || !toClass) {
                            const msg = document.getElementById('toStatusMsg');
                            msg.style.display = 'block';
                            msg.innerHTML = `<div class="to-status-missing"><div style="font-weight:700;color:#c2410c;"><i class="fas fa-exclamation-triangle me-1"></i>Target class not ready</div><div style="font-size:0.85rem;color:#9a3412;margin-top:0.3rem;">The destination class for <strong>${fromClassVal}</strong> has not been created yet. Please create it first.</div></div>`;
                            msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            return;
                        }

                        buildSummary();
                        promotionModal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                    }

                    function closeModal() {
                        promotionModal.classList.remove('show');
                        document.body.style.overflow = '';
                    }

                    if (openModalBtn) {
                        openModalBtn.addEventListener('click', openModal);
                    }
                    if (pmClose) {
                        pmClose.addEventListener('click', closeModal);
                    }
                    if (pmCancel) {
                        pmCancel.addEventListener('click', closeModal);
                    }
                    if (pmBackdrop) {
                        pmBackdrop.addEventListener('click', closeModal);
                    }
                    if (pmConfirm) {
                        pmConfirm.addEventListener('click', function() {
                            pmConfirm.disabled = true;
                            pmConfirm.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
                            const existingInput = promotionForm.querySelector('input[name="promote"]');
                            if (existingInput) existingInput.remove();
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type  = 'hidden';
                            hiddenInput.name  = 'promote';
                            hiddenInput.value = '1';
                            promotionForm.appendChild(hiddenInput);
                            promotionForm.submit();
                        });
                    }

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && promotionModal && promotionModal.classList.contains('show')) closeModal();
                    });
                });
            </script>

            <!-- ── Promotion Confirmation Modal ── -->
            <div id="promotionModal" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
                <div class="pm-backdrop" id="pmBackdrop"></div>
                <div class="pm-box">
                    <button class="pm-close" id="pmClose" title="Close"><i class="fas fa-times"></i></button>
                    <div class="pm-icon-ring">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <div class="pm-title" id="pmTitle">Confirm Promotion</div>
                    <div class="pm-subtitle">Please review the promotion details before proceeding.</div>
                    <div class="pm-summary" id="pmSummaryBox">
                        <div class="pm-summary-row">
                            <i class="fas fa-arrow-right"></i>
                            <span id="pmSummaryText">Loading details…</span>
                        </div>
                    </div>
                    <p class="text-muted" style="font-size:0.82rem; margin-bottom:1.4rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Previous exam results are preserved. Only the student's class association will be updated.
                    </p>
                    <div class="pm-actions">
                        <button class="pm-btn pm-btn-cancel" id="pmCancel">
                            <i class="fas fa-times-circle"></i> Cancel
                        </button>
                        <button class="pm-btn pm-btn-confirm" id="pmConfirm">
                            <i class="fas fa-rocket"></i> Promote Now
                        </button>
                    </div>
                </div>
            </div>

            <div class="alert alert-info mt-4">
                <i class="fas fa-exclamation-triangle me-2"></i> <strong>Important:</strong> Previous results are
                preserved in the database. Promotion only updates the student's current class association.
            </div>
        </div>
    </div>

</body>

</html>