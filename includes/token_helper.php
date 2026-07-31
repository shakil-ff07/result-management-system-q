<?php
/**
 * Generates a deterministic SHA-256 token for a marksheet.
 * Same student + class + exam will always produce the same token.
 */
function generate_marksheet_token($student_id, $class_id, $exam_type) {
    // A secret salt to make tokens unguessable. 
    // In a production app, this should be moved to a protected config file.
    $secret = 'SRMS_PRESTIGE_SECURE_SALT_2026'; 
    $raw = $student_id . '|' . $class_id . '|' . $exam_type . '|' . $secret;
    return hash('sha256', $raw);
}

/**
 * Ensures a token exists in the database and returns it.
 */
function get_or_create_token($conn, $student_id, $class_id, $exam_type) {
    $token = generate_marksheet_token($student_id, $class_id, $exam_type);
    
    // Use INSERT IGNORE to avoid errors if the token already exists
    $stmt = $conn->prepare("INSERT IGNORE INTO marksheet_tokens 
                            (token, student_id, class_id, exam_type) 
                            VALUES (?, ?, ?, ?)");
    $stmt->execute([$token, $student_id, $class_id, $exam_type]);
    
    return $token;
}
?>
