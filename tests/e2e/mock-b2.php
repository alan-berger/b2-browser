<?php
/**
 * Mock Backblaze B2 (API v4) and ntfy server for the end-to-end tests.
 * Run with: php -S 127.0.0.1:PORT tests/e2e/mock-b2.php
 *
 * Behaviour is steered through a JSON state file (MOCK_STATE env var):
 *   scope: 'restricted' | 'master'   which `allowed` block authorize returns
 *   expire_next: bool                next API call answers expired_auth_token
 * and every request is counted in it so tests can assert on call patterns.
 */
declare(strict_types=1);

const KEY_ID = 'test-key-id';
const APP_KEY = 'test-application-key';
const BUCKET_ID = 'bucket123';
const BUCKET_NAME = 'cams';

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/b2-mock-state.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['scope' => 'restricted', 'expire_next' => false, 'calls' => [], 'token' => 'acct-1', 'ntfy' => []];

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$self = 'http://' . $_SERVER['HTTP_HOST'];

function save(array $state, string $file): void
{
    file_put_contents($file, json_encode($state));
}

function json_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function b2_error(int $status, string $code, string $message): never
{
    json_out($status, ['status' => $status, 'code' => $code, 'message' => $message]);
}

function objects(): array
{
    static $objects = null;
    if ($objects !== null) {
        return $objects;
    }
    $objects = [
        'camera-footage/2026/10/13/000001/09-00-00.mp4' => str_repeat('A', 1000),
        'camera-footage/2026/10/14/000001/10-30-00.mp4' => 'MP4DATA-' . str_repeat('x', 5000),
        'camera-footage/2026/10/14/000001/10-30-00.jpg' => sample_jpeg(),
        'camera-footage/2026/10/14/000001/10-31-00.jpg' => sample_jpeg(true),
        'camera-footage/2026/10/14/000001/10-45-00 #1?.mp4' => 'special-name-video',
        'camera-footage/2026/10/14/000001/<img src=x onerror=alert(1)>.jpg' => 'evil',
        'camera-footage/2026/10/14/000001/empty.mp4' => '',
        'camera-footage/2026/10/14/000002/11-00-00.mp4' => 'cam2',
        'camera-footage-private/secret.mp4' => 'sibling-secret',
        'other/secret.mp4' => 'outside-secret',
    ];
    for ($i = 0; $i < 250; $i++) {
        $objects[sprintf('camera-footage/bulk/file-%03d.mp4', $i)] = "bulk{$i}";
    }
    ksort($objects, SORT_STRING);
    return $objects;
}

/** A small real JPEG when GD is available (so thumbnails render), else JPEG-magic bytes. */
function sample_jpeg(bool $portrait = false): string
{
    if (!function_exists('imagecreatetruecolor')) {
        return "\xFF\xD8\xFFjpegdata";
    }
    [$w, $h] = $portrait ? [108, 192] : [192, 108];
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 52, 73, 94));
    imagefilledrectangle($img, 10, (int) ($h * 0.55), $w - 10, $h - 10, imagecolorallocate($img, 39, 174, 96));
    imagefilledellipse($img, $w - 25, 25, 30, 30, imagecolorallocate($img, 241, 196, 15));
    // A red border shows at a glance whether the edges are cropped.
    imagerectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 231, 76, 60));
    imagerectangle($img, 1, 1, $w - 2, $h - 2, imagecolorallocate($img, 231, 76, 60));
    ob_start();
    imagejpeg($img, null, 80);
    return (string) ob_get_clean();
}

function allowed(array $state): array
{
    return $state['scope'] === 'master'
        ? ['buckets' => [], 'capabilities' => ['listFiles', 'readFiles', 'shareFiles', 'writeFiles', 'deleteFiles'], 'namePrefix' => null]
        : ['buckets' => [['id' => BUCKET_ID, 'name' => BUCKET_NAME]], 'capabilities' => ['listBuckets', 'listFiles', 'readFiles', 'shareFiles'], 'namePrefix' => 'camera-footage/'];
}

function key_prefix(array $state): string
{
    return allowed($state)['namePrefix'] ?? '';
}

function check_account_token(array &$state, string $file): void
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($state['expire_next']) {
        $state['expire_next'] = false;
        save($state, $file);
        b2_error(401, 'expired_auth_token', 'Authorization token has expired');
    }
    if ($auth !== $state['token']) {
        b2_error(401, 'bad_auth_token', 'Invalid authorization token');
    }
}

$state['calls'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path;
save($state, $stateFile);

// ------------------------------------------------------------------ ntfy
if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $state['ntfy'][] = json_decode((string) file_get_contents('php://input'), true);
    save($state, $stateFile);
    json_out(200, ['id' => 'x']);
}

// --------------------------------------------------------- authorize account
if ($path === '/b2api/v4/b2_authorize_account') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode(KEY_ID . ':' . APP_KEY)) {
        b2_error(401, 'unauthorized', 'bad key');
    }
    $state['token'] = 'acct-' . bin2hex(random_bytes(4));
    save($state, $stateFile);
    json_out(200, [
        'accountId' => 'acc',
        'authorizationToken' => $state['token'],
        'apiInfo' => ['storageApi' => [
            'apiUrl' => $self,
            'downloadUrl' => $self,
            'allowed' => allowed($state),
            'absoluteMinimumPartSize' => 5000000,
            'recommendedPartSize' => 100000000,
            's3ApiUrl' => 'https://s3.example',
        ]],
        'applicationKeyExpirationTimestamp' => null,
    ]);
}

// ---------------------------------------------------------- list file names
if ($path === '/b2api/v4/b2_list_file_names') {
    check_account_token($state, $stateFile);
    $req = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $prefix = (string) ($req['prefix'] ?? '');
    $delimiter = (string) ($req['delimiter'] ?? '');
    $start = (string) ($req['startFileName'] ?? '');
    $max = (int) ($req['maxFileCount'] ?? 100);
    if (($req['bucketId'] ?? '') !== BUCKET_ID) {
        b2_error(400, 'bad_request', 'bucketId');
    }
    if (!str_starts_with($prefix, key_prefix($state))) {
        b2_error(401, 'unauthorized', 'prefix outside key namePrefix');
    }

    $entries = [];
    foreach (objects() as $name => $body) {
        if (!str_starts_with($name, $prefix)) {
            continue;
        }
        $rest = substr($name, strlen($prefix));
        $pos = $delimiter !== '' ? strpos($rest, $delimiter) : false;
        if ($pos !== false) {
            $folder = $prefix . substr($rest, 0, $pos + 1);
            $entries[$folder] = ['fileName' => $folder, 'action' => 'folder', 'contentLength' => 0];
        } else {
            $entries[$name] = ['fileName' => $name, 'action' => 'upload', 'contentLength' => strlen($body),
                'contentType' => 'video/mp4', 'uploadTimestamp' => 1760400000000];
        }
    }
    // A hide marker and an unfinished large file must not be shown.
    if ($prefix === 'camera-footage/2026/10/14/000001/') {
        $entries[$prefix . 'hidden.mp4'] = ['fileName' => $prefix . 'hidden.mp4', 'action' => 'hide', 'contentLength' => 0];
        $entries[$prefix . 'zz-partial.mp4'] = ['fileName' => $prefix . 'zz-partial.mp4', 'action' => 'start', 'contentLength' => 0];
    }
    ksort($entries, SORT_STRING);
    $names = array_values(array_filter(array_keys($entries), static fn($n) => strcmp($n, $start) >= 0));
    $page = array_slice($names, 0, $max);
    $next = $names[$max] ?? null;
    json_out(200, ['files' => array_map(static fn($n) => $entries[$n], $page), 'nextFileName' => $next]);
}

// ---------------------------------------------------- download authorization
if ($path === '/b2api/v4/b2_get_download_authorization') {
    check_account_token($state, $stateFile);
    $req = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $prefix = (string) ($req['fileNamePrefix'] ?? '');
    $ttl = (int) ($req['validDurationInSeconds'] ?? 0);
    if ($ttl < 1 || $ttl > 604800 || !str_starts_with($prefix, key_prefix($state))) {
        b2_error(400, 'bad_request', 'bad download authorization request');
    }
    json_out(200, ['bucketId' => BUCKET_ID, 'fileNamePrefix' => $prefix,
        'authorizationToken' => 'dl.' . base64_encode(json_encode(['p' => $prefix, 'exp' => time() + $ttl]))]);
}

// ------------------------------------------------------------- download file
if (str_starts_with($path, '/file/' . BUCKET_NAME . '/')) {
    $name = rawurldecode(substr($path, strlen('/file/' . BUCKET_NAME . '/')));
    $queryToken = $_GET['Authorization'] ?? null;
    if ($queryToken !== null) {
        $data = json_decode((string) base64_decode(substr((string) $queryToken, 3)), true);
        if (!is_array($data) || !str_starts_with($name, (string) $data['p']) || $data['exp'] < time()) {
            b2_error(401, 'unauthorized', 'download token does not cover this file');
        }
    } else {
        check_account_token($state, $stateFile);
    }
    if (!str_starts_with($name, key_prefix($state))) {
        b2_error(401, 'unauthorized', 'outside namePrefix');
    }
    $objects = objects();
    if (!array_key_exists($name, $objects)) {
        b2_error(404, 'not_found', 'File not present: ' . $name);
    }
    $type = str_ends_with($name, '.jpg') ? 'image/jpeg' : (str_ends_with($name, '.mp4') ? 'video/mp4' : 'application/octet-stream');
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($objects[$name]));
    echo $objects[$name];
    exit;
}

b2_error(404, 'not_found', 'no such endpoint ' . $path);
