<?php
include('includes/db_config.php');

$settings = $conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$admit_cards_published = ($settings['admit_cards_published'] ?? '0') === '1';
$current_admit_year = $settings['current_admit_year'] ?? date('Y');
$current_admit_exam = $settings['current_admit_exam'] ?? 'Half Yearly';

if (!$admit_cards_published) {
    // If not published, we can just show a friendly message below.
}

$classes_data = $conn->query("SELECT id, class_name, section, academic_year FROM classes WHERE academic_year = '$current_admit_year' ORDER BY LENGTH(class_name), class_name ASC, section ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<script>(function(){var t=localStorage.getItem('srms-theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admit Card Portal | SRMS</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        :root {
            --prestige-navy: #0f172a;
            --prestige-gold: #b45309;
            --prestige-gold-light: #fef3c7;
            --prestige-slate: #f8fafc;
            --prestige-border: #e2e8f0;
            --prestige-text: #1e293b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--prestige-slate);
            color: var(--prestige-text);
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .serif-font {
            font-family: 'Playfair Display', serif;
        }

        /* Prestige Global Navigation */
        .prestige-nav-global {
            position: sticky;
            top: 0;
            z-index: 2000;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 12px 0;
            transition: all 0.3s ease;
        }

        .prestige-nav-global .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-brand {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 1.15rem;
            color: white !important;
            text-decoration: none;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-links-container {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .prestige-nav-link {
            font-size: 0.75rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.65) !important;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid transparent;
        }

        .prestige-nav-link:hover {
            color: white !important;
            background: rgba(255, 255, 255, 0.08);
        }

        .prestige-nav-link.active {
            color: var(--prestige-gold-light) !important;
            background: rgba(180, 83, 9, 0.25);
            border: 1px solid rgba(180, 83, 9, 0.3);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Hero Header */
        .prestige-header {
            background: var(--prestige-navy);
            padding: 40px 0 50px;
            color: white;
            text-align: center;
            border-bottom: 4px solid var(--prestige-gold);
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            font-size: 0.7rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 15px;
            color: var(--prestige-gold-light);
            font-weight: 600;
        }

        /* Registry Card Architecture */
        .registry-container {
            max-width: 800px;
            margin: -40px auto 40px;
            padding: 0 15px;
            width: 100%;
        }

        .registry-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 8px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .registry-header {
            padding: 30px 40px;
            border-bottom: 1px solid var(--prestige-border);
            text-align: center;
            background: #fdfdfd;
        }

        .registry-header h2 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: var(--prestige-navy);
        }

        .registry-body {
            padding: 40px;
        }

        /* Form Controls */
        .form-label {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }

        .prestige-input {
            width: 100%;
            height: 48px;
            padding: 0 16px;
            background: var(--prestige-slate);
            border: 1.5px solid var(--prestige-border);
            border-radius: 6px;
            font-weight: 600;
            color: var(--prestige-navy);
            transition: 0.2s;
            outline: none;
        }

        .prestige-input:focus {
            border-color: var(--prestige-gold);
            background: white;
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.05);
        }

        .prestige-input:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: #f1f5f9;
        }

        /* Custom Selects - Prestige Style */
        .custom-select-wrapper {
            position: relative;
        }

        .custom-select-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 48px;
            padding: 0 16px;
            background: var(--prestige-slate);
            border: 1.5px solid var(--prestige-border);
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .custom-select-trigger:hover:not(.disabled) {
            border-color: var(--prestige-gold);
            background: white;
        }

        .custom-select-trigger.disabled {
            opacity: 0.6;
            background: #f1f5f9;
            cursor: not-allowed;
        }

        .custom-options {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 6px;
            margin-top: 5px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: 0.2s;
            z-index: 100;
            max-height: 200px;
            overflow-y: auto;
        }

        .custom-select-wrapper.open .custom-options {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .custom-option {
            padding: 10px 16px;
            cursor: pointer;
            font-weight: 500;
            transition: 0.2s;
        }

        .custom-option:hover {
            background: var(--prestige-slate);
            color: var(--prestige-gold);
        }

        .custom-option.selected {
            background: var(--prestige-navy);
            color: white;
        }

        .native-select {
            display: none;
        }

        /* Action Button */
        .btn-retrieve {
            height: 54px;
            background: var(--prestige-navy);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            width: 100%;
            transition: 0.3s;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .btn-retrieve:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.2);
        }

        /* Portal Footer */
        .portal-footer {
            margin-top: auto;
            padding: 40px 0;
            text-align: center;
            border-top: 1px solid var(--prestige-border);
            background: white;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 15px;
        }

        .footer-link {
            color: var(--prestige-navy);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }

        .footer-link:hover {
            color: var(--prestige-gold);
        }

        .footer-link.ho-link {
            padding: 8px 16px;
            border: 1.5px solid var(--prestige-gold);
            border-radius: 4px;
            color: var(--prestige-gold);
        }

        .footer-link.ho-link:hover {
            background: var(--prestige-gold);
            color: white;
        }

        .copyright {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
        }

        /* Flatpickr Modal Customization */
        .flatpickr-calendar {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid var(--prestige-border) !important;
            border-radius: 8px !important;
        }

        /* Midnight Prestige - Dark Mode */
        body, .registry-card, .portal-footer, .custom-select-trigger,
        .custom-options, .prestige-input, .custom-option {
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease !important;
        }
        [data-theme="dark"] { --prestige-gold: #f59e0b; --prestige-slate: #060c18; --prestige-border: rgba(255,255,255,0.08); --prestige-text: #cbd5e1; }
        [data-theme="dark"] body { background-color: #060c18 !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .prestige-header { background: #030710 !important; }
        [data-theme="dark"] .registry-card { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; box-shadow: 0 20px 50px rgba(0,0,0,0.4) !important; }
        [data-theme="dark"] .registry-header { background: #0f172a !important; border-bottom-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .registry-header h2 { color: #e2e8f0 !important; }
        [data-theme="dark"] .prestige-input { background: #0a1020 !important; border-color: rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; }
        [data-theme="dark"] .prestige-input:focus { background: #060c18 !important; border-color: #f59e0b !important; box-shadow: 0 0 0 4px rgba(245,158,11,0.1) !important; }
        [data-theme="dark"] .form-label { color: #6b7280 !important; }
        [data-theme="dark"] .custom-select-trigger { background: #0a1020 !important; border-color: rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; }
        [data-theme="dark"] .custom-select-trigger:hover:not(.disabled) { background: #060c18 !important; border-color: #f59e0b !important; }
        [data-theme="dark"] .custom-select-trigger.disabled { background: #060c18 !important; }
        [data-theme="dark"] .custom-options { background: #111827 !important; border-color: rgba(255,255,255,0.08) !important; box-shadow: 0 10px 25px rgba(0,0,0,0.5) !important; }
        [data-theme="dark"] .custom-option { color: #cbd5e1; }
        [data-theme="dark"] .custom-option:hover { background: rgba(245,158,11,0.1) !important; color: #f59e0b !important; }
        [data-theme="dark"] .custom-option.selected { background: #0f172a !important; }
        [data-theme="dark"] .btn-retrieve { background: #f59e0b !important; color: #0f172a !important; }
        [data-theme="dark"] .btn-retrieve:hover { background: #d97706 !important; box-shadow: 0 8px 20px rgba(245,158,11,0.3) !important; }
        [data-theme="dark"] .portal-footer { background: #0f172a !important; border-top-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .footer-link { color: #94a3b8 !important; }
        [data-theme="dark"] .footer-link:hover { color: #f59e0b !important; }
        [data-theme="dark"] .footer-link.ho-link { border-color: #f59e0b !important; color: #f59e0b !important; }
        [data-theme="dark"] .copyright { color: #4b5563 !important; }
        [data-theme="dark"] .text-muted { color: #6b7280 !important; }

        /* Dark mode toggle */
        .dm-toggle-pub {
            width: 34px; height: 34px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            cursor: pointer; transition: all 0.2s ease; font-size: 0.85rem;
        }
        .dm-toggle-pub:hover { background: rgba(245,158,11,0.2); border-color: #f59e0b; color: #f59e0b; }

        /* --- PRESTIGE RESPONSIVE ENGINE --- */
        @media (max-width: 991px) {
            .nav-brand span { font-size: 1rem; }
            .prestige-nav-link { padding: 8px 12px; font-size: 0.7rem; }
        }

        @media (max-width: 768px) {
            .prestige-nav-global { padding: 10px 0; }
            .prestige-nav-global .container {
                flex-direction: column;
                gap: 12px;
            }
            .nav-brand-wrapper {
                width: 100%;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .nav-brand { font-size: 1.1rem; }
            .nav-brand span { font-size: 1.05rem; letter-spacing: 0.3px; }
            
            .nav-links-container {
                width: 100%;
                justify-content: space-between;
                gap: 4px;
                background: rgba(255, 255, 255, 0.03);
                padding: 4px;
                border-radius: 8px;
                border: 1px solid rgba(255, 255, 255, 0.05);
            }
            .prestige-nav-link {
                flex: 1;
                flex-direction: column;
                gap: 4px;
                padding: 8px 2px;
                font-size: 0.65rem;
                letter-spacing: 0.5px;
                text-align: center;
                border-radius: 6px;
            }
            .prestige-nav-link i { font-size: 1rem; }
            .prestige-nav-link span { display: block !important; }
            
            .prestige-header { padding: 30px 0 50px; }
            .hero-badge { font-size: 0.6rem; letter-spacing: 1px; }
            .prestige-header h1 { font-size: 1.75rem; }
            
            .registry-container { margin-top: -35px; }
            .registry-header { padding: 25px 20px; }
            .registry-header h2 { font-size: 1.25rem; }
            .registry-body { padding: 25px 20px; }
            
            .form-label { font-size: 0.7rem; margin-bottom: 6px; }
            .prestige-input, .custom-select-trigger { 
                height: 46px; 
                font-size: 16px !important;
                padding: 0 12px;
            }
            
            .btn-retrieve { height: 50px; font-size: 0.9rem; margin-top: 10px; }
            
            .footer-links { gap: 15px; flex-wrap: wrap; justify-content: center; }
            .footer-link { font-size: 0.75rem; }
        }

        @media (max-width: 480px) {
            .nav-brand span { font-size: 0.95rem; }
            .nav-brand i { font-size: 1.1rem !important; }
            .prestige-nav-link { font-size: 0.6rem; }
            .prestige-nav-link i { font-size: 0.9rem; }
            
            .registry-header { padding: 20px 15px; }
            .registry-body { padding: 20px 15px; }
            .registry-card { border-radius: 0; border-left: none; border-right: none; }
            .registry-container { padding: 0; }
        }

        .not-published-box {
            text-align: center;
            padding: 60px 20px;
        }
        .not-published-box i {
            font-size: 4rem;
            color: #94a3b8;
            margin-bottom: 20px;
        }
        .not-published-box h3 {
            font-family: 'Playfair Display', serif;
            color: var(--prestige-navy);
            margin-bottom: 10px;
        }
        [data-theme="dark"] .not-published-box h3 {
            color: #cbd5e1;
        }

        /* Fixed Value Display */
        .fixed-value-box {
            background: rgba(15, 23, 42, 0.03);
            border: 1.5px dashed var(--prestige-border);
            padding: 12px 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            height: 48px;
        }
        [data-theme="dark"] .fixed-value-box {
            background: rgba(255, 255, 255, 0.03);
        }
        .fixed-value-box span {
            font-weight: 700;
            color: var(--prestige-navy);
            font-size: 0.9rem;
        }
        [data-theme="dark"] .fixed-value-box span {
            color: #e2e8f0;
        }
    </style>
</head>

<body>
    <!-- Global Prestige Navigation -->
    <nav class="prestige-nav-global">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="nav-brand-wrapper">
                <a href="index.php" class="nav-brand">
                    <i class="fas fa-university text-warning" style="font-size: 1.2rem;"></i>
                    <span>ATN GIRLS HIGH SCHOOL</span>
                </a>
                <button id="dmTogglePubMobile" class="dm-toggle-pub d-md-none" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
            
            <div class="nav-links-container">
                <a href="index.php" class="prestige-nav-link">
                    <i class="fas fa-search-dollar"></i>
                    <span>Result Portal</span>
                </a>
                <?php if ($admit_cards_published): ?>
                <a href="admit-card.php" class="prestige-nav-link active">
                    <i class="fas fa-id-card"></i>
                    <span>Admit Card</span>
                </a>
                <?php endif; ?>
                <a href="hall-of-fame.php" class="prestige-nav-link">
                    <i class="fas fa-award"></i>
                    <span>Hall of Fame</span>
                </a>
                <button id="dmTogglePub" class="dm-toggle-pub ms-2 d-none d-md-flex" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </nav>

    <header class="prestige-header">
        <div class="container">
            <span class="hero-badge">Student Admit Card Portal</span>
            <h1 class="serif-font display-5 fw-bold mb-2">Examination Access</h1>
            <p class="opacity-75 small">Download your official admit card for the <strong><?php echo $current_admit_exam; ?> (<?php echo $current_admit_year; ?>)</strong> cycle.</p>
        </div>
    </header>

    <div class="registry-container">
        <div class="registry-card">
            <?php if (!$admit_cards_published): ?>
            <div class="not-published-box">
                <i class="fas fa-lock"></i>
                <h3>Admit Cards Unavailable</h3>
                <p class="text-muted">Admit cards for the current examination cycle have not been published yet.<br>Please check back later or contact the administration.</p>
            </div>
            <?php else: ?>
            <div class="registry-header">
                <h2 class="serif-font">Card Retrieval</h2>
                <p class="text-muted small mb-0">Enter student credentials to generate your admit card.</p>
            </div>

            <form action="print-admit-card.php" method="POST" target="_blank" class="registry-body">
                <!-- Hidden Fixed Settings -->
                <input type="hidden" name="year" value="<?php echo $current_admit_year; ?>">
                <input type="hidden" name="exam_type" value="<?php echo $current_admit_exam; ?>">

                <div class="row g-4">
                    <!-- Roll Number -->
                    <div class="col-md-6">
                        <label class="form-label">Official Roll ID</label>
                        <input type="number" name="roll" class="prestige-input" placeholder="e.g. 1001" required>
                    </div>

                    <!-- Academic Year (Fixed) -->
                    <div class="col-md-6">
                        <label class="form-label">Session Period</label>
                        <div class="fixed-value-box">
                            <i class="fas fa-calendar-alt text-warning"></i>
                            <span><?php echo $current_admit_year; ?></span>
                        </div>
                    </div>

                    <!-- Exam Type (Fixed) -->
                    <div class="col-md-6">
                        <label class="form-label">Examination Cycle</label>
                        <div class="fixed-value-box">
                            <i class="fas fa-file-invoice text-warning"></i>
                            <span><?php echo $current_admit_exam; ?></span>
                        </div>
                    </div>

                    <!-- DOB -->
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth (Security)</label>
                        <input type="text" name="dob" id="dob-picker" class="prestige-input" placeholder="YYYY-MM-DD"
                            required>
                    </div>

                    <!-- Class -->
                    <div class="col-md-6">
                        <label class="form-label">Level of Study</label>
                        <div class="custom-select-wrapper">
                            <select name="class_name" id="class_name" class="native-select" required>
                                <option value="">Select Level</option>
                                <?php 
                                $unique_classes = array_unique(array_column($classes_data, 'class_name'));
                                foreach ($unique_classes as $cls): ?>
                                    <option value="<?php echo $cls; ?>"><?php echo $cls; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="custom-select-trigger">
                                <span>Select Level</span>
                                <i class="fas fa-chevron-down opacity-50"></i>
                            </div>
                            <div class="custom-options" id="class-options">
                                <div class="custom-option" data-value="">Select Level</div>
                                <?php foreach ($unique_classes as $cls): ?>
                                    <div class="custom-option" data-value="<?php echo $cls; ?>"><?php echo $cls; ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section -->
                    <div class="col-md-6">
                        <label class="form-label">Enrollment Section</label>
                        <div class="custom-select-wrapper">
                            <select name="section" id="section" class="native-select" required disabled>
                                <option value="">Select Section</option>
                            </select>
                            <div class="custom-select-trigger disabled">
                                <span>Select Section</span>
                                <i class="fas fa-chevron-down opacity-50"></i>
                            </div>
                            <div class="custom-options" id="section-options">
                                <div class="custom-option" data-value="">Select Section</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn-retrieve">
                            <i class="fas fa-download"></i> DOWNLOAD ADMIT CARD
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <footer class="portal-footer">
        <div class="container">
            <div class="footer-links">

                <a href="admin/login.php" class="footer-link">
                    <i class="fas fa-user-shield"></i> Administration
                </a>
            </div>
            <p class="copyright mb-0">&copy; <?php echo date('Y'); ?> Education Board | Official Publication Portal</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <?php if ($admit_cards_published): ?>
    <script>
        // Dark Mode
        window.toggleDarkMode = function() {
            const html = document.documentElement;
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('srms-theme', next);
            const btns = [document.getElementById('dmTogglePub'), document.getElementById('dmTogglePubMobile')];
            btns.forEach(btn => {
                if (btn) btn.innerHTML = next === 'dark' ? '<i class="fas fa-sun" style="color:#f59e0b"></i>' : '<i class="fas fa-moon"></i>';
            });
        };
        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const btns = [document.getElementById('dmTogglePub'), document.getElementById('dmTogglePubMobile')];
            btns.forEach(btn => {
                if (btn && isDark) btn.innerHTML = '<i class="fas fa-sun" style="color:#f59e0b"></i>';
            });
        });

        const allData = <?php echo json_encode($classes_data); ?>;
        const classSelect = document.getElementById('class_name');
        const sectionSelect = document.getElementById('section');

        function setupCustomSelect(wrapper) {
            const trigger = wrapper.querySelector('.custom-select-trigger');
            const optionsContainer = wrapper.querySelector('.custom-options');
            const nativeSelect = wrapper.querySelector('.native-select');
            const triggerText = trigger.querySelector('span');

            trigger.addEventListener('click', (e) => {
                if (trigger.classList.contains('disabled')) return;
                e.stopPropagation();
                document.querySelectorAll('.custom-select-wrapper').forEach(w => {
                    if (w !== wrapper) w.classList.remove('open');
                });
                wrapper.classList.toggle('open');
            });

            optionsContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('custom-option')) {
                    const value = e.target.getAttribute('data-value');
                    const text = e.target.textContent.trim();
                    nativeSelect.value = value;
                    triggerText.textContent = text;
                    optionsContainer.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                    e.target.classList.add('selected');
                    wrapper.classList.remove('open');
                    nativeSelect.dispatchEvent(new Event('change'));
                }
            });
        }

        document.querySelectorAll('.custom-select-wrapper').forEach(setupCustomSelect);
        document.addEventListener('click', () => {
            document.querySelectorAll('.custom-select-wrapper').forEach(w => w.classList.remove('open'));
        });

        classSelect.addEventListener('change', function () {
            const selectedClass = this.value;
            const sectionWrapper = sectionSelect.closest('.custom-select-wrapper');
            const sectionTrigger = sectionWrapper.querySelector('.custom-select-trigger');
            const sectionOptionsContainer = sectionWrapper.querySelector('.custom-options');
            const sectionTriggerText = sectionTrigger.querySelector('span');

            sectionSelect.value = "";
            sectionTriggerText.textContent = "Select Section";
            sectionTrigger.classList.add('disabled');
            sectionSelect.disabled = true;

            if (!selectedClass) return;

            const validSections = allData.filter(item => item.class_name == selectedClass).map(item => item.section).sort();
            let nativeHtml = '<option value="">Select Section</option>';
            let customHtml = '<div class="custom-option" data-value="">Select Section</div>';
            validSections.forEach(sec => {
                nativeHtml += `<option value="${sec}">${sec}</option>`;
                customHtml += `<div class="custom-option" data-value="${sec}">${sec}</div>`;
            });
            sectionSelect.innerHTML = nativeHtml;
            sectionOptionsContainer.innerHTML = customHtml;
            sectionTrigger.classList.remove('disabled');
            sectionSelect.disabled = false;
        });

        flatpickr("#dob-picker", {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "F j, Y",
            animate: true
        });
    </script>
    <?php else: ?>
    <script>
        window.toggleDarkMode = function() {
            const html = document.documentElement;
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('srms-theme', next);
            const btns = [document.getElementById('dmTogglePub'), document.getElementById('dmTogglePubMobile')];
            btns.forEach(btn => {
                if (btn) btn.innerHTML = next === 'dark' ? '<i class="fas fa-sun" style="color:#f59e0b"></i>' : '<i class="fas fa-moon"></i>';
            });
        };
        document.addEventListener('DOMContentLoaded', function() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const btns = [document.getElementById('dmTogglePub'), document.getElementById('dmTogglePubMobile')];
            btns.forEach(btn => {
                if (btn && isDark) btn.innerHTML = '<i class="fas fa-sun" style="color:#f59e0b"></i>';
            });
        });
    </script>
    <?php endif; ?>
</body>

</html>
