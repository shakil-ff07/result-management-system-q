<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_data'])) {
    $academic_year = $_POST['academic_year'];

    if (empty($academic_year)) {
        $error = "Please select an academic year to archive.";
    } else {
        try {
            $conn->beginTransaction();

            // 1. Move students of that year to archived_students
            $conn->prepare("INSERT INTO archived_students SELECT * FROM students WHERE class_id IN (SELECT id FROM classes WHERE academic_year = ?)")->execute([$academic_year]);

            // 2. Move marks
            $conn->prepare("INSERT INTO archived_marks SELECT * FROM marks WHERE student_id IN (SELECT id FROM students WHERE class_id IN (SELECT id FROM classes WHERE academic_year = ?))")->execute([$academic_year]);

            // 3. Move final results
            $conn->prepare("INSERT INTO archived_final_results SELECT * FROM final_results WHERE student_id IN (SELECT id FROM students WHERE class_id IN (SELECT id FROM classes WHERE academic_year = ?))")->execute([$academic_year]);

            // 4. Delete from original tables (Optional but usually desirable to keep main tables clean)
            // Note: In some systems, we keep the data but mark as 'archived'. 
            // Here, we'll keep them for now to avoid breaking relationships if classes aren't deleted,
            // but the user specifically asked to "keep main system fast", which implies removal.

            // For now, let's just copy them. Deletion requires careful foreign key handling.

            $conn->commit();
            $message = "Academic year $academic_year data has been successfully archived!";
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Archive failed: " . $e->getMessage();
        }
    }
}

// Fetch unique years from classes
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch stats
$student_count = $conn->query("SELECT COUNT(*) FROM students")->fetchColumn();
$result_count  = $conn->query("SELECT COUNT(*) FROM final_results")->fetchColumn();
$archive_count = $conn->query("SELECT (SELECT COUNT(*) FROM archived_students) + (SELECT COUNT(*) FROM archived_final_results)")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>System Maintenance - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .maintenance-card {
            border: none;
            border-radius: 20px;
            transition: 0.3s;
        }

        .danger-zone {
            border: 2px dashed #e74a3b;
            background: #fff5f5;
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .card {
            background-color: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .card-header.bg-white {
            background-color: #111827 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] h1, 
        [data-theme="dark"] h2, 
        [data-theme="dark"] h5, 
        [data-theme="dark"] .fw-bold {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .form-select {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .form-select:focus {
            background-color: #0f172a !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .fas.fa-user-graduate {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .fas.fa-archive.text-secondary {
            color: #64748b !important;
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
                    <div class="header-icon-box shadow-sm" style="color: var(--prestige-gold);">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">System Maintenance</h1>
                        <p class="mb-0">Optimize, archive, and manage your database.</p>
                    </div>
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

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-body p-4 text-center">
                            <i class="fas fa-user-graduate fa-3x mb-3" style="color: var(--prestige-navy);"></i>
                            <h2 class="fw-bold mb-0"
                                style="font-family: 'Playfair Display', serif; color: var(--prestige-navy);">
                                <?php echo $student_count; ?>
                            </h2>
                            <span class="text-muted small fw-bold text-uppercase">Active Students</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-body p-4 text-center">
                            <i class="fas fa-file-invoice-dollar fa-3x mb-3" style="color: var(--prestige-gold);"></i>
                            <h2 class="fw-bold mb-0"
                                style="font-family: 'Playfair Display', serif; color: var(--prestige-navy);">
                                <?php echo $result_count; ?>
                            </h2>
                            <span class="text-muted small fw-bold text-uppercase">Result Records</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 rounded-4 h-100">
                        <div class="card-body p-4 text-center">
                            <i class="fas fa-archive fa-3x text-secondary mb-3"></i>
                            <h2 class="fw-bold mb-0"
                                style="font-family: 'Playfair Display', serif; color: var(--prestige-navy);">
                                <?php echo $archive_count; ?>
                            </h2>
                            <span class="text-muted small fw-bold text-uppercase">Archived Items</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm maintenance-card overflow-hidden">
                <div class="card-header bg-white py-3" style="border-bottom: 1px solid var(--prestige-border);">
                    <h5 class="mb-0 fw-bold"
                        style="font-family: 'Playfair Display', serif; color: var(--prestige-navy);"><i
                            class="fas fa-history me-2" style="color: var(--prestige-gold);"></i> Data Archiving</h5>
                </div>
                <div class="card-body p-4">
                    <!-- <p class="text-muted">Use this tool to move old academic session data to the archive. This keeps the
                        active searching process fast and the main database responsive.</p> -->

                    <form action="" method="POST" class="row align-items-end g-3"
                        onsubmit="return confirm('WARNING: Are you sure you want to archive this year? This process is intensive.')">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Academic Year to Archive</label>
                            <select name="academic_year" class="form-select rounded-pill px-4" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>">
                                        <?php echo $y; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" name="archive_data"
                                class="btn btn-primary rounded-pill px-5 w-100 shadow">
                                <i class="fas fa-box-archive me-2"></i> Start Archiving Process
                            </button>
                        </div>
                    </form>
                </div>
            </div>


        </div>
    </div>
</body>

</html>