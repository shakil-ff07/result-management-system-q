<?php
include('auth.php');
require_admin(); // Only admins can access this page
include('../includes/db_config.php');

// Auto-migration: Ensure 'role' and 'status' columns exist
try {
    $conn->query("SELECT role FROM admins LIMIT 1");
    // Attempt to add roles if they don't exist
    try {
        $conn->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('superadmin', 'headmaster', 'admin', 'teacher', 'assistant') DEFAULT 'headmaster'");
        $conn->exec("UPDATE admins SET role = 'headmaster' WHERE role = 'admin'");
        $conn->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('superadmin', 'headmaster', 'teacher', 'assistant') DEFAULT 'headmaster'");
        
        $stmt = $conn->query("SELECT COUNT(*) FROM admins WHERE role = 'superadmin'");
        if ($stmt->fetchColumn() == 0) {
            $hashed = password_hash('superadmin', PASSWORD_DEFAULT);
            $conn->exec("INSERT INTO admins (username, password, raw_password, role) VALUES ('superadmin', '$hashed', 'superadmin', 'superadmin')");
        }
    } catch (Exception $ex) {}
} catch (Exception $e) {
    try {
        $conn->exec("ALTER TABLE admins ADD COLUMN role ENUM('superadmin', 'headmaster', 'teacher', 'assistant') DEFAULT 'headmaster' AFTER full_name");
        $conn->exec("UPDATE admins SET role = 'headmaster' WHERE username = 'admin'");
    } catch (Exception $ex) {
    }
}

try {
    $conn->query("SELECT status FROM admins LIMIT 1");
} catch (Exception $e) {
    try {
        $conn->exec("ALTER TABLE admins ADD COLUMN status ENUM('active', 'suspended') DEFAULT 'active' AFTER role");
    } catch (Exception $ex) {
    }
}

try {
    $conn->query("SELECT raw_password FROM admins LIMIT 1");
} catch (Exception $e) {
    try {
        $conn->exec("ALTER TABLE admins ADD COLUMN raw_password VARCHAR(255) AFTER password");
    } catch (Exception $ex) {
    }
}

$msg = "";
$error = "";

// Handle User Deletion
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    // Prevent deleting self
    if ($id == $_SESSION['admin_id']) {
        $error = "You cannot delete your own account!";
    } else {
        $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: manage-users.php?msg=deleted");
        exit();
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['status'])) {
    $id = $_GET['toggle_status'];
    $new_status = $_GET['status'];

    if ($id == $_SESSION['admin_id']) {
        $error = "You cannot suspend your own account!";
    } else {
        $stmt = $conn->prepare("UPDATE admins SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        header("Location: manage-users.php?msg=" . ($new_status == 'active' ? 'activated' : 'suspended'));
        exit();
    }
}

// Handle User Addition/Update
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    $password = $_POST['password'];
    $user_id = $_POST['user_id'] ?? null;

    if ($role !== 'teacher' && $role !== 'assistant') {
        $error = "Only Teacher or Assistant accounts can be managed here.";
    } else {
        try {
            if (!empty($user_id)) {
                // Update
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE admins SET username = ?, role = ?, password = ?, raw_password = ? WHERE id = ?");
                    $stmt->execute([$username, $role, $hashed_password, $password, $user_id]);
                } else {
                    $stmt = $conn->prepare("UPDATE admins SET username = ?, role = ? WHERE id = ?");
                    $stmt->execute([$username, $role, $user_id]);
                }
                $msg = "User updated successfully!";
            } else {
                // Add
                if (empty($password)) {
                    $error = "Password is required for new users!";
                } else {
                    // Check if username exists
                    $stmt = $conn->prepare("SELECT id FROM admins WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error = "Username already exists!";
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO admins (username, password, raw_password, role) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$username, $hashed_password, $password, $role]);
                        $msg = "User created successfully!";
                    }
                }
            }
        } catch (Exception $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}

// Fetch teachers, assistants and current user
$stmt = $conn->prepare("SELECT * FROM admins WHERE role IN ('teacher', 'assistant') OR id = ? ORDER BY id DESC");
$stmt->execute([$_SESSION['admin_id']]);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Manage Users - SRMS Admin</title>
    <?php include('header.php'); ?>
    <style>
        .role-badge {
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: rgba(180, 83, 9, 0.1);
            color: var(--prestige-gold);
            border: 1px solid rgba(180, 83, 9, 0.2);
        }

        .role-headmaster {
            background: rgba(180, 83, 9, 0.1);
            color: var(--prestige-gold);
            border: 1px solid rgba(180, 83, 9, 0.2);
        }

        .role-superadmin {
            background: rgba(180, 83, 9, 0.15);
            color: var(--prestige-gold);
            border: 1px solid rgba(180, 83, 9, 0.3);
            font-weight: 800;
        }

        .role-teacher {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
            border: 1px solid rgba(15, 23, 42, 0.1);
        }

        .role-assistant {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .role-suspended {
            background: #f1f5f9;
            color: #64748b;
            text-decoration: line-through;
            border: 1px solid #cbd5e1;
        }

        /* ========== User Cards (Mobile) ========== */
        .user-cards {
            display: none;
        }

        .user-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 12px;
            border: 1px solid var(--prestige-border);
            transition: all 0.2s ease;
            position: relative;
        }

        .user-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        }

        .user-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            text-transform: uppercase;
            flex-shrink: 0;
            font-family: 'Playfair Display', serif;
        }

        .avatar-admin {
            background: rgba(180, 83, 9, 0.1);
            color: var(--prestige-gold);
        }

        .avatar-headmaster {
            background: rgba(180, 83, 9, 0.1);
            color: var(--prestige-gold);
        }

        .avatar-superadmin {
            background: rgba(180, 83, 9, 0.15);
            color: var(--prestige-gold);
        }

        .avatar-teacher {
            background: rgba(15, 23, 42, 0.05);
            color: var(--prestige-navy);
        }

        .avatar-assistant {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .avatar-suspended {
            background: #f1f5f9;
            color: #94a3b8;
        }

        .user-card-info {
            flex: 1;
            min-width: 0;
        }

        .user-card-name {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--prestige-navy);
            margin-bottom: 2px;
        }

        .user-card-meta {
            font-size: 0.78rem;
            color: #64748b;
        }

        .user-card-actions {
            display: flex;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px dashed var(--prestige-border);
        }

        .user-card-actions .btn {
            flex: 1;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 8px 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ========== Mobile Responsive ========== */
        @media (max-width: 768px) {
            .user-table-wrap {
                display: none !important;
            }

            .user-cards {
                display: block;
                padding: 5px 0;
            }

            .user-card {
                padding: 18px;
                border-radius: 16px;
                margin-bottom: 16px;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            }

            .user-card-actions {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                padding-top: 15px;
            }

            .user-card-actions .btn {
                flex: none;
                width: 100%;
                font-size: 0.78rem;
                padding: 10px 8px;
                justify-content: center;
                border-radius: 10px;
            }

            /* Make buttons full width if they are the only ones or we want to emphasize them */
            .user-card-actions .btn:only-child {
                grid-column: span 2;
            }
            
            .header-actions {
                width: 100%;
                margin-top: 15px;
                display: flex;
                justify-content: flex-start;
            }
            
            .header-actions .btn {
                width: 100%;
                padding: 12px;
                font-weight: 700;
                border-radius: 12px;
                white-space: normal;
            }

            .page-header {
                flex-wrap: wrap;
                align-items: flex-start;
                gap: 1rem;
            }

            .page-header > .d-flex.align-items-center {
                width: 100%;
                min-width: 0;
            }

            .page-header .header-actions {
                order: 2;
            }
        }

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.1);
        }

        .modal-header {
            border-bottom: 1px solid var(--prestige-border);
            padding: 24px;
        }

        .modal-title {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            color: var(--prestige-navy);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid var(--prestige-border);
            padding: 20px 24px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control, .form-select {
            padding: 12px 16px;
            border: 1px solid var(--prestige-border);
            border-radius: 8px;
            font-weight: 500;
            color: var(--prestige-navy);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--prestige-gold);
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.05);
        }

        .table th {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: #64748b;
            font-weight: 700;
            padding: 16px;
            border-bottom: 2px solid var(--prestige-border);
        }

        .table td {
            padding: 16px;
            vertical-align: middle;
            color: var(--prestige-text);
            border-bottom: 1px dashed var(--prestige-border);
        }

        /* Midnight Prestige - Dark Mode Overrides */
        [data-theme="dark"] .user-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .user-card-name {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .user-card-meta {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .user-card-actions {
            border-top-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .role-admin, [data-theme="dark"] .avatar-admin {
            background: rgba(245, 158, 11, 0.15) !important;
            color: #f59e0b !important;
            border-color: rgba(245, 158, 11, 0.3) !important;
        }

        [data-theme="dark"] .role-teacher, [data-theme="dark"] .avatar-teacher {
            background: rgba(255, 255, 255, 0.05) !important;
            color: #cbd5e1 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        [data-theme="dark"] .role-suspended, [data-theme="dark"] .avatar-suspended {
            background: rgba(255, 255, 255, 0.03) !important;
            color: #64748b !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .user-table-wrap {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .bg-light {
            background-color: #0a1020 !important;
        }

        [data-theme="dark"] tr.bg-light, [data-theme="dark"] .user-card.bg-light {
            background-color: rgba(255, 255, 255, 0.03) !important;
        }

        [data-theme="dark"] .modal-footer.bg-light {
            background-color: #0a1020 !important;
        }

        [data-theme="dark"] .form-control, [data-theme="dark"] .form-select {
            background-color: #050a14 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .table-responsive {
            background: #111827 !important;
        }

        [data-theme="dark"] .table {
            background-color: transparent !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .table tbody, [data-theme="dark"] .table tr, [data-theme="dark"] .table td, [data-theme="dark"] .table th {
            background-color: transparent !important;
            color: #cbd5e1 !important;
            border-bottom-color: rgba(255, 255, 255, 0.05) !important;
        }

        [data-theme="dark"] .table th {
            color: #64748b !important;
        }

        [data-theme="dark"] .table-hover tbody tr:hover td {
            background-color: rgba(255, 255, 255, 0.02) !important;
            color: #f59e0b !important;
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
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">User Management</h1>
                        <p class="mb-0">Manage administrative and teacher accounts.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#addUserModal">
                        <i class="fas fa-user-plus me-1"></i> Create New User
                    </button>
                </div>
            </div>

            <?php if ($msg || isset($_GET['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php
                    if ($msg)
                        echo $msg;
                    elseif ($_GET['msg'] == 'deleted')
                        echo 'User deleted successfully!';
                    elseif ($_GET['msg'] == 'suspended')
                        echo 'User suspended successfully!';
                    elseif ($_GET['msg'] == 'activated')
                        echo 'User activated successfully!';
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm border-0 mb-4" style="border-radius: 12px;">
                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- ===== DESKTOP TABLE ===== -->
            <div class="card user-table-wrap">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $status = $user['status'] ?? 'active';
                                    $is_suspended = $status === 'suspended';
                                    ?>
                                    <tr class="<?php echo $is_suspended ? 'opacity-50 bg-light' : ''; ?>">
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="user-avatar <?php echo $is_suspended ? 'avatar-suspended' : 'avatar-' . ($user['role'] ?? 'admin'); ?>" style="width: 40px; height: 40px; font-size: 0.9rem;">
                                                    <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                                                </div>
                                                <div>
                                                     <div class="fw-bold" style="color: var(--prestige-text);">
                                                        <?php echo htmlspecialchars($user['username']); ?>
                                                        <?php if ($is_suspended): ?>
                                                            <span class="badge bg-danger ms-2" style="font-size: 0.65rem;">SUSPENDED</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?php echo $user['role'] ?? 'admin'; ?>">
                                                <?php echo $user['role'] ?? 'admin'; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($user['id'] != $_SESSION['admin_id']): ?>
                                                <?php if ($is_suspended): ?>
                                                    <a href="#" class="btn btn-sm btn-success me-1 activate-user-btn" 
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                        title="Activate User" style="border-radius: 6px;">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="#" class="btn btn-sm btn-warning me-1 suspend-user-btn" 
                                                        data-user-id="<?php echo $user['id']; ?>"
                                                        data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                        title="Suspend User" style="border-radius: 6px;">
                                                        <i class="fas fa-ban"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <button class="btn btn-sm btn-outline-secondary me-1 edit-user"
                                                data-user='<?php echo json_encode($user); ?>' data-bs-toggle="modal"
                                                data-bs-target="#addUserModal" title="Edit User" style="border-radius: 6px;">
                                                <i class="fas fa-edit"></i>
                                            </button>

                                            <?php if (($user['role'] ?? 'admin') === 'teacher' && !$is_suspended): ?>
                                                <a href="assign-teacher.php?id=<?php echo $user['id']; ?>"
                                                    class="btn btn-sm btn-outline-info me-1" title="Manage Assignments" style="border-radius: 6px;">
                                                    <i class="fas fa-tasks"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($user['id'] != $_SESSION['admin_id']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-user-btn"
                                                    data-user-id="<?php echo $user['id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                    title="Delete User" style="border-radius: 6px;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== MOBILE CARDS ===== -->
            <div class="user-cards">
                <?php foreach ($users as $user): ?>
                    <?php
                    $status = $user['status'] ?? 'active';
                    $is_suspended = $status === 'suspended';
                    $role = $user['role'] ?? 'admin';
                    $initials = strtoupper(substr($user['username'], 0, 2));
                    ?>
                    <div class="user-card <?php echo $is_suspended ? 'bg-light' : ''; ?>">
                        <div class="user-card-header">
                            <div class="user-avatar <?php echo $is_suspended ? 'avatar-suspended' : 'avatar-' . $role; ?>">
                                <?php echo $initials; ?>
                            </div>
                            <div class="user-card-info">
                                 <div class="d-flex justify-content-between align-items-start">
                                    <div class="user-card-name" style="color: var(--prestige-text);">
                                        <?php echo htmlspecialchars($user['username']); ?>
                                        <?php if ($is_suspended): ?>
                                            <div class="badge bg-danger mt-1 d-inline-block" style="font-size: 0.6em;">SUSPENDED</div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="role-badge <?php echo $is_suspended ? 'role-suspended' : 'role-' . $role; ?>"><?php echo $role; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="user-card-actions">
                            <?php if ($user['id'] != $_SESSION['admin_id']): ?>
                                <?php if ($is_suspended): ?>
                                    <a href="#" class="btn btn-outline-success activate-user-btn"
                                        data-user-id="<?php echo $user['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                        <i class="fas fa-check-circle"></i> Activate
                                    </a>
                                <?php else: ?>
                                    <a href="#" class="btn btn-outline-warning suspend-user-btn"
                                        data-user-id="<?php echo $user['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                        <i class="fas fa-ban"></i> Suspend
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>

                            <button class="btn btn-outline-secondary edit-user" data-user='<?php echo json_encode($user); ?>'
                                data-bs-toggle="modal" data-bs-target="#addUserModal">
                                <i class="fas fa-edit"></i> Edit
                            </button>

                            <?php if ($role === 'teacher' && !$is_suspended): ?>
                                <a href="assign-teacher.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-tasks"></i> Assign
                                </a>
                            <?php endif; ?>

                            <?php if ($user['id'] != $_SESSION['admin_id']): ?>
                                <button type="button" class="btn btn-outline-danger delete-user-btn"
                                    data-user-id="<?php echo $user['id']; ?>"
                                    data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

    <!-- User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle" style="color: var(--prestige-text);">Create New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-4">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Role</label>
                            <select name="role" id="edit_role" class="form-select" required>
                                <option value="teacher">Teacher (Restricted Access)</option>
                                <option value="assistant">Assistant (Student Management Only)</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="user_password" class="form-control"
                                    placeholder="Leave blank to keep current">
                                <button class="btn btn-outline-secondary" type="button" id="toggleUserPassword" style="border-color: var(--prestige-border);">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted mt-2 d-block" id="password_hint"></small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4" style="border-radius: 8px;">Save User Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div style="width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem; color: #dc2626;">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <h5 class="fw-bold mb-2" style="font-family: 'Playfair Display', serif; color: var(--prestige-text);">Delete User?</h5>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">This will permanently remove <strong id="deleteUsername"
                            style="color: var(--prestige-text);"></strong> from the system.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger px-4" style="border-radius: 8px; background: #dc2626; border: none;">
                        <i class="fas fa-trash me-1"></i> Delete
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Suspend Confirmation Modal -->
    <div class="modal fade" id="suspendUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div style="width: 60px; height: 60px; background: #fef3c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem; color: #d97706;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <h5 class="fw-bold mb-2" style="font-family: 'Playfair Display', serif; color: var(--prestige-text);">Suspend User?</h5>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Are you sure you want to suspend <strong id="suspendUsername"
                            style="color: var(--prestige-text);"></strong>? They will not be able to log in.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <a href="#" id="confirmSuspendBtn" class="btn btn-warning px-4" style="border-radius: 8px; color: white;">
                        <i class="fas fa-ban me-1"></i> Suspend
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Activate Confirmation Modal -->
    <div class="modal fade" id="activateUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div style="width: 60px; height: 60px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 1.5rem; color: #16a34a;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h5 class="fw-bold mb-2" style="font-family: 'Playfair Display', serif; color: var(--prestige-text);">Activate User?</h5>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Are you sure you want to activate <strong id="activateUsername"
                            style="color: var(--prestige-text);"></strong>? They will regain access to log in.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <a href="#" id="confirmActivateBtn" class="btn btn-success px-4" style="border-radius: 8px; color: white;">
                        <i class="fas fa-check-circle me-1"></i> Activate
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.edit-user').forEach(btn => {
            btn.addEventListener('click', function () {
                const user = JSON.parse(this.dataset.user);
                document.getElementById('modalTitle').textContent = 'Edit User Account';
                document.getElementById('edit_user_id').value = user.id;
                document.getElementById('edit_username').value = user.username;
                document.getElementById('edit_role').value = user.role || 'teacher';
                const passField = document.getElementById('user_password');
                passField.value = user.raw_password || '';
                passField.type = 'text'; // Show as text by default when editing
                document.getElementById('toggleUserPassword').querySelector('i').className = 'fas fa-eye-slash';
                
                document.getElementById('user_password').required = false;
                document.getElementById('user_password').placeholder = user.raw_password ? 'Current password' : 'Not cached - enter new to save';
                document.getElementById('password_hint').textContent = user.raw_password 
                    ? 'Current password is shown above. You can modify it here.' 
                    : 'Note: Existing passwords are encrypted. Please enter a new one to make it visible here in the future.';
            });
        });

        document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('modalTitle').textContent = 'Create New User';
            document.getElementById('edit_user_id').value = '';
            document.getElementById('edit_username').value = '';
            document.getElementById('edit_role').value = 'teacher';
            document.getElementById('user_password').required = true; // Required for new user
            document.getElementById('user_password').placeholder = 'Enter password';
            document.getElementById('user_password').type = 'password'; // Reset to password type
            document.getElementById('toggleUserPassword').querySelector('i').className = 'fas fa-eye'; // Reset icon
            document.getElementById('password_hint').textContent = '';
        });

        // Toggle Password Visibility
        document.getElementById('toggleUserPassword').addEventListener('click', function () {
            const passwordField = document.getElementById('user_password');
            const icon = this.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                passwordField.type = 'password';
                icon.className = 'fas fa-eye';
            }
        });

        // Delete User Modal
        document.querySelectorAll('.delete-user-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const userId = this.dataset.userId;
                const username = this.dataset.username;
                document.getElementById('deleteUsername').textContent = username;
                document.getElementById('confirmDeleteBtn').href = '?delete=' + userId;
                new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
            });
        });

        // Suspend User Modal
        document.querySelectorAll('.suspend-user-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const userId = this.dataset.userId;
                const username = this.dataset.username;
                document.getElementById('suspendUsername').textContent = username;
                document.getElementById('confirmSuspendBtn').href = '?toggle_status=' + userId + '&status=suspended';
                new bootstrap.Modal(document.getElementById('suspendUserModal')).show();
            });
        });

        // Activate User Modal
        document.querySelectorAll('.activate-user-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const userId = this.dataset.userId;
                const username = this.dataset.username;
                document.getElementById('activateUsername').textContent = username;
                document.getElementById('confirmActivateBtn').href = '?toggle_status=' + userId + '&status=active';
                new bootstrap.Modal(document.getElementById('activateUserModal')).show();
            });
        });
    </script>
</body>

</html>