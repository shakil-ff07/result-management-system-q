<?php
include('includes/db_config.php');

// Fetch unique academic years from results
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$academic_year = $_GET['academic_year'] ?? null;
$exam_type = $_GET['exam_type'] ?? null;
$selected_class_name = $_GET['class_name'] ?? null;

$settings = $conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$admit_cards_published = ($settings['admit_cards_published'] ?? '0') === '1';

// User's Calendar-Based Logic
if (empty($academic_year) && empty($exam_type)) {
    $current_month = (int)date('n');
    $current_year = date('Y');
    $previous_year = $current_year - 1;

    if ($current_month >= 1 && $current_month <= 7) {
        // Months 1-7: Current Year Half Yearly, fallback to Previous Year Final
        $check = $conn->prepare("
            SELECT COUNT(*) FROM final_results fr 
            JOIN students s ON fr.student_id = s.id 
            JOIN classes c ON s.class_id = c.id 
            WHERE c.academic_year = ? AND fr.exam_type = 'Half Yearly'
        ");
        $check->execute([(string)$current_year]);
        
        if ($check->fetchColumn() > 0) {
            $academic_year = (string)$current_year;
            $exam_type = 'Half Yearly';
        } else {
            $academic_year = (string)$previous_year;
            $exam_type = 'Final';
        }
    } else {
        // Months 8-12: Current Year Final, fallback to Current Year Half Yearly
        $check = $conn->prepare("
            SELECT COUNT(*) FROM final_results fr 
            JOIN students s ON fr.student_id = s.id 
            JOIN classes c ON s.class_id = c.id 
            WHERE c.academic_year = ? AND fr.exam_type = 'Final'
        ");
        $check->execute([(string)$current_year]);
        
        if ($check->fetchColumn() > 0) {
            $academic_year = (string)$current_year;
            $exam_type = 'Final';
        } else {
            $academic_year = (string)$current_year;
            $exam_type = 'Half Yearly';
        }
    }
} elseif (!empty($academic_year) && empty($exam_type)) {
    // If only year is selected, find the best exam type for that year
    $latest_exam_stmt = $conn->prepare("
        SELECT fr.exam_type
        FROM final_results fr
        JOIN students s ON fr.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE c.academic_year = ?
        GROUP BY fr.exam_type
        ORDER BY CASE WHEN fr.exam_type = 'Final' THEN 2 ELSE 1 END DESC
        LIMIT 1
    ");
    $latest_exam_stmt->execute([$academic_year]);
    $exam = $latest_exam_stmt->fetchColumn();
    $exam_type = $exam ?: 'Final';
}

// Fetch classes for the selected year
$classes = [];
if ($academic_year) {
    $classes_stmt = $conn->prepare("SELECT DISTINCT class_name FROM classes WHERE academic_year = ? ORDER BY LENGTH(class_name), class_name");
    $classes_stmt->execute([$academic_year]);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);
}

if (!$selected_class_name && !empty($classes)) {
    $selected_class_name = $classes[0];
}

$toppers = [];
if ($selected_class_name && $academic_year && $exam_type) {
    $query = "
        SELECT fr.*, s.name, s.roll_number, c.section, c.class_name
        FROM final_results fr
        JOIN students s ON fr.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE c.class_name = ? AND c.academic_year = ? AND fr.exam_type = ?
        AND fr.final_grade != 'F'
        ORDER BY fr.total_gpa DESC, fr.total_marks DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute([$selected_class_name, $academic_year, $exam_type]);
    $toppers = $stmt->fetchAll();
}

$subject_toppers = [];
if ($selected_class_name && $academic_year && $exam_type) {
    $subject_query = "
        SELECT m.subject_id, su.subject_name, m.total_marks, s.name, c.class_name, c.section
        FROM marks m
        JOIN subjects su ON m.subject_id = su.id
        JOIN students s ON m.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN class_subjects cs ON cs.class_id = c.id AND cs.subject_id = m.subject_id
        WHERE c.class_name = ? AND c.academic_year = ? AND m.exam_type = ?
        AND m.grade != 'F'
        AND m.total_marks = (
            SELECT MAX(tm.total_marks) FROM marks tm
            JOIN students ts ON tm.student_id = ts.id
            JOIN classes tc ON ts.class_id = tc.id
            JOIN class_subjects tcs ON tcs.class_id = tc.id AND tcs.subject_id = tm.subject_id
            WHERE tm.subject_id = m.subject_id 
            AND tm.exam_type = m.exam_type
            AND tm.grade != 'F'
            AND tc.class_name = ? 
            AND tc.academic_year = ?
        )
        GROUP BY m.subject_id ORDER BY cs.is_optional ASC, cs.is_school_based ASC, su.id ASC
    ";
    $st_stmt = $conn->prepare($subject_query);
    $st_stmt->execute([$selected_class_name, $academic_year, $exam_type, $selected_class_name, $academic_year]);
    $subject_toppers = $st_stmt->fetchAll();
}
?>

<script>(function(){var t=localStorage.getItem('srms-theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hall of Fame | Prestige Academic Records</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Kalpurush';
            src: url('assets/fonts/kalpurush-webfont.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        :root {
            --prestige-navy: #0f172a;
            --prestige-gold: #b45309;
            --prestige-gold-light: #fef3c7;
            --prestige-slate: #f8fafc;
            --prestige-border: #e2e8f0;
        }

        body {
            font-family: 'Inter', 'Kalpurush', 'Noto Sans Bengali', sans-serif;
            background-color: var(--prestige-slate);
            color: #1e293b;
            overflow-x: hidden;
        }

        .serif-font {
            font-family: 'Playfair Display', 'Kalpurush', 'Noto Sans Bengali', serif;
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

        /* Floating Control Panel */
        .floating-controls {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(10px);
            padding: 15px 25px;
            border-radius: 50px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .floating-controls:hover {
            transform: translateX(-50%) translateY(-5px);
            background: rgba(15, 23, 42, 1);
        }

        .control-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: 0.2s;
            white-space: nowrap;
        }

        .control-btn:hover {
            background: var(--prestige-gold);
            border-color: var(--prestige-gold);
            color: white;
        }

        @keyframes pulse-selection {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        .control-btn.bg-warning {
            background: #0f172a !important; /* Deep Navy */
            border: 1px solid #d97706 !important; /* Amber-Gold Border */
            color: #fbbf24 !important; /* Elegant Gold Text */
            animation: none !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            transition: all 0.3s ease !important;
        }

        .control-btn.bg-warning:hover {
            transform: translateY(-2px);
            background: #1e293b !important;
            box-shadow: 0 8px 25px rgba(217, 119, 6, 0.2) !important;
            border-color: #fbbf24 !important;
            color: #fff !important;
        }

        [data-theme="dark"] .control-btn.bg-warning {
            background: rgba(255, 255, 255, 0.03) !important;
            border-color: #d97706 !important;
        }

        .class-picker-modal .modal-content {
            background: var(--prestige-navy);
            color: white;
            border: 1px solid var(--prestige-gold);
            border-radius: 20px;
        }

        .modal-class-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            text-decoration: none;
            display: block;
            transition: 0.2s;
            margin-bottom: 10px;
        }

        .modal-class-btn:hover {
            background: var(--prestige-gold);
            color: white;
            transform: scale(1.02);
        }

        .modal-class-btn.active {
            background: var(--prestige-gold);
            border-color: var(--prestige-gold);
        }

        /* Hero Section */
        .prestige-hero {
            background: var(--prestige-navy);
            padding: 40px 0 15px;
            color: white;
            text-align: center;
            position: relative;
            border-bottom: 4px solid var(--prestige-gold);
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            font-size: 0.8rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 16px;
            color: var(--prestige-gold-light);
        }

        /* Filter Architecture */
        .filter-container {
            max-width: 900px;
            margin: -48px auto 40px;
            position: relative;
            z-index: 100;
        }

        .prestige-filter-bar {
            background: white;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--prestige-border);
        }

        .filter-group {
            flex: 1;
            position: relative;
        }

        .filter-label {
            position: absolute;
            top: -29px;
            left: 20px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 1px;
            transition: 0.3s;
        }

        .filter-group:hover .filter-label {
            color: var(--prestige-navy);
        }

        .prestige-btn {
            width: 100%;
            height: 48px;
            padding: 0 20px;
            background: var(--prestige-slate);
            border: 1px solid var(--prestige-border);
            border-radius: 6px;
            font-weight: 600;
            color: var(--prestige-navy);
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: 0.2s;
        }

        .prestige-btn:hover {
            border-color: var(--prestige-gold);
            background: white;
        }

        .portal-btn {
            background: var(--prestige-navy);
            color: white;
            padding: 0 24px;
            height: 48px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--prestige-navy);
        }

        /* Navigation */
        .prestige-nav {
            justify-content: center;
            gap: 10px;
            margin-bottom: 0px;
            background: #f1f5f9;
            padding: 6px;
            border-radius: 50px;
            display: inline-flex;
            left: 50%;
            transform: translateX(-50%);
            position: relative;
            border: 1px solid #e2e8f0;
        }

        .prestige-nav .nav-link {
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.8rem;
            padding: 12px 25px;
            border-radius: 40px !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
        }

        .prestige-nav .nav-link:hover {
            color: var(--prestige-navy);
            background: rgba(15, 23, 42, 0.05);
        }

        .prestige-nav .nav-link.active {
            background: white !important;
            color: var(--prestige-gold) !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0 !important;
        }

        /* Merit Cards */
        .merit-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 8px;
            transition: 0.3s;
            position: relative;
            overflow: hidden;
            height: 100%;
        }

        .merit-card:hover {
            transform: translateY(-5px);
            border-color: var(--prestige-gold);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
        }

        .rank-indicator {
            position: absolute;
            top: 0px;
            right: 0px;
            width: 32px;
            height: 32px;
            background: var(--prestige-slate);
            border: 1px solid var(--prestige-border);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
            color: var(--prestige-navy);
        }

        .rank-1 .rank-indicator {
            background: var(--prestige-gold);
            color: white;
            border-color: var(--prestige-gold);
        }

        .merit-content {
            padding: 30px;
            text-align: center;
        }

        .student-name {
            font-size: 1.25rem;
            margin-bottom: 5px;
            color: var(--prestige-navy);
        }

        .student-meta {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 15px;
            display: block;
        }

        .score-grid {
            display: flex;
            border-top: 1px solid var(--prestige-border);
            padding-top: 20px;
        }

        .score-item {
            flex: 1;
        }

        .score-value {
            display: block;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--prestige-navy);
        }

        .score-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #94a3b8;
        }

        .total-badge {
            margin-top: 20px;
            padding: 8px;
            background: var(--prestige-slate);
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--prestige-gold);
        }

        /* Secondary Nav (Class Selection) */
        .class-select-bar {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            border: 1px solid var(--prestige-border);
        }

        .class-btn {
            padding: 8px 24px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            text-decoration: none;
            transition: 0.2s;
            border: 1px solid var(--prestige-border);
            color: var(--prestige-navy);
        }

        .class-btn.active {
            background: var(--prestige-navy);
            color: white;
            border-color: var(--prestige-navy);
        }

        /* Empty State */
        .prestige-empty {
            text-align: center;
            padding: 0px 0;
            background: white;
            border-radius: 12px;
            border: 2px dashed var(--prestige-border);
        }

        .prestige-empty i {
            color: var(--prestige-border);
            font-size: 4rem;
            margin-bottom: 20px;
        }

        /* Dropdown Styling */
        .dropdown-menu {
            border: 1px solid var(--prestige-border);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            padding: 8px;
        }

        .dropdown-item {
            border-radius: 4px;
            padding: 10px 15px;
            font-weight: 500;
            color: var(--prestige-navy);
            transition: all 0.2s;
        }

        .dropdown-menu-dark .dropdown-item {
            color: rgba(255, 255, 255, 0.75);
        }

        .dropdown-item:hover {
            background: var(--prestige-slate);
            color: var(--prestige-gold);
        }

        .dropdown-menu-dark .dropdown-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .dropdown-item.active {
            background: var(--prestige-navy);
            color: white;
        }

        .dropdown-menu-dark .dropdown-item.active {
            background: var(--prestige-gold);
            color: white;
        }

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
            
            .prestige-hero { padding: 30px 0 20px; }
            .prestige-hero h1 { font-size: 1.75rem; }
            .hero-badge { font-size: 0.65rem; padding: 6px 12px; }
            
            .filter-container { margin-top: -20px; margin-bottom: 20px; }
            .prestige-filter-bar { padding: 5px; border-radius: 6px; }
            .prestige-btn { height: 44px; font-size: 0.8rem; }
            .portal-btn { height: 44px; padding: 0 15px; font-size: 0.8rem; }
            
            .prestige-nav .nav-link { padding: 8px 15px; font-size: 0.7rem; }
            
            .floating-controls {
                bottom: 15px;
                padding: 8px 12px;
                gap: 6px;
                width: auto;
                max-width: 95vw;
                border-radius: 12px;
            }

            .control-btn {
                padding: 6px 10px;
                font-size: 0.75rem;
                border-radius: 8px;
            }

            .control-btn i {
                margin-right: 4px !important;
                font-size: 0.8rem;
            }

            .floating-controls .ms-3 {
                margin-left: 0.5rem !important;
            }
        }

        @media (max-width: 480px) {
            .nav-brand span { font-size: 0.95rem; }
            .nav-brand i { font-size: 1.1rem !important; }
            .prestige-nav-link { font-size: 0.6rem; }
            .prestige-nav-link i { font-size: 0.9rem; }
            .merit-content { padding: 20px; }
        }

        /* Midnight Prestige - Dark Mode for Hall of Fame */
        body, .merit-card, .prestige-filter-bar, .class-select-bar,
        .modal-content, .dropdown-menu { transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease !important; }

        [data-theme="dark"] body { background-color: #060c18 !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .prestige-hero { background: #030710 !important; }

        /* Filter bar */
        [data-theme="dark"] .filter-container .prestige-filter-bar { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; box-shadow: 0 10px 25px rgba(0,0,0,0.4) !important; }
        [data-theme="dark"] .prestige-btn { background: #0a1020 !important; border-color: rgba(255,255,255,0.1) !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .prestige-btn:hover { border-color: #f59e0b !important; background: #060c18 !important; }
        [data-theme="dark"] .filter-label { color: #4b5563 !important; }
        [data-theme="dark"] .portal-btn { background: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }

        /* Nav pills */
        [data-theme="dark"] .prestige-nav { background: #0a1020 !important; border-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .prestige-nav .nav-link { color: #6b7280 !important; }
        [data-theme="dark"] .prestige-nav .nav-link:hover { background: rgba(255,255,255,0.04) !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .prestige-nav .nav-link.active { background: #111827 !important; color: #f59e0b !important; border-color: rgba(255,255,255,0.08) !important; box-shadow: 0 4px 15px rgba(0,0,0,0.3) !important; }

        /* Class select bar */
        [data-theme="dark"] .class-select-bar { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .class-btn { border-color: rgba(255,255,255,0.08) !important; color: #94a3b8 !important; }
        [data-theme="dark"] .class-btn:hover { background: rgba(245,158,11,0.1) !important; color: #f59e0b !important; border-color: rgba(245,158,11,0.3) !important; }
        [data-theme="dark"] .class-btn.active { background: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }

        /* Merit cards */
        [data-theme="dark"] .merit-card { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .merit-card:hover { border-color: rgba(245,158,11,0.35) !important; box-shadow: 0 15px 35px rgba(0,0,0,0.4) !important; }
        [data-theme="dark"] .student-name { color: #e2e8f0 !important; }
        [data-theme="dark"] .score-grid { border-top-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .score-value { color: #e2e8f0 !important; }
        [data-theme="dark"] .total-badge { background: rgba(245,158,11,0.1) !important; color: #f59e0b !important; }
        [data-theme="dark"] .rank-indicator { background: #0a1020 !important; border-color: rgba(255,255,255,0.08) !important; color: #e2e8f0 !important; }
        [data-theme="dark"] .rank-1 .rank-indicator { background: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }

        /* Empty state */
        [data-theme="dark"] .prestige-empty { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; }

        /* Floating controls */
        [data-theme="dark"] .floating-controls { background: rgba(5,10,20,0.95) !important; border-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .control-btn { border-color: rgba(255,255,255,0.12) !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .control-btn:hover { background: rgba(245,158,11,0.15) !important; border-color: #f59e0b !important; color: #f59e0b !important; }

        /* Dropdown */
        [data-theme="dark"] .dropdown-menu { background: #111827 !important; border-color: rgba(255,255,255,0.08) !important; box-shadow: 0 10px 30px rgba(0,0,0,0.5) !important; }
        [data-theme="dark"] .dropdown-item { color: #cbd5e1 !important; }
        [data-theme="dark"] .dropdown-item:hover { background: rgba(245,158,11,0.1) !important; color: #f59e0b !important; }
        [data-theme="dark"] .dropdown-item.active { background: rgba(245,158,11,0.2) !important; color: #f59e0b !important; }

        /* Class picker modal */
        [data-theme="dark"] .class-picker-modal .modal-content { background: #0f172a !important; border-color: rgba(245,158,11,0.4) !important; }
        [data-theme="dark"] .modal-class-btn { background: rgba(255,255,255,0.04) !important; border-color: rgba(255,255,255,0.08) !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .modal-class-btn:hover { background: rgba(245,158,11,0.15) !important; color: #f59e0b !important; }
        [data-theme="dark"] .modal-class-btn.active { background: #f59e0b !important; color: #0f172a !important; }
        [data-theme="dark"] .btn-close { filter: invert(1) !important; }
        [data-theme="dark"] .text-muted { color: #6b7280 !important; }

        /* Subject Badge Dark Mode Fix */
        [data-theme="dark"] .merit-card .badge.text-dark {
            background-color: rgba(245, 158, 11, 0.1) !important;
            color: #f59e0b !important;
            border-color: rgba(245, 158, 11, 0.3) !important;
        }

        /* Dark Mode nav toggle */
        .dm-toggle-pub {
            width: 34px; height: 34px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8); cursor: pointer;
            transition: all 0.2s ease; font-size: 0.85rem;
        }
        .dm-toggle-pub:hover { background: rgba(245,158,11,0.2); border-color: #f59e0b; color: #f59e0b; }
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
                <a href="admit-card.php" class="prestige-nav-link">
                    <i class="fas fa-id-card"></i>
                    <span>Admit Card</span>
                </a>
                <?php endif; ?>
                <a href="hall-of-fame.php" class="prestige-nav-link active">
                    <i class="fas fa-award"></i>
                    <span>Hall of Fame</span>
                </a>
                <button id="dmTogglePub" class="dm-toggle-pub ms-2 d-none d-md-flex" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Prestige Hero -->
    <header class="prestige-hero">
        <div class="container">
            <span class="hero-badge">Merit Recognition System</span>
            <h1 class="serif-font display-4 fw-bold mb-3">Hall of Fame</h1>
            <p class="lead opacity-75">Recognizing sustained academic excellence across the institution.</p>
        </div>
    </header>

    <!-- Floating Controls Panel -->
    <div class="floating-controls">
        <div class="dropdown">
            <button class="control-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-calendar-alt me-2"></i>
                <?php echo $academic_year ?: 'Session'; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-dark">
                <?php foreach ($years as $y): ?>
                    <li><a class="dropdown-item <?php echo $y == $academic_year ? 'active' : ''; ?>"
                            href="?academic_year=<?php echo $y; ?><?php echo $exam_type ? '&exam_type=' . $exam_type : ''; ?>"><?php echo $y; ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="dropdown">
            <button class="control-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-file-invoice me-2"></i>
                <?php echo $exam_type ?: 'Exam'; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-dark">
                <li><a class="dropdown-item <?php echo $exam_type == 'Half Yearly' ? 'active' : ''; ?>"
                        href="?academic_year=<?php echo $academic_year; ?>&exam_type=Half Yearly">Half Yearly</a></li>
                <li><a class="dropdown-item <?php echo $exam_type == 'Final' ? 'active' : ''; ?>"
                        href="?academic_year=<?php echo $academic_year; ?>&exam_type=Final">Final Examination</a></li>
            </ul>
        </div>

        <?php if ($academic_year && $exam_type): ?>
            <button class="control-btn bg-warning text-dark border-0 fw-bold d-flex align-items-center"
                data-bs-toggle="modal" data-bs-target="#classPickerModal">
                <i class="fas fa-graduation-cap me-2"></i>
                <div class="text-start">
                    <div style="font-size: 0.85rem; line-height: 1;"><?php echo $selected_class_name ?: 'Select Class'; ?>
                    </div>
                    <div class="opacity-75 d-none d-md-block" style="font-size: 0.6rem; letter-spacing: 0.5px;">
                        <?php echo count($classes); ?> CLASSES LOADED
                    </div>
                </div>
                <i class="fas fa-chevron-circle-down ms-2 ms-md-3 opacity-75"></i>
            </button>
        <?php endif; ?>
    </div>

    <!-- Class Picker Floating Window (Modal) -->
    <div class="modal fade class-picker-modal" id="classPickerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title serif-font fs-3">Select Academy Class</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="opacity-50 mb-4">Choose a class to view its top performers for
                        <?php echo $academic_year; ?> - <?php echo $exam_type; ?>.
                    </p>
                    <div class="row g-3">
                        <?php foreach ($classes as $c): ?>
                            <div class="col-md-4">
                                <a href="?academic_year=<?php echo urlencode($academic_year); ?>&exam_type=<?php echo urlencode($exam_type); ?>&class_name=<?php echo urlencode($c); ?>"
                                    class="modal-class-btn <?php echo $selected_class_name == $c ? 'active' : ''; ?>">
                                    <i class="fas fa-university mb-2 d-block"></i>
                                    <?php echo $c; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Navigation -->
    <ul class="nav nav-pills prestige-nav" id="pills-tab" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#class-toppers">
                <i class="fas fa-award me-2"></i> Class Toppers
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#subject-toppers">
                <i class="fas fa-lightbulb me-2"></i> Subject Geniuses
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Class Toppers Section -->
        <div class="tab-pane fade show active" id="class-toppers">
            <!-- Result Tabbed Content -->

            <?php if (!$academic_year || !$exam_type): ?>
                <div class="prestige-empty">
                    <i class="fas fa-feather-pointed"></i>
                    <h3 class="serif-font">Registry Record Locked</h3>
                    <p class="text-muted">Please select an Academic Year and Examination Type to access the archives.</p>
                </div>
            <?php elseif (empty($toppers)): ?>
                <div class="prestige-empty">
                    <i class="fas fa-folder-open"></i>
                    <h3 class="serif-font">No Records Found</h3>
                    <p class="text-muted">The results for <strong><?php echo $selected_class_name; ?></strong> have not been
                        finalized yet.</p>
                </div>
            <?php else: ?>
                <div class="row g-4 row-cols-1 row-cols-md-3 row-cols-xl-5">
                    <?php foreach ($toppers as $idx => $t):
                        $rank = $idx + 1;
                        ?>
                        <div class="col">
                            <div class="merit-card rank-<?php echo $rank; ?>">
                                <div class="rank-indicator"><?php echo $rank; ?></div>
                                <div class="merit-content">
                                    <span class="student-meta"><?php echo $t['class_name']; ?> |
                                        <?php echo $t['section']; ?></span>
                                    <h3 class="serif-font student-name"><?php echo strtoupper($t['name']); ?></h3>
                                    <span class="student-meta opacity-50">Roll: <?php echo $t['roll_number']; ?></span>

                                    <div class="score-grid">
                                        <div class="score-item border-end">
                                            <span class="score-value"><?php echo number_format($t['total_gpa'], 2); ?></span>
                                            <span class="score-label">GPA</span>
                                        </div>
                                        <div class="score-item">
                                            <span class="score-value"><?php echo $t['final_grade']; ?></span>
                                            <span class="score-label">Grade</span>
                                        </div>
                                    </div>
                                    <div class="total-badge">
                                        MARK: <?php echo $t['total_marks']; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Subject Toppers Section -->
        <div class="tab-pane fade" id="subject-toppers">
            <?php if (!$academic_year || !$exam_type): ?>
                <div class="prestige-empty">
                    <i class="fas fa-fingerprint"></i>
                    <h3 class="serif-font">Selection Required</h3>
                    <p class="text-muted">Identify a session and exam type to view subject brilliance.</p>
                </div>
            <?php elseif (empty($subject_toppers)): ?>
                <div class="prestige-empty">
                    <i class="fas fa-ghost"></i>
                    <h3 class="serif-font">Archive Is Empty</h3>
                    <p class="text-muted">No subject toppers recorded for this session yet.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($subject_toppers as $st): ?>
                        <div class="col-xl-3 col-md-6">
                            <div class="merit-card">
                                <div class="merit-content">
                                    <span
                                        class="badge bg-dark bg-opacity-10 text-dark rounded-0 px-3 py-2 mb-3 fw-bold border border-dark text-wrap lh-base w-100">
                                        <?php echo strtoupper($st['subject_name']); ?>
                                    </span>
                                    <h3 class="serif-font student-name"><?php echo strtoupper($st['name']); ?></h3>
                                    <span class="student-meta mb-3"><?php echo $st['class_name']; ?>
                                        (<?php echo $st['section']; ?>)</span>

                                    <div class="d-flex align-items-center justify-content-center gap-3 border-top pt-3">
                                        <div class="text-center">
                                            <span class="score-value text-gold"><?php echo (int)$st['total_marks']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <!-- Footer Space -->
    <div class="py-5"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show class picker if year/exam selected but class isn't (on initial load of year/exam)
        <?php if ($academic_year && $exam_type && !$selected_class_name): ?>
            window.addEventListener('load', () => {
                new bootstrap.Modal(document.getElementById('classPickerModal')).show();
            });
        <?php endif; ?>

        // Prevent Scroll Jump: Store and Restore Scroll Position
        document.querySelectorAll('a, .dropdown-item').forEach(el => {
            el.addEventListener('click', function (e) {
                // Only for links that reload the page
                if (this.href && this.href.includes('?')) {
                    sessionStorage.setItem('hall_scroll_pos', window.scrollY);
                }
            });
        });

        window.addEventListener('load', () => {
            const scrollPos = sessionStorage.getItem('hall_scroll_pos');
            if (scrollPos) {
                window.scrollTo(0, parseInt(scrollPos));
                sessionStorage.removeItem('hall_scroll_pos');
            }
        });

        // Midnight Prestige Dark Mode
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
</body>

</html>