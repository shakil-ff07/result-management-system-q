<?php
// Clear the undo action session variable (called fire-and-forget from JS)
include '../auth.php';
require_admin_or_assistant();
include '../../includes/db_config.php';

if (isset($_SESSION['undo_action'])) {
    unset($_SESSION['undo_action']);
}
echo json_encode(['success' => true]);
