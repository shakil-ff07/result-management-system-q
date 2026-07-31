<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

function initializeClassSubjectsFromJson($conn, $class_id, $class_name) {
    $json_file = '../json/all_classes_subject_V6.json';

    if (!file_exists($json_file)) {
        return false;
    }

    $json_data = json_decode(file_get_contents($json_file), true);
    if (!is_array($json_data) || empty($json_data['classes'])) {
        return false;
    }

    $target_class = null;
    $normalized_db_class = strtolower(str_replace(' ', '', trim($class_name)));

    foreach ($json_data['classes'] as $entry) {
        $normalized_json_class = strtolower(str_replace(' ', '', trim($entry['class_name'] ?? '')));

        $match = (
            $normalized_json_class === $normalized_db_class ||
            stripos(strtolower($entry['class_name'] ?? ''), strtolower($class_name)) !== false ||
            (isset($entry['class_id']) && strtolower($entry['class_id']) === $normalized_db_class)
        );

        if ($match) {
            $target_class = $entry;
            break;
        }
    }

    if (!$target_class) {
        return false;
    }

    foreach ($target_class['groups'] ?? [] as $group_info) {
        $group_name = $group_info['group_name'] ?? 'General';
        $db_group = 'None';

        if (stripos($group_name, 'Science') !== false) {
            $db_group = 'Science';
        } elseif (stripos($group_name, 'Commerce') !== false || stripos($group_name, 'Business') !== false) {
            $db_group = 'Commerce';
        } elseif (stripos($group_name, 'Arts') !== false || stripos($group_name, 'Humanities') !== false) {
            $db_group = 'Arts';
        }

        foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
            foreach (($group_info['subjects'][$type] ?? []) as $sub) {
                $subject_name = $sub['name_en'] ?? null;
                if (!$subject_name) {
                    continue;
                }

                $subject_id = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ?");
                $subject_id->execute([$subject_name]);
                $sid = $subject_id->fetchColumn();

                if (!$sid) {
                    $insert_subject = $conn->prepare("INSERT IGNORE INTO subjects (subject_name) VALUES (?)");
                    $insert_subject->execute([$subject_name]);
                    $sid = $conn->lastInsertId();
                }

                $is_optional = ($type === 'optional') ? 1 : 0;
                $is_school_based = ($type === 'compulsory_school') ? 1 : 0;

                $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id, student_group, is_optional, is_school_based) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$class_id, $sid, $db_group, $is_optional, $is_school_based]);
            }
        }
    }

    return true;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_class'])) {
    $name = $_POST['class_name'];
    $section = $_POST['section'];
    $year = $_POST['year'];

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("INSERT INTO classes (class_name, section, academic_year) VALUES (?, ?, ?)");
        $stmt->execute([$name, $section, $year]);
        $class_id = $conn->lastInsertId();

        $auto_mapped = initializeClassSubjectsFromJson($conn, $class_id, $name);

        $conn->commit();
        $message = $auto_mapped
            ? "Class added successfully. Subject mappings were initialized automatically."
            : "Class added successfully.";
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Error: " . $e->getMessage();
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    try {
        $conn->beginTransaction();

        // ── Serialize EVERYTHING before we delete ────────────────────────────
        // 1. Class metadata
        $classRow = $conn->prepare("SELECT * FROM classes WHERE id = ?");
        $classRow->execute([$id]);
        $classData = $classRow->fetchAll();
        $className = !empty($classData) ? ($classData[0]['class_name'] . ' – ' . $classData[0]['section'] . ' (' . $classData[0]['academic_year'] . ')') : 'Class #' . $id;

        // 2. Class subjects
        $csStmt = $conn->prepare("SELECT * FROM class_subjects WHERE class_id = ?");
        $csStmt->execute([$id]);
        $classSubjects = $csStmt->fetchAll();

        // 3. Teacher assignments
        $taStmt = $conn->prepare("SELECT * FROM teacher_assignments WHERE class_id = ?");
        $taStmt->execute([$id]);
        $teacherAssignments = $taStmt->fetchAll();

        // 4. Publish settings
        $psStmt = $conn->prepare("SELECT * FROM publish_settings WHERE class_id = ?");
        $psStmt->execute([$id]);
        $publishSettings = $psStmt->fetchAll();

        // 5. Students in this class
        $studStmt = $conn->prepare("SELECT * FROM students WHERE class_id = ?");
        $studStmt->execute([$id]);
        $students = $studStmt->fetchAll();
        $studentIds = array_column($students, 'id');

        // 6. Student child records (marks, final_results, marksheet_tokens)
        $marks = $finalResults = $marksheetTokens = [];
        if (!empty($studentIds)) {
            $ph = implode(',', array_fill(0, count($studentIds), '?'));
            $mStmt = $conn->prepare("SELECT * FROM marks WHERE student_id IN ($ph)");
            $mStmt->execute($studentIds);
            $marks = $mStmt->fetchAll();

            $frStmt = $conn->prepare("SELECT * FROM final_results WHERE student_id IN ($ph)");
            $frStmt->execute($studentIds);
            $finalResults = $frStmt->fetchAll();

            $mtStmt = $conn->prepare("SELECT * FROM marksheet_tokens WHERE student_id IN ($ph)");
            $mtStmt->execute($studentIds);
            $marksheetTokens = $mtStmt->fetchAll();
        }

        // Build the payload and archive it
        $payload = [
            'classes'             => $classData,
            'class_subjects'      => $classSubjects,
            'teacher_assignments' => $teacherAssignments,
            'publish_settings'    => $publishSettings,
            'students'            => $students,
            'marks'               => $marks,
            'final_results'       => $finalResults,
            'marksheet_tokens'    => $marksheetTokens,
        ];
        $archiveStmt = $conn->prepare("INSERT INTO deleted_records (entity_type, entity_name, serialized_data) VALUES (?, ?, ?)");
        $archiveStmt->execute(['class', $className, json_encode($payload)]);
        $undoId = $conn->lastInsertId();

        // ── Now delete in safe dependency order ──────────────────────────
        if (!empty($studentIds)) {
            $ph = implode(',', array_fill(0, count($studentIds), '?'));
            $conn->prepare("DELETE FROM marksheet_tokens WHERE student_id IN ($ph)")->execute($studentIds);
            $conn->prepare("DELETE FROM final_results WHERE student_id IN ($ph)")->execute($studentIds);
            $conn->prepare("DELETE FROM marks WHERE student_id IN ($ph)")->execute($studentIds);
        }
        $conn->prepare("DELETE FROM students WHERE class_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM class_subjects WHERE class_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM teacher_assignments WHERE class_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM publish_settings WHERE class_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);

        $conn->commit();

        // Store undo in session
        $_SESSION['undo_action'] = [
            'record_id' => $undoId,
            'type'      => 'class',
            'name'      => $className,
        ];
        $message = "Class and its students deleted successfully!";
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Cannot delete class. " . $e->getMessage();
    }
}

// Fetch unique academic years for filtering
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC");
$available_years = array_values(array_unique(array_map('strval', array_column($years_stmt->fetchAll(), 'academic_year'))));

$current_year = (string) date('Y');
if (!in_array($current_year, $available_years, true)) {
    $available_years[] = $current_year;
}
sort($available_years, SORT_NUMERIC);

// Year filter logic
$selected_year = $_GET['academic_year'] ?? $current_year;
if ($selected_year !== 'all' && !in_array((string) $selected_year, $available_years, true)) {
    $selected_year = $current_year;
}

if ($selected_year === 'all') {
    $stmt = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, LENGTH(class_name), class_name, section");
} else {
    $stmt = $conn->prepare("SELECT * FROM classes WHERE academic_year = ? ORDER BY LENGTH(class_name), class_name, section");
    $stmt->execute([$selected_year]);
}
$classes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Classes - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control, .form-select {
            padding: 12px 16px;
            border: 1px solid var(--prestige-border);
            border-radius: 8px;
            font-weight: 500;
            color: var(--prestige-navy);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.05);
        }

        .table th {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: #64748b;
            font-weight: 700;
            padding: 16px;
            border-bottom: 2px solid var(--prestige-border);
        }

        .table td {
            padding: 16px;
            vertical-align: middle;
            color: var(--prestige-text);
            border-bottom: 1px dashed var(--prestige-border);
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .card-header.bg-white {
            background-color: #111827 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .form-control, [data-theme="dark"] .form-select {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
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

        [data-theme="dark"] .bg-light {
            background-color: #0a1020 !important;
        }

        .badge-prestige {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            border: 1px solid rgba(15, 23, 42, 0.1);
            font-size: 0.75rem;
        }

        .mobile-class-cards {
            display: none;
        }

        @media (max-width: 768px) {
            .table-responsive { display: none !important; }
            .mobile-class-cards { display: block !important; }
            .header-actions { width: 100%; margin-top: 15px; }
            .header-actions .btn { width: 100%; }
            
            .class-card {
                background: white;
                border: 1px solid var(--prestige-border);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 12px;
            }
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .badge-prestige {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }
        
        [data-theme="dark"] .class-card {
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
                        <i class="fas fa-school"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Manage Classes</h1>
                        <p class="mb-0">Configure your academic classes and sections.</p>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                        <div class="card-header bg-white" style="border-bottom: 1px solid var(--prestige-border);">
                            <h5 class="mb-0" style="font-family: 'Playfair Display', serif; color: var(--prestige-text); font-weight: 700;">Add New Class</h5>
                        </div>
                        <div class="card-body p-4">
                            <form action="" method="POST">
                                <div class="mb-4">
                                    <label class="form-label">Class Name</label>
                                    <select name="class_name" id="class_name_select" class="form-select" required onchange="updateSections()">
                                        <option value="Class 6">Class 6</option>
                                        <option value="Class 7">Class 7</option>
                                        <option value="Class 8">Class 8</option>
                                        <option value="Class 9">Class 9</option>
                                        <option value="Class 10">Class 10</option>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Section</label>
                                    <select name="section" id="section_select" class="form-select" required>
                                        <!-- Populated by JS -->
                                        <option value="A">Section A</option>
                                        <option value="B">Section B</option>
                                        <option value="C">Section C</option>
                                        <option value="D">Section D</option>
                                    </select>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Academic Year</label>
                                    <input type="number" name="year" class="form-control"
                                        value="<?php echo date('Y'); ?>" required>
                                </div>
                                <button type="submit" name="add_class" class="btn btn-primary w-100 py-2" style="border-radius: 8px; font-weight: 600;">Add Class</button>
                            </form>

                            <script>
                                // Section options per class
                                const sectionMap = {
                                    'Class 6':  [{v:'A',l:'Section A'},{v:'B',l:'Section B'},{v:'C',l:'Section C'},{v:'D',l:'Section D'}],
                                    'Class 7':  [{v:'A',l:'Section A'},{v:'B',l:'Section B'},{v:'C',l:'Section C'},{v:'D',l:'Section D'}],
                                    'Class 8':  [{v:'A',l:'Section A'},{v:'B',l:'Section B'},{v:'C',l:'Section C'},{v:'D',l:'Section D'}],
                                    'Class 9':  [{v:'A',l:'Section A'},{v:'B',l:'Section B'},{v:'Science',l:'Science'},{v:'Arts',l:'Arts'},{v:'Commerce',l:'Commerce'}],
                                    'Class 10': [{v:'Science',l:'Science'},{v:'Arts',l:'Arts'},{v:'Commerce',l:'Commerce'}]
                                };

                                function updateSections() {
                                    const cls = document.getElementById('class_name_select').value;
                                    const sel = document.getElementById('section_select');
                                    const options = sectionMap[cls] || sectionMap['Class 6'];

                                    sel.innerHTML = '';
                                    options.forEach(opt => {
                                        const o = document.createElement('option');
                                        o.value = opt.v;
                                        o.textContent = opt.l;
                                        sel.appendChild(o);
                                    });
                                }

                                // Run on page load to sync with default selection
                                document.addEventListener('DOMContentLoaded', updateSections);
                            </script>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 12px;">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between" style="border-bottom: 1px solid var(--prestige-border); padding-top: 12px; padding-bottom: 12px;">
                            <h5 class="mb-0" style="font-family: 'Playfair Display', serif; color: var(--prestige-text); font-weight: 700;">Existing Classes</h5>
                            
                            <form action="" method="GET" class="d-flex align-items-center gap-2">
                                <label class="small text-muted fw-bold text-uppercase mb-0 d-none d-sm-block" style="font-size: 0.65rem; letter-spacing: 0.5px;">Filter Year:</label>
                                <select name="academic_year" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto; min-width: 100px; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px;">
                                    <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>All Years</option>
                                    <?php foreach ($available_years as $y): ?>
                                        <option value="<?= $y ?>" <?= (string) $selected_year === (string) $y ? 'selected' : '' ?>><?= $y ?><?php if ((int) $y === (int) $current_year) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Class Name</th>
                                            <th>Section</th>
                                            <th>Year</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($classes as $c): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold" style="color: var(--prestige-text);">
                                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge-prestige">
                                                        <?php
                                                        $sec = $c['section'];
                                                        // Stream sections don't need 'Section' prefix
                                                        $isStream = in_array($sec, ['Science', 'Arts', 'Commerce']);
                                                        echo $isStream ? htmlspecialchars($sec) : 'Section ' . htmlspecialchars($sec);
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="text-muted"><?php echo htmlspecialchars($c['academic_year']); ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-danger btn-sm" style="border-radius: 6px;"
                                                        onclick="showDeleteModal('?delete=<?php echo $c['id']; ?>', 'Delete Class?', 'This will permanently remove <strong><?php echo htmlspecialchars($c['class_name'] . " - Section " . $c['section'] . " (" . $c['academic_year'] . ")"); ?></strong>.')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile View -->
                            <div class="mobile-class-cards p-3">
                                <?php if (empty($classes)): ?>
                                    <p class="text-center text-muted py-3">No classes found.</p>
                                <?php else: ?>
                                    <?php foreach ($classes as $c): ?>
                                        <div class="class-card shadow-sm">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0 fw-bold" style="color: var(--prestige-navy);"><?php echo htmlspecialchars($c['class_name']); ?></h6>
                                                <button type="button" class="btn btn-outline-danger btn-sm border-0"
                                                    onclick="showDeleteModal('?delete=<?php echo $c['id']; ?>', 'Delete Class?', 'This will permanently remove <strong><?php echo htmlspecialchars($c['class_name'] . " - Section " . $c['section'] . " (" . $c['academic_year'] . ")"); ?></strong>.')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="badge-prestige"><?php
                                                        $sec = $c['section'];
                                                        $isStream = in_array($sec, ['Science', 'Arts', 'Commerce']);
                                                        echo $isStream ? htmlspecialchars($sec) : 'Section ' . htmlspecialchars($sec);
                                                ?></span>
                                                <small class="text-muted"><i class="far fa-calendar-alt me-1"></i> <?php echo htmlspecialchars($c['academic_year']); ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include('includes/delete-modal.php'); ?>
    <?php include('includes/undo-toast.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>