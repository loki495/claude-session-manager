<?php
declare(strict_types=1);

/**
 * POST-only JSON endpoint for session.php's compose-bar "+" upload
 * button. Reads the real uploaded file from $_FILES (multipart/form-
 * data - php.ini's upload_max_filesize/post_max_size are raised in
 * docker-compose.yml to actually allow phone-camera-sized files), then
 * relays it to the host-agent as base64 JSON, same as any other action -
 * the container has no direct filesystem access to a session's real
 * project working directory, only the host-agent does (see
 * save_uploaded_file() in host-agent/lib/Sessions.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\AgentClient;
use App\Services\AuthService;

AuthService::start_app_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

if (!AuthService::same_origin_or_no_origin()) {
    http_response_code(403);
    echo "Rejected: cross-origin request.";
    exit;
}

AuthService::require_csrf();

header('Content-Type: application/json');

$sessionName = trim((string)($_POST['session'] ?? ''));
$file = $_FILES['file'] ?? null;

if (!is_array($file) || !isset($file['error'])) {
    echo json_encode(['ok' => false, 'message' => 'No file uploaded']);
    exit;
}

// UPLOAD_ERR_INI_SIZE/FORM_SIZE: over php.ini's upload_max_filesize or
// the form's own MAX_FILE_SIZE - a friendlier message than the generic
// fallback, since this is the one upload failure a user is actually
// likely to hit (a large phone photo/video).
$error = (int)$file['error'];

if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    echo json_encode(['ok' => false, 'message' => 'File too large']);
    exit;
}

if ($error !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'Upload failed (error code ' . $error . ')']);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$content = is_uploaded_file($tmpPath) ? @file_get_contents($tmpPath) : false;

if ($content === false) {
    echo json_encode(['ok' => false, 'message' => 'Could not read the uploaded file']);
    exit;
}

echo json_encode(AgentClient::agent_call([
    'action' => 'save_uploaded_file',
    'session' => $sessionName,
    'filename' => (string)($file['name'] ?? 'upload'),
    'content_base64' => base64_encode($content),
]));
