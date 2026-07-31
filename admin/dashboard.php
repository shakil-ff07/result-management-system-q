<?php
include('auth.php');
include('../includes/db_config.php');

// Fetch counts for dashboard
$current_year   = date('Y');
$student_count  = $conn->prepare("
    SELECT COUNT(s.id)
    FROM students s
    INNER JOIN classes c ON s.class_id = c.id
    WHERE c.academic_year = ?
");
$student_count->execute([$current_year]);
$student_count = $student_count->fetchColumn();

$section_count_stmt = $conn->prepare("SELECT COUNT(*) FROM classes WHERE academic_year = ?");
$section_count_stmt->execute([$current_year]);
$section_count = $section_count_stmt->fetchColumn();
$class_count_stmt = $conn->prepare("SELECT COUNT(DISTINCT class_name) FROM classes WHERE academic_year = ?");
$class_count_stmt->execute([$current_year]);
$class_count = $class_count_stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Dashboard - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .prestige-stat-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 12px;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .prestige-stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--prestige-gold);
        }

        .prestige-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
        }

        .icon-navy,
        .icon-gold,
        .icon-slate {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
        }

        .stat-details h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--prestige-navy);
            margin-bottom: 2px;
            font-family: 'Playfair Display', serif;
        }

        .stat-details p {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 0;
        }

        .quick-action-btn {
            display: block;
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 12px;
            padding: 24px 15px;
            text-align: center;
            text-decoration: none;
            color: var(--prestige-navy);
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
        }

        .quick-action-btn i {
            font-size: 2rem;
            color: var(--prestige-gold);
            margin-bottom: 15px;
            display: block;
            transition: transform 0.3s ease;
        }

        .quick-action-btn span {
            font-weight: 600;
            font-size: 0.95rem;
        }

        .quick-action-btn:hover {
            border-color: var(--prestige-gold);
            color: var(--prestige-gold);
            box-shadow: 0 10px 25px rgba(180, 83, 9, 0.08);
        }

        .quick-action-btn:hover i {
            transform: scale(1.1);
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .prestige-stat-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
        }

        [data-theme="dark"] .quick-action-btn {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1) !important;
        }

        [data-theme="dark"] .quick-action-btn:hover {
            border-color: var(--prestige-gold) !important;
            color: var(--prestige-gold) !important;
            background: #1e293b !important;
        }

        [data-theme="dark"] .stat-details h3 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .stat-details p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .icon-navy,
        [data-theme="dark"] .icon-gold,
        [data-theme="dark"] .icon-slate {
            background: rgba(255, 255, 255, 0.03) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] h1,
        [data-theme="dark"] .serif-font {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .quick-action-btn span {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .quick-action-btn:hover span {
            color: var(--prestige-gold) !important;
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
                        <i class="fas fa-desktop"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">
                            <?php
                            if (is_admin()) {
                                echo 'Admin Dashboard';
                            } elseif (is_assistant()) {
                                echo 'Assistant Dashboard';
                            } else {
                                echo 'Teacher Dashboard';
                            }
                            ?>
                        </h1>
                        <p class="mb-0">
                            <?php
                            if (is_admin()) {
                                echo 'Overview of students, subjects, and system status.';
                            } elseif (is_assistant()) {
                                echo 'Overview of student registration and enrollment.';
                            } else {
                                echo 'Overview of your assigned classes and marks entry status.';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <?php if (is_admin()):
                // Check if marks were updated after last compilation
                $stmt_check = $conn->query("
                    SELECT created_at FROM activity_logs 
                    WHERE action = 'Updated Marks' 
                    AND (
                        created_at > (SELECT MAX(created_at) FROM activity_logs WHERE action = 'Compiled Results')
                        OR (SELECT COUNT(*) FROM activity_logs WHERE action = 'Compiled Results') = 0
                    )
                    ORDER BY created_at DESC LIMIT 1
                ");
                $pending_marks = $stmt_check->fetch();

                if ($pending_marks): ?>
                    <div class="alert shadow-sm border-0 mb-4 d-flex align-items-center justify-content-between p-3"
                        style="background: linear-gradient(135deg, #fff 0%, #fff7ed 100%); border-left: 5px solid #f59e0b !important; border-radius: 12px;">
                        <div class="d-flex align-items-center">
                            <div class="bg-warning bg-opacity-10 p-3 rounded-circle me-3">
                                <i class="fas fa-sync-alt fa-spin text-warning"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">Pending Result Finalization</h6>
                                <p class="mb-0 text-muted small">New marks were recorded on
                                    <strong><?php echo date('d M, h:i A', strtotime($pending_marks['created_at'])); ?></strong>.
                                    Please re-compile results to update merit lists.</p>
                            </div>
                        </div>
                        <a href="generate-results.php" class="btn btn-warning btn-sm fw-bold px-3 py-2 rounded-3 shadow-sm">
                            <i class="fas fa-magic me-2"></i> Compile Now
                        </a>
                    </div>

                    <style>
                        /* Dark Mode Alert Adjustment */
                        [data-theme="dark"] .alert {
                            background: linear-gradient(135deg, #111827 0%, #1e1b15 100%) !important;
                            border-color: rgba(245, 158, 11, 0.2) !important;
                        }

                        [data-theme="dark"] .alert h6 {
                            color: #f8fafc !important;
                        }
                    </style>
                <?php endif;
            endif; ?>

            <!-- Stats Row -->
            <div class="row g-4 mb-5">
                <?php if (is_admin()): ?>
                    <div class="col-md-4">
                        <div class="prestige-stat-card">
                            <div class="stat-details">
                                <p>Total Classes</p>
                                <h3><?php echo number_format($class_count); ?></h3>
                            </div>
                            <div class="stat-icon icon-slate">
                                <i class="fas fa-layer-group"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="prestige-stat-card">
                            <div class="stat-details">
                                <p>Total Sections</p>
                                <h3><?php echo number_format($section_count); ?></h3>
                            </div>
                            <div class="stat-icon icon-gold">
                                <i class="fas fa-book-open"></i>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (is_admin() || is_assistant()): ?>
                    <div class="col-md-4">
                        <div class="prestige-stat-card">
                            <div class="stat-details">
                                <p>Total Students</p>
                                <h3><?php echo number_format($student_count); ?></h3>
                            </div>
                            <div class="stat-icon icon-navy">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <h4 class="serif-font mb-4" style="color: var(--prestige-navy); font-weight: 700;">Quick Actions</h4>
            <div class="row g-4">
                <?php if (is_admin() || is_assistant()): ?>
                    <div class="<?php echo is_admin() ? 'col-md-3 col-sm-6' : 'col-md-6'; ?>">
                        <a href="add-student.php" class="quick-action-btn">
                            <i class="fas fa-user-plus"></i>
                            <span>Register New Student</span>
                        </a>
                    </div>
                    <div class="<?php echo is_admin() ? 'col-md-3 col-sm-6' : 'col-md-6'; ?>">
                        <a href="manage-students.php" class="quick-action-btn">
                            <i class="fas fa-user-graduate"></i>
                            <span>Manage Students</span>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!is_assistant()): ?>
                    <?php if (is_teacher()): ?>
                        <div class="col-md-12">
                            <a href="marks-entry.php" class="quick-action-btn">
                                <i class="fas fa-pen-nib"></i>
                                <span>Enter Marks</span>
                            </a>
                        </div>

                    <?php else: ?>
                        <div class="col-md-3 col-sm-6">
                            <a href="marks-entry.php" class="quick-action-btn">
                                <i class="fas fa-pen-nib"></i>
                                <span>Enter Marks</span>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (is_admin()): ?>
                    <div class="col-md-3 col-sm-6">
                        <a href="generate-results.php" class="quick-action-btn">
                            <i class="fas fa-poll"></i>
                            <span>Process Final Results</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</body>

</html>