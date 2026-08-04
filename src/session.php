<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/Auth.php';

use App\AgentClient;
use App\Views\PageView;

start_app_session();

$sessionName = trim((string)($_GET['session'] ?? $_POST['session'] ?? ''));

if ($sessionName === '') {
    header('Location: /', true, 303);
    exit;
}

$csrfToken = csrf_token();

$detail = AgentClient::agent_call(['action' => 'session_detail', 'session' => $sessionName]);
$found = (bool)($detail['ok'] ?? false);

$pushResult = AgentClient::agent_call(['action' => 'push_public_key']);
$vapidPublicKey = (string)($pushResult['public_key'] ?? '');

$history = $found ? AgentClient::agent_call(['action' => 'session_history', 'session' => $sessionName, 'before' => null, 'limit' => 30]) : ['ok' => false];
$historyOk = (bool)($history['ok'] ?? false);
$entries = $historyOk ? ($history['entries'] ?? []) : [];
$nextBefore = $historyOk ? ($history['next_before'] ?? null) : null;
$hasMore = $historyOk && ($history['has_more'] ?? false);
$newestLine = !empty($entries) ? end($entries)['line'] : null;

echo PageView::render_session_page([
    'sessionName' => $sessionName,
    'csrfToken' => $csrfToken,
    'detail' => $detail,
    'found' => $found,
    'vapidPublicKey' => $vapidPublicKey,
    'history' => $history,
    'historyOk' => $historyOk,
    'entries' => $entries,
    'nextBefore' => $nextBefore,
    'hasMore' => $hasMore,
    'newestLine' => $newestLine,
]);
