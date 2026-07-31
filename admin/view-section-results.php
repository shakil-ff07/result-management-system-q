<?php
include('auth.php');
include('../includes/db_config.php');

$class_id = $_GET['class_id'] ?? null;
$exam_type = $_GET['exam_type'] ?? null;

// New parameters for multi-section
$class_name = $_GET['class_name'] ?? null;
$academic_year = $_GET['academic_year'] ?? null;
$section_filter = $_GET['section'] ?? null;

if ((!$class_id && (!$class_name || !$academic_year)) || !$exam_type) {
    die("Invalid access. Class/Year/Exam Type are required.");
}

$results = [];
$class_info = [];
$is_multi_section = false;

try {
    // If a specific section is requested via class_name and academic_year, find the class_id
    if (!$class_id && $class_name && $academic_year && $section_filter && $section_filter !== 'all') {
        $stmt = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? AND section = ?");
        $stmt->execute([$class_name, $academic_year, $section_filter]);
        $class_id = $stmt->fetchColumn();
    }

    if ($section_filter === 'all' && $class_name && $academic_year) {
        $is_multi_section = true;

        // Fetch all matching class IDs
        $stmt_classes = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? ORDER BY section");
        $stmt_classes->execute([$class_name, $academic_year]);
        $class_ids = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);

        if (empty($class_ids))
            die("No classes found.");

        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));

        // Fetch joined results
        $query = "
            SELECT fr.*, s.name, s.roll_number, s.student_group, s.dob,
                   c.section, c.class_name, c.academic_year, s.id as student_id
            FROM final_results fr
            JOIN students s ON fr.student_id = s.id
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id IN ($placeholders) AND fr.exam_type = ?
            ORDER BY 
                CASE WHEN fr.status = 'Pass' THEN 1 ELSE 2 END ASC,
                CAST(fr.position AS UNSIGNED) ASC, 
                fr.total_gpa DESC, 
                fr.total_marks DESC
        ";

        $params = array_merge($class_ids, [$exam_type]);
        $stmt_results = $conn->prepare($query);
        $stmt_results->execute($params);
        $results = $stmt_results->fetchAll();

        // Metadata
        $class_info = [
            'class_name' => $class_name,
            'section' => 'All Sections',
            'academic_year' => $academic_year
        ];

    } else {
        // Single Section Logic (Original)
        $stmt_class = $conn->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt_class->execute([$class_id]);
        $class_info = $stmt_class->fetch();

        if (!$class_info)
            die("Class not found.");

        $stmt_results = $conn->prepare("
            SELECT fr.*, s.name, s.roll_number, s.student_group, s.dob,
                   '{$class_info['section']}' as section,
                   '{$class_info['class_name']}' as class_name,
                   '{$class_info['academic_year']}' as academic_year,
                   s.id as student_id
            FROM final_results fr
            JOIN students s ON fr.student_id = s.id
            WHERE s.class_id = ? AND fr.exam_type = ?
            ORDER BY 
                CASE WHEN fr.status = 'Pass' THEN 1 ELSE 2 END ASC,
                CAST(fr.position AS UNSIGNED) ASC, 
                fr.total_gpa DESC, 
                fr.total_marks DESC
        ");
        $stmt_results->execute([$class_id, $exam_type]);
        $results = $stmt_results->fetchAll();
    }
} catch (PDOException $e) {
    die("Error fetching results: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merit List -
        <?php echo $class_info['class_name'] . " (" . $class_info['section'] . ") - " . $exam_type; ?>
    </title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <?php include('header.php'); ?>
    <style>
        body {
            background: #f1f5f9;
            color: var(--prestige-navy);
        }

        .print-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 35px 40px;
            background: #fff;
            box-shadow: var(--prestige-shadow);
            border-radius: 20px;
            position: relative;
        }

        .school-header h1 {
            font-family: 'Playfair Display', serif;
            color: var(--prestige-navy);
            letter-spacing: 1px;
        }

        .table-merit thead th {
            background: var(--prestige-navy) !important;
            color: white !important;
            border: none !important;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            padding: 15px 10px;
        }

        .table-merit td {
            border-bottom: 1px solid var(--prestige-border) !important;
            border-left: none !important;
            border-right: none !important;
            padding: 12px 10px;
            font-size: 0.9rem;
        }

        .badge-status {
            padding: 5px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        .badge-pass { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-fail { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }

        .row-fail td { background-color: rgba(239, 68, 68, 0.02) !important; }
        .row-aplus td { background-color: rgba(16, 185, 129, 0.02) !important; }

        .page-footer {
            text-align: center;
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px dashed var(--prestige-border);
        }

        .btn-prestige-outline {
            border: 1.5px solid var(--prestige-navy);
            color: var(--prestige-navy);
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .btn-prestige-outline:hover {
            background: var(--prestige-navy);
            color: white;
        }

        @media print {
            @page {
                size: auto;
                margin: 0mm;
            }
            .no-print { display: none !important; }
            body { background: #fff !important; padding: 15mm !important; color: #000 !important; }
            .print-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .table-merit thead th { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            
            /* Force table view in print */
            .table-responsive { display: block !important; overflow: visible !important; }
            .merit-mobile-cards { display: none !important; }
            
            /* Force horizontal header in print */
            .school-header .gap-5 { 
                display: flex !important; 
                flex-direction: row !important; 
                grid-template-columns: none !important;
                gap: 30px !important; 
                justify-content: center !important;
            }
        }

        [data-theme="dark"] .table-merit td { color: #cbd5e1 !important; }
        [data-theme="dark"] .school-header h1 { color: var(--prestige-gold); }
        [data-theme="dark"] body { background: #0a0f1d !important; color: #cbd5e1 !important; }
        [data-theme="dark"] .print-container { 
            background: #111827 !important; 
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5) !important;
        }
        [data-theme="dark"] .school-header .d-flex {
            background: rgba(255, 255, 255, 0.03) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        [data-theme="dark"] .school-header .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] .school-header strong { color: #f8fafc !important; }
        [data-theme="dark"] .table-merit th { background: #0f172a !important; border-bottom: 2px solid var(--prestige-gold) !important; }
        [data-theme="dark"] .table-merit td { border-bottom-color: rgba(255, 255, 255, 0.05) !important; }
        [data-theme="dark"] .btn-prestige-outline { border-color: #334155; color: #94a3b8; }
        [data-theme="dark"] .btn-prestige-outline:hover { background: #1e293b; color: #f8fafc; }
        [data-theme="dark"] .page-footer { color: #64748b; border-top-color: rgba(255, 255, 255, 0.05); }
        [data-theme="dark"] .row-fail td { background-color: rgba(239, 68, 68, 0.03) !important; }
        [data-theme="dark"] .row-aplus td { background-color: rgba(16, 185, 129, 0.03) !important; }
        [data-theme="dark"] .btn-outline-dark { color: #94a3b8; border-color: #334155; }
        [data-theme="dark"] .btn-outline-dark:hover { background: #1e293b; color: #f8fafc; border-color: #475569; }
        [data-theme="dark"] .student-name-cell { color: #f8fafc !important; }
        [data-theme="dark"] .table-merit tr, [data-theme="dark"] .table-merit td { background-color: transparent !important; }
        [data-theme="dark"] .row-fail td { background-color: rgba(239, 68, 68, 0.1) !important; }
        [data-theme="dark"] .row-aplus td { background-color: rgba(16, 185, 129, 0.1) !important; }
        [data-theme="dark"] .btn-outline-dark { background-color: rgba(255, 255, 255, 0.05) !important; color: #cbd5e1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }

        @media screen and (max-width: 991.98px) {
            .no-print { display: flex; flex-direction: column; gap: 10px; margin-bottom: 25px !important; }
            .no-print .btn { width: 100%; justify-content: center; }
            .no-print > div { width: 100%; display: flex; flex-direction: column; gap: 10px; }
            
            .print-container { padding: 20px; margin: 10px; border-radius: 12px; }
            .school-header h1 { font-size: 1.5rem; }
            .school-header .gap-5 { 
                display: grid !important; 
                grid-template-columns: 3fr 2fr; 
                gap: 15px !important; 
                padding: 20px !important; 
                text-align: center;
            }
            .school-header .gap-5 > div { 
                border-bottom: none; 
                padding-bottom: 0; 
                background: rgba(15, 23, 42, 0.03);
                padding: 10px;
                border-radius: 10px;
            }
            .school-header .gap-5 > div:last-child { border-bottom: none; }
            
            .table-responsive { display: none !important; }
            .merit-mobile-cards { display: block !important; }
        }

        /* Desktop defaults for cards */
        .merit-mobile-cards { display: none; margin-top: 20px; }
        .merit-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid var(--prestige-border);
            position: relative;
        }
        
        [data-theme="dark"] .merit-card { background: #111827; border-color: rgba(255,255,255,0.05); }

        .merit-card .pos-tag {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--prestige-navy);
            color: white;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 800;
        }

        .merit-card .stat-box {
            background: rgba(15, 23, 42, 0.02);
            border-radius: 8px;
            padding: 8px;
            text-align: center;
            flex: 1;
        }
        
        [data-theme="dark"] .merit-card .stat-box { background: rgba(255,255,255,0.02); }
    </style>
</head>

<body>

    <div class="print-container">
        <!-- Control Buttons -->
        <div class="no-print mb-4 d-flex justify-content-between align-items-center">
            <a href="generate-results.php" class="btn btn-prestige-outline px-3 py-1-5" style="font-size: 0.85rem;">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
            <div class="d-flex gap-2">
                <?php if (!$is_multi_section): ?>
                    <a href="print-results.php?class_id=<?php echo $class_id; ?>&exam_type=<?php echo urlencode($exam_type); ?>"
                        target="_blank" class="btn btn-success px-3 py-1-5" style="border-radius:8px; font-weight:600; font-size: 0.85rem;">
                        <i class="fas fa-file-download me-2"></i> Download Marksheets
                    </a>
                <?php else: ?>
                    <a href="print-results.php?class_name=<?php echo urlencode($class_name); ?>&academic_year=<?php echo urlencode($academic_year); ?>&exam_type=<?php echo urlencode($exam_type); ?>"
                        target="_blank" class="btn btn-success px-3 py-1-5" style="border-radius:8px; font-weight:600; font-size: 0.85rem;">
                        <i class="fas fa-layer-group me-2"></i> Bulk All Sections
                    </a>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-primary px-3 py-1-5" style="border-radius:8px; font-weight:600; background: var(--prestige-navy); border:none; font-size: 0.85rem;">
                    <i class="fas fa-print me-2"></i> Print Merit List
                </button>
            </div>
        </div>

        <!-- School Header -->
        <div class="school-header text-center mb-4">
            <h1 class="mb-1 fw-bold text-uppercase fs-3">ATN GIRLS HIGH SCHOOL</h1>
            <p class="mb-3 text-muted fw-bold" style="letter-spacing: 1.5px; font-size: 0.8rem;">ACADEMIC CONSOLIDATED RESULT SHEET (MERIT LIST)</p>
            <div class="d-flex justify-content-center gap-5 p-2 rounded-3" style="background: rgba(15, 23, 42, 0.02); border: 1px solid var(--prestige-border);">
                <div><span class="text-muted" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 2px;">Class</span> <strong style="font-size: 1.1rem;"><?php echo $class_info['class_name']; ?></strong></div>
                <div><span class="text-muted" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 2px;">Section</span> <strong style="font-size: 1.1rem;"><?php echo $class_info['section']; ?></strong></div>
                <div><span class="text-muted" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 2px;">Exam Type</span> <strong style="font-size: 1.1rem;"><?php echo $exam_type; ?></strong></div>
                <div><span class="text-muted" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 2px;">Session</span> <strong style="font-size: 1.1rem;"><?php echo $class_info['academic_year']; ?></strong></div>
            </div>
        </div>

        <!-- Results Table -->
        <?php
        $hide_group = in_array($class_info['class_name'], ['Class 6', 'Class 7', 'Class 8']);
        $col_count = $hide_group ? 8 : 9; // Increased count by 1 for the Action column
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-merit text-center">
            <thead>
                <tr>
                    <th width="50">Pos</th>
                    <th width="80">Roll</th>
                    <th class="text-start">Student Name</th>
                    <?php if ($is_multi_section): ?>
                        <th width="80">Section</th>
                    <?php endif; ?>
                    <?php if (!$hide_group): ?>
                        <th width="100">Group</th>
                    <?php endif; ?>
                    <th width="100">Total Marks</th>
                    <th width="80">GPA</th>
                    <th width="100">Grade</th>
                    <th width="100">Status</th>
                    <th width="110" class="no-print">Marksheet</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pos = 1;
                foreach ($results as $r):
                    $row_class = '';
                    if ($r['status'] != 'Pass') {
                        $row_class = 'row-fail';
                    } elseif ($r['final_grade'] === 'A+') {
                        $row_class = 'row-aplus';
                    }
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td class="fw-bold text-muted">#<?php echo $r['position']; ?></td>
                        <td><span class="badge-prestige"><?php echo $r['roll_number']; ?></span></td>
                        <td class="text-start fw-bold student-name-cell"><?php echo strtoupper($r['name']); ?></td>
                        <?php if ($is_multi_section): ?>
                            <td class="fw-bold"><?php echo $r['section']; ?></td>
                        <?php endif; ?>
                        <?php if (!$hide_group): ?>
                            <td class="small text-muted fw-bold"><?php echo $r['student_group']; ?></td>
                        <?php endif; ?>
                        <td class="fw-bold"><?php echo (int) $r['total_marks']; ?></td>
                        <td class="fw-bold text-primary"><?php echo number_format($r['total_gpa'], 2); ?></td>
                        <td class="fw-bold"><?php echo $r['final_grade']; ?></td>
                        <td>
                            <span class="badge-status <?php echo $r['status'] == 'Pass' ? 'badge-pass' : 'badge-fail'; ?>">
                                <?php echo strtoupper($r['status']); ?>
                            </span>
                        </td>
                        <td class="no-print">
                            <a href="../result.php?roll=<?php echo urlencode($r['roll_number']); ?>&dob=<?php echo urlencode($r['dob']); ?>&class_name=<?php echo urlencode($r['class_name'] ?? $class_info['class_name']); ?>&section=<?php echo urlencode($r['section']); ?>&year=<?php echo urlencode($r['academic_year'] ?? $class_info['academic_year']); ?>&exam_type=<?php echo urlencode($exam_type); ?>"
                                target="_blank"
                                class="btn btn-sm btn-outline-dark px-3 py-1"
                                style="border-radius: 8px; font-size: 0.75rem; font-weight: 700;"
                                title="Print Individual Marksheet">
                                <i class="fas fa-file-alt me-1"></i> Marksheet
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="<?php echo $col_count; ?>" class="py-4 text-muted">No results found for this selection.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Merit Cards -->
    <div class="merit-mobile-cards no-print">
        <?php if (empty($results)): ?>
            <div class="text-center py-4 text-muted">No results found.</div>
        <?php else: ?>
            <?php foreach ($results as $r): ?>
                <div class="merit-card shadow-sm">
                    <div class="pos-tag">POS #<?php echo $r['position']; ?></div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="badge-prestige me-3"><?php echo $r['roll_number']; ?></div>
                        <div>
                            <div class="fw-bold" style="color: var(--prestige-navy); font-size: 1rem;"><?php echo strtoupper($r['name']); ?></div>
                            <div class="small text-muted fw-bold">
                                Section <?php echo $r['section']; ?> 
                                <?php if (!$hide_group): ?> | <?php echo $r['student_group']; ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mb-3">
                        <div class="stat-box">
                            <div class="text-muted" style="font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">GPA</div>
                            <div class="fw-bold text-primary"><?php echo number_format($r['total_gpa'], 2); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="text-muted" style="font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">Grade</div>
                            <div class="fw-bold"><?php echo $r['final_grade']; ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="text-muted" style="font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">Status</div>
                            <div class="badge-status <?php echo $r['status'] == 'Pass' ? 'badge-pass' : 'badge-fail'; ?>" style="font-size: 0.6rem; padding: 2px 6px;">
                                <?php echo $r['status']; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <div class="small fw-bold">Total: <?php echo (int)$r['total_marks']; ?></div>
                        <a href="../result.php?roll=<?php echo urlencode($r['roll_number']); ?>&dob=<?php echo urlencode($r['dob']); ?>&class_name=<?php echo urlencode($r['class_name'] ?? $class_info['class_name']); ?>&section=<?php echo urlencode($r['section']); ?>&year=<?php echo urlencode($r['academic_year'] ?? $class_info['academic_year']); ?>&exam_type=<?php echo urlencode($exam_type); ?>"
                            target="_blank"
                            class="btn btn-sm btn-outline-dark px-3 py-1"
                            style="border-radius: 8px; font-size: 0.75rem; font-weight: 700;">
                            <i class="fas fa-file-alt me-1"></i> Marksheet
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

        <!-- Footer -->
        <div class="page-footer">
            Generated on: <?php echo date('d-M-Y h:i A'); ?> | Official Result Document
        </div>
    </div>

</body>

</html>