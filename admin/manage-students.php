<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

$academic_year = $_GET['academic_year'] ?? null;
$class_id = $_GET['class_id'] ?? null;
$selected_class = $_GET['class_name'] ?? null;

$message = "";
$error = "";

// ─── Helper: Serialize student(s) + child records into JSON ─────────────────
function serializeStudentsForUndo($conn, array $student_ids): array {
    if (empty($student_ids)) return [];
    $ph = implode(',', array_fill(0, count($student_ids), '?'));
    $params = array_values($student_ids);

    $students         = $conn->prepare("SELECT * FROM students WHERE id IN ($ph)");
    $marks            = $conn->prepare("SELECT * FROM marks WHERE student_id IN ($ph)");
    $final_results    = $conn->prepare("SELECT * FROM final_results WHERE student_id IN ($ph)");
    $marksheet_tokens = $conn->prepare("SELECT * FROM marksheet_tokens WHERE student_id IN ($ph)");

    $students->execute($params);
    $marks->execute($params);
    $final_results->execute($params);
    $marksheet_tokens->execute($params);

    return [
        'students'         => $students->fetchAll(),
        'marks'            => $marks->fetchAll(),
        'final_results'    => $final_results->fetchAll(),
        'marksheet_tokens' => $marksheet_tokens->fetchAll(),
    ];
}

// Handle bulk deletion and individual deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_delete']) && !empty($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        $ids = array_filter($_POST['student_ids'], 'ctype_digit');
        if (!empty($ids)) {
            try {
                $conn->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_values($ids);

                // ── Serialize before deleting ──────────────────────────────
                $payload    = serializeStudentsForUndo($conn, $ids);
                $names      = array_column($payload['students'], 'name');
                $entityName = count($names) === 1
                    ? $names[0]
                    : count($names) . ' students (' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : '') . ')';

                $archiveStmt = $conn->prepare("INSERT INTO deleted_records (entity_type, entity_name, serialized_data) VALUES (?, ?, ?)");
                $archiveStmt->execute(['students_bulk', $entityName, json_encode($payload)]);
                $undoId = $conn->lastInsertId();

                // Clean up dependent records first
                $conn->prepare("DELETE FROM marksheet_tokens WHERE student_id IN ($placeholders)")->execute($params);
                $conn->prepare("DELETE FROM final_results WHERE student_id IN ($placeholders)")->execute($params);
                $conn->prepare("DELETE FROM marks WHERE student_id IN ($placeholders)")->execute($params);

                // Delete students
                $stmt = $conn->prepare("DELETE FROM students WHERE id IN ($placeholders)");
                $stmt->execute($params);
                $deletedCount = $stmt->rowCount();

                $conn->commit();

                // Store undo in session
                $_SESSION['undo_action'] = [
                    'record_id' => $undoId,
                    'type'      => 'students_bulk',
                    'name'      => $entityName,
                ];

                // Redirect to the current students page without the delete/bulk_delete parameters
                $redirectUrl = 'manage-students.php?view=students&class_id=' . urlencode($class_id);
                if (!empty($selected_class)) {
                    $redirectUrl .= '&class_name=' . urlencode($selected_class);
                }
                if (!empty($academic_year)) {
                    $redirectUrl .= '&academic_year=' . urlencode($academic_year);
                }
                header('Location: ' . $redirectUrl);
                exit;
            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error = "Error: " . $e->getMessage();
            }
        } else {
            $error = "Please select valid students to delete.";
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    try {
        $conn->beginTransaction();

        // ── Serialize before deleting ──────────────────────────────────────
        $payload    = serializeStudentsForUndo($conn, [$id]);
        $entityName = !empty($payload['students']) ? $payload['students'][0]['name'] : 'Student #' . $id;

        $archiveStmt = $conn->prepare("INSERT INTO deleted_records (entity_type, entity_name, serialized_data) VALUES (?, ?, ?)");
        $archiveStmt->execute(['student', $entityName, json_encode($payload)]);
        $undoId = $conn->lastInsertId();

        // Delete dependents then student
        $conn->prepare("DELETE FROM marksheet_tokens WHERE student_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM final_results WHERE student_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM marks WHERE student_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);

        $conn->commit();

        // Store undo in session
        $_SESSION['undo_action'] = [
            'record_id' => $undoId,
            'type'      => 'student',
            'name'      => $entityName,
        ];

        // Redirect to the current students page without the delete parameter
        $redirectUrl = 'manage-students.php?view=students&class_id=' . urlencode($class_id);
        if (!empty($selected_class)) {
            $redirectUrl .= '&class_name=' . urlencode($selected_class);
        }
        if (!empty($academic_year)) {
            $redirectUrl .= '&academic_year=' . urlencode($academic_year);
        }
        header('Location: ' . $redirectUrl);
        exit;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Error: " . $e->getMessage();
    }
}

$view = $_GET['view'] ?? ($academic_year ? 'classes' : 'years'); // Default to years if no year selected

// --- SMART REDIRECT LOGIC ---
// If landing on years view without a specific intent to view all, 
// auto-redirect to the current session classes if it exists.
if ($view == 'years' && !isset($_GET['view_all'])) {
    $current_cal_year = date('Y');
    $check_stmt = $conn->prepare("SELECT DISTINCT academic_year FROM classes WHERE academic_year = ?");
    $check_stmt->execute([$current_cal_year]);
    if ($check_stmt->fetch()) {
        header("Location: manage-students.php?view=classes&academic_year=" . urlencode($current_cal_year));
        exit();
    }
}
// ----------------------------

$selected_class = $_GET['class_name'] ?? null;
$selected_section = $_GET['section'] ?? null;
$class_id = $_GET['class_id'] ?? null;

$data = [];

if ($view == 'years') {
    $stmt = $conn->query("SELECT academic_year, COUNT(DISTINCT class_name) as class_count, 
                         (SELECT COUNT(*) FROM students WHERE class_id IN (SELECT id FROM classes WHERE academic_year = c.academic_year)) as student_count
                         FROM classes c 
                         GROUP BY academic_year 
                         ORDER BY academic_year ASC");
    $data = $stmt->fetchAll();
} elseif ($view == 'classes' && $academic_year) {
    // Show unique class names for a specific year
    $stmt = $conn->prepare("SELECT class_name, COUNT(DISTINCT id) as section_count, 
                           (SELECT COUNT(*) FROM students WHERE class_id IN (SELECT id FROM classes WHERE class_name = c.class_name AND academic_year = ?)) as total_students
                           FROM classes c 
                           WHERE academic_year = ?
                           GROUP BY class_name 
                           ORDER BY LENGTH(class_name), class_name");
    $stmt->execute([$academic_year, $academic_year]);
    $data = $stmt->fetchAll();
} elseif ($view == 'sections' && $selected_class && $academic_year) {
    // Show sections for a specific class in a specific year
    $stmt = $conn->prepare("SELECT id, section, (SELECT COUNT(*) FROM students WHERE class_id = classes.id) as student_count 
                          FROM classes 
                          WHERE class_name = ? AND academic_year = ?
                          ORDER BY section");
    $stmt->execute([$selected_class, $academic_year]);
    $data = $stmt->fetchAll();
} elseif ($view == 'students' && $class_id) {
    // Show students for a specific class instance
    $stmt = $conn->prepare("SELECT class_name, section, academic_year FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch();
    $academic_year = $class_info['academic_year'];

    $stmt = $conn->prepare("SELECT s.*, c.class_name, c.section, sub.subject_name as main_elective_name, opt_sub.subject_name as optional_subject_name 
                          FROM students s 
                          JOIN classes c ON s.class_id = c.id 
                          LEFT JOIN subjects sub ON s.main_elective_id = sub.id
                          LEFT JOIN subjects opt_sub ON s.optional_subject_id = opt_sub.id
                          WHERE s.class_id = ? 
                          ORDER BY s.roll_number");
    $stmt->execute([$class_id]);
    $data = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Students - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        /* Prestige drill-down cards */
        .drill-card {
            transition: all 0.25s ease;
            border: 1px solid var(--prestige-border);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none !important;
            display: flex;
            flex-direction: column;
            height: 100%;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }
        .drill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08) !important;
            border-color: var(--prestige-gold);
            text-decoration: none;
        }
        .drill-card .card-body {
            padding: 2rem 1.5rem;
            flex: 1;
        }
        .drill-icon {
            font-size: 2.2rem;
            margin-bottom: 0.75rem;
        }
        /* Year cards — dark navy */
        .year-drill-card {
            background: var(--prestige-navy);
            border-color: transparent;
        }
        .year-drill-card:hover {
            background: #1e293b;
            border-color: var(--prestige-gold) !important;
        }
        /* Class cards */
        .class-drill-card {
            border-left: 4px solid var(--prestige-navy);
        }
        /* Section cards */
        .section-drill-card {
            border-left: 4px solid var(--prestige-gold);
        }

        /* Breadcrumb prestige style */
        .breadcrumb-item a {
            text-decoration: none;
            color: var(--prestige-gold);
            font-weight: 600;
        }
        .breadcrumb-item.active { color: #64748b; font-weight: 600; }
        .breadcrumb-item + .breadcrumb-item::before { color: #cbd5e1; }

        /* Student table action buttons */
        .btn-outline-info { border-color: #0ea5e9; color: #0ea5e9; }
        .btn-outline-info:hover { background: #0ea5e9; color: white; }

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

        [data-theme="dark"] .year-drill-card {
            background: #0a1020 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .breadcrumb-item.active {
            color: #94a3b8 !important;
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

        [data-theme="dark"] thead.table-light, [data-theme="dark"] .table-light {
            background-color: #0a1020 !important;
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
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .class-drill-card .drill-icon {
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

        .student-mobile-cards { display: none; }

        @media (max-width: 768px) {
            .student-table-wrap { display: none !important; }
            .student-mobile-cards { display: block !important; }
            
            .page-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 1rem;
                padding-bottom: 10px;
            }

            .page-header > .d-flex.align-items-center {
                width: 100%;
                min-width: 0;
            }

            .page-header h1 {
                font-size: 1.4rem;
                margin-bottom: 0.5rem;
            }

            .page-header nav {
                width: 100%;
            }

            .header-actions {
                width: 100%;
                margin-top: 0;
                gap: 0.75rem;
                flex-wrap: wrap;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
            }

            .header-actions .btn {
                width: 100%;
                max-width: 100%;
                padding: 12px 14px;
                text-align: center;
                font-weight: 700;
                border-radius: 12px;
                white-space: normal;
            }

            .header-actions .btn + .btn {
                margin-top: 0.75rem;
            }

            .student-card {
                background: white;
                border: 1px solid var(--prestige-border);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.02);
            }
        }
        
        [data-theme="dark"] .student-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
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
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Student Management</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb bg-transparent p-0 mb-0 small">
                                <li class="breadcrumb-item"><a href="manage-students.php?view_all=1">Academic Year</a></li>
                                <?php if ($academic_year): ?>
                                    <li class="breadcrumb-item <?php echo ($view == 'classes') ? 'active' : ''; ?>">
                                        <?php if ($view != 'classes'): ?>
                                            <a href="manage-students.php?view=classes&academic_year=<?php echo urlencode($academic_year); ?>"><?php echo $academic_year; ?></a>
                                        <?php else: ?>
                                            <?php echo $academic_year; ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                                <?php if ($selected_class): ?>
                                    <li class="breadcrumb-item <?php echo ($view == 'sections') ? 'active' : ''; ?>">
                                        <?php if ($view == 'students'): ?>
                                            <a href="manage-students.php?view=sections&class_name=<?php echo urlencode($selected_class); ?>&academic_year=<?php echo urlencode($academic_year); ?>"><?php echo $selected_class; ?></a>
                                        <?php else: ?>
                                            <?php echo $selected_class; ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                                <?php if ($view == 'students' && isset($class_info)): ?>
                                    <li class="breadcrumb-item active">Section <?php echo $class_info['section']; ?></li>
                                <?php endif; ?>
                            </ol>
                        </nav>
                    </div>
                </div>
                <div class="header-actions d-flex align-items-center">
                    <?php
                    if ($view == 'students' && $class_id) {
                        $add_link = "add-student.php?class_id=" . $class_id . "&class_name=" . urlencode($class_info['class_name']) . "&section=" . urlencode($class_info['section']) . "&academic_year=" . urlencode($academic_year);
                    } else {
                        $add_link = "add-student.php" . ($academic_year ? '?academic_year=' . urlencode($academic_year) : '');
                    }
                    ?>
                    <a href="<?php echo $add_link; ?>" class="btn btn-sm btn-primary px-3 me-2" style="border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-user-plus me-1"></i> Add Student
                    </a>
                    <?php if ($view == 'students' && $class_id): ?>
                        <button type="button" class="btn btn-sm btn-danger px-3 me-2" style="border-radius: 8px; font-weight: 600;"
                            onclick="confirmBulkDelete()">
                            <i class="fas fa-trash-alt me-1"></i> Delete Selected
                        </button>
                        <a href="bulk-import-students.php?class_id=<?php echo $class_id; ?>"
                            class="btn btn-sm btn-outline-secondary px-3 me-2" style="border-radius: 8px; font-weight: 600;">
                            <i class="fas fa-file-import me-1"></i> Bulk Import
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Context subtitle -->
            <p class="text-muted small mb-4" style="font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;">
                <i class="fas fa-chevron-right me-2 opacity-50" style="font-size: 0.7rem;"></i>
                <?php
                if ($view == 'years') echo "Select Academic Session";
                elseif ($view == 'classes') echo "Classes &mdash; " . htmlspecialchars($academic_year);
                elseif ($view == 'sections') echo "Sections &mdash; " . htmlspecialchars($selected_class);
                else echo "Students: " . htmlspecialchars($class_info['class_name']) . " &mdash; Section " . htmlspecialchars($class_info['section']);
                ?>
            </p>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ======================== VIEW LEVELS ======================== -->
            <div class="row g-4">

                <!-- YEARS -->
                <?php if ($view == 'years'): ?>
                    <?php foreach ($data as $y): ?>
                        <div class="col-xl-2 col-md-3 col-sm-6">
                            <a href="?view=classes&academic_year=<?php echo urlencode($y['academic_year']); ?>"
                                class="drill-card year-drill-card h-100">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: var(--prestige-gold-light);">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="h4 fw-bold text-white mb-1" style="font-family: 'Playfair Display', serif;">
                                        <?php echo $y['academic_year']; ?>
                                    </div>
                                    <div class="text-white-50" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                        <?php echo $y['class_count']; ?> Classes &bull; <?php echo $y['student_count']; ?> Students
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>

                <!-- CLASSES -->
                <?php elseif ($view == 'classes'): ?>
                    <?php foreach ($data as $c): ?>
                        <div class="col-xl-3 col-md-6">
                            <a href="?view=sections&class_name=<?php echo urlencode($c['class_name']); ?>&academic_year=<?php echo urlencode($academic_year); ?>"
                                class="drill-card class-drill-card h-100">
                                <div class="card-body text-center">
                                    <div class="drill-icon" style="color: var(--prestige-navy);">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                        <?php echo $c['class_name']; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?php echo $c['section_count']; ?> Sections &bull; <?php echo $c['total_students']; ?> Students
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>

                <!-- SECTIONS -->
                <?php elseif ($view == 'sections'): ?>
                    <?php
                    // Organize sections as requested by user:
                    // - "Temporary" group for single-letter sections (A, B, ...)
                    // - "FINAL" group for Science / Arts / Commerce streams
                    // - Any remaining sections go into "Other"
                    $section_groups = [
                        'Temporary' => [],
                        'FINAL' => [],
                        'Other' => []
                    ];

                    foreach ($data as $s) {
                        $sec = trim($s['section']);
                        $low = strtolower($sec);

                        if (preg_match('/^[A-Za-z]$/', $sec)) {
                            $section_groups['Temporary'][] = $s;
                        } elseif (preg_match('/(science|arts|art|commerce|comm)/i', $low)) {
                            $section_groups['FINAL'][] = $s;
                        } else {
                            $section_groups['Other'][] = $s;
                        }
                    }

                    // Render Temporary first, then FINAL, then Other
                    $render_order = ['Temporary', 'FINAL', 'Other'];
                    foreach ($render_order as $group_name):
                        $items = $section_groups[$group_name];
                        if (empty($items)) continue;
                    ?>
                        <div class="col-12 mb-2">
                            <h5 class="mb-3" style="font-weight:700; color: var(--prestige-navy);"><?php echo $group_name; ?></h5>
                        </div>
                        <?php foreach ($items as $s): ?>
                            <div class="col-xl-3 col-md-6">
                                <a href="?view=students&class_id=<?php echo $s['id']; ?>&class_name=<?php echo urlencode($selected_class); ?>&academic_year=<?php echo urlencode($academic_year); ?>"
                                    class="drill-card section-drill-card h-100">
                                    <div class="card-body text-center">
                                        <div class="drill-icon" style="color: var(--prestige-gold);">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div class="h5 fw-bold mb-1" style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                            <?php
                                            $display = $s['section'];
                                            // For clarity, label single letters as "Section A" in the card title
                                            if (preg_match('/^[A-Za-z]$/', $display)) {
                                                $display = 'Section ' . $display;
                                            }
                                            echo $display;
                                            ?>
                                        </div>
                                        <div class="small text-muted"><?php echo $s['student_count']; ?> Students</div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                <!-- STUDENTS TABLE -->
                <?php else: ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm overflow-hidden student-table-wrap" style="border-radius: 12px;">
                            <div class="card-body p-0">
                                <form id="studentListForm" method="POST" action="manage-students.php?view=students&class_id=<?php echo $class_id; ?>&class_name=<?php echo urlencode($selected_class); ?>&academic_year=<?php echo urlencode($academic_year); ?>">
                                    <input type="hidden" name="bulk_delete" id="bulkDeleteField" value="0">
                                    <div class="table-responsive">
                                        <?php $hide_group = in_array($class_info['class_name'], ['Class 6', 'Class 7', 'Class 8']); ?>
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="text-center pe-0">
                                                    <input type="checkbox" id="studentSelectAll" class="form-check-input">
                                                </th>
                                                <th class="ps-4">Roll No.</th>
                                                <th>Student Name</th>
                                                <?php if (!$hide_group): ?>
                                                    <th>Group</th>
                                                    <th>Elective</th>
                                                    <th>Optional</th>
                                                <?php endif; ?>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($data) > 0): ?>
                                                <?php foreach ($data as $s): ?>
                                                    <tr>
                                                        <td class="text-center">
                                                            <input type="checkbox" class="form-check-input student-checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>">
                                                        </td>
                                                        <td class="ps-4">
                                                            <span class="badge-prestige">
                                                                <?php echo $s['roll_number']; ?>
                                                            </span>
                                                        </td>
                                                        <td class="fw-bold" style="color: var(--prestige-text);"><?php echo htmlspecialchars($s['name']); ?></td>
                                                        <?php if (!$hide_group): ?>
                                                            <td>
                                                                <span class="badge-prestige">
                                                                    <?php echo htmlspecialchars($s['student_group']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="text-muted small fw-bold">
                                                                    <?php echo htmlspecialchars($s['main_elective_name'] ?? '-'); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="text-muted small fw-bold">
                                                                    <?php echo htmlspecialchars($s['optional_subject_name'] ?? '-'); ?>
                                                                </span>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td class="text-center">
                                                            <a href="edit-student.php?id=<?php echo $s['id']; ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                                                                class="btn btn-sm btn-outline-info me-1" style="border-radius: 6px;">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius: 6px;"
                                                                onclick="showDeleteModal('?view=students&class_id=<?php echo $class_id; ?>&delete=<?php echo $s['id']; ?>', 'Delete Student?', 'This will permanently remove <strong><?php echo htmlspecialchars($s['name']); ?></strong> (Roll: <?php echo $s['roll_number']; ?>).')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="<?php echo $hide_group ? 5 : 7; ?>" class="text-center py-5">
                                                        <i class="fas fa-users fa-3x mb-3 d-block" style="color: var(--prestige-border);"></i>
                                                        <span class="text-muted">No students found in this section.</span>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                        </div>
                    </div>

                        <!-- Student Mobile View -->
                        <div class="student-mobile-cards">
                            <?php if (count($data) > 0): ?>
                                <?php foreach ($data as $s): ?>
                                    <div class="student-card shadow-sm mb-3">
                                        <div class="d-flex justify-content-between align-items-start mb-0">
                                            <div class="d-flex align-items-center">
                                                <div class="badge-prestige me-3" style="width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; border-radius: 10px;">
                                                    <?php echo $s['roll_number']; ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0 fw-bold" style="color: var(--prestige-navy);"><?php echo htmlspecialchars($s['name']); ?></h6>
                                                    <?php if (!$hide_group): ?>
                                                        <div class="mt-1">
                                                            <span class="badge-prestige py-0 px-2" style="font-size: 0.65rem;"><?php echo htmlspecialchars($s['student_group']); ?></span>
                                                            <small class="text-muted ms-1" style="font-size: 0.7rem;">
                                                                E: <?php echo htmlspecialchars($s['main_elective_name'] ?? '-'); ?> | 
                                                                O: <?php echo htmlspecialchars($s['optional_subject_name'] ?? '-'); ?>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-2 align-items-center">
                                                <a href="edit-student.php?id=<?php echo $s['id']; ?>&return_url=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                                                    class="btn btn-sm btn-outline-info shadow-sm"
                                                    style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border-radius: 10px;">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-danger shadow-sm"
                                                    style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; border-radius: 10px;"
                                                    onclick="showDeleteModal('?view=students&class_id=<?php echo $class_id; ?>&delete=<?php echo $s['id']; ?>', 'Delete Student?', 'Permanently remove <?php echo htmlspecialchars($s['name']); ?>?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5 bg-white rounded-3 shadow-sm border">
                                    <i class="fas fa-users fa-3x mb-3 d-block opacity-10"></i>
                                    <span class="text-muted">No students found in this section.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- end .row -->
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('studentSelectAll');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.student-checkbox').forEach(function(cb) {
                        cb.checked = selectAll.checked;
                    });
                });
            }
        });

        function confirmBulkDelete() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (!selected.length) {
                alert('Please select at least one student to delete.');
                return;
            }
            if (!confirm('Delete the selected students? This action cannot be undone.')) {
                return;
            }
            document.getElementById('bulkDeleteField').value = '1';
            document.getElementById('studentListForm').submit();
        }
    </script>
    <?php include('includes/delete-modal.php'); ?>
    <?php include('includes/undo-toast.php'); ?>
</body>
