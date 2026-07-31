<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

// Sync Logic (Professionalized for JSON V4)
if (isset($_POST['sync_subjects'])) {
    $json_file = '../json/all_classes_subject_V6.json';
    if (file_exists($json_file)) {
        $json_data = json_decode(file_get_contents($json_file), true);
        try {
            $conn->beginTransaction();

            // 1. DEDUPLICATION ROUTINE (Fix existing duplicates)
            $stmt = $conn->query("SELECT subject_name, COUNT(*) as count FROM subjects GROUP BY subject_name HAVING count > 1");
            $duplicates = $stmt->fetchAll();

            foreach ($duplicates as $dupe) {
                $name = $dupe['subject_name'];
                $ids_stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ? ORDER BY id ASC");
                $ids_stmt->execute([$name]);
                $ids = $ids_stmt->fetchAll(PDO::FETCH_COLUMN);

                $target_id = array_shift($ids);
                $other_ids = $ids;
                $placeholders = implode(',', array_fill(0, count($other_ids), '?'));

                if (!empty($other_ids)) {
                    $tables_to_update = [
                        ['table' => 'class_subjects', 'col' => 'subject_id'],
                        ['table' => 'marks', 'col' => 'subject_id'],
                        ['table' => 'students', 'col' => 'optional_subject_id'],
                        ['table' => 'teacher_assignments', 'col' => 'subject_id'],
                        ['table' => 'archived_marks', 'col' => 'subject_id'],
                        ['table' => 'archived_students', 'col' => 'optional_subject_id']
                    ];
                    foreach ($tables_to_update as $t) {
                        $check = $conn->query("SHOW TABLES LIKE '{$t['table']}'")->fetch();
                        if ($check) {
                            $sql = "UPDATE IGNORE {$t['table']} SET {$t['col']} = ? WHERE {$t['col']} IN ($placeholders)";
                            $conn->prepare($sql)->execute(array_merge([$target_id], $other_ids));
                        }
                    }
                    $sql_del = "DELETE FROM subjects WHERE id IN ($placeholders)";
                    $conn->prepare($sql_del)->execute($other_ids);
                }
            }
            $conn->commit();

            // 2. Add UNIQUE constraint
            try {
                $conn->exec("ALTER TABLE subjects ADD UNIQUE (subject_name)");
            } catch (Exception $e) {
            }

            // 3. Process Sync from JSON V4
            $conn->beginTransaction();
            $all_unique_subs = [];
            if (isset($json_data['classes'])) {
                foreach ($json_data['classes'] as $class_info) {
                    foreach ($class_info['groups'] as $group_info) {
                        foreach (['compulsory', 'compulsory_school', 'optional'] as $type) {
                            if (isset($group_info['subjects'][$type])) {
                                foreach ($group_info['subjects'][$type] as $sub) {
                                    $all_unique_subs[] = $sub['name_en'];
                                }
                            }
                        }
                    }
                }
            }
            $all_unique_subs = array_unique($all_unique_subs);

            foreach ($all_unique_subs as $sub_name) {
                $stmt = $conn->prepare("INSERT IGNORE INTO subjects (subject_name) VALUES (?)");
                $stmt->execute([$sub_name]);
            }

            // 4. Update Class Mappings
            $stmt_classes = $conn->query("SELECT id, class_name FROM classes");
            $db_classes = $stmt_classes->fetchAll();

            foreach ($db_classes as $class) {
                $c_name = $class['class_name'];

                // Find class in JSON using aggressive flexible matching
                $class_json = null;
                foreach ($json_data['classes'] as $cj) {
                    $json_cname = strtolower($cj['class_name']);
                    $db_cname = strtolower($c_name);

                    $match = ($json_cname == $db_cname ||
                        str_replace(' ', '', $json_cname) == str_replace(' ', '', $db_cname) ||
                        stripos($json_cname, $db_cname) !== false ||
                        (isset($cj['class_id']) && strcasecmp($cj['class_id'], str_replace(' ', '', $c_name)) == 0));

                    // Special handling for SSC (9 & 10)
                    if (!$match && (stripos($db_cname, 'Class 9') !== false || stripos($db_cname, 'Class 10') !== false)) {
                        if (stripos($json_cname, '9') !== false && stripos($json_cname, '10') !== false) {
                            $match = true;
                        }
                    }

                    if ($match) {
                        $class_json = $cj;
                        break;
                    }
                }

                if ($class_json) {
                    // Only delete if we found a match to replace it with
                    $conn->prepare("DELETE FROM class_subjects WHERE class_id = ?")->execute([$class['id']]);

                    foreach ($class_json['groups'] as $group_info) {
                        $group_name = $group_info['group_name'];
                        // Map group name to DB enum
                        $db_group = 'None';
                        if (stripos($group_name, 'Science') !== false)
                            $db_group = 'Science';
                        elseif (stripos($group_name, 'Commerce') !== false || stripos($group_name, 'Business') !== false)
                            $db_group = 'Commerce';
                        elseif (stripos($group_name, 'Arts') !== false || stripos($group_name, 'Humanities') !== false)
                            $db_group = 'Arts';

                        // Compulsory
                        if (isset($group_info['subjects']['compulsory'])) {
                            foreach ($group_info['subjects']['compulsory'] as $sub) {
                                $sid = $conn->query("SELECT id FROM subjects WHERE subject_name = " . $conn->quote($sub['name_en']))->fetchColumn();
                                if ($sid) {
                                    $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id, student_group, is_optional, is_school_based) VALUES (?, ?, ?, 0, 0)")->execute([$class['id'], $sid, $db_group]);
                                }
                            }
                        }
                        // Compulsory School (Non-GPA)
                        if (isset($group_info['subjects']['compulsory_school'])) {
                            foreach ($group_info['subjects']['compulsory_school'] as $sub) {
                                $sid = $conn->query("SELECT id FROM subjects WHERE subject_name = " . $conn->quote($sub['name_en']))->fetchColumn();
                                if ($sid) {
                                    $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id, student_group, is_optional, is_school_based) VALUES (?, ?, ?, 0, 1)")->execute([$class['id'], $sid, $db_group]);
                                }
                            }
                        }
                        // Optional
                        if (isset($group_info['subjects']['optional'])) {
                            foreach ($group_info['subjects']['optional'] as $sub) {
                                $sid = $conn->query("SELECT id FROM subjects WHERE subject_name = " . $conn->quote($sub['name_en']))->fetchColumn();
                                if ($sid) {
                                    $conn->prepare("INSERT IGNORE INTO class_subjects (class_id, subject_id, student_group, is_optional, is_school_based) VALUES (?, ?, ?, 1, 0)")->execute([$class['id'], $sid, $db_group]);
                                }
                            }
                        }
                    }
                }
            }
            $conn->commit();
            $message = "Subjects synchronized successfully!";
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "JSON file not found.";
    }
}

// Database Fix Logic (Existing)
if (isset($_POST['fix_database'])) {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM class_subjects LIKE 'student_group'");
        if (!$stmt->fetch()) {
            $conn->exec("ALTER TABLE class_subjects ADD COLUMN student_group ENUM('None', 'Science', 'Commerce', 'Arts') DEFAULT 'None' AFTER subject_id");
            $conn->exec("ALTER TABLE class_subjects DROP PRIMARY KEY, ADD PRIMARY KEY (class_id, subject_id, student_group)");
        }

        $stmt2 = $conn->query("SHOW COLUMNS FROM class_subjects LIKE 'is_school_based'");
        if (!$stmt2->fetch()) {
            $conn->exec("ALTER TABLE class_subjects ADD COLUMN is_school_based TINYINT(1) DEFAULT 0 AFTER is_optional");
        }

        $message = "Database schema updated successfully!";
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Toggle Practical Logic
if (isset($_POST['toggle_practical'])) {
    $sub_id = $_POST['subject_id'];
    $new_status = $_POST['status'];
    $conn->prepare("UPDATE subjects SET has_practical = ? WHERE id = ?")->execute([$new_status, $sub_id]);
    echo "success";
    exit();
}

// View Logic
$view = $_GET['view'] ?? 'classes';
$selected_class_name = $_GET['class_name'] ?? null;

if ($view == 'subjects' && $selected_class_name) {
    // Get subjects for the selected class (taking representative class_id)
    $stmt = $conn->prepare("SELECT s.id, s.subject_name, s.has_practical, cs.student_group 
                          FROM class_subjects cs 
                          JOIN subjects s ON cs.subject_id = s.id 
                          JOIN classes c ON cs.class_id = c.id 
                          WHERE c.class_name = ? 
                          GROUP BY s.subject_name, cs.student_group
                          ORDER BY cs.student_group, s.subject_name");
    $stmt->execute([$selected_class_name]);
    $subject_mappings = $stmt->fetchAll();

    // Group subjects for display
    $grouped_subjects = [];
    foreach ($subject_mappings as $m) {
        $grouped_subjects[$m['student_group']][] = $m;
    }
} else {
    $view = 'classes';
    // Get unique class names and their total subject counts
    $stmt = $conn->query("SELECT c.class_name, COUNT(DISTINCT cs.subject_id) as subject_count 
                         FROM classes c 
                         LEFT JOIN class_subjects cs ON c.id = cs.class_id 
                         GROUP BY c.class_name 
                         ORDER BY LENGTH(c.class_name), c.class_name");
    $classes_data = $stmt->fetchAll();

    // Detect class SECTIONS with zero subject mappings (unsynced)
    $stmt_unsynced = $conn->query("SELECT c.id, c.class_name, c.section, c.academic_year, COUNT(cs.subject_id) as subject_count
                                   FROM classes c
                                   LEFT JOIN class_subjects cs ON c.id = cs.class_id
                                   GROUP BY c.id
                                   HAVING subject_count = 0
                                   ORDER BY c.class_name, c.section");
    $unsynced_sections = $stmt_unsynced->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Manage Subjects - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .drill-card {
            transition: all 0.25s ease;
            border: 1px solid var(--prestige-border);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none !important;
            display: block;
            background: white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
            border-left: 4px solid var(--prestige-gold);
        }

        .drill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08) !important;
            border-color: var(--prestige-gold);
            text-decoration: none;
        }

        .drill-card .card-body {
            padding: 2rem 1.5rem;
        }

        .drill-icon {
            font-size: 2.2rem;
            margin-bottom: 0.75rem;
            color: var(--prestige-gold);
        }

        .group-header {
            background: var(--prestige-slate);
            padding: 10px 18px;
            border-radius: 10px;
            margin: 16px 0 10px;
            border-left: 4px solid var(--prestige-navy);
            font-weight: 700;
            color: var(--prestige-navy);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .subject-item {
            padding: 12px 18px;
            border-bottom: 1px dashed var(--prestige-border);
            display: flex;
            align-items: center;
            color: var(--prestige-text);
        }

        .subject-item i {
            color: var(--prestige-gold);
            margin-right: 14px;
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .drill-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2) !important;
        }

        [data-theme="dark"] .drill-card:hover {
            background: #1e293b !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .card-header.bg-white {
            background-color: #111827 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .group-header {
            background: #0a1020 !important;
            color: #cbd5e1 !important;
            border-left-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .subject-item {
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .badge {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">

            <!-- Header -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Subject Management</h1>
                        <p class="mb-0">
                            <?php if ($view == 'subjects' && $selected_class_name): ?>
                                Subjects assigned to <strong><?php echo htmlspecialchars($selected_class_name); ?></strong>
                            <?php else: ?>
                                Assign and sync subject mappings for each class.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="header-actions d-flex align-items-center gap-2">
                    <?php if ($view == 'classes'): ?>
                        <form method="POST">
                            <button type="submit" name="sync_subjects" class="btn btn-sm btn-primary px-3"
                                style="border-radius: 8px; font-weight: 600;">
                                <i class="fas fa-sync-alt me-1"></i> Sync from JSON
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="manage-subjects.php" class="btn btn-sm btn-outline-secondary"
                            style="border-radius: 8px; font-weight: 600;">
                            <i class="fas fa-arrow-left me-1"></i> All Classes
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- ===================== VIEW: CLASSES ===================== -->
            <?php if ($view == 'classes'): ?>
                <?php if (empty($classes_data)): ?>
                    <div class="alert alert-info">No classes found. Please add classes first in Manage Classes.</div>
                <?php endif; ?>

                <?php if (!empty($unsynced_sections)): ?>
                    <div class="alert alert-warning d-flex align-items-start gap-3 mb-4" style="border-radius: 12px; border-left: 4px solid #f59e0b;">
                        <i class="fas fa-exclamation-triangle fa-lg mt-1" style="color: #d97706; flex-shrink: 0;"></i>
                        <div>
                            <strong style="color: #92400e;">Unsynced Class Sections Detected</strong>
                            <p class="mb-1 mt-1" style="font-size: 0.875rem; color: #78350f;">The following sections have no subject mappings. Click <strong>Sync from JSON</strong> to fix them:</p>
                            <ul class="mb-0" style="font-size: 0.875rem; color: #78350f;">
                                <?php foreach ($unsynced_sections as $us): ?>
                                    <li><?php echo htmlspecialchars($us['class_name'] . ' — Section ' . $us['section'] . ' (' . $us['academic_year'] . ')'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <?php foreach ($classes_data as $c): ?>
                        <div class="col-xl-3 col-md-6">
                            <a href="?view=subjects&class_name=<?php echo urlencode($c['class_name']); ?>"
                                class="drill-card h-100">
                                <div class="card-body text-center">
                                    <div class="drill-icon"><i class="fas fa-book-open"></i></div>
                                    <div class="h5 fw-bold mb-1"
                                        style="color: var(--prestige-text); font-family: 'Playfair Display', serif;">
                                        <?php echo htmlspecialchars($c['class_name']); ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?php if ($c['subject_count'] == 0): ?>
                                            <span class="badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Not Synced
                                            </span>
                                        <?php else: ?>
                                            <span class="badge">
                                                <?php echo $c['subject_count']; ?> Subjects Mapped
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ===================== VIEW: SUBJECTS ===================== -->
            <?php elseif ($view == 'subjects'): ?>
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white" style="border-bottom: 1px solid var(--prestige-border);">
                                <h5 class="mb-0"
                                    style="font-family: 'Playfair Display', serif; color: var(--prestige-text); font-weight: 700;">
                                    <i class="fas fa-list-ul me-2" style="color: var(--prestige-gold);"></i>
                                    <?php echo htmlspecialchars($selected_class_name); ?> — Subject List
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($grouped_subjects)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-book fa-3x d-block mb-3" style="color: var(--prestige-border);"></i>
                                        <p class="text-muted">No subjects mapped to this class yet. Use <strong>Sync from
                                                JSON</strong> to populate.</p>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    $hide_group = in_array($selected_class_name, ['Class 6', 'Class 7', 'Class 8']);
                                    $group_labels = [
                                        'None' => 'Compulsory Subjects',
                                        'Science' => 'Science Group',
                                        'Commerce' => 'Commerce Group',
                                        'Arts' => 'Arts / Humanities Group'
                                    ];
                                    foreach ($group_labels as $group_key => $label):
                                        if (isset($grouped_subjects[$group_key])):
                                            if (!$hide_group || $group_key == 'None'):
                                                ?>
                                                <?php if (!$hide_group): ?>
                                                    <div class="group-header"><?php echo $label; ?></div>
                                                <?php endif; ?>
                                                <div class="list-group list-group-flush">
                                                    <?php foreach ($grouped_subjects[$group_key] as $sub_obj): ?>
                                                        <div class="subject-item d-flex align-items-center justify-content-between">
                                                            <div class="d-flex align-items-center">
                                                                <i class="fas fa-check-circle"></i>
                                                                <span
                                                                    class="fw-medium"><?php echo htmlspecialchars($sub_obj['subject_name']); ?></span>
                                                            </div>
                                                            <div>
                                                                <button type="button"
                                                                    class="btn btn-sm <?php echo $sub_obj['has_practical'] ? 'btn-success' : 'btn-outline-secondary'; ?> py-0 px-2"
                                                                    style="font-size: 0.7rem; border-radius: 50px;"
                                                                    onclick="togglePractical(<?php echo $sub_obj['id']; ?>, <?php echo $sub_obj['has_practical'] ? 0 : 1; ?>, this)">
                                                                    <i class="fas fa-flask me-1"></i>
                                                                    <?php echo $sub_obj['has_practical'] ? 'Practical ON' : 'Practical OFF'; ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php
                                            endif;
                                        endif;
                                    endforeach;
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    function togglePractical(subjectId, newStatus, btn) {
                        const formData = new FormData();
                        formData.append('toggle_practical', '1');
                        formData.append('subject_id', subjectId);
                        formData.append('status', newStatus);

                        fetch('manage-subjects.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(res => res.text())
                            .then(data => {
                                if (data.trim() === 'success') {
                                    // Toggle UI
                                    if (newStatus === 1) {
                                        btn.classList.remove('btn-outline-secondary');
                                        btn.classList.add('btn-success');
                                        btn.innerHTML = '<i class="fas fa-flask me-1"></i> Practical ON';
                                        btn.onclick = () => togglePractical(subjectId, 0, btn);
                                    } else {
                                        btn.classList.remove('btn-success');
                                        btn.classList.add('btn-outline-secondary');
                                        btn.innerHTML = '<i class="fas fa-flask me-1"></i> Practical OFF';
                                        btn.onclick = () => togglePractical(subjectId, 1, btn);
                                    }
                                }
                            });
                    }
                </script>
            <?php endif; ?>

        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>