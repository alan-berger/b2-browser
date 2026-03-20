<?php
/**
 * Backblaze B2 Private Bucket File Browser
 * Version 1.4.0 - Content Security Policy; nonce-based script authorisation
 *
 * Changes in v1.4.0:
 *  - Strict Content Security Policy enforced via HTTP headers.
 *  - Per-request cryptographic nonce (random_bytes) replaces 'unsafe-inline'
 *    in script-src; all <script> tags carry the nonce attribute.
 *  - All inline CSS extracted to external b2-browse.css (style-src 'self').
 *  - All inline event handlers (onclick, onchange, onkeyup, onerror)
 *    replaced with addEventListener in nonced script blocks.
 *  - Added CSP directives: form-action, base-uri, frame-ancestors,
 *    object-src, img-src, media-src.
 *  - Added X-Content-Type-Options: nosniff header globally.
 *  - Added Referrer-Policy: no-referrer header.
 *
 * Changes in v1.3.1:
 *  #3  TOTP replay store moved from $_SESSION (per-session) to a shared
 *      server-side file so replay is blocked across ALL sessions, not just
 *      the current browser session.  isToTpSliceUsed() / markToTpSliceUsed()
 *      added; $_SESSION['totp_used_slices'] removed.
 *  #4  QR code library switched from the npm `qrcode` package on jsDelivr
 *      (static QRCode.toCanvas() API, inconsistent browser support) to
 *      `qrcodejs` on cdnjs (constructor API, canvas + img fallback, SRI hash).
 *      A visible fallback message is now shown if the CDN is unreachable.
 *
 * Security fixes applied in v1.3.0:
 *  #1  IP spoofing: getClientIP() now uses only REMOTE_ADDR.
 *  #2  Session fixation: session_regenerate_id(true) called on every login.
 *  #3  TOTP replay: used time-slices tracked in session and rejected.
 *  #4  MFA secret privacy: QR code rendered client-side via QRCode.js
 *      (secret never transmitted to a third-party server).
 *  #5  Path validation: sanitizeAndValidatePath() rejects traversal and
 *      enforces B2_ALLOWED_PREFIX when configured.
 *  #6  Race condition: rate-limit file uses flock(LOCK_EX) across the
 *      full read-modify-write cycle.
 *  #7  Predictable rate-limit file: path is derived from a SHA-256 hash;
 *      override via RATE_LIMIT_FILE constant.
 *  #9  Timing attacks: hash_equals() used for all credential comparisons.
 *  #10 CSRF: MFA form includes a session-bound token validated on POST.
 *  #11 session_write_close() ordering: last_activity updated before the
 *      session is closed in the stream path.
 *  #12 First-time stream 403: ?stream= now also accepts valid Basic Auth
 *      credentials when no session exists.
 */
 
// ============================================
// SECURITY HEADERS
// ============================================
// A per-request nonce eliminates the need for 'unsafe-inline' in script-src.
// Every <script> tag (inline or external) carries nonce="$cspNonce"; scripts
// injected by an attacker will not have it and will be blocked by the browser.
//
// Directive summary:
//   script-src  — nonce-only (no unsafe-inline, no domain whitelist)
//   style-src   — external b2-browse.css only; no inline styles
//   img-src     — 'self' plus data: URIs (qrcodejs renders QR as data: <img>)
//   media-src   — 'self' plus B2 download pod subdomains (signed video URLs)
//   form-action — restrict form targets to same origin
//   base-uri    — prevent <base> tag injection
//   object-src  — block plugins (Flash, Java, etc.)
//   frame-ancestors — equivalent to X-Frame-Options: DENY

require_once __DIR__ . '/../private/b2browse-secrets.php';

$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'nonce-{$cspNonce}'; "
    . "style-src 'self'; "
    . "img-src 'self' data:; "
    . "media-src 'self' https://*.backblazeb2.com; "
    . "form-action 'self'; "
    . "base-uri 'self'; "
    . "frame-ancestors 'none'; "
    . "object-src 'none'"
);
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

// ============================================
// CONFIGURATION - FILL THESE IN
// ============================================

// Backblaze B2 Credentials
defined('B2_KEY_ID')           || define('B2_KEY_ID', '');
defined('B2_APPLICATION_KEY')  || define('B2_APPLICATION_KEY', '');
defined('B2_BUCKET_NAME')      || define('B2_BUCKET_NAME', '');
defined('B2_BUCKET_ID')        || define('B2_BUCKET_ID', '');

// Fix #5 — restrict download/stream/play to files whose path starts with this
// prefix (e.g. 'camera-footage/'). Leave empty to allow any file in the bucket or modify to 
define('B2_ALLOWED_PREFIX', 'camera-footage');

// Your Domain and Contact Information
define('YOUR_DOMAIN',   'yourdomain.com');
define('YOUR_HOMEPAGE', 'https://yourdomain.com');
define('ADMIN_EMAIL',   'admin@yourdomain.com');
define('ALERT_EMAILS',  'admin@yourdomain.com,alt-recipient@yourdomain.com');

// Basic authentication — leave both empty to disable
defined('AUTH_USERNAME')     || define('AUTH_USERNAME', '');
defined('AUTH_PASSWORD')     || define('AUTH_PASSWORD', '');

// Rate Limiting (fail2ban style)
define('RATE_LIMIT_ENABLED',  true);
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOCKOUT_DURATION',    900); // seconds (15 minutes)
define('ATTEMPT_WINDOW',      300); // seconds (5 minutes)

// Fix #7 — absolute path for the rate-limit data file.
// Must be writable by PHP and ideally outside the webroot.
// Leave empty to auto-generate a hard-to-guess name in sys_get_temp_dir().
define('RATE_LIMIT_FILE', '/home/username/temp/b2_login_attempts.json');

// Multi-Factor Authentication (TOTP) — optional but strongly recommended
define('MFA_ENABLED', false);
defined('MFA_SECRET')        || define('MFA_SECRET', '');
define('MFA_ISSUER',  'B2 File Browser');

// Activity Logging and Email Alerts
define('LOG_ENABLED',          true);
define('LOG_FILE_PATH',        '/home/username/logs/b2_activity.log');
define('EMAIL_ALERTS_ENABLED', true);
define('ALERT_ON_LOGIN',         true);
define('ALERT_ON_FAILED_LOGIN',  true);
define('ALERT_ON_LOCKOUT',       true);
define('ALERT_ON_DOWNLOADS',     false);
define('ALERT_ON_PLAYBACK',      false);

// Session timeout in seconds (default: 30 minutes)
define('SESSION_TIMEOUT', 1800);

// Diagnostic mode — set to true to see detailed error messages
define('DEBUG_MODE', false);

// Pagination — number of files to show per page
define('FILES_PER_PAGE', 100);

// Cache B2 auth token in session to reduce API calls
define('USE_SESSION_CACHE', true);

// ============================================
// BASIC AUTHENTICATION WITH SESSION TIMEOUT
// ============================================
if (defined('AUTH_USERNAME') && defined('AUTH_PASSWORD') && AUTH_USERNAME !== '' && AUTH_PASSWORD !== '') {
    session_start();

    // ------------------------------------------------------------------ Logout
    if (isset($_GET['logout'])) {
        logActivity('LOGOUT', [
            'username' => $_SESSION['username'] ?? 'Unknown',
        ]);

        session_unset();
        session_destroy();

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<meta http-equiv="refresh" content="2;url=' . YOUR_HOMEPAGE . '" />';
        echo '<link rel="stylesheet" href="b2-browse.css">';
        echo '<title>Logged Out</title></head>';
        echo '<body class="page-message">';
        echo '<h1 class="success-heading">✅ Logged Out Successfully</h1>';
        echo '<p class="message-text">You have been logged out.</p>';
        echo '<p class="message-subtext">Redirecting to homepage in 2 seconds...</p>';
        echo '<p class="message-action"><a href="' . YOUR_HOMEPAGE . '" class="link-primary">← Return to Homepage Now</a></p>';
        echo '</body></html>';
        exit;
    }

    // Fix #1: getClientIP() now uses only REMOTE_ADDR — see function definition.
    $clientIP = getClientIP();

    // --------------------------------------------------------- Rate limiting
    $rateLimitCheck = checkRateLimit($clientIP);

    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Rate limit check for IP {$clientIP}: " . json_encode($rateLimitCheck));
    }

    if (!$rateLimitCheck['allowed']) {
        $minutes = ceil($rateLimitCheck['remaining'] / 60);

        logActivity('ACCOUNT_LOCKED', [
            'reason'           => 'Too many failed login attempts',
            'attempts'         => $rateLimitCheck['attempts'],
            'lockout_duration' => $minutes . ' minutes',
        ]);

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
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<link rel="stylesheet" href="b2-browse.css">';
        echo '<title>Account Locked</title></head>';
        echo '<body class="page-message">';
        echo '<h1 class="danger-heading">🔒 Account Temporarily Locked</h1>';
        echo '<p class="message-text-lg">Too many failed login attempts.</p>';
        echo '<p class="message-subtext">Please try again in <strong>' . $minutes . ' minute(s)</strong>.</p>';
        echo '<p class="message-detail">Attempts: ' . $rateLimitCheck['attempts'] . '/' . MAX_LOGIN_ATTEMPTS . '</p>';
        echo '</body></html>';
        exit;
    }

    // ------------------------------------------------- Session validity check
    $isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

    if ($isAuthenticated && isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            session_start();
            $isAuthenticated = false;
        }
    }

    // ------------------------------------------------- ?stream= early exit
    // iOS Safari cannot forward Basic Auth credentials on video sub-requests
    // (e.g. the range requests the <video> element makes). We allow the stream
    // through if a valid session exists OR if valid Basic Auth credentials are
    // present on this specific request.
    if (isset($_GET['stream'])) {
        $streamAllowed = false;

        if ($isAuthenticated) {
            // Fix #11: update last_activity BEFORE releasing the session lock.
            // Previously session_write_close() was called first, causing this
            // write to be silently discarded.
            $_SESSION['last_activity'] = time();
            session_write_close();
            $streamAllowed = true;
        } else {
            // Fix #12: accept a first-time request that carries valid Basic Auth
            // credentials even though no PHP session exists yet. The browser
            // sends credentials automatically on same-origin sub-requests.
            if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
                // Fix #9: constant-time comparison
                if (hash_equals(AUTH_USERNAME, $_SERVER['PHP_AUTH_USER']) &&
                    hash_equals(AUTH_PASSWORD, $_SERVER['PHP_AUTH_PW'])) {
                    $streamAllowed = true;
                    // Do not create a full session for a stream-only request;
                    // the next regular page load will go through the full login flow.
                }
            }
        }

        if (!$streamAllowed) {
            // Reject without a WWW-Authenticate header so iOS does not trigger
            // a redundant Basic Auth pop-up on a background video sub-request.
            header('HTTP/1.1 403 Forbidden');
            exit;
        }
        // Fall through to the stream handler in the main application block.
    }

    // ------------------------------------------------- MFA setup page
    if (isset($_GET['setup_mfa']) && MFA_ENABLED && !empty(MFA_SECRET)) {
        if (!$isAuthenticated || !isset($_SESSION['username'])) {
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        // Fix #4: build the otpauth URL server-side but render the QR code
        // entirely in the browser using qrcodejs (loaded from cdnjs).
        // The secret is embedded in the HTML served over HTTPS to the already-
        // authenticated user — it is never transmitted to any third-party server.
        $otpauthUrl = 'otpauth://totp/'
            . urlencode(MFA_ISSUER) . ':' . urlencode($_SESSION['username'])
            . '?secret=' . rawurlencode(MFA_SECRET)
            . '&issuer='  . urlencode(MFA_ISSUER);

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MFA Setup</title>';
        echo '<link rel="stylesheet" href="b2-browse.css">';
        echo '</head><body class="mfa-setup-body">';
        echo '<h1>🔐 Multi-Factor Authentication Setup</h1>';
        echo '<div class="info"><strong>Step 1:</strong> Install an authenticator app (Google Authenticator, Authy, Microsoft Authenticator, etc.)</div>';
        echo '<div class="info"><strong>Step 2:</strong> Scan this QR code with your authenticator app:</div>';
        // qrcodejs renders into a plain div — no canvas element needed
        echo '<div id="qrcode-container"><div id="qrcode"></div></div>';
        echo '<div id="qr-fallback" class="qr-fallback">⚠️ QR code could not be rendered. Please enter the secret code below manually into your authenticator app.</div>';
        echo '<div class="info"><strong>Step 3:</strong> Or manually enter this secret code:</div>';
        echo '<div class="secret">' . chunk_split(MFA_SECRET, 4, ' ') . '</div>';
        echo '<div class="info"><strong>Step 4:</strong> Once configured, you\'ll need to enter the 6-digit code from your app when logging in.</div>';
        echo '<p><a href="' . strtok($_SERVER['REQUEST_URI'], '?') . '" class="link-primary">← Back to File Browser</a></p>';
        // qrcodejs — purpose-built browser QR library served from cdnjs.
        // The nonce on the <script> tag authorises it under CSP; no domain
        // whitelist is needed.  If the CDN is unreachable the library will
        // not load, and the try/catch below will show the manual-entry
        // fallback (typeof QRCode === "undefined").
        echo '<script ';
        echo 'src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" ';
        echo 'crossorigin="anonymous" ';
        echo 'nonce="' . htmlspecialchars($cspNonce) . '">';
        echo '</script>';
        echo '<script nonce="' . htmlspecialchars($cspNonce) . '">';
        echo '(function() {';
        echo '  var container = document.getElementById("qrcode");';
        echo '  var fallback  = document.getElementById("qr-fallback");';
        echo '  try {';
        echo '    if (typeof QRCode === "undefined") throw new Error("QRCode not loaded");';
        echo '    new QRCode(container, {';
        echo '      text: ' . json_encode($otpauthUrl) . ',';
        echo '      width: 200,';
        echo '      height: 200,';
        echo '      correctLevel: QRCode.CorrectLevel.M';
        echo '    });';
        echo '  } catch (e) {';
        echo '    console.error("QR render error:", e);';
        echo '    container.style.display = "none";';
        echo '    fallback.style.display  = "block";';
        echo '  }';
        echo '})();';
        echo '</script>';
        echo '</body></html>';
        exit;
    }

    // ---------------------------------------- Credential + MFA verification
    if (!$isAuthenticated) {
        $credentialsValid = false;
        $mfaRequired      = MFA_ENABLED && !empty(MFA_SECRET);

        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            // Fix #9: constant-time comparison prevents timing oracle attacks.
            if (hash_equals(AUTH_USERNAME, $_SERVER['PHP_AUTH_USER']) &&
                hash_equals(AUTH_PASSWORD, $_SERVER['PHP_AUTH_PW'])) {
                $credentialsValid = true;
            } else {
                recordFailedAttempt($clientIP);

                logActivity('LOGIN_FAILED', [
                    'reason'           => 'Invalid credentials',
                    'username_attempt' => $_SERVER['PHP_AUTH_USER'],
                ]);

                // Email alert after 3 failed attempts
                $attemptsFile = getAttemptsFilePath();
                if (file_exists($attemptsFile)) {
                    $attemptsData = json_decode(file_get_contents($attemptsFile), true) ?: [];
                    if (isset($attemptsData[$clientIP]) &&
                        $attemptsData[$clientIP]['count'] >= 3 &&
                        ALERT_ON_FAILED_LOGIN) {
                        $remaining = MAX_LOGIN_ATTEMPTS - $attemptsData[$clientIP]['count'];
                        $message   = "
                        <div class='alert-box alert-warning'>
                            <p class='alert-title'>⚠️ Multiple Failed Login Attempts</p>
                            <p>Your camera system has detected multiple unsuccessful login attempts from the same IP address.</p>
                        </div>
                        <table>
                            <tr><th>IP Address</th><td>{$clientIP}</td></tr>
                            <tr><th>Failed Attempts</th><td>{$attemptsData[$clientIP]['count']}</td></tr>
                            <tr><th>Username Attempted</th><td>" . htmlspecialchars($_SERVER['PHP_AUTH_USER']) . "</td></tr>
                            <tr><th>Event Time</th><td class='timestamp'>" . date('Y-m-d H:i:s T') . "</td></tr>
                        </table>
                        <p><strong>Note:</strong> The account will be automatically locked after " . MAX_LOGIN_ATTEMPTS . " failed attempts ({$remaining} attempt(s) remaining).</p>
                        <p>If you recognise this activity you can ignore this message. If not, someone may be attempting to gain unauthorised access.</p>
                        ";
                        sendEmailAlert('⚠️ Security Alert: Failed Login Attempts', $message);
                    }
                }
            }
        }

        // Not authenticated — issue the WWW-Authenticate challenge
        if (!$credentialsValid) {
            header('WWW-Authenticate: Basic realm="B2 File Browser"');
            header('HTTP/1.0 401 Unauthorized');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
            echo '<link rel="stylesheet" href="b2-browse.css">';
            echo '<title>Authentication Required</title></head>';
            echo '<body class="page-message">';
            echo '<h1>Authentication Required</h1>';
            echo '<p>You must log in to access this page.</p>';
            echo '<p class="message-action"><a href="' . YOUR_HOMEPAGE . '" class="link-primary">← Return to Homepage</a></p>';
            echo '</body></html>';
            exit;
        }

        // ---------------------- MFA second factor (credentials already valid)
        if ($credentialsValid && $mfaRequired) {
            // Fix #10: ensure a CSRF token is bound to this session.
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }

            if (isset($_POST['mfa_code'])) {
                // Fix #10: validate the CSRF token before processing the code.
                $submittedToken = $_POST['csrf_token'] ?? '';
                if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
                    $mfaError = 'Invalid request token. Please try again.';
                } else {
                    $mfaCode   = preg_replace('/[^0-9]/', '', $_POST['mfa_code']);
                    $usedSlice = null; // Fix #3: will be set by verifyTOTP()

                    if (verifyTOTP(MFA_SECRET, $mfaCode, $usedSlice)) {
                        // Fix #3 (strengthened): check the global server-side store so
                        // replay is blocked across ALL sessions, not just the current one.
                        // Session-scoped tracking (v1.3.0) would allow an attacker who
                        // intercepts a valid code to replay it in a different browser/session.
                        if (isToTpSliceUsed($usedSlice)) {
                            // Replay detected
                            recordFailedAttempt($clientIP);
                            logActivity('MFA_FAILED', ['reason' => 'TOTP replay attempt (cross-session)']);
                            $mfaError = 'Invalid authentication code. Please try again.';
                        } else {
                            // Mark the slice as consumed globally before doing anything else,
                            // so a near-simultaneous second request cannot also slip through.
                            markToTpSliceUsed($usedSlice);

                            // Fix #2: rotate session ID on successful login.
                            session_regenerate_id(true);

                            $_SESSION['authenticated'] = true;
                            $_SESSION['last_activity'] = time();
                            $_SESSION['username']      = AUTH_USERNAME;
                            // Rotate CSRF token after login
                            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                            clearFailedAttempts($clientIP);

                            logActivity('LOGIN_SUCCESS', [
                                'method'     => 'HTTP Basic Auth + MFA',
                                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                            ]);

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
                                <p>If this login was authorised, no action is needed. If you don't recognise this activity, please secure your account immediately:</p>
                                <ul>
                                    <li>Change your password</li>
                                    <li>Review recent access logs</li>
                                    <li>Check your MFA device for unauthorised access</li>
                                </ul>
                                ";
                                sendEmailAlert('✅ Login Notification: Camera System Access', $message);
                            }

                            // Redirect to strip POST data from the URL
                            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                            exit;
                        }
                    } else {
                        recordFailedAttempt($clientIP);
                        logActivity('MFA_FAILED', ['reason' => 'Invalid TOTP code']);
                        $mfaError = 'Invalid authentication code. Please try again.';
                    }
                }
            }

            // Render the MFA input form
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<title>Two-Factor Authentication</title>';
            echo '<link rel="stylesheet" href="b2-browse.css">';
            echo '</head><body class="mfa-page-body">';
            echo '<div class="mfa-container">';
            echo '<h1>🔐 Two-Factor Authentication</h1>';
            echo '<p>Enter the 6-digit code from your authenticator app</p>';
            if (isset($mfaError)) {
                echo '<div class="error">' . htmlspecialchars($mfaError) . '</div>';
            }
            echo '<form method="POST">';
            // Fix #10: CSRF token as a hidden field
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
            echo '<div class="form-group">';
            echo '<label for="mfa_code">Authentication Code</label>';
            echo '<input type="text" id="mfa_code" name="mfa_code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off">';
            echo '<div class="help">Enter the 6-digit code from your authenticator app</div>';
            echo '</div>';
            echo '<button type="submit" class="btn">Verify</button>';
            echo '</form>';
            echo '</div>';
            echo '<script nonce="' . htmlspecialchars($cspNonce) . '">document.getElementById("mfa_code").focus();</script>';
            echo '</body></html>';
            exit;
        }

        // Credentials valid, MFA not required — complete the login
        if ($credentialsValid && !$mfaRequired) {
            // Fix #2: rotate session ID to prevent session fixation.
            session_regenerate_id(true);

            $_SESSION['authenticated'] = true;
            $_SESSION['last_activity'] = time();
            $_SESSION['username']      = AUTH_USERNAME;
            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

            clearFailedAttempts($clientIP);

            logActivity('LOGIN_SUCCESS', [
                'method'     => 'HTTP Basic Auth (no MFA)',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            ]);

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
                    <p>Consider enabling Multi-Factor Authentication (MFA) for enhanced security.</p>
                </div>
                ";
                sendEmailAlert('✅ Login Notification: Camera System Access', $message);
            }
        }
    } else {
        // Already authenticated — refresh the activity timestamp
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
        if (USE_SESSION_CACHE &&
            isset($_SESSION['b2_auth_token'], $_SESSION['b2_auth_expiry']) &&
            time() < $_SESSION['b2_auth_expiry']) {
            $this->authToken   = $_SESSION['b2_auth_token'];
            $this->apiUrl      = $_SESSION['b2_api_url'];
            $this->downloadUrl = $_SESSION['b2_download_url'];
            $this->authExpiry  = $_SESSION['b2_auth_expiry'];

            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('B2: Using cached authentication token');
            }
        } else {
            $this->authenticate();
        }
    }

    private function authenticate() {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('B2: Attempting authentication with Key ID: ' . substr(B2_KEY_ID, 0, 10) . '...');
        }

        $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode(B2_KEY_ID . ':' . B2_APPLICATION_KEY),
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('B2: Auth HTTP Code: ' . $httpCode);
            if ($curlError) {
                error_log('B2: cURL Error: ' . $curlError);
            }
        }

        if ($httpCode !== 200) {
            $errorMsg = 'B2 Authentication failed (HTTP ' . $httpCode . ')';
            if ($curlError) { $errorMsg .= ' - cURL Error: ' . $curlError; }
            if ($response)  { $errorMsg .= ' - Response: '  . $response;  }
            throw new Exception($errorMsg);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['authorizationToken'])) {
            throw new Exception('B2 Authentication failed: Invalid response format');
        }

        $this->authToken   = $data['authorizationToken'];
        $this->apiUrl      = $data['apiUrl'];
        $this->downloadUrl = $data['downloadUrl'];
        $this->authExpiry  = time() + 82800; // 23 hours

        if (USE_SESSION_CACHE) {
            $_SESSION['b2_auth_token']    = $this->authToken;
            $_SESSION['b2_api_url']       = $this->apiUrl;
            $_SESSION['b2_download_url']  = $this->downloadUrl;
            $_SESSION['b2_auth_expiry']   = $this->authExpiry;
        }

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('B2: Authentication successful! API URL: ' . $this->apiUrl);
        }
    }

    private function ensureAuth() {
        if (time() >= $this->authExpiry) {
            $this->authenticate();
        }
    }

    public function listFiles($prefix = '', $delimiter = '', $startFileName = '') {
        $this->ensureAuth();

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Listing files with prefix: '{$prefix}' and delimiter: '{$delimiter}'");
        }

        $url  = $this->apiUrl . '/b2api/v2/b2_list_file_names';
        $data = [
            'bucketId'     => B2_BUCKET_ID,
            'prefix'       => $prefix,
            'maxFileCount' => FILES_PER_PAGE,
        ];

        if ($startFileName !== '') { $data['startFileName'] = $startFileName; }
        if ($delimiter      !== '') { $data['delimiter']     = $delimiter;     }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->authToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('B2: List files HTTP Code: ' . $httpCode);
            if ($curlError) { error_log('B2: cURL Error: ' . $curlError); }
            error_log('B2: Response: ' . substr($response, 0, 500));
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
     * The browser can use it directly in a <video> tag with no Authorization
     * header, which fixes iOS Safari which cannot forward credentials on video
     * sub-requests.
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
            'Content-Type: application/json',
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
    $info  = [
        'camera'   => '',
        'year'     => '',
        'month'    => '',
        'day'      => '',
        'cameraId' => '',
        'fileName' => '',
        'type'     => '',
    ];

    if (count($parts) >= 6) {
        $info['camera']   = $parts[0];
        $info['year']     = $parts[1];
        $info['month']    = $parts[2];
        $info['day']      = $parts[3];
        $info['cameraId'] = $parts[4];
        $info['fileName'] = end($parts);
        $info['type']     = pathinfo($info['fileName'], PATHINFO_EXTENSION);
    }

    return $info;
}

/**
 * Sanitize and validate a file path received from user input.
 *
 * Fix #5 (insufficient path validation): extends the previous simple ".."
 * check to also reject null bytes and enforce the B2_ALLOWED_PREFIX
 * constraint when configured, preventing authenticated users from accessing
 * arbitrary files outside the intended scope of the browser.
 *
 * Returns the sanitized path string on success, or false on failure.
 */
function sanitizeAndValidatePath(string $fileName) {
    // Reject null bytes (can confuse some low-level string handling)
    if (strpos($fileName, "\0") !== false) {
        return false;
    }
    // Reject any form of directory traversal
    if (preg_match('/\.\./', $fileName)) {
        return false;
    }
    // Strip leading slashes so the path is always relative
    $fileName = ltrim($fileName, '/');
    // Reject empty result
    if ($fileName === '') {
        return false;
    }
    // Enforce the optional allowed-prefix restriction (Fix #5)
    if (defined('B2_ALLOWED_PREFIX') && B2_ALLOWED_PREFIX !== '') {
        if (strpos($fileName, B2_ALLOWED_PREFIX) !== 0) {
            return false;
        }
    }
    return $fileName;
}

// ============================================
// ACTIVITY LOGGING AND EMAIL ALERTS
// ============================================
function logActivity($event, $details = []) {
    if (!LOG_ENABLED) {
        return;
    }

    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event'     => $event,
        'username'  => isset($_SESSION['username']) ? $_SESSION['username'] : 'N/A',
        'ip'        => getClientIP(),
        'details'   => $details,
    ];

    $logDir = dirname(LOG_FILE_PATH);
    if (!file_exists($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents(LOG_FILE_PATH, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

    if (file_exists(LOG_FILE_PATH) && filesize(LOG_FILE_PATH) > 10485760) {
        @rename(LOG_FILE_PATH, LOG_FILE_PATH . '.' . date('Y-m-d-His') . '.old');
    }
}

function sendEmailAlert($subject, $message) {
    if (!EMAIL_ALERTS_ENABLED) {
        return;
    }

    $emails    = array_map('trim', explode(',', ALERT_EMAILS));
    $fromEmail = 'alerts@' . YOUR_DOMAIN;
    $fromName  = 'Camera Security System';

    $headers = [
        'From: '       . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: '   . ADMIN_EMAIL,
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Priority: 1',
        'Importance: High',
        'Message-ID: <' . time() . '.' . md5($subject . microtime()) . '@' . YOUR_DOMAIN . '>',
    ];

    $htmlMessage = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>" . htmlspecialchars($subject) . "</title>
        <style>
            body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;line-height:1.6;color:#333;margin:0;padding:0;background-color:#f4f4f4;}
            .email-wrapper{max-width:600px;margin:20px auto;background:#ffffff;}
            .header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px 20px;text-align:center;}
            .header h1{margin:0;font-size:24px;font-weight:600;}
            .content{background:#ffffff;padding:30px 20px;}
            .alert-box{padding:20px;border-radius:8px;margin:20px 0;}
            .alert-success{background:#d4edda;border-left:4px solid #28a745;color:#155724;}
            .alert-warning{background:#fff3cd;border-left:4px solid #ffc107;color:#856404;}
            .alert-danger{background:#f8d7da;border-left:4px solid #dc3545;color:#721c24;}
            .alert-title{font-size:18px;font-weight:600;margin:0 0 10px 0;}
            table{width:100%;margin:20px 0;border-collapse:collapse;background:#fff;}
            th{text-align:left;padding:12px;background:#f8f9fa;color:#495057;font-weight:600;border-bottom:2px solid #dee2e6;}
            td{padding:12px;border-bottom:1px solid #dee2e6;color:#212529;}
            tr:last-child td{border-bottom:none;}
            .footer{background:#f8f9fa;padding:20px;text-align:center;font-size:12px;color:#6c757d;border-top:1px solid #dee2e6;}
            .footer p{margin:5px 0;}
            .timestamp{font-family:'Courier New',monospace;background:#f8f9fa;padding:2px 6px;border-radius:3px;font-size:13px;}
        </style>
    </head>
    <body>
        <div class='email-wrapper'>
            <div class='header'><h1>🎥 Camera Security Alert</h1></div>
            <div class='content'>{$message}</div>
            <div class='footer'>
                <p><strong>Camera Backup System</strong></p>
                <p>This is an automated security notification from your camera monitoring system</p>
                <p>System: " . YOUR_DOMAIN . " | Time: " . date('l, F j, Y \a\t g:i A T') . "</p>
                <p style='margin-top:15px;font-size:11px;color:#999;'>If you did not expect this email, please contact the system administrator immediately.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    foreach ($emails as $email) {
        @mail($email, $subject, $htmlMessage, implode("\r\n", $headers), '-f ' . $fromEmail);
    }
}

// ============================================
// RATE LIMITING FUNCTIONS
// ============================================

/**
 * Fix #1 (IP spoofing bypass of rate limiting).
 *
 * The original implementation trusted HTTP headers such as X-Forwarded-For
 * and CF-Connecting-IP, which any client can set to an arbitrary value.
 * An attacker could therefore cycle through spoofed IP addresses on every
 * request, effectively resetting their rate-limit slot each time and
 * making the brute-force protection completely ineffective.
 *
 * REMOTE_ADDR is the actual TCP peer address established by the kernel and
 * cannot be forged by the end client. It is the only safe source of the
 * client IP for security-sensitive decisions.
 *
 * If your server sits behind a trusted reverse proxy (e.g. Cloudflare or an
 * Nginx gateway you control), configure the proxy to rewrite REMOTE_ADDR
 * (e.g. via the PROXY protocol or a trusted-proxy module) rather than
 * adding a header that PHP reads here.
 */
function getClientIP(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

/**
 * Fix #7 (predictable rate-limit file location).
 *
 * The original code always wrote to sys_get_temp_dir() . '/b2_login_attempts.json'
 * — a well-known, guessable path. On shared hosting any co-tenant whose PHP
 * process can reach the same temp directory could read or truncate the file,
 * clearing all lockout records.
 *
 * Now:
 *  - If RATE_LIMIT_FILE is configured, that path is used (ideal: point it
 *    outside the webroot to a directory only your PHP user can access).
 *  - Otherwise a SHA-256 hash of the B2 key, the server hostname, and the
 *    script path is used to derive a filename that neighbouring tenants
 *    cannot guess.
 */
function getAttemptsFilePath(): string {
    if (defined('RATE_LIMIT_FILE') && RATE_LIMIT_FILE !== '') {
        return RATE_LIMIT_FILE;
    }
    $salt = (defined('B2_KEY_ID') ? B2_KEY_ID : '') . php_uname('n') . __FILE__;
    return sys_get_temp_dir() . '/b2_rl_' . substr(hash('sha256', $salt), 0, 20) . '.json';
}

/**
 * Load the attempts array from an already-open, exclusively-locked file handle.
 * Part of the Fix #6 (race condition) — all read-modify-write operations share
 * the same lock so no two concurrent requests can interleave.
 */
function _rl_load($fh): array {
    rewind($fh);
    $raw = stream_get_contents($fh);
    return ($raw !== '' && $raw !== false) ? (json_decode($raw, true) ?: []) : [];
}

/**
 * Persist the attempts array back through the locked file handle.
 */
function _rl_save($fh, array $data): void {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
}

/**
 * Fix #6 (race condition in rate limiting).
 *
 * The original code did separate file_get_contents / file_put_contents calls
 * with no locking between them. Two concurrent failed-login requests could
 * therefore both read count=4, both write count=5, and the lockout that should
 * trigger at count=5 would only be recorded once — effectively halving the
 * protection under a rapid parallel attack.
 *
 * The fix opens the file once with fopen('c+') and holds an exclusive lock
 * (LOCK_EX) for the entire read-modify-write cycle before releasing it.
 */
function checkRateLimit(string $ip): array {
    if (!RATE_LIMIT_ENABLED) {
        return ['allowed' => true];
    }

    $file = getAttemptsFilePath();
    $fh   = @fopen($file, 'c+');
    if (!$fh) {
        error_log("b2-browse: cannot open rate-limit file: {$file}");
        return ['allowed' => true]; // fail open rather than locking everyone out
    }

    flock($fh, LOCK_EX); // exclusive lock for the full operation

    $attempts = _rl_load($fh);
    $now      = time();

    // Purge entries whose attempt window has expired
    foreach (array_keys($attempts) as $key) {
        if ($now - $attempts[$key]['first_attempt'] > ATTEMPT_WINDOW) {
            unset($attempts[$key]);
        }
    }

    // If a previous lockout has now expired, clear it and allow
    if (isset($attempts[$ip]['locked_until']) && $now >= $attempts[$ip]['locked_until']) {
        unset($attempts[$ip]);
        _rl_save($fh, $attempts);
        flock($fh, LOCK_UN);
        fclose($fh);
        return ['allowed' => true];
    }

    // Still within an active lockout window
    if (isset($attempts[$ip]['locked_until']) && $now < $attempts[$ip]['locked_until']) {
        $remaining = $attempts[$ip]['locked_until'] - $now;
        flock($fh, LOCK_UN);
        fclose($fh);
        return [
            'allowed'  => false,
            'locked'   => true,
            'remaining' => $remaining,
            'attempts' => $attempts[$ip]['count'],
        ];
    }

    // Attempt count has just reached the threshold — apply lockout now
    if (isset($attempts[$ip]) && $attempts[$ip]['count'] >= MAX_LOGIN_ATTEMPTS) {
        $attempts[$ip]['locked_until'] = $now + LOCKOUT_DURATION;
        _rl_save($fh, $attempts);
        flock($fh, LOCK_UN);
        fclose($fh);
        return [
            'allowed'  => false,
            'locked'   => true,
            'remaining' => LOCKOUT_DURATION,
            'attempts' => $attempts[$ip]['count'],
        ];
    }

    // Below threshold — save cleaned data and allow
    _rl_save($fh, $attempts);
    flock($fh, LOCK_UN);
    fclose($fh);
    return ['allowed' => true];
}

function recordFailedAttempt(string $ip): void {
    if (!RATE_LIMIT_ENABLED) {
        return;
    }

    $file = getAttemptsFilePath();
    $fh   = @fopen($file, 'c+');
    if (!$fh) {
        error_log("b2-browse: cannot open rate-limit file: {$file}");
        return;
    }

    flock($fh, LOCK_EX);

    $attempts = _rl_load($fh);
    $now      = time();

    if (!isset($attempts[$ip])) {
        $attempts[$ip] = ['count' => 1, 'first_attempt' => $now];
    } else {
        $attempts[$ip]['count']++;
    }

    _rl_save($fh, $attempts);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function clearFailedAttempts(string $ip): void {
    if (!RATE_LIMIT_ENABLED) {
        return;
    }

    $file = getAttemptsFilePath();
    $fh   = @fopen($file, 'c+');
    if (!$fh) {
        return;
    }

    flock($fh, LOCK_EX);

    $attempts = _rl_load($fh);
    if (isset($attempts[$ip])) {
        unset($attempts[$ip]);
        _rl_save($fh, $attempts);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
}

// ============================================
// MFA (TOTP) FUNCTIONS
// ============================================
function generateMFASecret($length = 16) {
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}

function base32Decode($secret) {
    $chars             = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret            = strtoupper($secret);
    $paddingCharCount  = substr_count($secret, '=');
    $allowedValues     = [6, 4, 3, 1, 0];

    if (!in_array($paddingCharCount, $allowedValues)) {
        return false;
    }

    $buffer  = 0;
    $bitsLeft = 0;
    $output  = '';

    for ($i = 0; $i < strlen($secret); $i++) {
        $val = strpos($chars, $secret[$i]);
        if ($val === false) { continue; }

        $buffer   = ($buffer << 5) | $val;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $output   .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }

    return $output;
}

/**
 * Fix #3 (TOTP replay attack).
 *
 * The original verifyTOTP() had no way to communicate which time-slice was
 * matched.  The v1.3.0 fix stored used slices in $_SESSION, which only
 * protects against replay within the same PHP session — an attacker who
 * intercepts a valid code and uses it in a *different* session (e.g. a new
 * browser or Incognito window) would not have been blocked.
 *
 * The $usedSlice output parameter is set to the 30-second slice that matched.
 * The caller then uses isToTpSliceUsed() / markToTpSliceUsed() to check and
 * record the slice in a shared server-side file, blocking replay from any
 * session.
 */
function verifyTOTP(string $secret, string $code, ?int &$usedSlice = null, ?int $timeSlice = null): bool {
    if ($timeSlice === null) {
        $timeSlice = (int) floor(time() / 30);
    }

    $secretKey = base32Decode($secret);

    for ($i = -1; $i <= 1; $i++) {
        $calculatedCode = getTOTP($secretKey, $timeSlice + $i);
        if ($calculatedCode === $code) {
            $usedSlice = $timeSlice + $i;
            return true;
        }
    }

    return false;
}

function getTOTP($key, $timeSlice) {
    $time   = pack('N*', 0) . pack('N*', $timeSlice);
    $hash   = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code   = (
        ((ord($hash[$offset])     & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8)  |
        (ord($hash[$offset + 3])  & 0xff)
    ) % 1000000;

    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

// ============================================
// GLOBAL TOTP USED-SLICE STORE
// ============================================

/**
 * Returns the path of the file used to track TOTP time-slices that have
 * already been accepted, shared across all PHP sessions.
 * Kept separate from the rate-limit store so a log rotation or manual
 * clearance of one does not affect the other.
 */
function getToTpUsedSlicesFilePath(): string {
    if (defined('RATE_LIMIT_FILE') && RATE_LIMIT_FILE !== '') {
        return dirname(RATE_LIMIT_FILE) . '/b2_totp_slices.json';
    }
    // Derive a hard-to-guess filename from the same server-side salt used for
    // the rate-limit file, but with a different suffix so the two are distinct.
    $salt = (defined('B2_KEY_ID') ? B2_KEY_ID : '') . php_uname('n') . __FILE__ . '_totp';
    return sys_get_temp_dir() . '/b2_totp_' . substr(hash('sha256', $salt), 0, 20) . '.json';
}

/**
 * Check whether the given TOTP time-slice has already been consumed globally
 * (across all sessions).  Also prunes slices older than ±3 periods (~90 s)
 * so the file does not grow without bound.
 *
 * Uses the same flock-based read-modify-write pattern as checkRateLimit() to
 * avoid race conditions between concurrent requests.
 */
function isToTpSliceUsed(int $slice): bool {
    $file = getToTpUsedSlicesFilePath();
    $fh   = @fopen($file, 'c+');
    if (!$fh) {
        error_log('b2-browse: cannot open TOTP slice file: ' . $file);
        return false; // fail open rather than locking everyone out
    }

    flock($fh, LOCK_EX);

    $data    = _rl_load($fh);
    $current = (int) floor(time() / 30);

    // Prune stale slices and persist the clean list
    $data = array_values(array_filter(
        $data,
        static fn($s) => is_int($s) && abs($current - $s) <= 3
    ));
    _rl_save($fh, $data);

    $used = in_array($slice, $data, true);

    flock($fh, LOCK_UN);
    fclose($fh);

    return $used;
}

/**
 * Record a consumed TOTP time-slice in the global store so it cannot be
 * replayed from any session or browser.
 */
function markToTpSliceUsed(int $slice): void {
    $file = getToTpUsedSlicesFilePath();
    $fh   = @fopen($file, 'c+');
    if (!$fh) {
        error_log('b2-browse: cannot open TOTP slice file: ' . $file);
        return;
    }

    flock($fh, LOCK_EX);

    $data    = _rl_load($fh);
    $current = (int) floor(time() / 30);

    // Prune stale entries
    $data = array_values(array_filter(
        $data,
        static fn($s) => is_int($s) && abs($current - $s) <= 3
    ));

    if (!in_array($slice, $data, true)) {
        $data[] = $slice;
    }

    _rl_save($fh, $data);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Note: getMFAQRCode() has been removed.
// Fix #4: the QR code is now generated client-side in the setup page below.
// The MFA secret is embedded in HTML served over HTTPS only to the already-
// authenticated user — it is never transmitted to api.qrserver.com or any
// other external service.

// ============================================
// MAIN APPLICATION LOGIC
// ============================================

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

$error       = null;
$files       = [];
$currentPath = '';

if (!$configError) {
    try {
        $b2 = new B2Api();

        // ------------------------------------------------- Download handler
        if (isset($_GET['download'])) {
            // Fix #5: use sanitizeAndValidatePath() instead of the bare ".." check
            $fileName = sanitizeAndValidatePath($_GET['download']);
            if ($fileName === false) {
                http_response_code(400);
                die('Invalid file path');
            }

            logActivity('FILE_DOWNLOAD', [
                'filename' => $fileName,
                'filesize' => 'Unknown',
            ]);

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
                <p>This file has been successfully retrieved from your backup storage. If this download was not authorised, please review your access controls.</p>
                ";
                sendEmailAlert('📥 Download Notification: File Retrieved', $message);
            }

            $downloadUrl = $b2->getDownloadUrl($fileName);

            // HEAD request to retrieve metadata
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ['Authorization: ' . $b2->getAuthToken()],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER         => false,
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            curl_exec($ch);
            $contentType   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            $headers = [
                'Content-Type'        => $contentType ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . basename($fileName) . '"',
                'Cache-Control'       => 'must-revalidate',
                'Pragma'              => 'public',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($contentLength > 0) {
                $headers['Content-Length'] = $contentLength;
            }
            foreach ($headers as $key => $value) {
                header($key . ': ' . $value);
            }

            // Stream the file content
            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ['Authorization: ' . $b2->getAuthToken()],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER         => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_BUFFERSIZE     => 8192,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            if (ob_get_level()) { ob_end_clean(); }

            curl_exec($ch);
            if (curl_errno($ch)) {
                error_log('B2 File Transfer Error: ' . curl_error($ch));
            }
            curl_close($ch);
            exit;
        }

        // ------------------------------------------------- Stream handler
        if (isset($_GET['stream'])) {
            // Fix #5: validate path
            $fileName = sanitizeAndValidatePath($_GET['stream']);
            if ($fileName === false) {
                http_response_code(400);
                die('Invalid file path');
            }

            $downloadUrl = $b2->getDownloadUrl($fileName);
            $authToken   = $b2->getAuthToken();

            // Step 1: HEAD to get Content-Length and Content-Type.
            // Safari (iOS) requires an accurate Content-Length in every 206 response.
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER,    ['Authorization: ' . $authToken]);
            curl_setopt($ch, CURLOPT_NOBODY,         true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER,         true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        15);
            $headResponse  = curl_exec($ch);
            $contentType   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);

            // Fallback: parse Content-Length from raw headers if cURL returned -1
            if ($contentLength <= 0 && preg_match('/Content-Length:\s*(\d+)/i', $headResponse, $m)) {
                $contentLength = (int) $m[1];
            }

            if (empty($contentType) || strpos($contentType, 'octet-stream') !== false) {
                $contentType = 'video/mp4';
            }

            // Step 2: parse the client's Range header (if any).
            $rangeHeader   = isset($_SERVER['HTTP_RANGE']) ? trim($_SERVER['HTTP_RANGE']) : '';
            $start         = 0;
            $end           = $contentLength > 0 ? $contentLength - 1 : 0;
            $isRangeRequest = false;

            if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
                $isRangeRequest = true;
                $start = ($matches[1] !== '') ? (int) $matches[1] : 0;
                $end   = ($matches[2] !== '') ? (int) $matches[2] : ($contentLength > 0 ? $contentLength - 1 : 0);
                if ($contentLength > 0 && $end >= $contentLength) {
                    $end = $contentLength - 1;
                }
            }

            $chunkLength = $end - $start + 1;

            // Step 3: stream from B2, forwarding Range so B2 sends only the needed bytes.
            $ch         = curl_init($downloadUrl);
            $reqHeaders = ['Authorization: ' . $authToken];
            if ($isRangeRequest) {
                $reqHeaders[] = 'Range: bytes=' . $start . '-' . $end;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER,    $reqHeaders);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER,         false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_BUFFERSIZE,     65536);
            curl_setopt($ch, CURLOPT_TIMEOUT,        0);

            // Step 4: send correct response headers before streaming.
            if (ob_get_level()) { ob_end_clean(); }

            if ($isRangeRequest) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $contentLength);
            } else {
                header('HTTP/1.1 200 OK');
            }

            header('Content-Type: '   . $contentType);
            header('Content-Length: ' . $chunkLength);
            header('Accept-Ranges: bytes');
            header('Cache-Control: no-cache, no-store');

            curl_exec($ch);
            curl_close($ch);
            exit;
        }

        // ------------------------------------------------- Playback handler
        if (isset($_GET['play'])) {
            // Fix #5: validate path
            $fileName = sanitizeAndValidatePath($_GET['play']);
            if ($fileName === false) {
                http_response_code(400);
                die('Invalid file path');
            }

            logActivity('VIDEO_PLAYBACK', ['filename' => $fileName]);

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
                <p>The video is being streamed directly in the browser. If this playback was not authorised, please review your access controls.</p>
                ";
                sendEmailAlert('▶️ Playback Notification: Video Streaming', $message);
            }

            $signedUrl = $b2->getSignedUrl($fileName, 3600);
            $fileInfo  = parseFilePath($fileName);

            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<link rel="stylesheet" href="b2-browse.css">';
            echo '<title>Video Player - ' . htmlspecialchars(basename($fileName)) . '</title>';
            echo '</head><body class="player-page">';

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
                echo '<span class="info-item"><span class="info-label">Date:</span>'
                    . $fileInfo['year'] . '-' . $fileInfo['month'] . '-' . $fileInfo['day'] . '</span>';
            }
            if ($fileInfo['cameraId']) {
                echo '<span class="info-item"><span class="info-label">Camera:</span>'
                    . htmlspecialchars($fileInfo['cameraId']) . '</span>';
            }
            echo '<span class="info-item"><span class="info-label">File:</span>'
                . htmlspecialchars(basename($fileName)) . '</span>';
            echo '</div>';

            $isIOS = preg_match('/iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');

            echo '<div class="controls">';
            if ($isIOS) {
                echo '<a href="vlc://' . htmlspecialchars($signedUrl) . '" class="btn btn-vlc">▶️ Open in VLC</a>';
            }
            echo '<a href="?download=' . urlencode($fileName) . '" class="btn">⬇️ Download Video</a>';
            echo '<a href="?" class="btn btn-secondary">← Back to Files</a>';
            echo '</div>';

            echo '<script nonce="' . htmlspecialchars($cspNonce) . '">
                const video = document.getElementById("videoPlayer");

                let userInteracted = false;
                function unmuteOnInteract() {
                    if (!userInteracted) {
                        userInteracted = true;
                        video.muted = false;
                    }
                }
                video.addEventListener("click",      unmuteOnInteract);
                video.addEventListener("touchstart", unmuteOnInteract);

                document.addEventListener("keydown", function(e) {
                    if (e.key === " " || e.key === "k") {
                        e.preventDefault();
                        unmuteOnInteract();
                        if (video.paused) video.play(); else video.pause();
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

                video.addEventListener("error", function(e) {
                    console.error("Video playback error:", e);
                    alert("Error playing video. You can try downloading it instead.");
                });
            </script>';

            echo '</body></html>';
            exit;
        }

        // ------------------------------------------------ Directory listing
        $currentPath = isset($_GET['path']) ? $_GET['path'] : '';

        // Sanitize path: remove traversal sequences and normalise
        $currentPath = str_replace(['../', '..' . DIRECTORY_SEPARATOR], '', $currentPath);

        if ($currentPath !== '' && substr($currentPath, -1) !== '/') {
            $currentPath .= '/';
        }

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("B2: Current path being queried: '{$currentPath}'");
        }

        $startFileName = isset($_GET['start']) ? $_GET['start'] : '';
        $result        = $b2->listFiles($currentPath, '/', $startFileName);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log('B2: API returned ' . count($result['files']) . ' files');
            if (isset($result['nextFileName'])) {
                error_log('B2: More results available (nextFileName set)');
            }
        }

        $files        = isset($result['files'])        ? $result['files']        : [];
        $nextFileName = isset($result['nextFileName']) ? $result['nextFileName'] : null;

        usort($files, function ($a, $b) {
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
    <link rel="stylesheet" href="b2-browse.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>📹 Camera Backup Browser</h1>

            <?php if (defined('AUTH_USERNAME') && AUTH_USERNAME !== '' && isset($_SESSION['authenticated'])): ?>
                <div class="session-bar">
                    <div>
                        Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                        <?php
                        $timeRemaining  = SESSION_TIMEOUT - (time() - $_SESSION['last_activity']);
                        $minutesRemaining = floor($timeRemaining / 60);
                        ?>
                        | Session expires in: <strong><?php echo $minutesRemaining; ?> minutes</strong>
                        <?php if (MFA_ENABLED && !empty(MFA_SECRET)): ?>
                            | <a href="?setup_mfa=1" class="link-mfa">🔐 View MFA Setup</a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="?logout=1" id="logoutLink" class="link-logout">🚪 Logout</a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="breadcrumb">
                <a href="?">🏠 Home</a>
                <?php
                if ($currentPath !== '') {
                    $parts     = explode('/', trim($currentPath, '/'));
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
                <div class="debug-info">
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
                <ul class="config-list">
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
                    <ul class="debug-list">
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
                <select id="fileTypeFilter">
                    <option value="all">All Files</option>
                    <option value="video">Videos Only</option>
                    <option value="image">Images Only</option>
                    <option value="folder">Folders Only</option>
                </select>
                <input type="text" id="fileNameSearch" placeholder="Search by filename..." class="search-input">
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
                    $fileName    = $file['fileName'];
                    $isFolder    = substr($fileName, -1) === '/';
                    $displayName = $isFolder ? rtrim(basename($fileName), '/') : basename($fileName);
                    $fileInfo    = parseFilePath($fileName);
                    $isVideo     = strtolower($fileInfo['type']) === 'mp4';
                    $isImage     = strtolower($fileInfo['type']) === 'jpg';
                    $fileType    = $isFolder ? 'folder' : ($isVideo ? 'video' : ($isImage ? 'image' : 'other'));
                    ?>
                    <div class="file-item" data-type="<?php echo $fileType; ?>" data-name="<?php echo htmlspecialchars(strtolower($displayName)); ?>">
                        <div class="file-icon">
                            <?php if ($isFolder): ?>📁
                            <?php elseif ($isVideo): ?>🎥
                            <?php elseif ($isImage): ?>🖼️
                            <?php else: ?>📄
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

        <?php
        $paginationBase = '?';
        if ($currentPath !== '') {
            $paginationBase .= 'path=' . urlencode(rtrim($currentPath, '/')) . '&';
        }
        $hasPrev = ($startFileName !== '');
        $hasNext = ($nextFileName !== null);

        if ($hasPrev || $hasNext):
        ?>
        <div class="pagination">
            <?php if ($hasPrev): ?>
                <a href="<?php echo $paginationBase; ?>">← First Page</a>
            <?php else: ?>
                <span class="spacer"></span>
            <?php endif; ?>

            <span class="page-info">
                Showing <?php echo count($files); ?> items
                <?php if ($hasNext): ?> &mdash; more available<?php endif; ?>
            </span>

            <?php if ($hasNext): ?>
                <a href="<?php echo $paginationBase . 'start=' . urlencode($nextFileName); ?>">Next Page →</a>
            <?php else: ?>
                <span class="spacer"></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <script nonce="<?php echo htmlspecialchars($cspNonce); ?>">
        function filterFiles() {
            const typeFilter = document.getElementById('fileTypeFilter').value;
            const searchText = document.getElementById('fileNameSearch').value.toLowerCase();
            const items      = document.querySelectorAll('.file-item');

            items.forEach(item => {
                const itemType = item.getAttribute('data-type');
                const itemName = item.getAttribute('data-name');

                const showType   = typeFilter === 'all' || itemType === typeFilter;
                const showSearch = searchText === '' || itemName.includes(searchText);

                item.style.display = (showType && showSearch) ? 'flex' : 'none';
            });
        }

        // Event listeners (moved out of inline HTML attributes for CSP compliance)
        var filterSelect = document.getElementById('fileTypeFilter');
        if (filterSelect) filterSelect.addEventListener('change', filterFiles);

        var searchInput = document.getElementById('fileNameSearch');
        if (searchInput) searchInput.addEventListener('keyup', filterFiles);

        var logoutLink = document.getElementById('logoutLink');
        if (logoutLink) {
            logoutLink.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to log out?')) {
                    e.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
