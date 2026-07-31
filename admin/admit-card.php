<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

// ── Auto-create exam_schedule table if not exists ──
$conn->exec("
    CREATE TABLE IF NOT EXISTS exam_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        exam_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        exam_type VARCHAR(50) NOT NULL DEFAULT 'Half Yearly',
        student_group VARCHAR(50) NOT NULL DEFAULT 'None',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_schedule (class_id, subject_id, exam_type)
    )
");

$message = '';
$error = '';

// --- ADMIN SYSTEM CONTROLS ---
if (is_admin() && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_admit_status'])) {
    $admit_published = isset($_POST['admit_cards_published']) ? '1' : '0';
    $admit_year = $_POST['current_admit_year'] ?? date('Y');
    $admit_exam = $_POST['current_admit_exam'] ?? 'Half Yearly';
    
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES 
            ('admit_cards_published', ?),
            ('current_admit_year', ?),
            ('current_admit_exam', ?)");
        $stmt->execute([$admit_published, $admit_year, $admit_exam]);
        $conn->commit();
        $message = $admit_published === '1' ? "Admit card successfully published!" : "Admit card successfully unpublished!";
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Error updating status: " . $e->getMessage();
    }
}

// Get available years for the dropdown (Low to High)
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC");
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Ensure current year is always available as an option
if (!in_array(date('Y'), $available_years)) {
    $available_years[] = date('Y');
    sort($available_years);
}

// Fetch current settings
$stmt_sys = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $stmt_sys->fetchAll(PDO::FETCH_KEY_PAIR);
$admit_cards_active = ($settings['admit_cards_published'] ?? '0') === '1';
$current_admit_year = $settings['current_admit_year'] ?? date('Y');
$current_admit_exam = $settings['current_admit_exam'] ?? 'Half Yearly';

// Use settings as default if not overridden by GET/POST
$sel_academic_year = $_GET['academic_year'] ?? ($_POST['academic_year'] ?? $current_admit_year);
$sel_class_name    = $_GET['class_name']    ?? ($_POST['class_name']    ?? '');
$sel_exam_type     = $_GET['exam_type']     ?? ($_POST['exam_type']     ?? $current_admit_exam);

// ── Handle Save Schedule ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    $class_name    = $_POST['class_name'];
    $academic_year = $_POST['academic_year'];
    $exam_type     = $_POST['exam_type'];
    $schedules     = $_POST['schedule'] ?? [];

    $stmt_ids = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ?");
    $stmt_ids->execute([$class_name, $academic_year]);
    $all_class_ids = array_column($stmt_ids->fetchAll(), 'id');

    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO exam_schedule (class_id, subject_id, exam_date, start_time, end_time, exam_type)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE exam_date=VALUES(exam_date), start_time=VALUES(start_time), end_time=VALUES(end_time)
        ");
        foreach ($all_class_ids as $cid) {
            foreach ($schedules as $subject_id => $row) {
                if (!empty($row['exam_date']) && !empty($row['start_time']) && !empty($row['end_time'])) {
                    $stmt->execute([$cid, $subject_id, $row['exam_date'], $row['start_time'], $row['end_time'], $exam_type]);
                }
            }
        }
        $conn->commit();
        $count = count($all_class_ids);
        $message = "Schedule saved for all $count section(s) of $class_name!";
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error saving schedule: ' . $e->getMessage();
    }
}

// ── Build unique class list grouped by class_name for the latest year only ──
$stmt_classes = $conn->prepare("
    SELECT class_name, academic_year, COUNT(id) as section_count
    FROM classes
    WHERE academic_year = ?
    GROUP BY class_name, academic_year
    ORDER BY LENGTH(class_name), class_name
");
$stmt_classes->execute([$sel_academic_year]);
$unique_classes = $stmt_classes->fetchAll();

// Resolve ALL class_ids for selected class
$all_sel_class_ids = [];
$ref_class_id      = null; // reference class_id for subject query
if ($sel_class_name && $sel_academic_year) {
    $stmt_ids = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? ORDER BY section");
    $stmt_ids->execute([$sel_class_name, $sel_academic_year]);
    $all_sel_class_ids = array_column($stmt_ids->fetchAll(), 'id');
    $ref_class_id = $all_sel_class_ids[0] ?? null;
}

// ── Fetch subjects (use first section as reference) ──
$subjects_for_schedule = [];
if ($ref_class_id) {
    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name,
               MIN(cs.student_group) as student_group,
               COUNT(DISTINCT cs.student_group) as group_count,
               es.exam_date, es.start_time, es.end_time
        FROM class_subjects cs
        JOIN subjects s ON cs.subject_id = s.id
        LEFT JOIN exam_schedule es
            ON es.subject_id = cs.subject_id
            AND es.class_id = cs.class_id
            AND es.exam_type = ?
        WHERE cs.class_id = ?
        GROUP BY s.id
        ORDER BY cs.is_optional ASC, cs.is_school_based ASC, s.id ASC
    ");
    $stmt->execute([$sel_exam_type, $ref_class_id]);
    $subjects_for_schedule = $stmt->fetchAll();
}

// ── Fetch all students from ALL sections ──
$students_list = [];
if (!empty($all_sel_class_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_sel_class_ids), '?'));
    $stmt = $conn->prepare("
        SELECT s.*, c.class_name, c.section
        FROM students s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id IN ($placeholders)
        ORDER BY c.section ASC, CAST(s.roll_number AS UNSIGNED) ASC
    ");
    $stmt->execute($all_sel_class_ids);
    $students_list = $stmt->fetchAll();
}

// ── Check schedule count ──
$schedule_count = 0;
if (!empty($all_sel_class_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_sel_class_ids), '?'));
    $stmt_chk = $conn->prepare("SELECT COUNT(*) FROM exam_schedule WHERE class_id IN ($placeholders) AND exam_type = ?");
    $stmt_chk->execute(array_merge($all_sel_class_ids, [$sel_exam_type]));
    $schedule_count = $stmt_chk->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admit Card — SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .schedule-row { transition: background 0.2s; border-bottom: 1px solid var(--prestige-border); }
        .schedule-row:hover { background: rgba(180,83,9,0.04); }
        [data-theme="dark"] .schedule-row:hover { background: rgba(245,158,11,0.08); }

        .subject-badge {
            display: inline-block;
            font-size: 0.68rem;
            padding: 2px 8px;
            border-radius: 12px;
            background: rgba(15,23,42,0.06);
            color: var(--prestige-navy);
            font-weight: 700;
            border: 1px solid rgba(15,23,42,0.08);
        }
        [data-theme="dark"] .subject-badge {
            background: rgba(255,255,255,0.06);
            color: #94a3b8;
            border-color: rgba(255,255,255,0.1);
        }

        .section-pill {
            display: inline-block;
            font-size: 0.65rem;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(15,23,42,0.05);
            color: var(--prestige-navy);
            font-weight: 700;
            border: 1px solid rgba(15,23,42,0.08);
            margin-left: 6px;
        }
        [data-theme="dark"] .section-pill {
            background: rgba(255,255,255,0.05);
            color: #94a3b8;
            border-color: rgba(255,255,255,0.08);
        }

        .student-check-row { transition: background 0.15s; cursor: pointer; border-bottom: 1px solid var(--prestige-border); }
        .student-check-row:hover { background: rgba(180,83,9,0.04) !important; }
        [data-theme="dark"] .student-check-row:hover { background: rgba(245,158,11,0.08) !important; }

        .exam-type-pill {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            background: var(--prestige-navy);
            color: white;
            letter-spacing: 0.5px;
        }

        .section-divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 32px 0 24px;
        }
        .section-divider hr { flex: 1; margin: 0; opacity: 0.1; }
        .section-divider span {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #94a3b8;
            white-space: nowrap;
        }

        .print-action-bar {
            position: sticky;
            bottom: 20px;
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            z-index: 10;
            margin-top: 20px;
        }
        [data-theme="dark"] .print-action-bar {
            background: #111827;
            border-color: rgba(255,255,255,0.07);
        }

        .time-input-group { display: flex; gap: 6px; align-items: center; }
        [data-theme="dark"] .time-input-group .text-muted { color: var(--prestige-gold) !important; }

        .section-header-row td {
            background: rgba(15,23,42,0.04) !important;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--prestige-navy);
            padding: 8px 16px !important;
            border-bottom: 2px solid var(--prestige-border) !important;
        }
        [data-theme="dark"] .section-header-row td {
            background: rgba(255,255,255,0.03) !important;
            color: #94a3b8;
            border-bottom-color: rgba(255,255,255,0.1) !important;
        }

        /* Dark Mode Table Overrides */
        [data-theme="dark"] .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--prestige-text);
            color: var(--prestige-text);
        }

        .info-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            border-radius: 12px;
            background: rgba(180,83,9,0.05);
            border: 1px solid rgba(180,83,9,0.1);
            margin-bottom: 24px;
        }
        [data-theme="dark"] .info-bar {
            background: rgba(245,158,11,0.08);
            border-color: rgba(245,158,11,0.2);
            color: #fbbf24;
        }
        .info-bar i { color: var(--prestige-gold); font-size: 1.1rem; }
        [data-theme="dark"] .info-bar i { color: #f59e0b; }

        @media (max-width: 768px) {
            .print-action-bar { flex-direction: column; gap: 10px; text-align: center; }
            .print-action-bar .d-flex { width: 100%; justify-content: center; }

            /* Card-style table for mobile schedule setup */
            .schedule-table-wrap table thead { display: none; }
            .schedule-table-wrap table tbody tr.schedule-row {
                display: flex;
                flex-direction: column;
                background: #fff;
                border: 1px solid var(--prestige-border);
                border-radius: 10px;
                margin-bottom: 15px;
                padding: 12px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.02);
            }
            [data-theme="dark"] .schedule-table-wrap table tbody tr.schedule-row {
                background: #1e293b;
            }
            .schedule-table-wrap table tbody td {
                display: flex;
                flex-direction: column;
                border: none !important;
                padding: 8px 0 !important;
            }
            .schedule-table-wrap table tbody td::before {
                font-size: 0.65rem;
                font-weight: 800;
                color: #94a3b8;
                text-transform: uppercase;
                margin-bottom: 6px;
                letter-spacing: 1px;
            }
            .schedule-table-wrap table tbody td:nth-child(1)::before { content: 'Subject'; }
            .schedule-table-wrap table tbody td:nth-child(2)::before { content: 'Exam Date'; }
            .schedule-table-wrap table tbody td:nth-child(3)::before { content: 'Time (Start → End)'; }
            .schedule-table-wrap table tbody td:nth-child(4)::before { content: 'Status'; }

            .time-input-group { flex-direction: row; gap: 10px; align-items: center; }
            .time-input-group input { flex: 1; }

            /* ─── Select Students: Card layout on mobile ─── */
            .students-table-wrap { overflow: visible !important; }
            .students-table-wrap table thead { display: none; }

            .students-table-wrap table tbody tr.section-header-row {
                display: block;
                border: none !important;
                background: transparent !important;
                margin-top: 8px;
            }
            .students-table-wrap table tbody tr.section-header-row td {
                display: block;
                border-bottom: 2px solid var(--prestige-border) !important;
                padding: 8px 4px !important;
            }

            .students-table-wrap table tbody tr.student-check-row {
                display: flex;
                align-items: center;
                gap: 12px;
                border: 1px solid var(--prestige-border) !important;
                border-radius: 10px;
                margin-bottom: 10px;
                padding: 12px;
                background: #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            }
            [data-theme="dark"] .students-table-wrap table tbody tr.student-check-row {
                background: #111827;
                border-color: rgba(255,255,255,0.1) !important;
            }

            /* Checkbox cell */
            .students-table-wrap table tbody tr.student-check-row td:nth-child(1) {
                display: flex; align-items: center;
                flex-shrink: 0; padding: 0 !important; border: none !important;
            }
            /* Roll cell */
            .students-table-wrap table tbody tr.student-check-row td:nth-child(2) {
                display: flex; flex-shrink: 0;
                padding: 0 !important; border: none !important;
            }
            /* Name cell */
            .students-table-wrap table tbody tr.student-check-row td:nth-child(3) {
                display: flex; flex-direction: column; flex: 1;
                padding: 0 !important; border: none !important;
            }
            /* Section + Group: hidden on mobile (section shown in header row) */
            .students-table-wrap table tbody tr.student-check-row td:nth-child(4),
            .students-table-wrap table tbody tr.student-check-row td:nth-child(5) {
                display: none;
            }
            /* Print button cell */
            .students-table-wrap table tbody tr.student-check-row td:nth-child(6) {
                display: flex; align-items: center; flex-shrink: 0;
                padding: 0 !important; border: none !important;
            }
            .students-table-wrap table tbody tr.student-check-row td:nth-child(6) .btn {
                white-space: nowrap; font-size: 0.75rem; padding: 5px 10px;
            }
        }
        .btn-print-gold {
            border: 1px solid var(--prestige-gold);
            color: var(--prestige-gold);
            background: transparent;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        .btn-print-gold:hover {
            background: var(--prestige-gold);
            color: white !important;
            box-shadow: 0 0 15px rgba(180, 83, 9, 0.3);
            transform: translateY(-2px);
        }
        [data-theme="dark"] .btn-print-gold:hover {
            color: #000 !important;
        }
        .page-header {
            display: flex !important;
            flex-direction: column !important; /* Mobile default */
            align-items: flex-start !important;
            gap: 15px;
        }

        @media (min-width: 992px) {
            .page-header {
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
            }
            .admit-status-card {
                margin-bottom: 0 !important;
            }
        }

        .admit-status-card {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(15, 23, 42, 0.1) !important;
            border-radius: 16px;
            padding: 10px 24px;
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.05);
            display: inline-flex; /* Use inline-flex for auto width on desktop */
            align-items: center;
            gap: 30px;
            margin-bottom: 10px;
            max-width: 100%;
        }

        .status-control-item {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .status-control-item:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -15px;
            height: 20px;
            width: 1px;
            background: rgba(15, 23, 42, 0.1);
        }

        .status-control-label {
            font-size: 0.65rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-control-label i {
            color: var(--prestige-gold);
            font-size: 0.8rem;
        }

        .status-select-sm {
            padding: 4px 35px 4px 12px !important; /* Extra right padding for arrow */
            font-size: 0.85rem !important;
            font-weight: 700 !important;
            border-radius: 10px !important;
            border: 1.5px solid #e2e8f0 !important;
            width: auto !important;
            min-width: 100px;
            cursor: pointer;
            background-color: #ffffff !important;
            color: var(--prestige-navy);
            height: 36px;
            transition: all 0.2s ease;
        }

        .status-select-sm:hover {
            border-color: var(--prestige-gold) !important;
            background-color: #f8fafc !important;
        }

        /* Blue Switch in Light Mode */
        .admit-status-card .form-check-input:checked {
            background-color: #3b82f6 !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.3);
        }

        [data-theme="dark"] .admit-status-card {
            background: rgba(15, 23, 42, 0.6) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4) !important;
        }
        
        [data-theme="dark"] .status-control-item:not(:last-child)::after {
            background: rgba(255, 255, 255, 0.1);
        }

        [data-theme="dark"] .status-control-label {
            color: #94a3b8;
        }

        [data-theme="dark"] .status-select-sm {
            background-color: rgba(255, 255, 255, 0.05) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .status-select-sm:hover {
            border-color: var(--prestige-gold) !important;
            background-color: rgba(255, 255, 255, 0.08) !important;
        }

        @media (max-width: 768px) {
            .status-control-item::after {
                display: none !important;
            }
            .admit-status-card {
                display: flex;
                border-radius: 12px;
                padding: 10px;
                flex-wrap: wrap;
                gap: 8px 15px;
                justify-content: center;
                margin-top: 5px;
                width: 100%;
            }
            .status-control-item {
                gap: 6px;
            }
            .status-control-label {
                font-size: 0.55rem;
                letter-spacing: 0.5px;
            }
            .status-select-sm {
                font-size: 0.7rem !important;
                height: 28px;
                padding: 2px 20px 2px 6px !important;
            }
            .hide-mobile {
                display: none !important;
            }
        }

        /* Gold Switch in Dark Mode */
        [data-theme="dark"] .admit-status-card .form-check-input {
            background-color: rgba(255, 255, 255, 0.1) !important;
            border-color: rgba(180, 83, 9, 0.4) !important;
        }
        [data-theme="dark"] .admit-status-card .form-check-input:checked {
            background-color: var(--prestige-gold) !important;
            border-color: var(--prestige-gold) !important;
        }
        [data-theme="dark"] .admit-status-card .form-check-input:focus {
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.2) !important;
        }
    </style>
</head>
<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">

            <!-- Page Header -->
            <div class="page-header mb-4">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Admit Card</h1>
                        <p class="mb-0 small text-muted hide-mobile">Manage exam schedules and student admit cards</p>
                    </div>
                </div>

                <?php if (is_admin()): ?>
                <form method="POST" class="admit-status-card">
                    <div class="status-control-item">
                        <div class="status-control-label">
                            <i class="fas fa-history"></i>
                            <span class="hide-mobile">Session:</span>
                        </div>
                        <select name="current_admit_year" class="form-select status-select-sm" onchange="this.form.submit()">
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $current_admit_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="status-control-item">
                        <div class="status-control-label">
                            <i class="fas fa-file-invoice"></i>
                            <span class="hide-mobile">Exam:</span>
                        </div>
                        <select name="current_admit_exam" class="form-select status-select-sm" onchange="this.form.submit()">
                            <option value="Half Yearly" <?php echo $current_admit_exam == 'Half Yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                            <option value="Final" <?php echo $current_admit_exam == 'Final' ? 'selected' : ''; ?>>Final</option>
                        </select>
                    </div>

                    <div class="status-control-item border-0">
                        <div class="status-control-label">
                            <i class="fas fa-globe"></i>
                            <span class="hide-mobile">Public:</span>
                        </div>
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" name="admit_cards_published" id="admitToggle" <?php echo $admit_cards_active ? 'checked' : ''; ?> onchange="this.form.submit()" style="width: 2.8rem; height: 1.4rem; cursor: pointer;">
                            <label class="form-check-label ms-1 fw-bold small" for="admitToggle" style="color: <?php echo $admit_cards_active ? '#10b981' : '#ef4444'; ?>; min-width: 65px;">
                                <?php echo $admit_cards_active ? 'LIVE' : 'HIDDEN'; ?>
                            </label>
                        </div>
                    </div>
                    <input type="hidden" name="update_admit_status" value="1">
                </form>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════
                 SECTION 1: SCHEDULE MANAGEMENT
                 ═══════════════════════════════════ -->
            <div class="card">
                <div class="card-body p-4">
                    <h5 class="serif-font fw-bold mb-1" style="color: var(--prestige-text);">
                        <i class="fas fa-calendar-alt me-2" style="color: var(--prestige-gold);"></i>
                        Exam Schedule Setup
                    </h5>
                    <p class="text-muted small mb-4">
                        Select a class and exam type — the schedule is shared across all sections of that class automatically.
                    </p>

                    <form method="GET" action="" id="filterForm">
                        <div class="row g-3 mb-4 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Class</label>
                                <select name="class_name" class="form-select" onchange="this.form.submit()" id="classSelect">
                                    <option value="">— Select Class —</option>
                                    <?php foreach ($unique_classes as $uc): ?>
                                        <option value="<?= htmlspecialchars($uc['class_name']) ?>"
                                                <?= ($sel_class_name === $uc['class_name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($uc['class_name']) ?>
                                            — <?= $uc['section_count'] ?> Section<?= $uc['section_count'] > 1 ? 's' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Exam Type</label>
                                <select name="exam_type" class="form-select" onchange="this.form.submit()">
                                    <option value="Half Yearly" <?= ($sel_exam_type === 'Half Yearly') ? 'selected' : '' ?>>Half Yearly</option>
                                    <option value="Final"       <?= ($sel_exam_type === 'Final')       ? 'selected' : '' ?>>Final</option>
                                </select>
                            </div>
                        </div>
                    </form>


                    <?php if ($sel_class_name && !empty($subjects_for_schedule)): ?>


                    <form method="POST" action="">
                        <input type="hidden" name="class_name"    value="<?= htmlspecialchars($sel_class_name) ?>">
                        <input type="hidden" name="academic_year" value="<?= htmlspecialchars($sel_academic_year) ?>">
                        <input type="hidden" name="exam_type"     value="<?= htmlspecialchars($sel_exam_type) ?>">
                        <input type="hidden" name="save_schedule" value="1">

                        <div class="table-responsive schedule-table-wrap">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Subject</th>
                                        <th style="min-width:150px;">Exam Date</th>
                                        <th style="min-width:230px;">Time (Start → End)</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjects_for_schedule as $sub): ?>
                                    <tr class="schedule-row">
                                        <td>
                                            <span class="fw-600"><?= htmlspecialchars($sub['subject_name']) ?></span>
                                            <?php if ($sub['student_group'] && $sub['student_group'] !== 'None' && $sub['group_count'] == 1): ?>
                                                <span class="subject-badge ms-2"><?= htmlspecialchars($sub['student_group']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="date" name="schedule[<?= $sub['id'] ?>][exam_date]"
                                                   class="form-control form-control-sm"
                                                   value="<?= htmlspecialchars($sub['exam_date'] ?? '') ?>">
                                        </td>
                                        <td>
                                            <div class="time-input-group">
                                                <input type="time" name="schedule[<?= $sub['id'] ?>][start_time]"
                                                       class="form-control form-control-sm"
                                                       value="<?= htmlspecialchars($sub['start_time'] ?? '') ?>">
                                                <span class="text-muted fw-bold" style="font-size:0.8rem;">→</span>
                                                <input type="time" name="schedule[<?= $sub['id'] ?>][end_time]"
                                                       class="form-control form-control-sm"
                                                       value="<?= htmlspecialchars($sub['end_time'] ?? '') ?>">
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($sub['exam_date']): ?>
                                                <span class="badge bg-success" style="font-size:0.7rem;">
                                                    <i class="fas fa-check me-1"></i>Scheduled
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary" style="font-size:0.7rem;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-2"></i>Save Schedule for All Sections
                            </button>
                        </div>
                    </form>

                    <?php elseif ($sel_class_name): ?>
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No subjects found for <strong><?= htmlspecialchars($sel_class_name) ?></strong>. Please assign subjects first.
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-arrow-up fa-2x mb-3 d-block opacity-25"></i>
                            Select a class above to manage its exam schedule.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══════════════════════════════════
                 SECTION 2: PRINT ADMIT CARDS
                 ═══════════════════════════════════ -->
            <div class="section-divider">
                <hr>
                <span><i class="fas fa-print me-2"></i>Print Admit Cards</span>
                <hr>
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <h5 class="serif-font fw-bold mb-1" style="color: var(--prestige-text);">
                        <i class="fas fa-users me-2" style="color: var(--prestige-gold);"></i>
                        Select Students
                        <?php if ($sel_class_name): ?>
                            <span class="ms-2" style="font-size:0.85rem; font-weight:500; color:#64748b;">
                                — <?= htmlspecialchars($sel_class_name) ?>
                                (<?= count($all_sel_class_ids) ?> Section<?= count($all_sel_class_ids) > 1 ? 's' : '' ?>,
                                <?= count($students_list) ?> Students)
                            </span>
                        <?php endif; ?>
                    </h5>
                    <p class="text-muted small mb-4">All students across all sections are shown. Select individually or in bulk.</p>

                    <?php if ($sel_class_name && !empty($students_list)): ?>

                        <?php if (!$schedule_count): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No exam schedule found for <strong><?= htmlspecialchars($sel_class_name) ?></strong> — <strong><?= htmlspecialchars($sel_exam_type) ?></strong>.
                                Please fill in the schedule above and save it first.
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive students-table-wrap">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="selectAll" class="form-check-input" onchange="toggleAll(this)">
                                        </th>
                                        <th width="60">Roll</th>
                                        <th>Student Name</th>
                                        <th width="100">Section</th>
                                        <th>Group</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $current_section = null;
                                    foreach ($students_list as $std):
                                        // Section header row
                                        if ($std['section'] !== $current_section):
                                            $current_section = $std['section'];
                                    ?>
                                    <tr class="section-header-row">
                                        <td colspan="6">
                                            <i class="fas fa-layer-group me-2" style="color: var(--prestige-gold);"></i>
                                            Section <?= htmlspecialchars($current_section) ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="student-check-row" onclick="toggleRow(this)">
                                        <td onclick="event.stopPropagation()">
                                            <input type="checkbox" class="form-check-input student-cb"
                                                   value="<?= $std['id'] ?>"
                                                   data-class-id="<?= $std['class_id'] ?>"
                                                   onchange="updateBulkBar()">
                                        </td>
                                        <td><span class="fw-bold"><?= htmlspecialchars($std['roll_number']) ?></span></td>
                                        <td>
                                            <div class="fw-600"><?= htmlspecialchars(strtoupper($std['name'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="section-pill">Sec <?= htmlspecialchars($std['section']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($std['student_group'] && $std['student_group'] !== 'None'): ?>
                                                <span class="subject-badge"><?= htmlspecialchars($std['student_group']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center" onclick="event.stopPropagation()">
                                            <a href="print-admit-card.php?student_id=<?= $std['id'] ?>&class_id=<?= $std['class_id'] ?>&exam_type=<?= urlencode($sel_exam_type) ?>"
                                               target="_blank" class="btn btn-sm btn-print-gold">
                                                <i class="fas fa-print me-1"></i> Print
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bulk Action Bar -->
                        <div class="print-action-bar" id="bulkBar">
                            <div class="text-muted small">
                                <span id="selectedCount">0</span> of <?= count($students_list) ?> student(s) selected
                                <span class="ms-3 exam-type-pill"><?= htmlspecialchars($sel_exam_type) ?></span>
                            </div>
                            <div class="d-flex gap-2">
                                <button onclick="selectAllStudents()" class="btn btn-outline-secondary btn-sm px-3">
                                    <i class="fas fa-check-double me-1"></i> Select All
                                </button>
                                <button onclick="printBulk()" class="btn btn-primary btn-sm px-4">
                                    <i class="fas fa-print me-2"></i> Print Selected
                                </button>
                            </div>
                        </div>

                    <?php elseif ($sel_class_name): ?>
                        <div class="alert alert-info text-center">
                            <i class="fas fa-info-circle me-2"></i>
                            No students enrolled in <strong><?= htmlspecialchars($sel_class_name) ?></strong>.
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-id-card fa-2x mb-3 d-block opacity-25"></i>
                            Select a class from the Schedule Setup section above to see students.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAll(cb) {
            document.querySelectorAll('.student-cb').forEach(c => c.checked = cb.checked);
            updateBulkBar();
        }

        function toggleRow(row) {
            const cb = row.querySelector('.student-cb');
            if (cb) { cb.checked = !cb.checked; updateBulkBar(); }
        }

        function updateBulkBar() {
            const count = document.querySelectorAll('.student-cb:checked').length;
            document.getElementById('selectedCount').textContent = count;
            const allCb = document.getElementById('selectAll');
            const total = document.querySelectorAll('.student-cb').length;
            if (allCb) allCb.checked = (count === total && total > 0);
        }

        function selectAllStudents() {
            document.querySelectorAll('.student-cb').forEach(c => c.checked = true);
            const allCb = document.getElementById('selectAll');
            if (allCb) allCb.checked = true;
            updateBulkBar();
        }

        function printBulk() {
            const selected = [...document.querySelectorAll('.student-cb:checked')];
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            const studentIds = selected.map(c => c.value).join(',');
            // Use first selected student's class_id as the schedule reference
            const classId   = selected[0].dataset.classId;
            const examType  = '<?= addslashes($sel_exam_type) ?>';
            const url = 'print-admit-card.php?class_id=' + classId +
                        '&exam_type=' + encodeURIComponent(examType) +
                        '&student_ids=' + studentIds;
            window.open(url, '_blank');
        }
        // Auto-dismiss alerts after 3 seconds
        setTimeout(function() {
            let alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                let bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 3000);
    </script>
</body>
</html>
