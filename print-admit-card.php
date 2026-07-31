<?php
include('includes/db_config.php');

$settings = $conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$admit_cards_published = ($settings['admit_cards_published'] ?? '0') === '1';
$current_admit_year = $settings['current_admit_year'] ?? date('Y');
$current_admit_exam = $settings['current_admit_exam'] ?? 'Half Yearly';

if (!$admit_cards_published) {
    die("Admit cards are not currently published. Access denied.");
}

$roll = $_POST['roll'] ?? '';
$year = $current_admit_year; // Use official year from settings
$exam_type = $current_admit_exam; // Use official exam type from settings
$class_name = $_POST['class_name'] ?? '';
$section = $_POST['section'] ?? '';
$dob = $_POST['dob'] ?? '';

if (!$roll || !$year || !$class_name || !$section || !$dob || !$exam_type) {
    die("All fields are required. Please go back and fill the form.");
}

// Find the student securely
$stmt = $conn->prepare("
    SELECT s.*, c.class_name, c.section, c.academic_year
    FROM students s 
    JOIN classes c ON s.class_id = c.id
    WHERE s.roll_number = ? AND c.academic_year = ? AND c.class_name = ? AND c.section = ? AND s.dob = ?
");
$stmt->execute([$roll, $year, $class_name, $section, $dob]);
$std = $stmt->fetch();

if (!$std) {
    die("Student not found or incorrect credentials. Please verify your details.");
}

$class_id = $std['class_id'];
$students_to_print = [$std];

// Fetch Exam Schedule
$stmt = $conn->prepare("
    SELECT s.id as subject_id, s.subject_name, es.exam_date, es.start_time, es.end_time, es.student_group,
           cs.student_group as cs_group, cs.is_optional, cs.is_school_based
    FROM exam_schedule es
    JOIN subjects s ON es.subject_id = s.id
    JOIN class_subjects cs ON cs.subject_id = es.subject_id AND cs.class_id = es.class_id
    WHERE es.class_id = ? AND es.exam_type = ?
    ORDER BY cs.is_optional ASC, cs.is_school_based ASC, es.exam_date ASC, s.id ASC
");
$stmt->execute([$class_id, $exam_type]);
$all_schedule = $stmt->fetchAll();

if (empty($all_schedule)) {
    die("Exam schedule is not published or set up for this class yet. Please contact administration.");
}

// Fetch class info
$stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
$stmt->execute([$class_id]);
$class_info = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admit Card — <?= htmlspecialchars($exam_type) ?></title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&family=Playfair+Display:ital,wght@0,600;0,800;0,900;1,600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --primary-navy: #0f172a;
            --accent-gold: #b45309;
            --accent-gold-light: #d97706;
            --border-light: #cbd5e1;
            --bg-light: #f8fafc;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: #cbd5e1;
            font-family: 'Outfit', sans-serif;
            color: var(--primary-navy);
            margin: 0;
            padding: 20px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* ─── Print Controls Bar ─── */
        .print-controls {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* ─── Admit Card Container ─── */
        .admit-wrapper {
            transition: transform 0.2s ease-in-out;
            transform-origin: top center;
        }

        .admit-card {
            background: #ffffff;
            width: 210mm;
            height: 296mm;
            /* Force exactly A4 height */
            margin: 0 auto 30px auto;
            position: relative;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            page-break-after: always;
            padding: 15mm;
            /* Reduced padding */
        }

        .admit-card:last-child {
            page-break-after: auto;
        }

        /* Outer Double Border */
        .admit-card::before {
            content: '';
            position: absolute;
            top: 7mm;
            left: 7mm;
            right: 7mm;
            bottom: 10mm;
            border: 3px solid var(--primary-navy);
            pointer-events: none;
            z-index: 10;
        }

        /* Inner Gold Border */
        .admit-card::after {
            content: '';
            position: absolute;
            top: 8.5mm;
            left: 8.5mm;
            right: 8.5mm;
            bottom: 11.5mm;
            border: 1px solid var(--accent-gold);
            pointer-events: none;
            z-index: 10;
        }

        /* Subtle center watermark */
        .watermark-logo {
            position: absolute;
            top: 58%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 410px;
            opacity: 0.04;
            pointer-events: none;
            z-index: 0;
            filter: grayscale(100%);
        }

        /* ─── Top Header (Centered) ─── */
        .premium-header {
            text-align: center;
            position: relative;
            z-index: 2;
            margin-bottom: 12px;
        }

        .premium-header .logo {
            width: 50px;
            margin-bottom: 6px;
        }

        .premium-header .school-name {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            font-weight: 900;
            color: var(--primary-navy);
            letter-spacing: 0.5px;
            margin: 0 0 2px;
            text-transform: uppercase;
        }

        .premium-header .school-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* ─── Admit Card Banner ─── */
        .banner-wrap {
            text-align: center;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
        }

        .banner {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-navy), #1e293b);
            color: white;
            font-family: 'Playfair Display', serif;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 4px;
            padding: 5px 25px;
            border-radius: 4px;
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.2);
            position: relative;
        }

        .banner::after {
            content: '';
            position: absolute;
            top: 4px;
            left: 4px;
            right: 4px;
            bottom: 4px;
            border: 1px dashed rgba(255, 255, 255, 0.3);
            pointer-events: none;
        }

        /* ─── Student Info Grid ─── */
        .student-info-premium {
            background: var(--bg-light);
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 18px;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 8px 30px;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.02);
        }

        .info-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .info-label {
            font-size: 0.55rem;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--text-muted);
            letter-spacing: 1px;
        }

        .info-value {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary-navy);
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 3px;
        }

        .roll-group .info-value {
            font-size: 1.1rem;
            color: var(--accent-gold);
            font-family: 'Playfair Display', serif;
            font-weight: 900;
            border-bottom-color: rgba(180, 83, 9, 0.3);
        }

        /* ─── Premium Table ─── */
        .table-premium {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            position: relative;
            z-index: 2;
        }

        .table-premium th {
            background: var(--primary-navy);
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 8px 12px;
            text-align: left;
            border: none;
        }

        .table-premium th:first-child {
            border-radius: 6px 0 0 6px;
        }

        .table-premium th:last-child {
            border-radius: 0 6px 6px 0;
            text-align: center;
        }

        .table-premium td {
            padding: 7px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary-navy);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }

        .table-premium tr:nth-child(even) td {
            background: rgba(248, 250, 252, 0.6);
        }

        .table-premium tr:last-child td {
            border-bottom: none;
        }

        .time-badge {
            display: inline-block;
            background: rgba(15, 23, 42, 0.05);
            color: var(--primary-navy);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 800;
            border: 1px solid rgba(15, 23, 42, 0.08);
            text-align: center;
        }

        /* ─── Bottom Pinned Footer ─── */
        .card-bottom {
            position: absolute;
            bottom: 16mm;
            /* Inside the inner borders */
            left: 15mm;
            right: 15mm;
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            z-index: 2;
        }

        .sig-box {
            width: 150px;
            text-align: center;
        }

        .sig-line {
            border-top: 1.5px solid var(--primary-navy);
            margin-bottom: 4px;
        }

        .sig-label {
            font-size: 0.65rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--primary-navy);
        }

        /* ─── Outside Warning Note ─── */
        .admit-footer {
            position: absolute;
            bottom: 4mm;
            /* Outside the bottom border */
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.6rem;
            color: var(--text-muted);
            font-weight: 600;
            z-index: 2;
            letter-spacing: 0.5px;
        }

        /* ─── Zoom Controls ─── */
        .zoom-controls {
            position: fixed;
            top: 20px;
            left: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1100;
        }

        .zoom-btn {
            width: 44px;
            height: 44px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-navy);
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-decoration: none;
        }

        .zoom-btn:hover {
            background: var(--primary-navy);
            color: white;
            transform: translateY(-2px);
        }

        /* ─── Print Media ─── */
        @media print {

            html,
            body {
                background: none;
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-controls,
            .zoom-controls {
                display: none !important;
            }

            .admit-card {
                margin: 0 !important;
                box-shadow: none !important;
                width: 210mm;
                height: 296mm;
                /* Ensure it stays exactly A4 height when printing */
                padding: 15mm;
                page-break-after: always;
            }

            .admit-card:last-child {
                page-break-after: auto;
            }

            .admit-wrapper {
                transform: none !important;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }

        @media (max-width: 768px) {
            .print-controls {
                flex-wrap: wrap;
                gap: 10px;
                justify-content: center;
                padding: 12px;
            }

            .admit-wrapper {
                transform-origin: top left !important;
            }

            .admit-card {
                margin: 0 0 20px 0 !important;
            }

            .zoom-controls {
                top: 15px;
                left: 15px;
                flex-direction: row;
            }
        }
    </style>
</head>

<body>

    <!-- Zoom Controls -->
    <div class="zoom-controls">
        <button onclick="changeZoom(0.1)" class="zoom-btn" title="Zoom In"><i class="fas fa-plus"></i></button>
        <button onclick="changeZoom(-0.1)" class="zoom-btn" title="Zoom Out"><i class="fas fa-minus"></i></button>
        <button onclick="resetZoom()" class="zoom-btn" title="Reset"><i class="fas fa-expand"></i></button>
    </div>

    <!-- Control Bar -->
    <div class="print-controls">
        <div>
            <h6 class="mb-0 fw-bold">Official Admit Card</h6>
            <p class="text-muted mb-0" style="font-size:0.65rem;">
                <?= htmlspecialchars($std['name']) ?> &nbsp;|&nbsp;
                <?= htmlspecialchars($exam_type) ?> Examination
            </p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.close()" class="btn btn-outline-secondary px-3">
                <i class="fas fa-times me-1"></i>Close
            </button>
            <button onclick="window.print()" class="btn btn-dark px-3">
                <i class="fas fa-print me-1"></i>Print / Save PDF
            </button>
        </div>
    </div>

    <!-- Admit Cards -->
    <div class="admit-wrapper" id="admitWrapper">

        <?php foreach ($students_to_print as $std):
            // Filter schedule for this student (respects group and electives)
            $filtered_schedule = array_filter($all_schedule, function ($row) use ($std) {
                // Group check
                $grp = $row['cs_group'] ?? 'None';
                if ($grp !== 'None' && $grp !== $std['student_group']) {
                    return false;
                }

                $subj_id = $row['subject_id'];

                // If it is a compulsory subject (not optional)
                if ($row['is_optional'] == 0) {
                    return true;
                }

                // If it is an optional subject, it must be the student's main elective or 4th subject
                if ($subj_id == $std['main_elective_id'] || $subj_id == $std['optional_subject_id']) {
                    return true;
                }

                return false;
            });

            // Sort exactly like the result sheet: 
            // 1. Compulsory & Main Elective (effective_optional = 0) vs 4th Subject (effective_optional = 1)
            // 2. School Based (0 vs 1)
            // 3. Subject ID ASC
            usort($filtered_schedule, function ($a, $b) use ($std) {
                $a_eff_opt = ($a['subject_id'] == $std['optional_subject_id']) ? 1 : 0;
                $b_eff_opt = ($b['subject_id'] == $std['optional_subject_id']) ? 1 : 0;

                if ($a_eff_opt !== $b_eff_opt) {
                    return $a_eff_opt <=> $b_eff_opt;
                }
                if ($a['is_school_based'] !== $b['is_school_based']) {
                    return $a['is_school_based'] <=> $b['is_school_based'];
                }
                return $a['subject_id'] <=> $b['subject_id'];
            });

            $student_schedule = $filtered_schedule;
            ?>
            <div class="admit-card">

                <!-- Large Watermark Logo -->
                <img src="logo/atn.png" alt="" class="watermark-logo">

                <!-- Premium Centered Header -->
                <div class="premium-header">
                    <img src="logo/atn.png" alt="School Logo" class="logo">
                    <h1 class="school-name">ATN GIRLS HIGH SCHOOL</h1>
                    <div class="school-sub">
                        <?= htmlspecialchars($exam_type) ?> Examination &nbsp;&bull;&nbsp;
                        Academic Session <?= htmlspecialchars($class_info['academic_year'] ?? '') ?>
                    </div>
                </div>

                <!-- Admit Card Banner -->
                <div class="banner-wrap">
                    <div class="banner">Admit Card</div>
                </div>

                <!-- Student Info Premium Grid -->
                <div class="student-info-premium">
                    <!-- Left Column -->
                    <div class="d-flex flex-column" style="gap:8px;">
                        <div class="info-group">
                            <span class="info-label">Student Name</span>
                            <span class="info-value"><?= htmlspecialchars(strtoupper($std['name'])) ?></span>
                        </div>
                        <div class="info-group">
                            <span class="info-label">Father's Name</span>
                            <span class="info-value"><?= htmlspecialchars($std['father_name']) ?></span>
                        </div>
                        <div class="info-group">
                            <span class="info-label">Mother's Name</span>
                            <span class="info-value"><?= htmlspecialchars($std['mother_name']) ?></span>
                        </div>
                    </div>
                    <!-- Right Column -->
                    <div class="d-flex flex-column" style="gap:8px;">
                        <div class="info-group">
                            <span class="info-label">Class / Section</span>
                            <span class="info-value">
                                <?= htmlspecialchars($std['class_name'] ?? $class_info['class_name']) ?>
                                (<?= htmlspecialchars($std['section'] ?? $class_info['section']) ?>)
                            </span>
                        </div>
                        <div class="info-group roll-group">
                            <span class="info-label">Roll No.</span>
                            <span class="info-value">
                                <?= htmlspecialchars($std['roll_number']) ?>
                            </span>
                        </div>
                        <?php if ($std['student_group'] && $std['student_group'] !== 'None'): ?>
                            <div class="info-group">
                                <span class="info-label">Group</span>
                                <span class="info-value"><?= htmlspecialchars($std['student_group']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Examination Timetable -->
                <table class="table-premium">
                    <thead>
                        <tr>
                            <th width="40">SL</th>
                            <th>Subject</th>
                            <th width="120">Date</th>
                            <th width="180" class="text-center">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sl = 1;
                        foreach ($student_schedule as $row):
                            $subj_name = htmlspecialchars($row['subject_name']);
                            if ($row['subject_id'] == $std['optional_subject_id']) {
                                $subj_name .= ' <small class="text-muted" style="font-size: 0.6rem; letter-spacing: 0.5px;">(Optional)</small>';
                            }
                            ?>
                            <tr>
                                <td><?= str_pad($sl++, 2, '0', STR_PAD_LEFT) ?></td>
                                <td><?= $subj_name ?></td>
                                <td>
                                    <?php
                                    $d = $row['exam_date'] ? date('d M, Y', strtotime($row['exam_date'])) : '—';
                                    echo htmlspecialchars($d);
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['start_time'] && $row['end_time']): ?>
                                        <span class="time-badge">
                                            <?= date('h:i A', strtotime($row['start_time'])) ?>
                                            &rarr;
                                            <?= date('h:i A', strtotime($row['end_time'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Bottom bar: Principal signature — always inside borders -->
                <div class="card-bottom">
                    <div class="sig-box">
                        <div class="sig-line"></div>
                        <div class="sig-label">Principal</div>
                    </div>
                </div>

                <!-- Warning Footer — strictly outside the border at the very bottom -->
                <div class="admit-footer">
                    <i class="fas fa-info-circle me-1" style="color:var(--accent-gold);"></i>
                    This admit card must be presented at the examination hall. Without this card, entry will not be
                    permitted.
                </div>

            </div>
        <?php endforeach; ?>

    </div><!-- end .admit-wrapper -->

    <script>
        let currentZoom = 1;
        const wrapper = document.getElementById('admitWrapper');

        function setInitialZoom() {
            if (window.innerWidth < 1000) {
                currentZoom = (window.innerWidth - 40) / 794; // 210mm ≈ 794px
                updateZoom();
            }
        }

        function changeZoom(delta) {
            currentZoom = Math.max(0.3, Math.min(2, currentZoom + delta));
            updateZoom();
        }

        function resetZoom() { currentZoom = 1; updateZoom(); }

        function updateZoom() {
            wrapper.style.transform = `scale(${currentZoom})`;
            if (currentZoom < 1) {
                wrapper.style.marginBottom = `-${(1 - currentZoom) * wrapper.offsetHeight}px`;
            } else {
                wrapper.style.marginBottom = '30px';
            }
        }

        window.addEventListener('load', setInitialZoom);
    </script>
</body>

</html>