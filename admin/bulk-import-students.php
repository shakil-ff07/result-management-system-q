<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

$class_id = $_GET['class_id'] ?? null;
if (!$class_id) {
    header("Location: manage-students.php");
    exit();
}

// Fetch class details
$stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
$stmt->execute([$class_id]);
$class_info = $stmt->fetch();

if (!$class_info) {
    header("Location: manage-students.php");
    exit();
}

$message = "";
$error = "";
$stats = null;

$is_9_10 = (strpos($class_info['class_name'], '9') !== false || strpos($class_info['class_name'], '10') !== false);
$import_group = $_POST['import_group'] ?? 'None';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['student_csv'])) {
    $file = $_FILES['student_csv']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {
        // Skip header row
        fgetcsv($handle, 1000, ",");

        $conn->beginTransaction();
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row_count = 1; // Starting from 1 for user feedback (ignorer header)

        try {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_count++;

                // mapping logic based on level
                if ($is_9_10) {
                    // 0:Roll, 1:Name, 2:Father, 3:Mother, 4:DOB, 5:Optional, 6:Phone
                    $roll = trim($data[0]);
                    $name = trim($data[1]);
                    $father = trim($data[2] ?? '');
                    $mother = trim($data[3] ?? '');
                    $dob = trim($data[4] ?? '');
                    $opt_name = trim($data[5] ?? '');
                    $phone = trim($data[6] ?? '');
                } else {
                    // 0:Roll, 1:Name, 2:Father, 3:Mother, 4:DOB, 5:Phone
                    $roll = trim($data[0]);
                    $name = trim($data[1]);
                    $father = trim($data[2] ?? '');
                    $mother = trim($data[3] ?? '');
                    $dob = trim($data[4] ?? '');
                    $phone = trim($data[5] ?? '');
                    $opt_name = '';
                }

                $group = $is_9_10 ? $import_group : 'None';
                $main_name = '';

                if (empty($name) || empty($roll)) {
                    $skipped++;
                    $errors[] = "Row $row_count: Name and Roll Number are required.";
                    continue;
                }

                // Apply the "Formula" for Class 9/10
                if ($is_9_10) {
                    if ($group === 'Science') {
                        if (stripos($opt_name, 'Biology') !== false) {
                            $main_name = 'Higher Mathematics';
                            $opt_name = 'Biology'; // Standardize name
                        } elseif (stripos($opt_name, 'Higher Mathematics') !== false || stripos($opt_name, 'Higher Math') !== false) {
                            $main_name = 'Biology';
                            $opt_name = 'Higher Mathematics'; // Standardize name
                        } else {
                            $main_name = 'Biology'; // Default main for Science
                        }
                    } elseif ($group === 'Arts') {
                        if (stripos($opt_name, 'Geography') !== false) {
                            $main_name = 'Economics';
                            $opt_name = 'Geography';
                        } elseif (stripos($opt_name, 'Economics') !== false) {
                            $main_name = 'Geography';
                            $opt_name = 'Economics';
                        }
                    }
                }

                // Lookup IDs
                $main_id = null;
                $opt_id = null;
                if (!empty($main_name)) {
                    $stmt_sub = $conn->prepare("SELECT id FROM subjects WHERE subject_name LIKE ?");
                    $stmt_sub->execute(["%$main_name%"]);
                    $main_id = $stmt_sub->fetchColumn() ?: null;
                }
                if (!empty($opt_name)) {
                    $stmt_sub = $conn->prepare("SELECT id FROM subjects WHERE subject_name LIKE ?");
                    $stmt_sub->execute(["%$opt_name%"]);
                    $opt_id = $stmt_sub->fetchColumn() ?: null;
                }

                // Check for duplicate roll in this class
                $stmt_check = $conn->prepare("SELECT id FROM students WHERE roll_number = ? AND class_id = ?");
                $stmt_check->execute([$roll, $class_id]);
                if ($stmt_check->fetch()) {
                    $skipped++;
                    $errors[] = "Row $row_count: Roll $roll already exists in this class.";
                    continue;
                }

                // Insert with Phone only
                $stmt_insert = $conn->prepare("INSERT INTO students (roll_number, name, father_name, mother_name, dob, class_id, student_group, main_elective_id, optional_subject_id, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_insert->execute([$roll, $name, $father, $mother, $dob ?: null, $class_id, $group, $main_id, $opt_id, $phone ?: null]);
                $imported++;
            }

            $conn->commit();
            $message = "Successfully imported $imported students!";
            if ($skipped > 0) {
                $error = "Skipped $skipped rows due to errors.";
            }
            $stats = ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];

        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error during import: " . $e->getMessage();
        }

        fclose($handle);
    } else {
        $error = "Could not open uploaded file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Bulk Import Students - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .import-card {
            border-radius: 15px;
            border: 1px dashed #4e73df;
            background: #f8f9fc;
        }

        .drop-zone {
            padding: 3rem;
            text-align: center;
            cursor: pointer;
            transition: 0.3s;
        }

        .drop-zone:hover {
            background: #eef2ff;
        }

        .stats-box {
            border-radius: 12px;
        }

        /* Dark mode visibility fixes */
        [data-theme="dark"] .instruction-title { color: var(--prestige-gold-light) !important; }
        [data-theme="dark"] .instruction-text { color: #94a3b8 !important; }
        [data-theme="dark"] .group-label { color: #cbd5e1 !important; }
        [data-theme="dark"] .instruction-card { background: rgba(255, 255, 255, 0.03) !important; }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-4">
            <!-- Header -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-file-import"></i>
                    </div>
                    <div>
                        <h1 class="fw-bold mb-0">Bulk Student Import</h1>
                        <p class="mb-0 text-primary fw-bold">Target:
                            <?php echo $class_info['class_name']; ?> - Section
                            <?php echo $class_info['section']; ?> (
                            <?php echo $class_info['academic_year']; ?>)
                        </p>
                    </div>
                </div>
                <div class="header-actions d-flex gap-2 align-items-center">
                    <?php if ($is_9_10): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-success rounded-pill px-3 shadow-sm dropdown-toggle" type="button"
                                data-bs-toggle="dropdown">
                                <i class="fas fa-download me-1"></i> Download Template
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li><a class="dropdown-item small fw-bold"
                                        href="student-template-csv.php?class_id=<?php echo $class_id; ?>&group=Science"><i
                                            class="fas fa-microscope me-2 text-primary"></i> Science </a></li>
                                <li><a class="dropdown-item small fw-bold"
                                        href="student-template-csv.php?class_id=<?php echo $class_id; ?>&group=Arts"><i
                                            class="fas fa-paint-brush me-2 text-info"></i> Arts </a></li>
                                <li><a class="dropdown-item small fw-bold"
                                        href="student-template-csv.php?class_id=<?php echo $class_id; ?>&group=Commerce"><i
                                            class="fas fa-calculator me-2 text-success"></i> Commerce </a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="student-template-csv.php?class_id=<?php echo $class_id; ?>"
                            class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                            <i class="fas fa-download me-1"></i> Download Template
                        </a>
                    <?php endif; ?>
                    <a href="manage-students.php?view=students&class_id=<?php echo $class_id; ?>"
                        class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success shadow-sm mb-4 border-left-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm mb-4 border-left-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($stats && !empty($stats['errors'])): ?>
                <div class="card shadow-sm mb-4 border-0 stats-box overflow-hidden">
                    <div class="card-header bg-danger text-white border-0 py-3">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-bug me-2"></i> Troubleshooting Report</h6>
                    </div>
                    <div class="card-body bg-light">
                        <ul class="mb-0 small text-danger">
                            <?php foreach ($stats['errors'] as $err): ?>
                                <li>
                                    <?php echo $err; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body p-5">
                            <form action="" method="POST" enctype="multipart/form-data" id="importForm">
                                <div class="import-card mb-4">
                                    <label for="student_csv" class="drop-zone w-100 mb-0">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                        <h5 class="fw-bold">Upload CSV File</h5>
                                        <p class="text-muted small">Only .csv files generated from our template are
                                            supported.</p>
                                        <div id="file-name" class="badge bg-primary px-3 py-2 d-none"></div>
                                        <input type="file" name="student_csv" id="student_csv" class="d-none"
                                            accept=".csv" required>
                                    </label>
                                </div>

                                <?php if ($is_9_10): ?>
                                    <div class="mb-4 text-center">
                                        <label
                                            class="form-label fw-bold text-uppercase small text-muted mb-3 d-block">Select
                                            Group for this CSV</label>
                                        <div class="d-flex justify-content-center gap-3">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="import_group"
                                                    id="g_science" value="Science" required>
                                                <label class="form-check-label fw-bold group-label" for="g_science">Science</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="import_group" id="g_arts"
                                                    value="Arts" required>
                                                <label class="form-check-label fw-bold group-label" for="g_arts">Arts</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="import_group"
                                                    id="g_commerce" value="Commerce" required>
                                                <label class="form-check-label fw-bold group-label" for="g_commerce">Commerce</label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="text-center">
                                    <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 shadow"
                                        id="submitBtn">
                                        <i class="fas fa-sync-alt me-2"></i> Start Importing students
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Instructions Card -->
                    <div class="card border-0 shadow-sm mt-4 instruction-card"
                        style="background: rgba(15, 23, 42, 0.04); border-left: 4px solid var(--prestige-gold) !important; border-radius: 12px;">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3 instruction-title" style="color: var(--prestige-navy);"><i
                                    class="fas fa-info-circle me-2 text-gold"></i> Data Entry Instructions</h6>
                            <ul class="small mb-0 text-muted list-unstyled instruction-text">
                                <li class="mb-2"><strong class="text-dark">1. Template:</strong> Download the correct
                                    template for your target class and group.</li>
                                <li class="mb-2"><strong class="text-dark">2. Format:</strong> Keep the columns in
                                    order: <strong>Roll Number</strong> first, then <strong>Name</strong>.</li>
                                <li class="mb-2"><strong class="text-dark">3. Phone:</strong> This field is optional and can be left blank.</li>
                                <li class="mb-2"><strong class="text-dark">4. Auto-Logic:</strong> For Class 9/10, the
                                    <strong>Main Elective</strong> will be automatically assigned based on the
                                    <strong>Optional Subject</strong> you provide.</li>
                                <li class="ms-4 mb-2 italic small"><i class="fas fa-magic me-1 text-gold"></i> Science
                                    Example: "Biology" &rarr; "Higher Mathematics"</li>
                                <li><strong class="text-dark">5. Dates:</strong> Use <strong>YYYY-MM-DD</strong> format
                                    (e.g. 2010-05-15) for Date of Birth.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('student_csv').addEventListener('change', function (e) {
            const fileName = e.target.files[0]?.name;
            const badge = document.getElementById('file-name');
            if (fileName) {
                badge.textContent = fileName;
                badge.classList.remove('d-none');
            }
        });

        document.getElementById('importForm').addEventListener('submit', function () {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
        });
    </script>
</body>

</html>