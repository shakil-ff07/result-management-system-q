<?php
/**
 * Professional Session Management Configuration
 * Optimizes cookies and sessions for performance and security
 */

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    
    // Configure session settings BEFORE session_start()
    // For better performance and security
    
    // Session cookie parameters
    ini_set('session.name', 'SRMS_SESSION');
    
    // Session timeout: 30 minutes of inactivity
    $session_timeout = 1800; // 30 minutes
    ini_set('session.gc_maxlifetime', $session_timeout);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    
    // Session cookie parameters (PHP 7.3+ compatible)
    $cookie_options = [
        'lifetime' => 0,                    // Cookie expires when browser closes
        'path'     => '/',                  // Available to entire site
        'domain'   => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // HTTPS only
        'httponly' => true,                 // JavaScript cannot access
        'samesite' => 'Lax'                 // CSRF protection (Lax or Strict)
    ];
    
    session_set_cookie_params($cookie_options);
    
    // Disable transparent session ID propagation
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    
    // Start the session
    session_start();
    
    // Session validation for security
    // Prevent session fixation attacks
    if (!isset($_SESSION['_initiated'])) {
        $_SESSION['_initiated'] = true;
        $_SESSION['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['_user_agent'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50);
        
        // Regenerate session ID on first login
        session_regenerate_id(true);
    } else {
        // Validate session hasn't been hijacked
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== ($_SESSION['_ip'] ?? '') ||
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50) !== ($_SESSION['_user_agent'] ?? '')) {
            // Session possibly compromised
            $_SESSION = [];
            session_destroy();
            header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/../../index.php');
            exit('Session validation failed');
        }
    }
    
    // Session activity tracking for timeout
    $_SESSION['last_activity'] = $_SESSION['last_activity'] ?? time();
    
    // Check for timeout (30 minutes)
    if (time() - $_SESSION['last_activity'] > $session_timeout) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/../../index.php?session_expired=1');
        exit('Session expired');
    }
    
    // Update last activity
    $_SESSION['last_activity'] = time();
    
    // Periodically regenerate session ID (every 15 minutes)
    if (!isset($_SESSION['_regenerate_time']) || (time() - $_SESSION['_regenerate_time']) > 900) {
        session_regenerate_id(true);
        $_SESSION['_regenerate_time'] = time();
    }
}
?>
