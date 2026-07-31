<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

$stmt = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, LENGTH(class_name), class_name, section");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = array_unique(array_column($all_classes, 'academic_year'));
$current_year = date('Y');
if (!in_array($current_year, $years, true)) {
    $years[] = $current_year;
}
sort($years);

$sel_year = (!empty($_GET['year']) && in_array($_GET['year'], $years, true))
    ? $_GET['year']
    : $current_year;
$sel_class = $_GET['class_name'] ?? '';
$sel_section = $_GET['section'] ?? 'all';
$show_report = isset($_GET['view']) && $_GET['view'] === '1' && $sel_class !== '';

$students = [];
$class_info = null;
$is_multi_section = false;
$fetch_error = '';
$show_optional_column = false;

$normalized_selected_class = strtolower(trim($sel_class));
$show_optional_column = (
    strpos($normalized_selected_class, 'class 6') !== false ||
    strpos($normalized_selected_class, 'class 7') !== false ||
    strpos($normalized_selected_class, 'class 8') !== false ||
    strpos($normalized_selected_class, 'class 9') !== false ||
    strpos($normalized_selected_class, 'class 10') !== false
);

function format_dob($dob) {
    if (!$dob) {
        return '—';
    }
    return date('d-m-Y', strtotime($dob));
}

function format_phone($phone) {
    return $phone ? htmlspecialchars($phone) : '—';
}

if ($show_report) {
    $is_multi_section = ($sel_section === 'all');

    try {
        if ($is_multi_section) {
            $stmt_classes = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? ORDER BY section");
            $stmt_classes->execute([$sel_class, $sel_year]);
            $class_ids = array_column($stmt_classes->fetchAll(PDO::FETCH_ASSOC), 'id');

            if (empty($class_ids)) {
                $fetch_error = 'No classes found for the selected year and class.';
            } else {
                $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                $stmt = $conn->prepare("
                    SELECT s.id, s.roll_number, s.name, s.father_name, s.mother_name, s.dob, s.phone,
                           opt_sub.subject_name AS optional_subject_name,
                           c.class_name, c.section, c.academic_year
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    LEFT JOIN subjects opt_sub ON s.optional_subject_id = opt_sub.id
                    WHERE s.class_id IN ($placeholders)
                    ORDER BY c.section ASC, CAST(s.roll_number AS UNSIGNED) ASC
                ");
                $stmt->execute($class_ids);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $class_info = [
                    'class_name' => $sel_class,
                    'section' => 'All',
                    'academic_year' => $sel_year
                ];
            }
        } else {
            $stmt_class = $conn->prepare("SELECT id FROM classes WHERE class_name = ? AND academic_year = ? AND section = ?");
            $stmt_class->execute([$sel_class, $sel_year, $sel_section]);
            $class_id = $stmt_class->fetchColumn();

            if (!$class_id) {
                $fetch_error = 'No class found for the selected section.';
            } else {
                $stmt = $conn->prepare("
                    SELECT s.id, s.roll_number, s.name, s.father_name, s.mother_name, s.dob, s.phone,
                           opt_sub.subject_name AS optional_subject_name,
                           c.class_name, c.section, c.academic_year
                    FROM students s
                    JOIN classes c ON s.class_id = c.id
                    LEFT JOIN subjects opt_sub ON s.optional_subject_id = opt_sub.id
                    WHERE s.class_id = ?
                    ORDER BY CAST(s.roll_number AS UNSIGNED) ASC
                ");
                $stmt->execute([$class_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $class_info = [
                    'class_name' => $sel_class,
                    'section' => $sel_section,
                    'academic_year' => $sel_year
                ];
            }
        }
    } catch (PDOException $e) {
        $fetch_error = 'Error fetching students: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details Report - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .config-card {
            background: #fff;
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: var(--prestige-shadow);
            border: 1px solid var(--prestige-border);
        }

        .report-card {
            background: #fff;
            border-radius: 24px;
            padding: 1.5rem;
            box-shadow: var(--prestige-shadow);
            border: 1px solid var(--prestige-border);
        }

        .form-label-custom {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--prestige-navy);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-label-custom i {
            color: var(--prestige-gold);
            margin-right: 6px;
        }

        .select-premium {
            border-radius: 12px;
            border: 1.5px solid var(--prestige-border);
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: var(--prestige-navy);
            transition: all 0.3s ease;
        }

        .select-premium:focus {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.1);
        }

        .btn-view-report {
            background: linear-gradient(135deg, var(--prestige-navy), #1e3a5f);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-weight: 700;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
            white-space: nowrap;
            transition: all 0.3s ease;
        }

        .btn-view-report:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25);
            color: #fff;
        }

        .student-edit-modal .modal-dialog {
            max-width: 980px;
        }

        .student-edit-modal .modal-content {
            border-radius: 24px;
            border: 1px solid var(--prestige-border);
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.14);
        }

        .student-edit-modal .modal-header {
            padding: 1.15rem 1.2rem 0.35rem;
        }

        .student-edit-modal .modal-body {
            padding: 0.35rem 1.2rem 1.15rem;
        }

        .student-edit-modal .form-control,
        .student-edit-modal .form-select {
            border-radius: 12px;
            border: 1.5px solid var(--prestige-border);
            padding: 0.72rem 0.9rem;
            font-size: 0.92rem;
            color: var(--prestige-navy);
            background-color: #fff;
            transition: all 0.2s ease;
        }

        .student-edit-modal .form-select {
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 14px 14px;
            padding-right: 2.2rem;
        }

        .student-edit-modal .form-control:focus,
        .student-edit-modal .form-select:focus {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.09);
            outline: none;
        }

        .report-toolbar-wrap {
            margin-bottom: 1.25rem;
        }

        .report-toolbar {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.2rem;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            flex-wrap: wrap;
        }

        .report-toolbar-stats {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
            background: transparent;
            border: none;
            border-radius: 0;
            overflow: visible;
        }

        .stat-pill {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.35rem;
            padding: 0.75rem 1rem;
            border-right: none;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fafbfc;
            line-height: 1.15;
            transition: all 0.2s ease;
            min-width: 90px;
        }

        .stat-pill:hover {
            border-color: var(--prestige-navy);
            background: #f0f4f8;
        }

        .stat-pill:last-child {
            border-right: 1px solid #e5e7eb;
        }

        .stat-pill i {
            font-size: 0.95rem;
            color: var(--prestige-gold);
            opacity: 1;
            width: auto;
            text-align: left;
        }

        .stat-pill span {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #6b7280;
            display: block;
            letter-spacing: 0.3px;
            margin-bottom: 0.15rem;
        }

        .stat-pill strong {
            font-size: 0.95rem;
            color: var(--prestige-navy);
            font-weight: 700;
        }

        .toolbar-divider {
            width: 1px;
            height: 50px;
            background: transparent;
            flex-shrink: 0;
        }

        .report-toolbar-search {
            flex: 1;
            min-width: 260px;
            max-width: 100%;
        }

        .search-box {
            display: flex;
            align-items: center;
            height: 44px;
            background: #ffffff;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            padding-right: 0.35rem;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
            transition: all 0.2s ease;
        }

        .search-box:focus-within {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 3px rgba(180, 83, 9, 0.08);
        }

        .search-box-icon {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0 0.85rem;
            color: #64748b;
            flex-shrink: 0;
            border-right: none;
            height: 100%;
        }

        .search-box-icon i {
            font-size: 0.95rem;
            color: var(--prestige-gold);
        }

        .search-box-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #9ca3af;
        }

        .search-box-input {
            flex: 1;
            min-width: 0;
            height: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: 0 0.75rem;
            font-size: 0.88rem;
            color: var(--prestige-navy);
        }

        .search-box-input::placeholder {
            color: #9ca3af;
        }

        .search-box-clear {
            display: none;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            background: #f3f4f6;
            color: #6b7280;
            flex-shrink: 0;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .search-box-clear:hover {
            background: #e5e7eb;
            color: var(--prestige-navy);
        }

        .search-box-clear.is-visible {
            display: flex;
        }

        .btn-save-pdf {
            height: 44px;
            display: inline-flex;
            align-items: center;
            margin-left: auto;
            background: linear-gradient(135deg, var(--prestige-navy), #1e3a5f);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 0 1.35rem;
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
        }

        .btn-save-pdf:hover {
            background: linear-gradient(135deg, #1e3a5f, #0f172a);
            color: #fff;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.2);
            transform: translateY(-1px);
        }

        .btn-save-pdf.is-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.95;
            min-width: 180px;
            justify-content: center;
        }

        .btn-save-pdf.is-loading .btn-label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pdf-loader {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-top-color: #fff;
            animation: pdfSpin 0.8s linear infinite;
        }

        @keyframes pdfSpin {
            to {
                transform: rotate(360deg);
            }
        }

        .search-hint {
            font-size: 0.72rem;
            margin: 0.4rem 0 0 0.15rem;
            color: #64748b;
            min-height: 0;
        }

        .pdf-processing-modal .modal-content {
            border: none;
            border-radius: 24px;
            background: linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
            overflow: hidden;
        }

        .pdf-processing-modal .modal-body {
            padding: 1.75rem 1.5rem 1.5rem;
        }

        .pdf-processing-badge {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            margin: 0 auto 1rem;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.08), rgba(59, 130, 246, 0.12));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
            color: var(--prestige-navy);
        }

        .pdf-processing-badge i {
            font-size: 1.4rem;
        }

        .search-hint:empty {
            display: none;
        }

        @media (max-width: 1199.98px) {
            .report-toolbar {
                flex-wrap: wrap;
            }

            .report-toolbar-search {
                order: 3;
                flex: 1 1 100%;
                max-width: none;
                margin-top: 0.5rem;
            }

            .toolbar-divider {
                display: none;
            }

        }

        @media (max-width: 767.98px) {
            .report-toolbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
                padding: 0.75rem;
            }

            .report-toolbar-stats {
                width: 100%;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .stat-pill {
                flex: 1 1 calc(50% - 0.25rem);
                padding: 0.6rem 0.7rem;
                min-width: auto;
                font-size: 0.75rem;
            }

            .stat-pill i {
                font-size: 0.8rem;
            }

            .stat-pill span {
                font-size: 0.6rem;
            }

            .stat-pill strong {
                font-size: 0.85rem;
            }

            .report-toolbar-search {
                width: 100%;
                max-width: none;
            }

            .btn-save-pdf {
                width: 100%;
                justify-content: center;
                margin-left: 0;
            }

            .table-details {
                width: 100%;
                border-collapse: separate;
            }

            .table-details thead {
                display: none;
            }

            .table-details tbody,
            .table-details tr,
            .table-details td {
                display: block;
                width: 100%;
            }

            .table-details tr {
                margin-bottom: 1rem;
                border-radius: 18px;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
                background: #ffffff;
                overflow: hidden;
                border: 1px solid rgba(15, 23, 42, 0.08);
            }

            .table-details td {
                padding: 12px 14px;
                font-size: 0.8rem;
                text-align: left;
                border: none !important;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08) !important;
                position: relative;
                background: transparent;
            }

            .table-details td:last-child {
                border-bottom: none !important;
            }

            .table-details td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.35rem;
                font-size: 0.68rem;
                color: #6b7280;
                font-weight: 700;
                letter-spacing: 0.3px;
                text-transform: uppercase;
            }

            .table-details td.text-start {
                text-align: left;
            }

            .table-details td .btn {
                width: 100%;
                justify-content: center;
            }

            .table-details tr:hover {
                transform: translateY(0);
            }
        }

        .table-details thead th {
            background: var(--prestige-navy) !important;
            color: white !important;
            border: 1px solid var(--prestige-navy) !important;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            padding: 12px 8px;
            white-space: nowrap;
        }

        .table-details td {
            border: 1px solid var(--prestige-border) !important;
            padding: 10px 8px;
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .table-details tbody tr:nth-child(even) {
            background: rgba(15, 23, 42, 0.02);
        }

        .search-no-results {
            display: none;
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        [data-theme="dark"] .config-card,
        [data-theme="dark"] .report-card {
            background: #111827;
            border-color: rgba(255, 255, 255, 0.07);
        }

        [data-theme="dark"] .form-label-custom {
            color: #cbd5e1;
        }

        [data-theme="dark"] .select-premium {
            background-color: #050a14;
            border-color: rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
        }

        [data-theme="dark"] .report-toolbar {
            background: linear-gradient(135deg, #111827 0%, #0f172a 100%);
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        [data-theme="dark"] .report-toolbar-stats {
            background: transparent;
            border-color: transparent;
        }

        [data-theme="dark"] .stat-pill {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
            border-right-color: rgba(255, 255, 255, 0.1);
            border-bottom-color: rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
        }

        [data-theme="dark"] .stat-pill:hover {
            background: rgba(180, 83, 9, 0.1);
            border-color: rgba(180, 83, 9, 0.3);
        }

        [data-theme="dark"] .stat-pill i {
            color: var(--prestige-gold);
            opacity: 1;
        }

        [data-theme="dark"] .stat-pill span {
            color: #9ca3af;
        }

        [data-theme="dark"] .stat-pill strong {
            color: #f1f5f9;
        }

        [data-theme="dark"] .search-box {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        [data-theme="dark"] .search-box:focus-within {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 3px rgba(180, 83, 9, 0.15);
        }

        [data-theme="dark"] .search-box-icon {
            border-right-color: transparent;
        }

        [data-theme="dark"] .search-box-icon i,
        [data-theme="dark"] .search-box-clear i,
        [data-theme="dark"] .stat-pill i,
        [data-theme="dark"] .header-icon-box i,
        [data-theme="dark"] .ai-tip-icon i {
            color: var(--prestige-gold);
        }

        [data-theme="dark"] .search-box-label {
            color: #9ca3af;
        }

        [data-theme="dark"] .search-box-input {
            color: #e2e8f0;
        }

        [data-theme="dark"] .search-box-input::placeholder {
            color: #6b7280;
        }

        [data-theme="dark"] .search-box-clear {
            background: rgba(255, 255, 255, 0.08);
            color: #9ca3af;
        }

        [data-theme="dark"] .search-box-clear:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #e2e8f0;
        }

        [data-theme="dark"] .btn-save-pdf {
            background: linear-gradient(135deg, var(--prestige-gold), #a16207);
            color: #0f172a;
            box-shadow: 0 2px 8px rgba(180, 83, 9, 0.2);
        }

        [data-theme="dark"] .btn-save-pdf:hover {
            background: linear-gradient(135deg, #b45309, #92400e);
            color: #fef3c7;
            box-shadow: 0 6px 20px rgba(180, 83, 9, 0.35);
        }

        [data-theme="dark"] .pdf-processing-modal .modal-content {
            background: linear-gradient(145deg, #111827 0%, #172554 100%);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(148, 163, 184, 0.14);
        }

        [data-theme="dark"] .pdf-processing-modal .modal-body {
            background: linear-gradient(145deg, rgba(17, 24, 39, 0.98), rgba(15, 23, 42, 0.98));
        }

        [data-theme="dark"] .pdf-processing-badge {
            background: linear-gradient(135deg, rgba(180, 83, 9, 0.14), rgba(59, 130, 246, 0.12));
            color: #fbbf24;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        [data-theme="dark"] .pdf-processing-modal h5 {
            color: #f8fafc;
        }

        [data-theme="dark"] .pdf-processing-modal .text-muted {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .pdf-processing-modal .spinner-border {
            color: #fbbf24 !important;
        }

        [data-theme="dark"] .btn-view-report {
            background: linear-gradient(135deg, var(--prestige-gold), #a16207);
            color: #0f172a;
            box-shadow: 0 2px 8px rgba(180, 83, 9, 0.2);
        }

        [data-theme="dark"] .btn-view-report:hover {
            background: linear-gradient(135deg, #b45309, #92400e);
            color: #fef3c7;
            box-shadow: 0 10px 30px rgba(180, 83, 9, 0.35);
        }

        [data-theme="dark"] .toolbar-divider {
            background: transparent;
        }

        [data-theme="dark"] .table-details {
            color: #e5e7eb;
            border-collapse: separate;
            border-spacing: 0;
        }

        [data-theme="dark"] .table-details thead th {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%) !important;
            color: #f9fafb !important;
            border-color: rgba(148, 163, 184, 0.18) !important;
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25);
        }

        [data-theme="dark"] .table-details td {
            background: #111827;
            color: #e5e7eb;
            border-color: rgba(148, 163, 184, 0.14) !important;
        }

        [data-theme="dark"] .table-details tbody tr:nth-child(odd) td {
            background: #111827;
        }

        [data-theme="dark"] .table-details tbody tr:nth-child(even) td {
            background: #17212f;
        }

        [data-theme="dark"] .table-details tbody tr:hover td {
            background: #1f2937;
        }

        [data-theme="dark"] .table-details .student-row td {
            box-shadow: inset 0 -1px 0 rgba(148, 163, 184, 0.08);
        }

        [data-theme="dark"] .table-details .btn-outline-primary {
            color: #bfdbfe;
            border-color: rgba(147, 197, 253, 0.35);
            background-color: rgba(30, 41, 59, 0.85);
        }

        [data-theme="dark"] .table-details .btn-outline-primary:hover {
            color: #ffffff;
            background-color: #2563eb;
            border-color: #2563eb;
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">

            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Student Details Report</h1>
                        <p class="mb-0">View or download student information as a printable table.</p>
                    </div>
                </div>
            </div>

            <form method="GET" class="card config-card" id="filterForm">
                <input type="hidden" name="view" value="1">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" for="year">
                            <i class="fas fa-calendar-check"></i> Academic Session
                        </label>
                        <select name="year" id="year" class="form-select select-premium" required onchange="updateClasses()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo htmlspecialchars($y); ?>" <?php echo (string) $y === (string) $sel_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y); ?><?php if ((int) $y === (int) $current_year)?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" for="class_name">
                            <i class="fas fa-graduation-cap"></i> Class
                        </label>
                        <select name="class_name" id="class_name" class="form-select select-premium" required onchange="updateSections()">
                            <option value="">Select Class</option>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" for="section">
                            <i class="fas fa-layer-group"></i> Section <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <select name="section" id="section" class="form-select select-premium">
                            <option value="all">All Sections</option>
                        </select>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <button type="submit" class="btn btn-view-report w-100">
                            <i class="fas fa-table me-2"></i> View Report
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($show_report): ?>
                <div class="card report-card mt-3" id="reportResults">
                    <?php if ($fetch_error): ?>
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($fetch_error); ?>
                        </div>
                    <?php elseif (empty($students)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-users-slash fa-3x mb-3"></i>
                            <h5>No students found</h5>
                            <p class="mb-0">There are no students enrolled in the selected class and section.</p>
                        </div>
                    <?php else: ?>
                        <div class="report-toolbar-wrap">
                            <div class="report-toolbar">
                                <div class="report-toolbar-stats">
                                    <div class="stat-pill">
                                        <i class="fas fa-graduation-cap"></i>
                                        <div>
                                            <span>Class</span>
                                            <strong><?php echo htmlspecialchars($class_info['class_name']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="stat-pill">
                                        <i class="fas fa-layer-group"></i>
                                        <div>
                                            <span>Section</span>
                                            <strong><?php echo htmlspecialchars($class_info['section']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="stat-pill">
                                        <i class="fas fa-calendar-alt"></i>
                                        <div>
                                            <span>Session</span>
                                            <strong><?php echo htmlspecialchars($class_info['academic_year']); ?></strong>
                                        </div>
                                    </div>
                                    <div class="stat-pill">
                                        <i class="fas fa-users"></i>
                                        <div>
                                            <span>Students</span>
                                            <strong><?php echo count($students); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="toolbar-divider d-none d-xl-block"></div>

                                <div class="report-toolbar-search">
                                    <div class="search-box">
                                        <div class="search-box-icon">
                                            <i class="fas fa-search"></i>
                                            <span class="search-box-label">Search</span>
                                        </div>
                                        <input type="text" id="studentSearch" class="search-box-input"
                                            placeholder="Roll, name, father, mother, phone..."
                                            autocomplete="off"
                                            aria-label="Search students">
                                        <button type="button" id="clearSearchBtn" class="search-box-clear" onclick="clearSearch()" title="Clear search">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>

                                <button type="button" onclick="downloadStudentPDF(this)" class="btn btn-save-pdf" id="savePdfBtn">
                                    <span class="btn-label">
                                        <i class="fas fa-download"></i>
                                        <span>Save as PDF</span>
                                    </span>
                                </button>
                            </div>
                            <p id="searchResultInfo" class="search-hint"></p>
                        </div>

                        <div id="searchNoResults" class="search-no-results">
                            <i class="fas fa-search fa-2x mb-2 text-muted"></i>
                            <p class="mb-0">No students match your search.</p>
                        </div>

                        <div class="table-responsive" id="studentTableWrap">
                            <table class="table table-bordered table-details text-center mb-0">
                                <thead>
                                    <tr>
                                        <?php if ($is_multi_section): ?>
                                            <th width="70">Section</th>
                                        <?php endif; ?>
                                        <th width="60">Roll</th>
                                        <th class="text-start">Name</th>
                                        <th class="text-start">Father's Name</th>
                                        <th class="text-start">Mother's Name</th>
                                        <th width="100">DOB</th>
                                        <th width="120">Phone</th>
                                        <?php if ($show_optional_column): ?>
                                            <th width="160">Optional Subject</th>
                                        <?php endif; ?>
                                        <th width="110">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="studentTableBody">
                                    <?php foreach ($students as $std): ?>
                                        <tr class="student-row" data-student-id="<?php echo (int) $std['id']; ?>">
                                            <?php if ($is_multi_section): ?>
                                                <td class="fw-bold" data-label="Section"><?php echo htmlspecialchars($std['section']); ?></td>
                                            <?php endif; ?>
                                            <td class="fw-bold" data-label="Roll"><?php echo htmlspecialchars($std['roll_number']); ?></td>
                                            <td class="text-start fw-bold" data-label="Name"><?php echo htmlspecialchars(strtoupper($std['name'])); ?></td>
                                            <td class="text-start" data-label="Father's Name"><?php echo htmlspecialchars($std['father_name']); ?></td>
                                            <td class="text-start" data-label="Mother's Name"><?php echo htmlspecialchars($std['mother_name']); ?></td>
                                            <td data-label="DOB"><?php echo format_dob($std['dob']); ?></td>
                                            <td data-label="Phone"><?php echo format_phone($std['phone']); ?></td>
                                            <?php if ($show_optional_column): ?>
                                                <td data-label="Optional Subject">
                                                    <?php echo htmlspecialchars(html_entity_decode($std['optional_subject_name'] ?? '—', ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                            <?php endif; ?>
                                            <td data-label="Action">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm edit-student-btn"
                                                    data-student-id="<?php echo (int) $std['id']; ?>"
                                                    data-roll-number="<?php echo htmlspecialchars($std['roll_number']); ?>"
                                                    data-name="<?php echo htmlspecialchars($std['name']); ?>"
                                                    data-father-name="<?php echo htmlspecialchars($std['father_name']); ?>"
                                                    data-mother-name="<?php echo htmlspecialchars($std['mother_name']); ?>"
                                                    data-dob="<?php echo htmlspecialchars($std['dob'] ?? ''); ?>"
                                                    data-phone="<?php echo htmlspecialchars($std['phone'] ?? ''); ?>"
                                                    data-optional-subject="<?php echo htmlspecialchars(html_entity_decode($std['optional_subject_name'] ?? '', ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="fas fa-edit me-1"></i> Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="ai-tip-box py-3 px-4 mt-3 rounded-4">
                <div class="ai-tip-icon" style="font-size: 1.2rem; padding: 8px;">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="ai-tip-text">
                    <h6 class="mb-1" style="font-size: 0.95rem;">Report Contents</h6>
                    <p class="small mb-0">The report includes Section, Roll, Name, Father's Name, Mother's Name, DOB, and Phone. Select filters above and click View to load the table, then use Save as PDF to download.</p>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade pdf-processing-modal" id="pdfProcessingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-body text-center py-4 px-4">
                    <div class="pdf-processing-badge">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 2rem; height: 2rem;">
                        <span class="visually-hidden">Loading</span>
                    </div>
                    <h5 class="mb-1 fw-semibold">Preparing your PDF</h5>
                    <p class="text-muted mb-0">Please wait while the student details report is being generated.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade student-edit-modal" id="studentEditModal" tabindex="-1" aria-labelledby="studentEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="studentEditModalLabel">Edit Student Details</h5>
                        <p class="text-muted small mb-0">Update the selected student record without leaving this page.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <div id="editSaveAlert" class="alert alert-success d-none mb-3"></div>
                    <form id="studentEditForm" class="row g-3">
                        <input type="hidden" id="edit_student_id" name="id">
                        <div class="col-md-3">
                            <label for="edit_roll_number" class="form-label-custom mb-1">Roll Number</label>
                            <input type="number" class="form-control" id="edit_roll_number" name="roll_number" required>
                        </div>
                        <div class="col-md-9">
                            <label for="edit_name" class="form-label-custom mb-1">Full Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_father_name" class="form-label-custom mb-1">Father's Name</label>
                            <input type="text" class="form-control" id="edit_father_name" name="father_name">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_mother_name" class="form-label-custom mb-1">Mother's Name</label>
                            <input type="text" class="form-control" id="edit_mother_name" name="mother_name">
                        </div>
                        <div class="col-md-4">
                            <label for="edit_dob" class="form-label-custom mb-1">Date of Birth</label>
                            <input type="date" class="form-control" id="edit_dob" name="dob" required>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_phone" class="form-label-custom mb-1">Phone Number</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone" placeholder="Optional">
                        </div>
                        <div class="col-md-4">
                            <label for="edit_optional_subject_id" class="form-label-custom mb-1">Optional Subject</label>
                            <select class="form-select select-premium" id="edit_optional_subject_id" name="optional_subject_id">
                                <option value="">Loading optional subjects...</option>
                            </select>
                        </div>
                        <div class="col-12 text-end mt-2">
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 me-2" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-view-report px-4" id="saveStudentBtn">
                                <i class="fas fa-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const allData = <?php echo json_encode($all_classes); ?>;
        const retainedClass = <?php echo json_encode($sel_class); ?>;
        const retainedSection = <?php echo json_encode($sel_section); ?>;

        function updateClasses() {
            const year = document.getElementById('year').value;
            const classSelect = document.getElementById('class_name');

            const filteredClasses = [...new Set(allData
                .filter(c => c.academic_year == year)
                .map(c => c.class_name))];

            classSelect.innerHTML = '<option value="">Select Class</option>';
            filteredClasses.forEach(cls => {
                const opt = document.createElement('option');
                opt.value = cls;
                opt.textContent = cls;
                if (cls === retainedClass) {
                    opt.selected = true;
                }
                classSelect.appendChild(opt);
            });
            updateSections();
        }

        function updateSections() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const sectionSelect = document.getElementById('section');

            if (!className) {
                sectionSelect.innerHTML = '<option value="all">All Sections</option>';
                return;
            }

            const filteredSections = [...new Set(allData
                .filter(c => c.academic_year == year && c.class_name == className)
                .map(c => c.section))];

            sectionSelect.innerHTML = '<option value="all">All Sections</option>';
            filteredSections.forEach(sec => {
                const opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = 'Section ' + sec;
                if (sec === retainedSection) {
                    opt.selected = true;
                }
                sectionSelect.appendChild(opt);
            });

            if (retainedSection === 'all') {
                sectionSelect.value = 'all';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateClasses();

            <?php if ($show_report): ?>
            const reportEl = document.getElementById('reportResults');
            if (reportEl) {
                reportEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            <?php endif; ?>
        });
    </script>

    <?php if ($show_report && !empty($students)): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const totalStudents = <?php echo count($students); ?>;
        const classInfo = <?php echo json_encode($class_info, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function filterStudents() {
            const input = document.getElementById('studentSearch');
            const clearBtn = document.getElementById('clearSearchBtn');
            const query = input.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#studentTableBody .student-row');
            const info = document.getElementById('searchResultInfo');
            const noResults = document.getElementById('searchNoResults');
            const tableWrap = document.getElementById('studentTableWrap');
            let visible = 0;

            clearBtn.classList.toggle('is-visible', !!query);

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = !query || text.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            if (query) {
                info.textContent = visible + ' of ' + totalStudents + ' student(s) found';
                noResults.style.display = visible === 0 ? 'block' : 'none';
                tableWrap.style.display = visible === 0 ? 'none' : 'block';
            } else {
                info.textContent = '';
                noResults.style.display = 'none';
                tableWrap.style.display = 'block';
            }
        }

        function clearSearch() {
            const input = document.getElementById('studentSearch');
            input.value = '';
            filterStudents();
            input.focus();
        }

        document.getElementById('studentSearch').addEventListener('input', filterStudents);

        document.addEventListener('DOMContentLoaded', function () {
            const editModalElement = document.getElementById('studentEditModal');
            if (!editModalElement) return;

            const editModal = new bootstrap.Modal(editModalElement);
            const editSaveAlert = document.getElementById('editSaveAlert');

            document.querySelectorAll('.edit-student-btn').forEach(button => {
                button.addEventListener('click', () => {
                    const studentId = button.getAttribute('data-student-id');
                    document.getElementById('edit_student_id').value = studentId;
                    document.getElementById('edit_roll_number').value = button.getAttribute('data-roll-number') || '';
                    document.getElementById('edit_name').value = button.getAttribute('data-name') || '';
                    document.getElementById('edit_father_name').value = button.getAttribute('data-father-name') || '';
                    document.getElementById('edit_mother_name').value = button.getAttribute('data-mother-name') || '';
                    document.getElementById('edit_dob').value = button.getAttribute('data-dob') || '';
                    document.getElementById('edit_phone').value = button.getAttribute('data-phone') || '';

                    const optionalSelect = document.getElementById('edit_optional_subject_id');
                    optionalSelect.innerHTML = '<option value="">Loading optional subjects...</option>';
                    editSaveAlert.classList.add('d-none');
                    editSaveAlert.classList.remove('alert-danger');
                    editSaveAlert.classList.add('alert-success');
                    editSaveAlert.textContent = '';

                    fetch('get_student_optional_subjects.php?id=' + encodeURIComponent(studentId))
                        .then(response => response.json())
                        .then(data => {
                            if (!data.success) {
                                optionalSelect.innerHTML = '<option value="">None</option>';
                                return;
                            }
                            optionalSelect.innerHTML = '';
                            data.options.forEach(item => {
                                const option = document.createElement('option');
                                option.value = item.id;
                                option.textContent = item.name;
                                if (item.id === data.current_subject_id) {
                                    option.selected = true;
                                }
                                optionalSelect.appendChild(option);
                            });
                        })
                        .catch(() => {
                            optionalSelect.innerHTML = '<option value="">None</option>';
                        });

                    editModal.show();
                });
            });

            const studentEditForm = document.getElementById('studentEditForm');
            if (studentEditForm) {
                studentEditForm.addEventListener('submit', function (event) {
                    event.preventDefault();

                    const saveBtn = document.getElementById('saveStudentBtn');
                    const originalLabel = saveBtn.innerHTML;
                    saveBtn.disabled = true;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';

                    const formData = new FormData(this);
                    formData.set('optional_subject_id', document.getElementById('edit_optional_subject_id').value);
                    const payload = new URLSearchParams(formData).toString();

                    fetch('update-student-inline.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: payload
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (!result.success) {
                            throw new Error(result.message || 'Unable to save the changes.');
                        }

                        const row = document.querySelector('.student-row[data-student-id="' + result.student_id + '"]');
                        if (row) {
                            row.querySelector('[data-label="Roll"]').textContent = result.student.roll_number;
                            row.querySelector('[data-label="Name"]').textContent = result.student.name.toUpperCase();
                            row.querySelector('[data-label="Father\'s Name"]').textContent = result.student.father_name;
                            row.querySelector('[data-label="Mother\'s Name"]').textContent = result.student.mother_name;
                            row.querySelector('[data-label="DOB"]').textContent = result.student.dob_display;
                            row.querySelector('[data-label="Phone"]').textContent = result.student.phone_display;
                            if (row.querySelector('[data-label="Optional Subject"]')) {
                                row.querySelector('[data-label="Optional Subject"]').textContent = result.student.optional_subject_name || '—';
                            }
                        }

                        editSaveAlert.textContent = 'Student details updated successfully.';
                        editSaveAlert.classList.remove('d-none');
                        editSaveAlert.classList.remove('alert-danger');
                        editSaveAlert.classList.add('alert-success');
                        setTimeout(() => editModal.hide(), 500);
                    })
                    .catch(error => {
                        editSaveAlert.textContent = error.message || 'Unable to save the changes.';
                        editSaveAlert.classList.remove('d-none');
                        editSaveAlert.classList.add('alert-danger');
                        editSaveAlert.classList.remove('alert-success');
                    })
                    .finally(() => {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = originalLabel;
                    });
                });
            }
        });

        function downloadStudentPDF(btn) {
            const headers = [];
            const visibleColumnIndexes = [];

            document.querySelectorAll('#studentTableWrap thead th').forEach((th, index) => {
                const headerText = th.textContent.trim();
                if (headerText !== 'Action') {
                    headers.push(headerText);
                    visibleColumnIndexes.push(index);
                }
            });

            const body = [];
            document.querySelectorAll('#studentTableBody .student-row').forEach(row => {
                if (row.style.display === 'none') return;
                const rowData = [];
                row.querySelectorAll('td').forEach((td, index) => {
                    if (visibleColumnIndexes.includes(index)) {
                        rowData.push(td.textContent.trim());
                    }
                });
                body.push(rowData);
            });

            if (body.length === 0) {
                alert('No students match your current view.');
                return;
            }

            const originalHtml = btn.innerHTML;
            const startTime = Date.now();
            const minimumDelayMs = 3000;
            const pdfModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('pdfProcessingModal'));

            const restoreButton = () => {
                pdfModal.hide();
                btn.disabled = false;
                btn.classList.remove('is-loading');
                btn.innerHTML = originalHtml;
            };

            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.innerHTML = originalHtml;
            pdfModal.show();

            try {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                const pageWidth = doc.internal.pageSize.getWidth();
                const pdfMarginX = 20;
                const navy = [15, 23, 42];

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(15);
                doc.setTextColor(navy[0], navy[1], navy[2]);
                doc.text('ATN GIRLS HIGH SCHOOL', pageWidth / 2, 14, { align: 'center' });

                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9);
                doc.setTextColor(80, 80, 80);
                doc.text('STUDENT DETAILS REPORT', pageWidth / 2, 20, { align: 'center' });

                doc.setFontSize(8);
                doc.text(
                    'Class: ' + classInfo.class_name +
                    '    Section: ' + classInfo.section +
                    '    Session: ' + classInfo.academic_year +
                    '    Students: ' + body.length,
                    pageWidth / 2, 26,
                    { align: 'center' }
                );

                const columnStyles = {};
                headers.forEach((label, i) => {
                    if (['Name', "Father's Name", "Mother's Name"].includes(label)) {
                        columnStyles[i] = { halign: 'left' };
                    } else if (label === 'Roll' || label === 'Section') {
                        columnStyles[i] = { halign: 'center' };
                    }
                });

                doc.autoTable({
                    head: [headers],
                    body: body,
                    startY: 30,
                    theme: 'grid',
                    headStyles: {
                        fillColor: navy,
                        textColor: [255, 255, 255],
                        fontStyle: 'bold',
                        fontSize: 7,
                        halign: 'center'
                    },
                    bodyStyles: { fontSize: 7, valign: 'middle' },
                    columnStyles: columnStyles,
                    margin: { left: pdfMarginX, right: pdfMarginX, top: 30 },
                    styles: {
                        cellPadding: 1.8,
                        lineColor: [203, 213, 225],
                        lineWidth: 0.1,
                        overflow: 'linebreak'
                    }
                });

                const elapsed = Date.now() - startTime;
                const waitTime = Math.max(0, minimumDelayMs - elapsed);

                setTimeout(() => {
                    try {
                        doc.save('Student Details - ' + classInfo.class_name +
                            ' (' + classInfo.section + ') - ' + classInfo.academic_year + '.pdf');
                    } catch (saveErr) {
                        alert('Could not generate the PDF. Please try again.');
                    } finally {
                        restoreButton();
                    }
                }, waitTime);
            } catch (err) {
                alert('Could not generate the PDF. Please try again.');
                restoreButton();
            }
        }
    </script>
    <?php endif; ?>
</body>

</html>
