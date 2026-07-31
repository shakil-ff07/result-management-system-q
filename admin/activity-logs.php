<?php
include('auth.php');
require_admin(); // Only admins should see logs
include('../includes/db_config.php');

// Handle Deletion
$delete_message = '';
if (isset($_POST['delete_logs']) && !empty($_POST['delete_date'])) {
    $delete_date = $_POST['delete_date'] . ' 23:59:59';
    $stmt = $conn->prepare("DELETE FROM activity_logs WHERE created_at <= ?");
    if ($stmt->execute([$delete_date])) {
        $count = $stmt->rowCount();
        $delete_message = "Successfully deleted $count activity logs up to " . htmlspecialchars($_POST['delete_date']) . ".";
    }
}

// Handle 30-day auto-cleanup
$cleanup_message = '';
if (isset($_POST['cleanup_old_logs'])) {
    $stmt_clean = $conn->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt_clean->execute();
    $cleaned = $stmt_clean->rowCount();
    $cleanup_message = "Removed <strong>$cleaned</strong> log" . ($cleaned !== 1 ? 's' : '') . " older than 30 days.";
}

// Pagination Logic
$limit = 50; // Items per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filtering logic
$where_clauses = [];
$params = [];

$search = $_GET['search'] ?? '';
$action_filter = $_GET['action'] ?? '';

if (!empty($search)) {
    $where_clauses[] = "(al.details LIKE ? OR a.username LIKE ? OR a.full_name LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($action_filter)) {
    $where_clauses[] = "al.action = ?";
    $params[] = $action_filter;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) 
    FROM activity_logs al 
    LEFT JOIN admins a ON al.user_id = a.id 
    $where_sql
";
$stmt_count = $conn->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Main Query with Pagination
$query = "
    SELECT al.*, a.username, a.full_name 
    FROM activity_logs al 
    LEFT JOIN admins a ON al.user_id = a.id 
    $where_sql
    ORDER BY al.created_at DESC 
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get unique actions for filter dropdown
$available_actions = $conn->query("SELECT DISTINCT action FROM activity_logs")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Activity Logs - SRMS Admin</title>
    <?php include('header.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .log-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .filter-section {
            background: #fff;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            border: 1px solid var(--prestige-border);
            box-shadow: 0 5px 25px rgba(0,0,0,0.02) !important;
        }
        .action-badge {
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .bg-update { background-color: var(--prestige-gold-light); color: var(--prestige-gold); border: 1px solid rgba(180, 83, 9, 0.2); }
        .bg-other { background-color: #f1f5f9; color: var(--prestige-navy); border: 1px solid var(--prestige-border); }
        
        .timestamp { 
            font-family: 'JetBrains Mono', 'Courier New', monospace; 
            font-size: 0.85rem; 
            color: #64748b; 
            white-space: nowrap;
        }
        .details-box {
            font-size: 0.85rem;
            color: var(--prestige-text);
            background: var(--prestige-slate);
            padding: 10px 15px;
            border-radius: 8px;
            border-left: 4px solid var(--prestige-gold);
            line-height: 1.5;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--prestige-navy);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .table thead th {
            background-color: var(--prestige-slate);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: #64748b;
            padding: 16px;
            border-bottom: 2px solid var(--prestige-border) !important;
        }
        .search-input {
            border-radius: 10px;
            padding-left: 40px;
            border: 1px solid #d1d3e2;
        }
        .search-container {
            position: relative;
        }
        .search-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #858796;
        }
        .pagination {
            margin-bottom: 0;
            gap: 5px;
        }
        .pagination .page-link {
            border-radius: 8px !important;
            border: 1px solid var(--prestige-border);
            color: var(--prestige-navy);
            font-weight: 600;
            padding: 8px 16px;
            transition: all 0.2s;
        }
        .pagination .page-item.active .page-link {
            background-color: var(--prestige-navy);
            border-color: var(--prestige-navy);
            color: white;
        }
        .pagination .page-link:hover {
            background-color: var(--prestige-slate);
            border-color: var(--prestige-gold);
        }
        .cleanup-btn {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-weight: 600;
            border-radius: 10px;
            padding: 8px 16px;
            transition: all 0.2s;
        }
        .cleanup-btn:hover {
            background-color: #fecaca;
            color: #991b1b;
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .filter-section {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .log-card {
            background: #111827 !important;
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .table thead th {
            background-color: #0a1020 !important;
            border-bottom-color: rgba(255, 255, 255, 0.1) !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02) !important;
        }

        [data-theme="dark"] .table td {
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .details-box {
            background: #0a1020 !important;
            color: #cbd5e1 !important;
            border-color: var(--prestige-gold) !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2) !important;
        }

        [data-theme="dark"] .timestamp {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .search-input, [data-theme="dark"] .form-select {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .search-input:focus, [data-theme="dark"] .form-select:focus {
            background-color: #0f172a !important;
            border-color: var(--prestige-gold) !important;
        }

        [data-theme="dark"] .avatar {
            background: #1e293b !important;
            color: var(--prestige-gold) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .text-dark {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .text-muted {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .bg-other {
            background-color: #1e293b !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .cleanup-btn {
            background-color: rgba(239, 68, 68, 0.1) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.2) !important;
        }

        [data-theme="dark"] .cleanup-btn:hover {
            background-color: rgba(239, 68, 68, 0.2) !important;
        }

        [data-theme="dark"] .pagination .page-link {
            background-color: #111827 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .pagination .page-item.active .page-link {
            background-color: var(--prestige-gold) !important;
            border-color: var(--prestige-gold) !important;
            color: #000 !important;
        }

        [data-theme="dark"] .modal-content {
            background-color: #111827 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .modal-header, [data-theme="dark"] .modal-footer {
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        /* Forced Table Dark Mode */
        [data-theme="dark"] .table {
            background-color: transparent !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .table tbody, 
        [data-theme="dark"] .table tr, 
        [data-theme="dark"] .table td {
            background-color: transparent !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .table-hover tbody tr:hover td {
            background-color: rgba(255, 255, 255, 0.03) !important;
            color: #fff !important;
        }

        [data-theme="dark"] .card {
            background-color: #111827 !important;
        }

        /* ── Mobile: stack header title + buttons vertically ── */
        @media (max-width: 767.98px) {
            .page-header {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 14px !important;
            }

            .page-header .header-actions {
                width: 100%;
            }

            .page-header .header-actions form,
            .page-header .header-actions button {
                flex: 1;
            }

            .page-header .header-actions .cleanup-btn {
                width: 100%;
                justify-content: center;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                font-size: 0.88rem;
            }
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
                        <i class="fas fa-history"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">System Activity Logs</h1>
                        <p class="mb-0 text-muted">Audit trail for all teacher and administrative actions.</p>
                    </div>
                </div>
                <div class="header-actions d-flex gap-2">
                    <form method="POST" id="quickCleanupForm">
                        <button type="submit" name="cleanup_old_logs" class="cleanup-btn"
                            title="Delete all logs older than 30 days">
                            <i class="fas fa-clock me-1"></i> Clear 30d+
                        </button>
                    </form>
                    <button class="cleanup-btn" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                        <i class="fas fa-trash-alt me-2"></i> Clear History
                    </button>
                </div>
            </div>

            <?php if ($delete_message): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Logs Cleaned',
                        text: '<?php echo $delete_message; ?>',
                        confirmButtonColor: '#0f172a'
                    });
                </script>
            <?php endif; ?>

            <?php if ($cleanup_message): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Old Logs Removed',
                        html: '<?php echo $cleanup_message; ?>',
                        confirmButtonColor: '#0f172a',
                        timer: 3000,
                        timerProgressBar: true
                    });
                </script>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filter-section shadow-sm">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">Search Logs</label>
                        <div class="search-container">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" class="form-control search-input" placeholder="Search by name, username, or details..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted">Action Type</label>
                        <select name="action" class="form-select" style="border-radius: 10px;">
                            <option value="">All Actions</option>
                            <?php foreach ($available_actions as $act): ?>
                                <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $action_filter == $act ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($act); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4" style="border-radius: 10px;">
                                <i class="fas fa-filter me-2"></i> Apply Filters
                            </button>
                            <?php if (!empty($search) || !empty($action_filter)): ?>
                                <a href="activity-logs.php" class="btn btn-outline-secondary" style="border-radius: 10px;">
                                    <i class="fas fa-undo"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card log-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th width="200">Date & Time</th>
                                    <th width="250">Performed By</th>
                                    <th width="180">Action</th>
                                    <th>Activity Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <div class="timestamp">
                                                <i class="far fa-calendar-alt me-2 opacity-50"></i>
                                                <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                                            </div>
                                            <div class="timestamp mt-1">
                                                <i class="far fa-clock me-2 opacity-50"></i>
                                                <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="avatar">
                                                    <?php echo strtoupper(substr($log['username'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['full_name'] ?: $log['username']); ?></div>
                                                    <div class="text-muted small">@<?php echo htmlspecialchars($log['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                                $badge_class = ($log['action'] == 'Updated Marks') ? 'bg-update' : 'bg-other';
                                                $icon_class = ($log['action'] == 'Updated Marks') ? 'fa-edit' : 'fa-info-circle';
                                            ?>
                                            <span class="action-badge <?php echo $badge_class; ?>">
                                                <i class="fas <?php echo $icon_class; ?>"></i>
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="details-box shadow-sm">
                                                <?php 
                                                    // Parse details to make them more structured if they contain separators
                                                    $details = $log['details'];
                                                    if (strpos($details, ' | ') !== false) {
                                                        $parts = explode(' | ', $details);
                                                        foreach ($parts as $part) {
                                                            echo '<div class="mb-1"><i class="fas fa-caret-right me-2 text-primary"></i>' . htmlspecialchars($part) . '</div>';
                                                        }
                                                    } else {
                                                        echo htmlspecialchars($details);
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5">
                                            <div class="opacity-25 mb-3">
                                                <i class="fas fa-search fa-4x"></i>
                                            </div>
                                            <h5 class="text-muted">No activity logs found matching your criteria.</h5>
                                            <p class="text-muted small">Try adjusting your filters or search terms.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card log-card mt-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                        <div class="text-muted small">
                            Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_records); ?></strong> of <strong><?php echo $total_records; ?></strong> activities
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation">
                                <ul class="pagination">
                                    <?php 
                                        $query_string = $_GET;
                                        unset($query_string['page']);
                                        $url_base = "activity-logs.php?" . http_build_query($query_string) . "&page=";
                                    ?>
                                    
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $url_base . ($page - 1); ?>"><i class="fas fa-chevron-left"></i></a>
                                    </li>

                                    <?php
                                    $range = 2;
                                    for ($i = 1; $i <= $total_pages; $i++) {
                                        if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                                            echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '">';
                                            echo '<a class="page-link" href="' . $url_base . $i . '">' . $i . '</a>';
                                            echo '</li>';
                                        } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    ?>

                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $url_base . ($page + 1); ?>"><i class="fas fa-chevron-right"></i></a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cleanup Modal -->
    <div class="modal fade" id="cleanupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold serif-font text-danger"><i class="fas fa-exclamation-triangle me-2"></i> Clear Activity History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="text-muted">Deleting activity logs is irreversible. Please select a date to delete all logs up to that day.</p>
                    <form id="deleteForm" method="POST">
                        <input type="hidden" name="delete_logs" value="1">
                        <div class="mb-4">
                            <label class="form-label text-dark fw-bold mb-2">Delete Logs Up To:</label>
                            <input type="date" name="delete_date" class="form-control" required 
                                   max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
                            <div class="form-text mt-2"><i class="fas fa-info-circle me-1"></i> Logs from today cannot be deleted for security reasons.</div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-danger py-3 fw-bold shadow-sm" style="border-radius: 12px;">
                                <i class="fas fa-trash-alt me-2"></i> Confirm Permanent Deletion
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const date = this.querySelector('input[name="delete_date"]').value;
            Swal.fire({
                title: 'Are you sure?',
                text: "All activity logs recorded on or before " + date + " will be permanently removed!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#0f172a',
                confirmButtonText: 'Yes, delete them!',
                cancelButtonText: 'No, cancel',
                background: '#fff',
                borderRadius: '20px'
            }).then((result) => {
                if (result.isConfirmed) { this.submit(); }
            });
        });

        document.getElementById('quickCleanupForm').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Delete old logs?',
                text: 'All logs older than 30 days will be permanently removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#0f172a',
                confirmButtonText: 'Yes, clean up!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) { this.submit(); }
            });
        });
    </script>
</body>
</html>
