<?php
/**
 * Backblaze B2 Private Bucket File Browser
 * 
 * Configuration Instructions:
 * 1. Fill in your B2 credentials below
 * 2. Upload this file to your shared hosting
 * 3. Ensure PHP has curl and json extensions enabled
 */

// ============================================
// CONFIGURATION - FILL THESE IN
// ============================================
define('B2_KEY_ID', 'your_application_key_id');
define('B2_APPLICATION_KEY', 'your_application_key');
define('B2_BUCKET_NAME', 'your_bucket_name');
define('B2_BUCKET_ID', 'your_bucket_id');

// Optional: Add basic authentication for extra security
define('AUTH_USERNAME', ''); // Leave empty to disable
define('AUTH_PASSWORD', ''); // Leave empty to disable

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
    
    // Check if user is already authenticated
    $isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    
    // Check session timeout
    if ($isAuthenticated && isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            // Session expired
            session_unset();
            session_destroy();
            $isAuthenticated = false;
            
            header('WWW-Authenticate: Basic realm="B2 File Browser"');
            header('HTTP/1.0 401 Unauthorized');
            echo '<html><body><h2>Session Expired</h2><p>Your session has expired due to inactivity. Please refresh the page to log in again.</p></body></html>';
            exit;
        }
    }
    
    // If not authenticated, check credentials
    if (!$isAuthenticated) {
        if (!isset($_SERVER['PHP_AUTH_USER']) || 
            $_SERVER['PHP_AUTH_USER'] !== AUTH_USERNAME || 
            $_SERVER['PHP_AUTH_PW'] !== AUTH_PASSWORD) {
            header('WWW-Authenticate: Basic realm="B2 File Browser"');
            header('HTTP/1.0 401 Unauthorized');
            echo 'Authentication required';
            exit;
        }
        
        // Authentication successful
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['username'] = AUTH_USERNAME;
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
        
        // Handle download request
        if (isset($_GET['download'])) {
            $fileName = $_GET['download'];
            
            // Security: Prevent directory traversal attacks
            if (strpos($fileName, '..') !== false) {
                die('Invalid file path');
            }
            
            $downloadUrl = $b2->getDownloadUrl($fileName);
            
            // Stream the file directly instead of loading into memory
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $b2->getAuthToken()
            ]);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, true); // Get headers only first
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            // Get headers to determine content type and size
            curl_exec($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            
            // Set headers for download
            if ($contentType) {
                header('Content-Type: ' . $contentType);
            } else {
                header('Content-Type: application/octet-stream');
            }
            header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
            if ($contentLength > 0) {
                header('Content-Length: ' . $contentLength);
            }
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('X-Content-Type-Options: nosniff'); // Security header
            
            // Stream the actual file content
            $ch = curl_init($downloadUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $b2->getAuthToken()
            ]);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Output directly to browser
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192); // Stream in 8KB chunks
            curl_setopt($ch, CURLOPT_TIMEOUT, 0); // No timeout for large files
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            
            // Flush output buffer before streaming
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            curl_exec($ch);
            
            // Check for errors
            if (curl_errno($ch)) {
                error_log("B2 Download Error: " . curl_error($ch));
            }
            
            curl_close($ch);
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
                <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                    Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                    <?php 
                    $timeRemaining = SESSION_TIMEOUT - (time() - $_SESSION['last_activity']);
                    $minutesRemaining = floor($timeRemaining / 60);
                    ?>
                    | Session expires in: <strong><?php echo $minutesRemaining; ?> minutes</strong>
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
