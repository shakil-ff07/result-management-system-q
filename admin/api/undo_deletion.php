<?php
/**
 * API: Undo Deletion
 * Restores a previously deleted student/class from the deleted_records trash bin.
 *
 * Called via AJAX POST with JSON body: { "record_id": 123 }
 * Returns JSON: { "success": true/false, "message": "..." }
 */

include '../auth.php';
require_admin_or_assistant();
include '../../includes/db_config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$record_id = isset($input['record_id']) ? (int) $input['record_id'] : 0;

if (!$record_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
    exit;
}

// Fetch the archived record
$stmt = $conn->prepare("SELECT * FROM deleted_records WHERE id = ?");
$stmt->execute([$record_id]);
$record = $stmt->fetch();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Undo record not found. It may have already expired.']);
    exit;
}

$data = json_decode($record['serialized_data'], true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Corrupt archive data. Cannot restore.']);
    exit;
}

try {
    $conn->beginTransaction();

    // Disable FK checks so we can re-insert with original IDs freely
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // ─── RESTORE: CLASSES ─────────────────────────────────────────────────
    if (!empty($data['classes'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO classes (id, class_name, section, academic_year) VALUES (?, ?, ?, ?)");
        foreach ($data['classes'] as $row) {
            $stmt->execute([$row['id'], $row['class_name'], $row['section'], $row['academic_year']]);
        }
    }

    // ─── RESTORE: CLASS SUBJECTS ──────────────────────────────────────────
    if (!empty($data['class_subjects'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO class_subjects (id, class_id, subject_id, student_group, is_optional, is_school_based) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($data['class_subjects'] as $row) {
            $stmt->execute([$row['id'], $row['class_id'], $row['subject_id'], $row['student_group'], $row['is_optional'], $row['is_school_based']]);
        }
    }

    // ─── RESTORE: TEACHER ASSIGNMENTS ─────────────────────────────────────
    if (!empty($data['teacher_assignments'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO teacher_assignments (id, teacher_id, class_id, subject_id) VALUES (?, ?, ?, ?)");
        foreach ($data['teacher_assignments'] as $row) {
            $stmt->execute([$row['id'], $row['teacher_id'], $row['class_id'], $row['subject_id']]);
        }
    }

    // ─── RESTORE: PUBLISH SETTINGS ────────────────────────────────────────
    if (!empty($data['publish_settings'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO publish_settings (class_id, exam_type, is_published) VALUES (?, ?, ?)");
        foreach ($data['publish_settings'] as $row) {
            $stmt->execute([$row['class_id'], $row['exam_type'], $row['is_published']]);
        }
    }

    // ─── RESTORE: STUDENTS ────────────────────────────────────────────────
    if (!empty($data['students'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO students 
            (id, roll_number, name, father_name, mother_name, dob, class_id, student_group, 
             main_elective_id, optional_subject_id, created_at, email, phone) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['students'] as $row) {
            $stmt->execute([
                $row['id'], $row['roll_number'], $row['name'],
                $row['father_name'] ?? null, $row['mother_name'] ?? null,
                $row['dob'] ?? null, $row['class_id'] ?? null,
                $row['student_group'] ?? 'None',
                $row['main_elective_id'] ?? null, $row['optional_subject_id'] ?? null,
                $row['created_at'] ?? null, $row['email'] ?? null, $row['phone'] ?? null
            ]);
        }
    }

    // ─── RESTORE: MARKS ───────────────────────────────────────────────────
    if (!empty($data['marks'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO marks 
            (id, student_id, subject_id, exam_type, cq_marks, mcq_marks, sq_marks, practical_marks, total_marks, grade, gpa) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['marks'] as $row) {
            $stmt->execute([
                $row['id'], $row['student_id'], $row['subject_id'], $row['exam_type'],
                $row['cq_marks'] ?? 0, $row['mcq_marks'] ?? 0, $row['sq_marks'] ?? 0,
                $row['practical_marks'] ?? 0, $row['total_marks'] ?? 0,
                $row['grade'] ?? null, $row['gpa'] ?? null
            ]);
        }
    }

    // ─── RESTORE: FINAL RESULTS ───────────────────────────────────────────
    if (!empty($data['final_results'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO final_results 
            (id, student_id, exam_type, total_marks, average_marks, total_gpa, final_grade, status, position) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['final_results'] as $row) {
            $stmt->execute([
                $row['id'], $row['student_id'], $row['exam_type'],
                $row['total_marks'] ?? null, $row['average_marks'] ?? null,
                $row['total_gpa'] ?? null, $row['final_grade'] ?? null,
                $row['status'] ?? 'Pass', $row['position'] ?? null
            ]);
        }
    }

    // ─── RESTORE: MARKSHEET TOKENS ────────────────────────────────────────
    if (!empty($data['marksheet_tokens'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO marksheet_tokens 
            (id, token, student_id, class_id, exam_type, issued_at, expires_at, view_count) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['marksheet_tokens'] as $row) {
            $stmt->execute([
                $row['id'], $row['token'], $row['student_id'], $row['class_id'],
                $row['exam_type'], $row['issued_at'] ?? null, $row['expires_at'] ?? null,
                $row['view_count'] ?? 0
            ]);
        }
    }

    // Re-enable FK checks
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Remove from trash bin
    $conn->prepare("DELETE FROM deleted_records WHERE id = ?")->execute([$record_id]);

    $conn->commit();

    // Clear session undo action if it matches this record
    if (isset($_SESSION['undo_action']) && (int)$_SESSION['undo_action']['record_id'] === $record_id) {
        unset($_SESSION['undo_action']);
    }

    echo json_encode(['success' => true, 'message' => htmlspecialchars($record['entity_name']) . ' has been restored successfully.']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
}
