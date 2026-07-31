<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$message = "";
$error = "";

// Get current settings
$stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get available years for the dropdown (Low to High)
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC");
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Ensure current year is always available as an option
if (!in_array(date('Y'), $available_years)) {
    $available_years[] = (string)date('Y');
    sort($available_years);
}

// Update settings
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_settings'])) {
    $marks_enabled = isset($_POST['marks_entry_enabled']) ? '1' : '0';
    $admit_published = isset($_POST['admit_cards_published']) ? '1' : '0';
    $lock_message = $_POST['lock_message'] ?? "Marks entry is currently closed. Please contact the administrator for access.";
    $admit_year = $_POST['current_admit_year'] ?? date('Y');
    $admit_exam = $_POST['current_admit_exam'] ?? 'Half Yearly';
    
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES 
            ('marks_entry_enabled', ?), 
            ('lock_message', ?),
            ('admit_cards_published', ?),
            ('current_admit_year', ?),
            ('current_admit_exam', ?)");
        $stmt->execute([$marks_enabled, $lock_message, $admit_published, $admit_year, $admit_exam]);
        
        $conn->commit();

        // Refresh settings
        $stmt = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $message = "System settings updated successfully!";
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Error updating settings: " . $e->getMessage();
    }
}

$marks_entry_active = ($settings['marks_entry_enabled'] ?? '1') === '1';
$admit_cards_active = ($settings['admit_cards_published'] ?? '0') === '1';
$current_lock_message = $settings['lock_message'] ?? "Marks entry is currently closed. Please contact the administrator for access.";
$current_admit_year = $settings['current_admit_year'] ?? date('Y');
$current_admit_exam = $settings['current_admit_exam'] ?? 'Half Yearly';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>System Controls | SRMS Portal</title>
    <?php include('header.php'); ?>
    <style>
        .settings-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .settings-card:hover {
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
        }

        /* Premium Switch */
        .form-switch .form-check-input {
            width: 3.5rem;
            height: 1.75rem;
            cursor: pointer;
        }
        .form-switch .form-check-input:checked {
            background-color: var(--prestige-gold);
            border-color: var(--prestige-gold);
        }
        .form-switch .form-check-input:not(:checked) {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='rgba%2815, 23, 42, 0.25%29'/%3e%3c/svg%3e");
        }
        [data-theme="dark"] .form-switch .form-check-input:not(:checked) {
            background-color: #334155 !important;
            border-color: rgba(255,255,255,0.2) !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='rgba%28255, 255, 255, 0.4%29'/%3e%3c/svg%3e");
        }

        .btn-prestige {
            background: var(--prestige-navy);
            color: white;
            border: 1px solid var(--prestige-gold);
            padding: 0.75rem 2rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-prestige:hover {
            background: #1e293b;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .page-header {
            background: white;
            padding: 2.5rem;
            border-bottom: 1px solid var(--prestige-border);
            margin-bottom: 2rem;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
        }

        .header-icon-box {
            width: 60px;
            height: 60px;
            background: var(--prestige-navy);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            margin-right: 1.5rem;
            font-size: 1.75rem;
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .settings-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
        }

        [data-theme="dark"] h3, 
        [data-theme="dark"] h6, 
        [data-theme="dark"] .fw-bold {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .form-control {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .form-control:focus {
            background-color: #0f172a !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .border-bottom, 
        [data-theme="dark"] .border-top {
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .form-check-input {
            background-color: #1e293b !important;
            border-color: rgba(255, 255, 255, 0.2) !important;
        }

        [data-theme="dark"] .bg-success.bg-opacity-10 {
            background-color: rgba(16, 185, 129, 0.1) !important;
        }

        [data-theme="dark"] .bg-danger.bg-opacity-10 {
            background-color: rgba(239, 68, 68, 0.1) !important;
        }

        /* Better visibility for the Switch in Dark Mode */
        [data-theme="dark"] .form-switch .form-check-input:checked {
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.2) !important;
        }
        [data-theme="dark"] .btn-prestige {
            border-color: var(--prestige-gold) !important;
            background: #111827 !important;
            color: #f59e0b !important;
        }
        [data-theme="dark"] .btn-prestige:hover {
            background: var(--prestige-gold) !important;
            color: #0f172a !important;
        }
    </style>
</head>
<body>
    <?php include('sidebar.php'); ?>

    <div id="content" class="main-content">
        <?php include('topbar.php'); ?>

        <div class="container py-4">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    
                    <div class="mb-4 text-center">
                        <h3 class="serif-font fw-bold mb-1" style="color: var(--prestige-navy);">System Controls</h3>
                        <p class="text-muted small mb-0">Global portal security &amp; permissions.</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success border-0 shadow-sm mb-3 py-2 small" style="border-radius: 10px;">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="settings-card shadow-sm">
                            <div class="p-4">
                                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                                    <div>
                                        <h6 class="fw-bold mb-0">Global Marks Entry</h6>
                                        <div class="small text-muted mt-1">Master grading switch</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="marks_entry_enabled" id="entryToggle" <?php echo $marks_entry_active ? 'checked' : ''; ?>>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                                    <div>
                                        <h6 class="fw-bold mb-0">Publish Admit Cards</h6>
                                        <div class="small text-muted mt-1">Make admit card portal visible to students</div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="admit_cards_published" id="admitToggle" <?php echo $admit_cards_active ? 'checked' : ''; ?>>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4 pb-3 border-bottom">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted text-uppercase mb-2">Current Admit Year</label>
                                        <select name="current_admit_year" class="form-control form-control-sm" style="border-radius: 8px;">
                                            <?php foreach ($available_years as $year): ?>
                                                <option value="<?php echo $year; ?>" <?php echo $current_admit_year == $year ? 'selected' : ''; ?>>
                                                    <?php echo $year; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-muted text-uppercase mb-2">Current Admit Exam</label>
                                        <select name="current_admit_exam" class="form-control form-control-sm" style="border-radius: 8px;">
                                            <option value="Half Yearly" <?php echo $current_admit_exam == 'Half Yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                                            <option value="Final" <?php echo $current_admit_exam == 'Final' ? 'selected' : ''; ?>>Final Examination</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-muted text-uppercase mb-2">Lock Notification</label>
                                    <textarea name="lock_message" class="form-control form-control-sm" rows="2" style="border-radius: 8px; background: #fcfcfc;" placeholder="Enter message..."><?php echo htmlspecialchars($current_lock_message); ?></textarea>
                                </div>

                                <div class="d-flex justify-content-between align-items-center pt-3 mt-4 border-top">
                                    <div>
                                        <?php if ($marks_entry_active): ?>
                                            <span class="status-badge bg-success bg-opacity-10 text-success py-1 px-3" style="font-size: 0.7rem;">Active</span>
                                        <?php else: ?>
                                            <span class="status-badge bg-danger bg-opacity-10 text-danger py-1 px-3" style="font-size: 0.7rem;">Locked</span>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" name="update_settings" class="btn btn-sm btn-prestige px-4" style="padding-top: 8px; padding-bottom: 8px;">
                                        Save Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
