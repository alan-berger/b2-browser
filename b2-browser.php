<?php
/**
 * Backblaze B2 Private Bucket File Browser
 * Version 1.2.0 - With Activity Logging and Email Alerts
 * 
 * Configuration Instructions:
 * 1. Fill in your B2 credentials below
 * 2. Upload this file to your shared hosting
 * 3. Ensure PHP has curl and json extensions enabled
 */

// ============================================
// CONFIGURATION - FILL THESE IN
// ============================================

// Backblaze B2 Credentials
define('B2_KEY_ID', 'your_application_key_id');
define('B2_APPLICATION_KEY', 'your_application_key');
define('B2_BUCKET_NAME', 'your_bucket_name');
define('B2_BUCKET_ID', 'your_bucket_id');

// Your Domain and Contact Information
define('YOUR_DOMAIN', 'yourdomain.com');           // Your domain (without https://)
define('YOUR_HOMEPAGE', 'https://yourdomain.com'); // Full URL to your homepage
define('ADMIN_EMAIL', 'admin@yourdomain.com');     // Primary admin email (used for Reply-To)
define('ALERT_EMAILS', 'admin@yourdomain.com,backup@yourdomain.com'); // Comma-separated list of emails to receive alerts

// Optional: Add basic authentication for extra security
define('AUTH_USERNAME', ''); // Leave empty to disable
define('AUTH_PASSWORD', ''); // Leave empty to disable

// Rate Limiting (fail2ban style protection)
define('RATE_LIMIT_ENABLED', true);
define('MAX_LOGIN_ATTEMPTS', 5); // Maximum failed attempts
define('LOCKOUT_DURATION', 900); // Lockout time in seconds (15 minutes)
define('ATTEMPT_WINDOW', 300); // Time window to track attempts (5 minutes)

// Multi-Factor Authentication (TOTP) - Optional but highly recommended
define('MFA_ENABLED', false); // Set to true to enable MFA
define('MFA_SECRET', ''); // Generate using instructions below
define('MFA_ISSUER', 'B2 File Browser');

// Activity Logging and Email Alerts
define('LOG_ENABLED', true);
define('LOG_FILE_PATH', '/path/to/activity.log');	// Your path for the activity.log
define('EMAIL_ALERTS_ENABLED', true);
// ALERT_EMAILS is now defined above with YOUR_DOMAIN and ADMIN_EMAIL
define('ALERT_ON_LOGIN', true);           // Email on successful login
define('ALERT_ON_FAILED_LOGIN', true);    // Email after 3 failed attempts
define('ALERT_ON_LOCKOUT', true);         // Email on account lockout
define('ALERT_ON_DOWNLOADS', false);      // Email on file downloads (can be noisy)
define('ALERT_ON_PLAYBACK', false);       // Email on video playback (can be noisy)

// Session timeout in seconds (default: 30 minutes)
define('SESSION_TIMEOUT', 1800);

// Diagnostic mode - set to true to see detailed error messages
define('DEBUG_MODE', false);

// Pagination - number of files to show per page
define('FILES_PER_PAGE', 100);

// Cache B2 auth token in session to reduce API calls
define('USE_SESSION_CACHE', true);

// ============================================
// BASIC AUTHENTICATION WITH SESSION TIMEOUT
// ============================================
if (defined('AUTH_USERNAME') && defined('AUTH_PASSWORD') && AUTH_USERNAME !== '' && AUTH_PASSWORD !== '') {
    session_start();
    
    // Handle logout request
    if (isset($_GET['logout'])) {
        // Log logout event
        logActivity('LOGOUT', [
            'username' => $_SESSION['username'] ?? 'Unknown'
        ]);
        
        session_unset();
        session_destroy();
        
        // Don't send 401 - just show logout message and redirect
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="2;url=' . YOUR_HOMEPAGE . '" />';
        echo '<title>Logged Out</title></head>';
        echo '<body style="font-family: Arial, sans-serif; padding: 40px; text-align: center;">';
        echo '<h1 style="color: #2ecc71;">✅ Logged Out Successfully</h1>';
        echo '<p style="font-size: 16px;">You have been logged out.</p>';
        echo '<p style="font-size: 14px; color: #666;">Redirecting to homepage in 2 seconds...</p>';
        echo '<p style="margin-top: 20px;"><a href="' . YOUR_HOMEPAGE . '" style="color: #3498db; text-decoration: none;">← Return to Homepage Now</a></p>';
        echo '</body></html>';
        exit;
    }
    
    $clientIP = getClientIP();
    
    // Check rate limiting
    $rateLimitCheck = checkRateLimit($clientIP);
    
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Rate limit check for IP {$clientIP}: " . json_encode($rateLimitCheck));
    }
    
    if (!$rateLimitCheck['allowed']) {
        $minutes = ceil($rateLimitCheck['remaining'] / 60);
        
        // Log lockout event
        logActivity('ACCOUNT_LOCKED', [
            'reason' => 'Too many failed login attempts',
            'attempts' => $rateLimitCheck['attempts'],
            'lockout_duration' => $minutes . ' minutes'
        ]);
        
        // Send email alert
        if (ALERT_ON_LOCKOUT) {
            $message = "
            <div class='alert-box alert-danger'>
                <p class='alert-title'>⚠️ Account Lockout Detected</p>
                <p>Your camera system has temporarily locked an IP address due to multiple failed login attempts.</p>
            </div>
            <table>
                <tr><th>IP Address</th><td>{$clientIP}</td></tr>
                <tr><th>Failed Attempts</th><td>{$rateLimitCheck['attempts']} / " . MAX_LOGIN_ATTEMPTS . "</td></tr>
                <tr><th>Lockout Duration</th><td>{$minutes} minutes</td></tr>
                <tr><th>Event Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
            </table>
            <p><strong>⚡ Action Required:</strong> If this was not you or an authorized user, someone may be attempting unauthorized access to your camera footage.</p>
            <p>The IP address has been automatically blocked and will be able to retry after the lockout period expires.</p>
            ";
            sendEmailAlert('🔒 Security Alert: Account Locked', $message);
        }
        
        header('HTTP/1.0 429 Too Many Requests');
        echo '<html><body style="font-family: Arial, sans-serif; padding: 40px; text-align: center;">';
        echo '<h1 style="color: #e74c3c;">🔒 Account Temporarily Locked</h1>';
        echo '<p style="font-size: 18px;">Too many failed login attempts.</p>';
        echo '<p style="font-size: 16px; color: #666;">Please try again in <strong>' . $minutes . ' minute(s)</strong>.</p>';
        echo '<p style="font-size: 14px; color: #999;">Attempts: ' . $rateLimitCheck['attempts'] . '/' . MAX_LOGIN_ATTEMPTS . '</p>';
        echo '</body></html>';
        exit;
    }
    
    // Check if user is already authenticated
    $isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    
    // Check session timeout
    if ($isAuthenticated && isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            // Session expired
            session_unset();
            session_destroy();
            session_start();
            $isAuthenticated = false;
        }
    }

    // Safari on iOS does not forward Basic Auth credentials on video sub-requests
    // (e.g. the ?stream= request made by the <video> element). If the user has a
    // valid session we allow the stream through without requiring a re-challenge,
    // and close the session immediately so it doesn't block the output stream.
    if (isset($_GET['stream'])) {
        if ($isAuthenticated) {
            session_write_close(); // Release session lock before streaming
            // Fall through to the stream handler below
        } else {
            // No valid session — reject cleanly without triggering a Basic Auth popup
            header('HTTP/1.1 403 Forbidden');
            exit;
        }
    }
    
    // Handle MFA setup request
    if (isset($_GET['setup_mfa']) && MFA_ENABLED && !empty(MFA_SECRET)) {
        if (!$isAuthenticated || !isset($_SESSION['username'])) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MFA Setup</title>';
        echo '<style>body{font-family:Arial,sans-serif;max-width:600px;margin:50px auto;padding:20px;text-align:center;}';
        echo 'h1{color:#2c3e50;}.qr-code{margin:20px 0;}.secret{background:#f5f5f5;padding:15px;border-radius:5px;font-family:monospace;font-size:18px;letter-spacing:3px;}.info{text-align:left;background:#e3f2fd;padding:15px;border-radius:5px;margin:20px 0;}</style></head><body>';
        echo '<h1>🔐 Multi-Factor Authentication Setup</h1>';
        echo '<div class="info"><strong>Step 1:</strong> Install an authenticator app on your phone (Google Authenticator, Authy, Microsoft Authenticator, etc.)</div>';
        echo '<div class="info"><strong>Step 2:</strong> Scan this QR code with your authenticator app:</div>';
        echo '<img src="' . getMFAQRCode(MFA_SECRET, $_SESSION['username']) . '" alt="QR Code" class="qr-code">';
        echo '<div class="info"><strong>Step 3:</strong> Or manually enter this secret code:</div>';
        echo '<div class="secret">' . chunk_split(MFA_SECRET, 4, ' ') . '</div>';
        echo '<div class="info"><strong>Step 4:</strong> Once configured, you\'ll need to enter the 6-digit code from your app when logging in.</div>';
        echo '<p><a href="' . strtok($_SERVER['REQUEST_URI'], '?') . '" style="color:#3498db;">← Back to File Browser</a></p>';
        echo '</body></html>';
        exit;
    }
    
    // If not authenticated, check credentials
    if (!$isAuthenticated) {
        $credentialsValid = false;
        $mfaRequired = MFA_ENABLED && !empty(MFA_SECRET);
        
        // Check basic auth credentials
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            if ($_SERVER['PHP_AUTH_USER'] === AUTH_USERNAME && $_SERVER['PHP_AUTH_PW'] === AUTH_PASSWORD) {
                $credentialsValid = true;
            } else {
                // Wrong username or password - record failed attempt
                recordFailedAttempt($clientIP);
                
                // Log failed attempt
                logActivity('LOGIN_FAILED', [
                    'reason' => 'Invalid credentials',
                    'username_attempt' => $_SERVER['PHP_AUTH_USER']
                ]);
                
                // Check if we should send email alert (after 3 failed attempts)
                $attemptsFile = sys_get_temp_dir() . '/b2_login_attempts.json';
                if (file_exists($attemptsFile)) {
                    $attempts = json_decode(file_get_contents($attemptsFile), true) ?: [];
                    if (isset($attempts[$clientIP]) && $attempts[$clientIP]['count'] >= 3 && ALERT_ON_FAILED_LOGIN) {
                        $message = "
                        <div class='alert-box alert-warning'>
                            <p class='alert-title'>⚠️ Multiple Failed Login Attempts</p>
                            <p>Your camera system has detected multiple unsuccessful login attempts from the same IP address.</p>
                        </div>
                        <table>
                            <tr><th>IP Address</th><td>{$clientIP}</td></tr>
                            <tr><th>Failed Attempts</th><td>{$attempts[$clientIP]['count']}</td></tr>
                            <tr><th>Username Attempted</th><td>" . htmlspecialchars($_SERVER['PHP_AUTH_USER']) . "</td></tr>
                            <tr><th>Event Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                        </table>
                        <p><strong>Note:</strong> The account will be automatically locked after " . MAX_LOGIN_ATTEMPTS . " failed attempts (" . (MAX_LOGIN_ATTEMPTS - $attempts[$clientIP]['count']) . " attempts remaining).</p>
                        <p>If you recognize this activity, you can ignore this message. If not, someone may be attempting to gain unauthorized access.</p>
                        ";
                        sendEmailAlert('⚠️ Security Alert: Failed Login Attempts', $message);
                    }
                }
            }
        }
        
        // If credentials are invalid, deny access
        if (!$credentialsValid) {
            header('WWW-Authenticate: Basic realm="B2 File Browser"');
            header('HTTP/1.0 401 Unauthorized');
            echo '<html><body style="font-family: Arial, sans-serif; padding: 40px; text-align: center;">';
            echo '<h1>Authentication Required</h1>';
            echo '<p>You must log in to access this page.</p>';
            echo '<p style="margin-top: 20px;"><a href="' . YOUR_HOMEPAGE . '" style="color: #3498db; text-decoration: none;">← Return to Homepage</a></p>';
            echo '</body></html>';
            exit;
        }
        
        // If credentials are valid and MFA is enabled, check MFA code
        if ($credentialsValid && $mfaRequired) {
            if (isset($_POST['mfa_code'])) {
                $mfaCode = preg_replace('/[^0-9]/', '', $_POST['mfa_code']);
                
                if (verifyTOTP(MFA_SECRET, $mfaCode)) {
                    // MFA verified, authentication successful
                    $_SESSION['authenticated'] = true;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['username'] = AUTH_USERNAME;
                    clearFailedAttempts($clientIP);
                    
                    // Log successful login
                    logActivity('LOGIN_SUCCESS', [
                        'method' => 'HTTP Basic Auth + MFA',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                    
                    // Send email alert
                    if (ALERT_ON_LOGIN) {
                        $message = "
                        <div class='alert-box alert-success'>
                            <p class='alert-title'>✅ Successful Login</p>
                            <p>A user has successfully authenticated and accessed your camera monitoring system.</p>
                        </div>
                        <table>
                            <tr><th>Username</th><td>" . htmlspecialchars(AUTH_USERNAME) . "</td></tr>
                            <tr><th>IP Address</th><td>{$clientIP}</td></tr>
                            <tr><th>Authentication Method</th><td>HTTP Basic Auth + Two-Factor (TOTP)</td></tr>
                            <tr><th>Login Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                            <tr><th>Browser/Device</th><td>" . htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 100)) . "</td></tr>
                        </table>
                        <p>If this login was authorized, no action is needed. If you don't recognize this activity, please secure your account immediately:</p>
                        <ul>
                            <li>Change your password</li>
                            <li>Review recent access logs</li>
                            <li>Check your MFA device for unauthorized access</li>
                        </ul>
                        ";
                        sendEmailAlert('✅ Login Notification: Camera System Access', $message);
                    }
                    
                    // Redirect to remove POST data
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                } else {
                    // Invalid MFA code
                    recordFailedAttempt($clientIP);
                    
                    // Log MFA failure
                    logActivity('MFA_FAILED', [
                        'reason' => 'Invalid TOTP code'
                    ]);
                    
                    $mfaError = 'Invalid authentication code. Please try again.';
                }
            }
            
            // Show MFA input form
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>Two-Factor Authentication</title>';
            echo '<style>body{font-family:Arial,sans-serif;background:#f5f5f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}';
            echo '.mfa-container{background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:400px;width:100%;}';
            echo 'h1{color:#2c3e50;margin-bottom:10px;font-size:24px;}p{color:#666;margin-bottom:20px;}';
            echo '.form-group{margin-bottom:20px;}label{display:block;margin-bottom:8px;color:#555;font-weight:500;}';
            echo 'input[type="text"]{width:100%;padding:12px;border:2px solid #ddd;border-radius:5px;font-size:18px;letter-spacing:5px;text-align:center;font-family:monospace;}';
            echo 'input[type="text"]:focus{outline:none;border-color:#3498db;}';
            echo '.btn{width:100%;padding:12px;background:#3498db;color:#fff;border:none;border-radius:5px;font-size:16px;cursor:pointer;transition:background 0.2s;}';
            echo '.btn:hover{background:#2980b9;}.error{background:#fee;color:#c33;padding:12px;border-radius:5px;margin-bottom:20px;border:1px solid #fcc;}';
            echo '.help{font-size:12px;color:#999;margin-top:10px;}</style></head><body>';
            echo '<div class="mfa-container">';
            echo '<h1>🔐 Two-Factor Authentication</h1>';
            echo '<p>Enter the 6-digit code from your authenticator app</p>';
            if (isset($mfaError)) {
                echo '<div class="error">' . htmlspecialchars($mfaError) . '</div>';
            }
            echo '<form method="POST">';
            echo '<div class="form-group">';
            echo '<label for="mfa_code">Authentication Code</label>';
            echo '<input type="text" id="mfa_code" name="mfa_code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off">';
            echo '<div class="help">Enter the 6-digit code from your authenticator app</div>';
            echo '</div>';
            echo '<button type="submit" class="btn">Verify</button>';
            echo '</form>';
            echo '</div>';
            echo '<script>document.getElementById("mfa_code").focus();</script>';
            echo '</body></html>';
            exit;
        }
        
        // If credentials are valid but MFA not required, authenticate now
        if ($credentialsValid && !$mfaRequired) {
            $_SESSION['authenticated'] = true;
            $_SESSION['last_activity'] = time();
            $_SESSION['username'] = AUTH_USERNAME;
            clearFailedAttempts($clientIP);
            
            // Log successful login (no MFA)
            logActivity('LOGIN_SUCCESS', [
                'method' => 'HTTP Basic Auth (no MFA)',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // Send email alert
            if (ALERT_ON_LOGIN) {
                $message = "
                <div class='alert-box alert-success'>
                    <p class='alert-title'>✅ Successful Login</p>
                    <p>A user has successfully authenticated and accessed your camera monitoring system.</p>
                </div>
                <table>
                    <tr><th>Username</th><td>" . htmlspecialchars(AUTH_USERNAME) . "</td></tr>
                    <tr><th>IP Address</th><td>{$clientIP}</td></tr>
                    <tr><th>Authentication Method</th><td>HTTP Basic Auth Only</td></tr>
                    <tr><th>Login Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                    <tr><th>Browser/Device</th><td>" . htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 100)) . "</td></tr>
                </table>
                <div class='alert-box alert-warning'>
                    <p class='alert-title'>🔐 Security Recommendation</p>
                    <p>Consider enabling Multi-Factor Authentication (MFA) for enhanced security. MFA adds an additional layer of protection by requiring a time-based code from your phone.</p>
                </div>
                ";
                sendEmailAlert('✅ Login Notification: Camera System Access', $message);
            }
        }
    } else {
        // Update last activity time
        $_SESSION['last_activity'] = time();
    }
}

// ============================================
// B2 API CLASS
// ============================================
class B2Api {
    private $authToken;
    private $apiUrl;
    private $downloadUrl;
    private $authExpiry;
    
    public function __construct() {
        // Try to restore from session cache first
        if (USE_SESSION_CACHE && isset($_SESSION['b2_auth_token']) && 
            isset($_SESSION['b2_auth_expiry']) && time() < $_SESSION['b2_auth_expiry']) {
            $this->authToken = $_SESSION['b2_auth_token'];
            $this->apiUrl = $_SESSION['b2_api_url'];
            $this->downloadUrl = $_SESSION['b2_download_url'];
            $this->authExpiry = $_SESSION['b2_auth_expiry'];
            
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("B2: Using cached authentication token");
            }
        } else {
            $this->authenticate();
        }
    }
    
    private function authenticate() {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Attempting authentication with Key ID: " . substr(B2_KEY_ID, 0, 10) . "...");
        }
        
        $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode(B2_KEY_ID . ':' . B2_APPLICATION_KEY)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Auth HTTP Code: " . $httpCode);
            if ($curlError) {
                error_log("B2: cURL Error: " . $curlError);
            }
        }
        
        if ($httpCode !== 200) {
            $errorMsg = 'B2 Authentication failed (HTTP ' . $httpCode . ')';
            if ($curlError) {
                $errorMsg .= ' - cURL Error: ' . $curlError;
            }
            if ($response) {
                $errorMsg .= ' - Response: ' . $response;
            }
            throw new Exception($errorMsg);
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['authorizationToken'])) {
            throw new Exception('B2 Authentication failed: Invalid response format');
        }
        
        $this->authToken = $data['authorizationToken'];
        $this->apiUrl = $data['apiUrl'];
        $this->downloadUrl = $data['downloadUrl'];
        $this->authExpiry = time() + 82800; // 23 hours
        
        // Cache in session
        if (USE_SESSION_CACHE) {
            $_SESSION['b2_auth_token'] = $this->authToken;
            $_SESSION['b2_api_url'] = $this->apiUrl;
            $_SESSION['b2_download_url'] = $this->downloadUrl;
            $_SESSION['b2_auth_expiry'] = $this->authExpiry;
        }
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Authentication successful! API URL: " . $this->apiUrl);
        }
    }
    
    private function ensureAuth() {
        if (time() >= $this->authExpiry) {
            $this->authenticate();
        }
    }
    
    public function listFiles($prefix = '', $delimiter = '') {
        $this->ensureAuth();
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Listing files with prefix: '$prefix' and delimiter: '$delimiter'");
        }
        
        $url = $this->apiUrl . '/b2api/v2/b2_list_file_names';
        $data = [
            'bucketId' => B2_BUCKET_ID,
            'prefix' => $prefix,
            'maxFileCount' => FILES_PER_PAGE
        ];
        
        if ($delimiter !== '') {
            $data['delimiter'] = $delimiter;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: List files HTTP Code: " . $httpCode);
            if ($curlError) {
                error_log("B2: cURL Error: " . $curlError);
            }
            error_log("B2: Response: " . substr($response, 0, 500));
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200 || !$result) {
            throw new Exception('B2 List files failed (HTTP ' . $httpCode . '): ' . $response);
        }
        
        return $result;
    }
    
    public function getDownloadUrl($fileName) {
        $this->ensureAuth();
        return $this->downloadUrl . '/file/' . B2_BUCKET_NAME . '/' . $fileName;
    }
    
    public function getAuthToken() {
        $this->ensureAuth();
        return $this->authToken;
    }

    /**
     * Generate a short-lived signed URL using b2_get_download_authorization.
     * The browser can use it directly in a <video> tag with no Authorization header,
     * which fixes iOS Safari which cannot forward credentials on video sub-requests.
     */
    public function getSignedUrl($fileName, $validDurationSeconds = 3600) {
        $this->ensureAuth();

        $url  = $this->apiUrl . '/b2api/v2/b2_get_download_authorization';
        $data = [
            'bucketId'               => B2_BUCKET_ID,
            'fileNamePrefix'         => $fileName,
            'validDurationInSeconds' => $validDurationSeconds,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode !== 200 || empty($result['authorizationToken'])) {
            throw new Exception('B2 signed URL failed (HTTP ' . $httpCode . '): ' . $response);
        }

        $baseUrl = $this->downloadUrl . '/file/' . B2_BUCKET_NAME . '/' . $fileName;
        return $baseUrl . '?Authorization=' . urlencode($result['authorizationToken']);
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function parseFilePath($filePath) {
    $parts = explode('/', $filePath);
    $info = [
        'camera' => '',
        'year' => '',
        'month' => '',
        'day' => '',
        'cameraId' => '',
        'fileName' => '',
        'type' => ''
    ];
    
    if (count($parts) >= 6) {
        $info['camera'] = $parts[0];
        $info['year'] = $parts[1];
        $info['month'] = $parts[2];
        $info['day'] = $parts[3];
        $info['cameraId'] = $parts[4];
        $info['fileName'] = end($parts);
        $info['type'] = pathinfo($info['fileName'], PATHINFO_EXTENSION);
    }
    
    return $info;
}

// ============================================
// ACTIVITY LOGGING AND EMAIL ALERTS
// ============================================
function logActivity($event, $details = []) {
    if (!LOG_ENABLED) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'N/A';
    
    $logEntry = [
        'timestamp' => $timestamp,
        'event' => $event,
        'username' => $username,
        'ip' => $ip,
        'details' => $details
    ];
    
    $logLine = json_encode($logEntry) . "\n";
    
    // Ensure log directory exists
    $logDir = dirname(LOG_FILE_PATH);
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Write to log file
    @file_put_contents(LOG_FILE_PATH, $logLine, FILE_APPEND | LOCK_EX);
    
    // Rotate log if it gets too large (> 10MB)
    if (file_exists(LOG_FILE_PATH) && filesize(LOG_FILE_PATH) > 10485760) {
        @rename(LOG_FILE_PATH, LOG_FILE_PATH . '.' . date('Y-m-d-His') . '.old');
    }
}

function sendEmailAlert($subject, $message) {
    if (!EMAIL_ALERTS_ENABLED) {
        return;
    }
    
    $emails = array_map('trim', explode(',', ALERT_EMAILS));
    
    // Use proper domain for sender
    $fromEmail = 'alerts@' . YOUR_DOMAIN;
    $fromName = 'Camera Security System';
    
    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . ADMIN_EMAIL,
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Priority: 1',
        'Importance: High',
        'Message-ID: <' . time() . '.' . md5($subject . microtime()) . '@' . YOUR_DOMAIN . '>'
    ];
    
    $htmlMessage = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>" . htmlspecialchars($subject) . "</title>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                line-height: 1.6; 
                color: #333;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
            }
            .email-wrapper {
                max-width: 600px;
                margin: 20px auto;
                background: #ffffff;
            }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff; 
                padding: 30px 20px;
                text-align: center;
            }
            .header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            .content { 
                background: #ffffff;
                padding: 30px 20px;
            }
            .alert-box {
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
            .alert-success { 
                background: #d4edda;
                border-left: 4px solid #28a745;
                color: #155724;
            }
            .alert-warning { 
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                color: #856404;
            }
            .alert-danger { 
                background: #f8d7da;
                border-left: 4px solid #dc3545;
                color: #721c24;
            }
            .alert-title {
                font-size: 18px;
                font-weight: 600;
                margin: 0 0 10px 0;
            }
            table { 
                width: 100%; 
                margin: 20px 0;
                border-collapse: collapse;
                background: #fff;
            }
            th { 
                text-align: left; 
                padding: 12px;
                background: #f8f9fa;
                color: #495057;
                font-weight: 600;
                border-bottom: 2px solid #dee2e6;
            }
            td { 
                padding: 12px;
                border-bottom: 1px solid #dee2e6;
                color: #212529;
            }
            tr:last-child td {
                border-bottom: none;
            }
            .footer { 
                background: #f8f9fa;
                padding: 20px;
                text-align: center;
                font-size: 12px;
                color: #6c757d;
                border-top: 1px solid #dee2e6;
            }
            .footer p {
                margin: 5px 0;
            }
            .button {
                display: inline-block;
                padding: 12px 24px;
                background: #667eea;
                color: #ffffff !important;
                text-decoration: none;
                border-radius: 6px;
                margin: 20px 0;
                font-weight: 600;
            }
            .timestamp {
                font-family: 'Courier New', monospace;
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 13px;
            }
        </style>
    </head>
    <body>
        <div class='email-wrapper'>
            <div class='header'>
                <h1>🎥 Camera Security Alert</h1>
            </div>
            <div class='content'>
                {$message}
            </div>
            <div class='footer'>
                <p><strong>Camera Backup System</strong></p>
                <p>This is an automated security notification from your camera monitoring system</p>
                <p>System: " . YOUR_DOMAIN . " | Time: " . date('l, F j, Y \a\t g:i A T') . "</p>
                <p style='margin-top: 15px; font-size: 11px; color: #999;'>
                    If you did not expect this email, please contact the system administrator immediately.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Plain text alternative for better deliverability
    $plainMessage = strip_tags(str_replace(['<br>', '<p>', '</p>'], ["\n", "\n", "\n"], $message));
    
    foreach ($emails as $email) {
        // Set envelope sender to match From address for SPF alignment
        @mail($email, $subject, $htmlMessage, implode("\r\n", $headers), '-f ' . $fromEmail);
    }
}

// ============================================
// RATE LIMITING FUNCTIONS
// ============================================
function getClientIP() {
    $ipHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($ipHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // For X-Forwarded-For, take the first IP
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            return trim($ip);
        }
    }
    return 'UNKNOWN';
}

function checkRateLimit($ip) {
    if (!RATE_LIMIT_ENABLED) {
        return ['allowed' => true];
    }
    
    $attemptsFile = sys_get_temp_dir() . '/b2_login_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $data = file_get_contents($attemptsFile);
        $attempts = json_decode($data, true) ?: [];
    }
    
    $now = time();
    $cleaned = false;
    
    // Clean old attempts
    foreach ($attempts as $key => $data) {
        if ($now - $data['first_attempt'] > ATTEMPT_WINDOW) {
            unset($attempts[$key]);
            $cleaned = true;
        }
    }
    
    // Only save if we cleaned something
    if ($cleaned) {
        file_put_contents($attemptsFile, json_encode($attempts));
    }
    
    // Check if IP is locked out
    if (isset($attempts[$ip])) {
        $ipData = $attempts[$ip];
        
        // Check if currently locked out
        if (isset($ipData['locked_until']) && $now < $ipData['locked_until']) {
            $remainingTime = $ipData['locked_until'] - $now;
            return [
                'allowed' => false,
                'locked' => true,
                'remaining' => $remainingTime,
                'attempts' => $ipData['count']
            ];
        }
        
        // Reset if lockout expired
        if (isset($ipData['locked_until']) && $now >= $ipData['locked_until']) {
            unset($attempts[$ip]);
            file_put_contents($attemptsFile, json_encode($attempts));
        }
    }
    
    // Check current attempt count
    if (isset($attempts[$ip]) && $attempts[$ip]['count'] >= MAX_LOGIN_ATTEMPTS) {
        $attempts[$ip]['locked_until'] = $now + LOCKOUT_DURATION;
        file_put_contents($attemptsFile, json_encode($attempts));
        
        return [
            'allowed' => false,
            'locked' => true,
            'remaining' => LOCKOUT_DURATION,
            'attempts' => $attempts[$ip]['count']
        ];
    }
    
    return ['allowed' => true];
}

function recordFailedAttempt($ip) {
    if (!RATE_LIMIT_ENABLED) {
        return;
    }
    
    $attemptsFile = sys_get_temp_dir() . '/b2_login_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $data = file_get_contents($attemptsFile);
        $attempts = json_decode($data, true) ?: [];
    }
    
    $now = time();
    
    if (!isset($attempts[$ip])) {
        $attempts[$ip] = [
            'count' => 1,
            'first_attempt' => $now
        ];
    } else {
        $attempts[$ip]['count']++;
    }
    
    file_put_contents($attemptsFile, json_encode($attempts));
}

function clearFailedAttempts($ip) {
    if (!RATE_LIMIT_ENABLED) {
        return;
    }
    
    $attemptsFile = sys_get_temp_dir() . '/b2_login_attempts.json';
    
    if (file_exists($attemptsFile)) {
        $data = file_get_contents($attemptsFile);
        $attempts = json_decode($data, true) ?: [];
        
        if (isset($attempts[$ip])) {
            unset($attempts[$ip]);
            file_put_contents($attemptsFile, json_encode($attempts));
        }
    }
}

// ============================================
// MFA (TOTP) FUNCTIONS
// ============================================
function generateMFASecret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}

function base32Decode($secret) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper($secret);
    $paddingCharCount = substr_count($secret, '=');
    $allowedValues = [6, 4, 3, 1, 0];
    
    if (!in_array($paddingCharCount, $allowedValues)) {
        return false;
    }
    
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $val = strpos($chars, $secret[$i]);
        if ($val === false) {
            continue;
        }
        
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $output .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    
    return $output;
}

function verifyTOTP($secret, $code, $timeSlice = null) {
    if ($timeSlice === null) {
        $timeSlice = floor(time() / 30);
    }
    
    $secretKey = base32Decode($secret);
    
    // Check current time slice and ±1 time slice (90 second window)
    for ($i = -1; $i <= 1; $i++) {
        $calculatedCode = getTOTP($secretKey, $timeSlice + $i);
        if ($calculatedCode === $code) {
            return true;
        }
    }
    
    return false;
}

function getTOTP($key, $timeSlice) {
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function getMFAQRCode($secret, $username) {
    $issuer = urlencode(MFA_ISSUER);
    $username = urlencode($username);
    $otpauthUrl = "otpauth://totp/{$issuer}:{$username}?secret={$secret}&issuer={$issuer}";
    return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauthUrl);
}

// ============================================
// MAIN APPLICATION LOGIC
// ============================================

// Display configuration check
$configError = null;
if (!defined('B2_KEY_ID') || B2_KEY_ID === 'your_application_key_id') {
    $configError = 'B2_KEY_ID not configured';
} elseif (!defined('B2_APPLICATION_KEY') || B2_APPLICATION_KEY === 'your_application_key') {
    $configError = 'B2_APPLICATION_KEY not configured';
} elseif (!defined('B2_BUCKET_NAME') || B2_BUCKET_NAME === 'your_bucket_name') {
    $configError = 'B2_BUCKET_NAME not configured';
} elseif (!defined('B2_BUCKET_ID') || B2_BUCKET_ID === 'your_bucket_id') {
    $configError = 'B2_BUCKET_ID not configured';
}

$error = null;
$files = [];
$currentPath = '';

if (!$configError) {
    try {
        $b2 = new B2Api();
        
        // Handle file retrieval request
        if (isset($_GET['download'])) {
            $fileName = $_GET['download'];
            
            // Security: Prevent directory traversal attacks
            if (strpos($fileName, '..') !== false) {
                die('Invalid file path');
            }
            
            // Log download event
            logActivity('FILE_DOWNLOAD', [
                'filename' => $fileName,
                'filesize' => 'Unknown'
            ]);
            
            // Send email alert if enabled
            if (ALERT_ON_DOWNLOADS) {
                $message = "
                <div class='alert-box alert-success'>
                    <p class='alert-title'>📥 File Downloaded</p>
                    <p>A user has downloaded a file from your camera backup system.</p>
                </div>
                <table>
                    <tr><th>Username</th><td>" . htmlspecialchars($_SESSION['username'] ?? 'Unknown') . "</td></tr>
                    <tr><th>IP Address</th><td>" . getClientIP() . "</td></tr>
                    <tr><th>Filename</th><td>" . htmlspecialchars(basename($fileName)) . "</td></tr>
                    <tr><th>Camera/Date Path</th><td>" . htmlspecialchars($fileName) . "</td></tr>
                    <tr><th>Download Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                </table>
                <p>This file has been successfully retrieved from your backup storage. If this download was not authorized, please review your access controls.</p>
                ";
                sendEmailAlert('📥 Download Notification: File Retrieved', $message);
            }
            
            $downloadUrl = $b2->getDownloadUrl($fileName);
            
            // Initialize file transfer
            $ch = curl_init($downloadUrl);
            $curlOptions = [
                CURLOPT_HTTPHEADER => ['Authorization: ' . $b2->getAuthToken()],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ];
            curl_setopt_array($ch, $curlOptions);
            
            // Retrieve metadata
            curl_exec($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            
            // Prepare response headers
            $headers = [
                'Content-Type' => $contentType ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . basename($fileName) . '"',
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
                'X-Content-Type-Options' => 'nosniff'
            ];
            
            if ($contentLength > 0) {
                $headers['Content-Length'] = $contentLength;
            }
            
            foreach ($headers as $key => $value) {
                header($key . ': ' . $value);
            }
            
            // Transfer file content
            $ch = curl_init($downloadUrl);
            $transferOptions = [
                CURLOPT_HTTPHEADER => ['Authorization: ' . $b2->getAuthToken()],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_BUFFERSIZE => 8192,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_CONNECTTIMEOUT => 10
            ];
            curl_setopt_array($ch, $transferOptions);
            
            // Clear output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            curl_exec($ch);
            
            // Check for errors
            if (curl_errno($ch)) {
                error_log("B2 File Transfer Error: " . curl_error($ch));
            }
            
            curl_close($ch);
            exit;
        }
        
        // Handle video streaming proxy
        if (isset($_GET['stream'])) {
            $fileName = $_GET['stream'];
            
            // Security: Prevent directory traversal attacks
            if (strpos($fileName, '..') !== false) {
                die('Invalid file path');
            }
            
            $downloadUrl = $b2->getDownloadUrl($fileName);
            $authToken  = $b2->getAuthToken();

            // -------------------------------------------------------
            // Step 1: HEAD request to get Content-Length and Content-Type
            // Safari (iOS) requires accurate Content-Length in every
            // 206 response before it will begin playback.
            // -------------------------------------------------------
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Authorization: ' . $authToken]);
            curl_setopt($ch, CURLOPT_NOBODY,          true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION,  true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,  true);
            curl_setopt($ch, CURLOPT_HEADER,          true);
            curl_setopt($ch, CURLOPT_TIMEOUT,         15);
            $headResponse = curl_exec($ch);
            $contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            // Fallback: parse Content-Length from raw HEAD headers if cURL returned -1
            if ($contentLength <= 0 && preg_match('/Content-Length:\s*(\d+)/i', $headResponse, $m)) {
                $contentLength = (int) $m[1];
            }

            // Normalise content type
            if (empty($contentType) || strpos($contentType, 'octet-stream') !== false) {
                $contentType = 'video/mp4';
            }

            // -------------------------------------------------------
            // Step 2: Parse the client's Range header (if any).
            // Safari always sends Range on first load; we must honour it.
            // -------------------------------------------------------
            $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';
            $start = 0;
            $end   = $contentLength > 0 ? $contentLength - 1 : 0;
            $isRangeRequest = false;

            if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
                $isRangeRequest = true;
                $start = ($matches[1] !== '') ? (int)$matches[1] : 0;
                $end   = ($matches[2] !== '') ? (int)$matches[2] : ($contentLength > 0 ? $contentLength - 1 : 0);

                // Clamp end to actual file size
                if ($contentLength > 0 && $end >= $contentLength) {
                    $end = $contentLength - 1;
                }
            }

            $chunkLength = ($end - $start + 1);

            // -------------------------------------------------------
            // Step 3: Stream from B2, forwarding the Range header so
            // B2 only sends the bytes we need (saves bandwidth).
            // -------------------------------------------------------
            $ch = curl_init($downloadUrl);
            $reqHeaders = ['Authorization: ' . $authToken];
            if ($isRangeRequest) {
                $reqHeaders[] = 'Range: bytes=' . $start . '-' . $end;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER,    $reqHeaders);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER,         false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_BUFFERSIZE,     65536); // 64 KB chunks
            curl_setopt($ch, CURLOPT_TIMEOUT,        0);

            // -------------------------------------------------------
            // Step 4: Send correct response headers BEFORE streaming.
            // 206 Partial Content is required for Safari to seek/play.
            // -------------------------------------------------------
            if (ob_get_level()) {
                ob_end_clean();
            }

            if ($isRangeRequest) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $contentLength);
            } else {
                header('HTTP/1.1 200 OK');
            }

            header('Content-Type: '   . $contentType);
            header('Content-Length: ' . $chunkLength);
            header('Accept-Ranges: bytes');
            header('Cache-Control: no-cache, no-store'); // Prevent stale range caches

            curl_exec($ch);
            curl_close($ch);
            exit;
        }
        
        // Handle video playback request
        if (isset($_GET['play'])) {
            $fileName = $_GET['play'];
            
            // Security: Prevent directory traversal attacks
            if (strpos($fileName, '..') !== false) {
                die('Invalid file path');
            }
            
            // Log playback event
            logActivity('VIDEO_PLAYBACK', [
                'filename' => $fileName
            ]);
            
            // Send email alert if enabled
            if (ALERT_ON_PLAYBACK) {
                $message = "
                <div class='alert-box alert-success'>
                    <p class='alert-title'>▶️ Video Playback Started</p>
                    <p>A user has started playing a video from your camera backup system.</p>
                </div>
                <table>
                    <tr><th>Username</th><td>" . htmlspecialchars($_SESSION['username'] ?? 'Unknown') . "</td></tr>
                    <tr><th>IP Address</th><td>" . getClientIP() . "</td></tr>
                    <tr><th>Filename</th><td>" . htmlspecialchars(basename($fileName)) . "</td></tr>
                    <tr><th>Camera/Date Path</th><td>" . htmlspecialchars($fileName) . "</td></tr>
                    <tr><th>Playback Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                </table>
                <p>The video is being streamed directly in the browser. If this playback was not authorized, please review your access controls.</p>
                ";
                sendEmailAlert('▶️ Playback Notification: Video Streaming', $message);
            }
            
            // Generate a short-lived signed URL so the browser's <video> element
            // can fetch directly from B2's CDN with no PHP proxy in the middle.
            // This fixes iOS Safari which stalls on large files proxied through PHP,
            // and also improves performance on all browsers.
            $signedUrl = $b2->getSignedUrl($fileName, 3600); // valid for 1 hour
            $fileInfo = parseFilePath($fileName);
            
            // Display video player page
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>Video Player - ' . htmlspecialchars(basename($fileName)) . '</title>';
            echo '<style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #000;
                    color: #fff;
                    display: flex;
                    flex-direction: column;
                    min-height: 100vh;
                }
                .header {
                    background: #1a1a1a;
                    padding: 15px 20px;
                    border-bottom: 1px solid #333;
                }
                .header h1 {
                    font-size: 18px;
                    font-weight: 500;
                    color: #fff;
                }
                .video-container {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    background: #000;
                }
                video {
                    max-width: 100%;
                    max-height: calc(100vh - 200px);
                    width: auto;
                    height: auto;
                    border-radius: 8px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
                }
                .controls {
                    background: #1a1a1a;
                    padding: 20px;
                    text-align: center;
                    border-top: 1px solid #333;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    margin: 5px;
                    background: #3498db;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-size: 14px;
                    transition: background 0.2s;
                }
                .btn:hover {
                    background: #2980b9;
                }
                .btn-secondary {
                    background: #95a5a6;
                }
                .btn-secondary:hover {
                    background: #7f8c8d;
                }
                .btn-vlc {
                    background: #e67e22;
                }
                .btn-vlc:hover {
                    background: #d35400;
                }
                .info {
                    background: #2c2c2c;
                    padding: 15px 20px;
                    font-size: 13px;
                    color: #999;
                }
                .info-item {
                    display: inline-block;
                    margin-right: 20px;
                    margin-bottom: 5px;
                }
                .info-label {
                    color: #666;
                    margin-right: 5px;
                }
                @media (max-width: 768px) {
                    video {
                        max-height: calc(100vh - 250px);
                    }
                    .btn {
                        display: block;
                        width: 100%;
                        margin: 5px 0;
                    }
                }
            </style></head><body>';
            
            echo '<div class="header">';
            echo '<h1>🎥 ' . htmlspecialchars(basename($fileName)) . '</h1>';
            echo '</div>';
            
            echo '<div class="video-container">';
            echo '<video controls autoplay muted playsinline preload="metadata" id="videoPlayer">';
            echo '<source src="' . htmlspecialchars($signedUrl) . '" type="video/mp4">';
            echo 'Your browser does not support the video tag.';
            echo '</video>';
            echo '</div>';
            
            echo '<div class="info">';
            if ($fileInfo['year']) {
                echo '<span class="info-item"><span class="info-label">Date:</span>' . 
                     $fileInfo['year'] . '-' . $fileInfo['month'] . '-' . $fileInfo['day'] . '</span>';
            }
            if ($fileInfo['cameraId']) {
                echo '<span class="info-item"><span class="info-label">Camera:</span>' . 
                     htmlspecialchars($fileInfo['cameraId']) . '</span>';
            }
            echo '<span class="info-item"><span class="info-label">File:</span>' . 
                 htmlspecialchars(basename($fileName)) . '</span>';
            echo '</div>';
            
            // Detect iOS Safari by user agent
            $isIOS = preg_match('/iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');

            echo '<div class="controls">';
            if ($isIOS) {
                // On iOS Safari, inline streaming doesn't work for non-faststart MP4s.
                // Offer an Open in VLC button which streams properly via VLC's media engine.
                echo '<a href="vlc://' . htmlspecialchars($signedUrl) . '" class="btn btn-vlc">▶️ Open in VLC</a>';
            }
            echo '<a href="?download=' . urlencode($fileName) . '" class="btn">⬇️ Download Video</a>';
            echo '<a href="?" class="btn btn-secondary">← Back to Files</a>';
            echo '</div>';
            
            echo '<script>
                const video = document.getElementById("videoPlayer");

                // iOS Safari requires muted autoplay — unmute on first user interaction
                let userInteracted = false;
                function unmuteOnInteract() {
                    if (!userInteracted) {
                        userInteracted = true;
                        video.muted = false;
                    }
                }
                video.addEventListener("click",     unmuteOnInteract);
                video.addEventListener("touchstart", unmuteOnInteract);
                
                // Add keyboard controls
                document.addEventListener("keydown", function(e) {
                    if (e.key === " " || e.key === "k") {
                        e.preventDefault();
                        unmuteOnInteract();
                        if (video.paused) video.play();
                        else video.pause();
                    } else if (e.key === "f") {
                        if (video.requestFullscreen) video.requestFullscreen();
                    } else if (e.key === "m") {
                        video.muted = !video.muted;
                    } else if (e.key === "ArrowLeft") {
                        video.currentTime -= 5;
                    } else if (e.key === "ArrowRight") {
                        video.currentTime += 5;
                    }
                });
                
                // Handle playback errors
                video.addEventListener("error", function(e) {
                    console.error("Video playback error:", e);
                    alert("Error playing video. You can try downloading it instead.");
                });
            </script>';
            
            echo '</body></html>';
            exit;
        }
        
        // Get current path from query parameter
        $currentPath = isset($_GET['path']) ? $_GET['path'] : '';
        
        // Security: Sanitize path to prevent directory traversal
        $currentPath = str_replace(['../', '..\\'], '', $currentPath);
        
        // Add trailing slash if path is not empty
        if ($currentPath !== '' && substr($currentPath, -1) !== '/') {
            $currentPath .= '/';
        }
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Current path being queried: '$currentPath'");
        }
        
        // List files with delimiter to get folder structure
        $result = $b2->listFiles($currentPath, '/');
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: API returned " . count($result['files']) . " files");
            if (isset($result['nextFileName'])) {
                error_log("B2: More results available (nextFileName set)");
            }
        }
        
        $files = isset($result['files']) ? $result['files'] : [];
        
        // Sort files: folders first, then by name
        usort($files, function($a, $b) {
            $aIsFolder = substr($a['fileName'], -1) === '/';
            $bIsFolder = substr($b['fileName'], -1) === '/';
            
            if ($aIsFolder && !$bIsFolder) return -1;
            if (!$aIsFolder && $bIsFolder) return 1;
            return strcmp($a['fileName'], $b['fileName']);
        });
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Camera Backup Browser</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        h1 {
            color: #2c3e50;
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .breadcrumb {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 5px;
            padding: 10px 0;
            color: #666;
        }
        
        .breadcrumb a {
            color: #3498db;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .breadcrumb a:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        
        .file-list {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        
        .file-item:hover {
            background: #f9f9f9;
        }
        
        .file-item:last-child {
            border-bottom: none;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 24px;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .file-name a {
            color: #3498db;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        .file-name a:hover {
            color: #2980b9;
            text-decoration: underline;
        }
        
        .file-meta {
            font-size: 13px;
            color: #777;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-download {
            background: #3498db;
            color: #fff;
        }
        
        .btn-download:hover {
            background: #2980b9;
        }
        
        .btn-view {
            background: #95a5a6;
            color: #fff;
        }
        
        .btn-view:hover {
            background: #7f8c8d;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .camera-badge {
            display: inline-block;
            padding: 3px 8px;
            background: #3498db;
            color: #fff;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }
        
        .video-badge {
            background: #e74c3c;
        }
        
        .image-badge {
            background: #2ecc71;
        }
        
        .filter-bar {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-bar label {
            font-weight: 500;
            color: #555;
        }
        
        .filter-bar select,
        .filter-bar input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .file-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .file-actions {
                margin-top: 10px;
                width: 100%;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📹 Camera Backup Browser</h1>
            
            <?php if (defined('AUTH_USERNAME') && AUTH_USERNAME !== '' && isset($_SESSION['authenticated'])): ?>
                <div style="font-size: 12px; color: #666; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                        <?php 
                        $timeRemaining = SESSION_TIMEOUT - (time() - $_SESSION['last_activity']);
                        $minutesRemaining = floor($timeRemaining / 60);
                        ?>
                        | Session expires in: <strong><?php echo $minutesRemaining; ?> minutes</strong>
                        <?php if (MFA_ENABLED && !empty(MFA_SECRET)): ?>
                            | <a href="?setup_mfa=1" style="color: #3498db; text-decoration: none;">🔐 View MFA Setup</a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="?logout=1" onclick="return confirm('Are you sure you want to log out?');" style="color: #e74c3c; text-decoration: none; font-weight: 500;">🚪 Logout</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="breadcrumb">
                <a href="?">🏠 Home</a>
                <?php
                if ($currentPath !== '') {
                    $parts = explode('/', trim($currentPath, '/'));
                    $buildPath = '';
                    foreach ($parts as $part) {
                        if ($part === '') continue;
                        $buildPath .= $part . '/';
                        echo ' / <a href="?path=' . urlencode(rtrim($buildPath, '/')) . '">' . htmlspecialchars($part) . '</a>';
                    }
                }
                ?>
            </div>
            
            <?php if (defined('DEBUG_MODE') && DEBUG_MODE && !$configError): ?>
                <div style="background: #e3f2fd; padding: 10px; border-radius: 5px; margin-top: 10px; font-size: 12px; color: #1565c0;">
                    <strong>Debug Info:</strong> Current path: "<?php echo htmlspecialchars($currentPath); ?>" | 
                    Files found: <?php echo count($files); ?>
                </div>
            <?php endif; ?>
        </header>
        
        <?php if ($configError): ?>
            <div class="error">
                <strong>Configuration Error:</strong> <?php echo htmlspecialchars($configError); ?>
                <br><br>
                <strong>Please edit the PHP file and configure:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <li>B2_KEY_ID</li>
                    <li>B2_APPLICATION_KEY</li>
                    <li>B2_BUCKET_NAME</li>
                    <li>B2_BUCKET_ID</li>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                <?php if (defined('DEBUG_MODE') && DEBUG_MODE): ?>
                    <br><br>
                    <strong>Debug Information:</strong>
                    <ul style="margin: 10px 0 0 20px; font-size: 12px;">
                        <li>PHP Version: <?php echo PHP_VERSION; ?></li>
                        <li>cURL Available: <?php echo function_exists('curl_init') ? 'Yes' : 'No'; ?></li>
                        <li>JSON Available: <?php echo function_exists('json_encode') ? 'Yes' : 'No'; ?></li>
                        <li>Current Path: <?php echo htmlspecialchars($currentPath); ?></li>
                        <li>Check your PHP error log for more details</li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$configError && !$error && $currentPath !== ''): ?>
            <div class="filter-bar">
                <label>Filter:</label>
                <select id="fileTypeFilter" onchange="filterFiles()">
                    <option value="all">All Files</option>
                    <option value="video">Videos Only</option>
                    <option value="image">Images Only</option>
                    <option value="folder">Folders Only</option>
                </select>
                
                <input type="text" id="fileNameSearch" placeholder="Search by filename..." onkeyup="filterFiles()" style="flex: 1; min-width: 200px;">
            </div>
        <?php endif; ?>
        
        <div class="file-list">
            <?php if (empty($files)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <p>No files or folders found</p>
                </div>
            <?php else: ?>
                <?php foreach ($files as $file): ?>
                    <?php
                    $fileName = $file['fileName'];
                    $isFolder = substr($fileName, -1) === '/';
                    $displayName = $isFolder ? rtrim(basename($fileName), '/') : basename($fileName);
                    $fileInfo = parseFilePath($fileName);
                    $isVideo = strtolower($fileInfo['type']) === 'mp4';
                    $isImage = strtolower($fileInfo['type']) === 'jpg';
                    
                    // Data attributes for filtering
                    $fileType = $isFolder ? 'folder' : ($isVideo ? 'video' : ($isImage ? 'image' : 'other'));
                    ?>
                    
                    <div class="file-item" data-type="<?php echo $fileType; ?>" data-name="<?php echo htmlspecialchars(strtolower($displayName)); ?>">
                        <div class="file-icon">
                            <?php if ($isFolder): ?>
                                📁
                            <?php elseif ($isVideo): ?>
                                🎥
                            <?php elseif ($isImage): ?>
                                🖼️
                            <?php else: ?>
                                📄
                            <?php endif; ?>
                        </div>
                        
                        <div class="file-info">
                            <div class="file-name">
                                <?php if ($isFolder): ?>
                                    <a href="?path=<?php echo urlencode(rtrim($fileName, '/')); ?>">
                                        <?php echo htmlspecialchars($displayName); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($displayName); ?>
                                    <?php if ($isVideo): ?>
                                        <span class="camera-badge video-badge">VIDEO</span>
                                    <?php elseif ($isImage): ?>
                                        <span class="camera-badge image-badge">IMAGE</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="file-meta">
                                <?php if (!$isFolder): ?>
                                    <?php echo formatFileSize($file['contentLength']); ?>
                                    <?php if ($fileInfo['year']): ?>
                                        • <?php echo $fileInfo['year'] . '-' . $fileInfo['month'] . '-' . $fileInfo['day']; ?>
                                    <?php endif; ?>
                                    <?php if ($fileInfo['cameraId']): ?>
                                        • Camera <?php echo htmlspecialchars($fileInfo['cameraId']); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Folder
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!$isFolder): ?>
                            <div class="file-actions">
                                <?php if ($isVideo): ?>
                                    <a href="?play=<?php echo urlencode($fileName); ?>" class="btn btn-view">
                                        ▶️ Play
                                    </a>
                                <?php endif; ?>
                                <a href="?download=<?php echo urlencode($fileName); ?>" class="btn btn-download">
                                    ⬇️ Download
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function filterFiles() {
            const typeFilter = document.getElementById('fileTypeFilter').value;
            const searchText = document.getElementById('fileNameSearch').value.toLowerCase();
            const items = document.querySelectorAll('.file-item');
            
            items.forEach(item => {
                const itemType = item.getAttribute('data-type');
                const itemName = item.getAttribute('data-name');
                
                let showType = typeFilter === 'all' || itemType === typeFilter;
                let showSearch = searchText === '' || itemName.includes(searchText);
                
                if (showType && showSearch) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
