<?php
include('includes/db_config.php');

$token = $_GET['t'] ?? '';

if (!$token) {
    die("Invalid access.");
}

// 1. Verify Token
$stmt = $conn->prepare("SELECT * FROM marksheet_tokens WHERE token = ?");
$stmt->execute([$token]);
$token_data = $stmt->fetch();

$verified = false;
$student = null;
$summary = null;
$class_info = null;

if ($token_data) {
    $verified = true;
    
    // Increment view count for audit
    $stmt = $conn->prepare("UPDATE marksheet_tokens SET view_count = view_count + 1 WHERE id = ?");
    $stmt->execute([$token_data['id']]);
    
    // Fetch Student Details
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$token_data['student_id']]);
    $student = $stmt->fetch();
    
    // Fetch Summary
    $stmt = $conn->prepare("SELECT * FROM final_results WHERE student_id = ? AND exam_type = ?");
    $stmt->execute([$token_data['student_id'], $token_data['exam_type']]);
    $summary = $stmt->fetch();
    
    // Fetch Class Info
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$token_data['class_id']]);
    $class_info = $stmt->fetch();
}
?>
<script>(function(){var t=localStorage.getItem('srms-theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Result Verification | SRMS</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --prestige-navy: #0f172a;
            --prestige-gold: #b45309;
            --success-green: #10b981;
            --error-red: #ef4444;
            --accent-blue: #3b82f6;
            --panel-bg: #ffffff;
            --page-bg: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--page-bg);
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 40px 40px;
            color: var(--prestige-navy);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            margin: 0;
            padding: 2rem 0;
        }

        .verification-wrapper {
            width: 95%;
            max-width: 1000px;
            background: white;
            border-radius: 40px;
            overflow: hidden;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.2);
            display: flex;
            min-height: 600px;
            margin: 2rem auto;
            animation: slideUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Left Panel: Identity */
        .identity-panel {
            width: 40%;
            background: var(--prestige-navy);
            color: white;
            padding: 3rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: center;
            position: relative;
        }

        .identity-panel::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
        }

        .school-logo-circle {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            color: var(--prestige-navy);
            font-size: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1;
            transition: transform 0.3s ease;
        }
        
        .school-logo-circle:hover { transform: scale(1.05); }

        /* Right Panel: Result */
        .result-panel {
            width: 60%;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            background: #ffffff;
        }

        .status-pill {
            position: absolute;
            top: 2rem;
            right: 2rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-pill.verified { background: #ecfdf5; color: var(--success-green); }
        .status-pill.invalid { background: #fef2f2; color: var(--error-red); }

        .data-grid {
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .data-item {
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 0.5rem;
        }

        .data-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .data-value {
            font-weight: 600;
            color: var(--prestige-navy);
            font-size: 1rem;
        }

        .grade-highlight {
            font-size: 4.5rem;
            font-weight: 900;
            color: var(--prestige-navy);
            line-height: 1;
            margin: 0.5rem 0;
            background: linear-gradient(135deg, var(--prestige-navy), var(--accent-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .serif-font { font-family: 'Playfair Display', serif; }

        @media (max-width: 768px) {
            body { 
                overflow: auto; 
                align-items: flex-start; 
                padding: 1rem; 
                height: auto;
                background-color: var(--page-bg);
            }
            .verification-wrapper {
                flex-direction: column;
                height: auto;
                width: 100%;
                max-width: 450px;
                margin: 0 auto;
                border-radius: 24px;
            }
            .identity-panel { 
                width: 100%; 
                padding: 1.25rem 1.15rem;
            }
            .result-panel { 
                width: 100%; 
                padding: 1.25rem 1.15rem;
            }
            .status-pill {
                top: 1rem;
                right: 1rem;
                padding: 0.3rem 0.6rem;
                font-size: 0.6rem;
            }
            .data-grid {
                gap: 0.65rem;
                margin-top: 1rem !important;
            }
            .grade-highlight {
                font-size: 2rem;
            }
            .data-value {
                font-size: 0.85rem;
            }
            .roll-card {
                padding: 0.75rem !important;
                margin-top: 0.5rem !important;
                background: rgba(255, 255, 255, 0.05) !important;
                border: 1px solid rgba(255, 255, 255, 0.1) !important;
                backdrop-filter: blur(10px);
            }
            .identity-panel h3 { font-size: 1.25rem; margin-bottom: 0.25rem !important; }
            .identity-panel .school-logo-circle { width: 60px; height: 60px; font-size: 1.5rem; margin-bottom: 1rem !important; }
            .identity-panel .mb-4 { margin-bottom: 1rem !important; }
            .identity-panel h4 { font-size: 1.1rem; }
            .result-panel h2 { font-size: 1.4rem; }
            .result-panel .mt-4, .result-panel .mt-5 { margin-top: 0.75rem !important; }
            .result-panel .mb-4 { margin-bottom: 0.75rem !important; }
            .serif-font { line-height: 1.2; }
        }

        /* Midnight Prestige - Dark Mode for Verify */
        [data-theme="dark"] body { background-color: #060c18 !important; }
        [data-theme="dark"] .verification-wrapper { box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6) !important; }
        [data-theme="dark"] .identity-panel { background: #050a14 !important; }
        [data-theme="dark"] .result-panel { background: #111827 !important; }
        [data-theme="dark"] .result-panel h2 { color: #f59e0b !important; }
        [data-theme="dark"] .result-panel p { color: #6b7280 !important; }
        [data-theme="dark"] .data-label { color: #6b7280 !important; }
        [data-theme="dark"] .data-value { color: #e2e8f0 !important; }
        [data-theme="dark"] .data-item { border-bottom-color: rgba(255,255,255,0.07) !important; }
        [data-theme="dark"] .grade-highlight { color: #f59e0b !important; }
        [data-theme="dark"] .status-pill.verified { background: rgba(5,150,105,0.15) !important; color: #34d399 !important; }
        [data-theme="dark"] .status-pill.invalid  { background: rgba(220,38,38,0.1) !important;  color: #f87171 !important; }
        [data-theme="dark"] .border-start { border-color: rgba(255,255,255,0.08) !important; }
        [data-theme="dark"] .bg-light { background: #0a1020 !important; }
        [data-theme="dark"] .bg-white { background: #111827 !important; }
        [data-theme="dark"] .text-muted { color: #6b7280 !important; }
        [data-theme="dark"] .btn.btn-dark { background: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }
    </style>
</head>
<body>

    <?php if ($verified && $student && $summary): ?>
        <div class="verification-wrapper">
            <!-- Left Panel -->
            <div class="identity-panel">
                <div style="position: relative; z-index: 1;">
                    <div class="school-logo-circle">
                        <i class="fas fa-university"></i>
                    </div>
                    <h3 class="serif-font mb-1">ATN HIGH</h3>
                    <p class="small opacity-75 text-uppercase letter-spacing-1">Digital Authentication</p>
                </div>
                
                <div style="position: relative; z-index: 1;">
                    <div class="mb-4">
                        <h4 class="serif-font mb-0"><?php echo htmlspecialchars($student['name']); ?></h4>
                        <p class="text-white-50 small mb-0">Official Student ID Record</p>
                    </div>
                    <div class="roll-card p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-10 text-center">
                        <div class="small opacity-50 text-uppercase mb-1" style="font-size: 0.6rem; letter-spacing: 1px;">Roll Number</div>
                        <div class="h4 mb-0 fw-bold"><?php echo $student['roll_number']; ?></div>
                    </div>
                </div>

                <div class="mt-auto" style="position: relative; z-index: 1;">
                    <div class="small opacity-50 mb-1">Verified on</div>
                    <div class="fw-medium small"><?php echo date('d M, Y'); ?></div>
                </div>
            </div>

            <!-- Right Panel -->
            <div class="result-panel">
                <div class="status-pill verified">
                    <i class="fas fa-shield-check me-1"></i> Authentic
                </div>

                <h5 class="data-label" style="font-size: 0.65rem; letter-spacing: 1.5px; color: #64748b;">EXAMINATION SUMMARY</h5>
                <h2 class="serif-font mb-1" style="font-weight: 700; color: var(--accent-blue);"><?php echo $token_data['exam_type']; ?></h2>
                <p class="text-muted small mb-4" style="font-weight: 500;">Academic Session <?php echo $class_info['academic_year']; ?></p>

                <div class="row align-items-center">
                    <div class="col-7">
                        <div class="data-grid">
                            <div class="data-item">
                                <div class="data-label">Class</div>
                                <div class="data-value"><?php echo $class_info['class_name']; ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Section</div>
                                <div class="data-value"><?php echo $class_info['section']; ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Total Marks</div>
                                <div class="data-value"><?php echo $summary['total_marks']; ?></div>
                            </div>
                            <div class="data-item">
                                <div class="data-label">Final GPA</div>
                                <div class="data-value"><?php echo number_format($summary['total_gpa'], 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-5 text-center border-start ps-4" style="border-left: 2px solid #f1f5f9 !important;">
                        <div class="data-label mb-1" style="color: #64748b;">LETTER GRADE</div>
                        <div class="grade-highlight"><?php echo $summary['final_grade']; ?></div>
                        <div class="badge <?php echo $summary['status'] == 'Pass' ? 'bg-success' : 'bg-danger'; ?> rounded-pill px-4 py-2" style="font-size: 0.75rem; letter-spacing: 1px;">
                            <?php echo strtoupper($summary['status']); ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4 mt-md-5 p-3 rounded-4 bg-white border d-flex align-items-center justify-content-center shadow-sm" style="border-style: dashed !important;">
                    <div class="me-2 text-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="small fw-bold text-center">
                        Security Cleared.
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- INVALID VIEW -->
        <div class="verification-wrapper" style="max-width: 500px; height: auto; padding: 4rem 2rem; flex-direction: column; text-align: center;">
            <div class="text-danger mb-4" style="font-size: 5rem;">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h2 class="serif-font fw-bold text-danger">Verification Failed</h2>
            <p class="text-muted mb-4">The provided security token is invalid or has been revoked. This marksheet cannot be verified.</p>
            <a href="index.php" class="btn btn-dark rounded-pill px-5">Back to Portal</a>
        </div>
    <?php endif; ?>

</body>
</html>
