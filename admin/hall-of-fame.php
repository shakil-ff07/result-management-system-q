<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

// Fetch unique academic years from results
$years_stmt = $conn->query("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year ASC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$academic_year = $_GET['academic_year'] ?? (end($years) ?: date('Y'));
$exam_type = $_GET['exam_type'] ?? null;
$selected_class_name = $_GET['class_name'] ?? null;

// Fetch classes for the selected year
$classes_stmt = $conn->prepare("SELECT DISTINCT class_name FROM classes WHERE academic_year = ? ORDER BY LENGTH(class_name), class_name");
$classes_stmt->execute([$academic_year]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$selected_class_name && !empty($classes)) {
    $selected_class_name = $classes[0];
}

$toppers = [];
if ($selected_class_name && $academic_year && $exam_type) {
    // Fetch Top 10 across all sections of this class for this year
    $query = "
        SELECT fr.*, s.name, s.roll_number, c.section
        FROM final_results fr
        JOIN students s ON fr.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE c.class_name = ? AND c.academic_year = ? AND fr.exam_type = ? AND fr.final_grade != 'F'
        ORDER BY fr.total_gpa DESC, fr.total_marks DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute([$selected_class_name, $academic_year, $exam_type]);
    $toppers = $stmt->fetchAll();
}

// Subject Toppers Logic (Top 1 per subject across the entire school year)
$subject_toppers = [];
if ($academic_year && $exam_type) {
    $subject_toppers_query = "
        SELECT m.subject_id, su.subject_name, m.total_marks, s.name, c.class_name, c.section, c.academic_year
        FROM marks m
        JOIN subjects su ON m.subject_id = su.id
        JOIN students s ON m.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        JOIN class_subjects cs ON cs.class_id = c.id AND cs.subject_id = m.subject_id
        WHERE c.academic_year = ? AND m.exam_type = ?
        AND m.total_marks = (
            SELECT MAX(total_marks) FROM marks 
            WHERE subject_id = m.subject_id 
            AND exam_type = ?
            AND student_id IN (SELECT id FROM students WHERE class_id IN (SELECT id FROM classes WHERE academic_year = ?))
        )
        GROUP BY m.subject_id ORDER BY LENGTH(c.class_name) ASC, c.class_name ASC, cs.is_optional ASC, cs.is_school_based ASC, su.id ASC
    ";
    $st_stmt = $conn->prepare($subject_toppers_query);
    $st_stmt->execute([$academic_year, $exam_type, $exam_type, $academic_year]);
    $subject_toppers = $st_stmt->fetchAll();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Hall of Fame - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .topper-card {
            border: none;
            border-radius: 12px;
            transition: 0.3s;
            background: linear-gradient(135deg, #ffffff 0%, #f1f4ff 100%);
        }

        .topper-card:hover {
            transform: scale(1.02);
            box-shadow: 0 15px 30px rgba(78, 115, 223, 0.15) !important;
        }

        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .rank-1 {
            background: #ffd700;
            color: #856404;
            box-shadow: 0 4px 10px rgba(255, 215, 0, 0.4);
        }

        .rank-2 {
            background: #c0c0c0;
            color: #383d41;
        }

        .rank-3 {
            background: #cd7f32;
            color: #fff;
        }

        .rank-other {
            background: #f8f9fc;
            color: #4e73df;
        }

        .topper-card h5 {
            font-size: 0.9rem;
        }

        .topper-card .small {
            font-size: 0.7rem;
        }

        .topper-card h4 {
            font-size: 1.1rem;
        }

        .topper-card .rank-badge {
            width: 30px;
            height: 30px;
            font-size: 0.9rem;
        }

        .topper-card .card-body {
            padding: 1rem !important;
        }

        .subject-badge {
            background: #eef2ff;
            color: #4e73df;
            border: 1px solid #d1d9ff;
            border-radius: 6px !important;
        }

        .gold-glow {
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.2) !important;
            border: 2px solid #ffd700 !important;
        }

        /* Professional Theme Overrides */
        .btn-primary {
            background-color: #1e293b;
            border-color: #1e293b;
        }

        .btn-primary:hover {
            background-color: #0f172a;
            border-color: #0f172a;
        }

        .btn-outline-primary {
            color: #1e293b;
            border-color: #1e293b;
        }

        .btn-outline-primary:hover {
            background-color: #1e293b;
            border-color: #1e293b;
            color: #fff;
        }

        .text-primary {
            color: #1e293b !important;
        }

        .bg-primary {
            background-color: #1e293b !important;
        }

        .nav-pills .nav-link {
            color: #64748b;
            font-weight: 600;
        }

        .nav-pills .nav-link.active {
            background-color: #1e293b !important;
            color: #fff !important;
        }

        .nav-pills .nav-link:not(.active) i {
            color: #94a3b8;
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .topper-card {
            background: linear-gradient(135deg, #111827 0%, #1e293b 100%) !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .rank-other {
            background: #0a1020 !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .bg-white, [data-theme="dark"] .card-header.bg-white {
            background-color: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .bg-light {
            background-color: #0a1020 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .topper-card h5 {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .topper-card h4.text-dark {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .topper-card h4.text-primary {
            color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .topper-card .bg-white.text-primary {
            background-color: #050a14 !important;
            color: #cbd5e1 !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .subject-badge {
            background: rgba(79, 70, 229, 0.1) !important;
            color: #a5b4fc !important;
            border-color: rgba(79, 70, 229, 0.2) !important;
        }

        [data-theme="dark"] .nav-pills {
            background-color: #111827 !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .dropdown-menu {
            background-color: #111827 !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .dropdown-item {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .dropdown-item:hover {
            background-color: #1e293b !important;
        }

        [data-theme="dark"] .dropdown-item.active {
            background-color: var(--prestige-gold) !important;
            color: #000 !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .gold-glow {
            box-shadow: 0 0 25px rgba(255, 215, 0, 0.15) !important;
            border-color: var(--prestige-gold) !important;
        }

        /* Merit Cards (Matching public hall-of-fame.php card design) */
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap');

        @font-face {
            font-family: 'Kalpurush';
            src: url('../assets/fonts/kalpurush-webfont.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        body {
            font-family: 'Inter', 'Kalpurush', 'Noto Sans Bengali', sans-serif !important;
        }

        .merit-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: 0.3s;
            position: relative;
            overflow: hidden;
            height: 100%;
        }

        .merit-card:hover {
            transform: translateY(-5px);
            border-color: #b45309;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
        }

        .merit-content {
            padding: 30px;
            text-align: center;
        }

        .merit-student-name {
            font-family: 'Playfair Display', 'Kalpurush', 'Noto Sans Bengali', serif;
            font-size: 1.25rem;
            margin-bottom: 5px;
            color: #0f172a;
            font-weight: 700;
        }

        .merit-student-meta {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 15px;
            display: block;
        }

        .merit-score-value {
            display: block;
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
        }

        .merit-text-gold {
            color: #b45309 !important;
        }

        /* Midnight Prestige - Dark Mode Overrides for Merit Card */
        [data-theme="dark"] .merit-card {
            background: #111827 !important;
            border-color: rgba(255,255,255,0.07) !important;
        }
        [data-theme="dark"] .merit-card:hover {
            border-color: rgba(245,158,11,0.35) !important;
            box-shadow: 0 15px 35px rgba(0,0,0,0.4) !important;
        }
        [data-theme="dark"] .merit-student-name {
            color: #e2e8f0 !important;
        }
        [data-theme="dark"] .merit-score-value {
            color: #e2e8f0 !important;
        }
        [data-theme="dark"] .merit-text-gold {
            color: #f59e0b !important;
        }
        [data-theme="dark"] .merit-card .badge.bg-dark.bg-opacity-10 {
            background-color: rgba(255, 255, 255, 0.05) !important;
            color: #f8fafc !important;
            border-color: rgba(255, 255, 255, 0.15) !important;
        }
        
        [data-theme="dark"] .header-divider {
            background-color: rgba(255, 255, 255, 0.15) !important;
        }

        /* Mobile layout optimization: Stacks the dropdown actions below title on mobile */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 16px;
            }
            .page-header .header-actions {
                width: 100%;
            }
            .page-header .header-actions > div {
                width: 100%;
                display: flex !important;
                justify-content: space-between;
                padding: 6px 12px !important;
            }
            .page-header .header-actions .dropdown {
                flex: 1;
            }
            .page-header .header-actions .dropdown button {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 10px 16px !important;
                font-size: 0.9rem !important;
            }
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-4">
            <!-- Header Section -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div>
                        <h1 class="fw-bold mb-0">Hall of Fame</h1>
                        <p class="mb-0">Celebrating excellence and academic achievement.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="d-flex align-items-center bg-white p-1 rounded-3 shadow-sm border">
                        <div class="dropdown me-1">
                            <button class="btn btn-sm btn-light rounded-3 px-3 dropdown-toggle border-0 fw-bold"
                                type="button" data-bs-toggle="dropdown">
                                <?php echo isset($_GET['academic_year']) ? 'Year: ' . $academic_year : 'Choose Year'; ?>
                            </button>
                            <ul class="dropdown-menu shadow-lg border-0 rounded-4">
                                <?php foreach ($years as $y): ?>
                                    <li><a class="dropdown-item <?php echo $y == $academic_year ? 'active' : ''; ?>"
                                            href="?academic_year=<?php echo $y; ?><?php echo $exam_type ? '&exam_type=' . $exam_type : ''; ?>&class_name=<?php echo urlencode($selected_class_name); ?>"><?php echo $y; ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="header-divider" style="width: 1px; height: 20px; background: #eee;"></div>
                        <div class="dropdown ms-1">
                            <button
                                class="btn btn-sm btn-light rounded-3 px-3 dropdown-toggle border-0 fw-bold text-primary"
                                type="button" data-bs-toggle="dropdown">
                                <?php echo isset($_GET['exam_type']) ? $exam_type : 'Choose Exam'; ?>
                            </button>
                            <ul class="dropdown-menu shadow-lg border-0 rounded-4">
                                <li><a class="dropdown-item <?php echo $exam_type == 'Half Yearly' ? 'active' : ''; ?>"
                                        href="?academic_year=<?php echo $academic_year; ?>&exam_type=Half Yearly&class_name=<?php echo urlencode($selected_class_name); ?>">Half
                                        Yearly</a></li>
                                <li><a class="dropdown-item <?php echo $exam_type == 'Final' ? 'active' : ''; ?>"
                                        href="?academic_year=<?php echo $academic_year; ?>&exam_type=Final&class_name=<?php echo urlencode($selected_class_name); ?>">Final
                                        Exam</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-pills mb-4 bg-white p-2 rounded-3 shadow-sm d-inline-flex" id="pills-tab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-3 px-4" data-bs-toggle="pill" data-bs-target="#pills-class"
                        type="button"><i class="fas fa-medal me-2"></i> Class
                        Toppers</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-3 px-4" data-bs-toggle="pill" data-bs-target="#pills-subject"
                        type="button"><i class="fas fa-star me-2"></i> Subject Genius</button>
                </li>
            </ul>

            <div class="tab-content" id="pills-tabContent">
                <!-- Class Toppers Tab -->
                <div class="tab-pane fade show active" id="pills-class">
                    <div class="card border-0 shadow-sm mb-4 rounded-4 overflow-hidden">
                        <div class="card-body p-0">
                            <div class="d-flex overflow-auto p-3 bg-light border-bottom">
                                <?php foreach ($classes as $c): ?>
                                    <a href="?academic_year=<?php echo urlencode($academic_year); ?>&exam_type=<?php echo urlencode($exam_type); ?>&class_name=<?php echo urlencode($c); ?>"
                                        class="btn btn-sm <?php echo $selected_class_name == $c ? 'btn-primary' : 'btn-outline-primary'; ?> rounded-3 px-4 me-2 flex-nowrap text-nowrap">
                                        <?php echo $c; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <div class="p-4">
                                <?php if (!$academic_year || !$exam_type): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-mouse-pointer fa-3x text-light mb-3"></i>
                                        <p class="text-muted">Please select an <strong>Academic Year</strong> and
                                            <strong>Exam Type</strong> above.
                                        </p>
                                    </div>
                                <?php elseif (!empty($toppers)): ?>
                                    <div
                                        class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 row-cols-xxl-5 g-3">
                                        <?php foreach ($toppers as $idx => $t):
                                            $rank = $idx + 1;
                                            $rankClass = ($rank <= 3) ? "rank-$rank" : "rank-other";
                                            $cardBorder = ($rank == 1) ? "gold-glow" : "";
                                            ?>
                                            <div class="col">
                                                <div class="card topper-card shadow-sm h-100 <?php echo $cardBorder; ?>">
                                                    <div class="card-body p-4">
                                                        <div class="d-flex align-items-center mb-3">
                                                            <div class="rank-badge <?php echo $rankClass; ?> me-3 shadow-sm">
                                                                <?php echo $rank; ?>
                                                            </div>
                                                            <div>
                                                                <h5 class="fw-bold mb-0">
                                                                    <?php echo strtoupper($t['name']); ?>
                                                                </h5>
                                                                <span class="text-muted small">Roll:
                                                                    <?php echo $t['roll_number']; ?> | Sec:
                                                                    <?php echo $t['section']; ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <hr class="opacity-10">
                                                        <div class="row text-center mt-3">
                                                            <div class="col-6 border-end">
                                                                <h4 class="fw-bold text-primary mb-0">
                                                                    <?php echo number_format($t['total_gpa'], 2); ?>
                                                                </h4>
                                                                <span class="text-muted small text-uppercase fw-bold">GPA</span>
                                                            </div>
                                                            <div class="col-6">
                                                                <h4 class="fw-bold text-dark mb-0">
                                                                    <?php echo $t['final_grade']; ?>
                                                                </h4>
                                                                <span
                                                                    class="text-muted small text-uppercase fw-bold">Grade</span>
                                                            </div>
                                                        </div>
                                                        <div
                                                            class="mt-3 bg-white p-2 rounded-3 text-center shadow-sm small fw-bold text-primary">
                                                            Total Marks:
                                                            <?php echo $t['total_marks']; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-search fa-3x text-light mb-3"></i>
                                        <p class="text-muted">No results generated yet for <strong>
                                                <?php echo $selected_class_name; ?>
                                            </strong>.</p>
                                        <a href="generate-results.php"
                                            class="btn btn-primary rounded-pill px-4 mt-2">Generate Now</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subject Toppers Tab -->
                <div class="tab-pane fade" id="pills-subject">
                    <div class="row g-4">
                        <?php if (!empty($subject_toppers)): ?>
                            <?php foreach ($subject_toppers as $st): ?>
                                <div class="col-xl-3 col-md-6">
                                    <div class="merit-card">
                                        <div class="merit-content">
                                            <span class="badge bg-dark bg-opacity-10 text-dark rounded-0 px-3 py-2 mb-3 fw-bold border border-dark text-wrap lh-base w-100">
                                                <?php echo strtoupper($st['subject_name']); ?>
                                            </span>
                                            <h3 class="merit-student-name"><?php echo strtoupper($st['name']); ?></h3>
                                            <span class="merit-student-meta mb-3"><?php echo $st['class_name']; ?>
                                                (<?php echo $st['section']; ?>)</span>

                                            <div class="d-flex align-items-center justify-content-center gap-3 border-top pt-3">
                                                <div class="text-center">
                                                    <span class="merit-score-value merit-text-gold"><?php echo (int)$st['total_marks']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-5">
                                <p class="text-muted">No subject toppers recorded for this session.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>