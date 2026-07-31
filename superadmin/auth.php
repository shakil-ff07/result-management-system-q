<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/db_config.php';

function check_auth()
{
    global $conn;
    if (!isset($_SESSION['admin_id'])) {
        header("Location: login.php");
        exit();
    }

    $admin_id = $_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT status FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] === 'suspended') {
        session_unset();
        session_destroy();
        header("Location: login.php?error=suspended");
        exit();
    }
}

// Automatically check auth on inclusion if session exists
if (isset($_SESSION['admin_id'])) {
    check_auth();
} else {
    header("Location: login.php");
    exit();
}

function is_superadmin()
{
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
}

function is_headmaster()
{
    return isset($_SESSION['admin_role']) && ($_SESSION['admin_role'] === 'headmaster' || $_SESSION['admin_role'] === 'admin');
}

function is_admin()
{
    // Both superadmin and headmaster have 'admin' privileges generally
    if (isset($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'headmaster', 'superadmin']))
        return true;
    // Fallback for primary admin during session transition
    if (isset($_SESSION['admin_username']) && $_SESSION['admin_username'] === 'admin')
        return true;
    return false;
}

function is_teacher()
{
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'teacher';
}

function require_superadmin()
{
    check_auth();
    if (!is_superadmin()) {
        header("Location: dashboard.php?error=unauthorized");
        exit();
    }
}
?>