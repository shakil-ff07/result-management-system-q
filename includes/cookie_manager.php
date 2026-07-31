<?php
/**
 * Professional Cookie Manager Class
 * Handles secure and optimized cookie management
 */

class CookieManager {
    
    private static $domain = '';
    private static $secure = false;
    private static $httponly = true;
    private static $samesite = 'Lax';
    
    /**
     * Initialize cookie settings
     */
    public static function initialize() {
        self::$domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        self::$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
    
    /**
     * Set a secure cookie with optimal parameters
     * 
     * @param string $name Cookie name
     * @param mixed $value Cookie value
     * @param int $expire Expiration time in seconds (0 = session cookie)
     * @param bool $httponly Whether cookie is HTTP only
     * @return bool
     */
    public static function set($name, $value = '', $expire = 0, $httponly = true) {
        // Sanitize cookie name
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        
        if (empty($name)) {
            return false;
        }
        
        $expire_time = $expire > 0 ? time() + $expire : 0;
        
        return setcookie(
            $name,
            $value,
            [
                'expires'  => $expire_time,
                'path'     => '/',
                'domain'   => self::$domain,
                'secure'   => self::$secure,
                'httponly' => $httponly,
                'samesite' => self::$samesite
            ]
        );
    }
    
    /**
     * Get a cookie value safely
     * 
     * @param string $name Cookie name
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public static function get($name, $default = null) {
        return isset($_COOKIE[$name]) ? $_COOKIE[$name] : $default;
    }
    
    /**
     * Delete a cookie
     * 
     * @param string $name Cookie name
     * @return bool
     */
    public static function delete($name) {
        return self::set($name, '', -3600); // Expire 1 hour ago
    }
    
    /**
     * Check if cookie exists
     * 
     * @param string $name Cookie name
     * @return bool
     */
    public static function exists($name) {
        return isset($_COOKIE[$name]);
    }
    
    /**
     * Set a preference cookie (e.g., theme, language)
     * Expires in 1 year
     * 
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @return bool
     */
    public static function setPreference($name, $value) {
        return self::set($name, $value, 31536000, true); // 1 year
    }
    
    /**
     * Clear all cookies
     */
    public static function clearAll() {
        foreach ($_COOKIE as $name => $value) {
            self::delete($name);
        }
    }
    
    /**
     * Get memory size of all cookies (for debugging)
     * 
     * @return int Size in bytes
     */
    public static function getSize() {
        $size = 0;
        foreach ($_COOKIE as $name => $value) {
            $size += strlen($name) + strlen($value);
        }
        return $size;
    }
}

// Initialize on include
CookieManager::initialize();
?>
