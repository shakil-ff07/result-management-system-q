<?php
include('auth.php');
require_superadmin();
include('../includes/db_config.php');

// Fetch counts for dashboard
$headmaster_count = $conn->query("SELECT COUNT(*) FROM admins WHERE role = 'headmaster'")->fetchColumn();
$student_count = $conn->query("SELECT COUNT(*) FROM students")->fetchColumn();
$subject_count = $conn->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Superadmin Portal - SRMS</title>
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
        }

        .icon-navy {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
        }

        .icon-gold {
            background: rgba(180, 83, 9, 0.05);
            color: var(--prestige-gold);
        }

        .icon-slate {
            background: var(--prestige-slate);
            color: #64748b;
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
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
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

        [data-theme="dark"] .icon-navy {
            background: rgba(255, 255, 255, 0.03) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .icon-gold {
            background: rgba(180, 83, 9, 0.15) !important;
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .icon-slate {
            background: rgba(255, 255, 255, 0.03) !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] h1, [data-theme="dark"] .serif-font {
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
                        <h1 class="mb-0">Superadmin Dashboard</h1>
                        <p class="mb-0">
                            System overview and headmaster management.
                        </p>
                    </div>
                </div>
            </div>



            <!-- Stats Row -->
            <div class="row g-4 mb-5">
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
                
                <div class="col-md-4">
                    <div class="prestige-stat-card">
                        <div class="stat-details">
                            <p>Total Headmasters</p>
                            <h3><?php echo number_format($headmaster_count); ?></h3>
                        </div>
                        <div class="stat-icon icon-gold">
                            <i class="fas fa-user-tie"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="prestige-stat-card">
                        <div class="stat-details">
                            <p>Total Students</p>
                            <h3><?php echo number_format($student_count); ?></h3>
                        </div>
                        <div class="stat-icon icon-slate">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <h4 class="serif-font mb-4" style="color: var(--prestige-navy); font-weight: 700;">Quick Actions</h4>
            <div class="row g-4">
                <div class="col-md-4 col-sm-6">
                    <a href="manage-headmasters.php" class="quick-action-btn">
                        <i class="fas fa-users-cog"></i>
                        <span>Manage Headmasters</span>
                    </a>
                </div>
            </div>

        </div>
    </div>

</body>
</html>