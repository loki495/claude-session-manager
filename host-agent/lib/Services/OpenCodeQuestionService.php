<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * OpenCode blocked-prompt (question) detection + answering via the `opencode
 * serve` HTTP API.
 *
 * opencode 1.18.21 keeps the pending question modal in-memory in the serve
 * process and exposes it over HTTP (no tmux-digit model, and the plugin
 * permission.ask hook is dormant). The relevant endpoints (see the serve's own
 * OpenAPI at GET /doc):
 *   - GET /question  -> array of QuestionRequest {id: queue_*, sessionID,
 *     questions[{question, header, options[{label,description}], multiple,
 *     custom}], tool{messageID, callID}} - [] when nothing is live or the
 *     modal is ORPHANED (server already resolved it, TUI still showing it),
 *     which is exactly the "sends a number but nothing arrives" failure CSM
 *     previously hit by tmux-scraping.
 *   - POST /question/{requestID}/reply -> body {answers: [[label], ...]}.
 *
 * This is how opencode's own web/TUI answers, so it sidesteps the arrow-key
 * shape mismatch (opencode renders questions as either an "↑↓ select" list or
 * a "⇆ tab" tab bar) entirely - a question is answered by label, not by the
 * key a particular rendering happens to use.
 */
class OpenCodeQuestionService
{
    /**
     * Returns the live pending questions for $sessionId from GET /question, or
     * null when there is none (and, crucially, the modal is NOT orphaned - an
     * orphaned question is still rendered in the pane but the server already
     * resolved it, so answering would no-op; GET /question returns [] for it).
     *
     * @return array{requestID:string, questions:array<int, array{question:string, header:string, options:array<int, array{label:string, description:string}>, multiple:bool, custom:bool}>}|null
     */
    public static function pending_question(string $sessionId): ?array
    {
        $server = Config::opencode_server_url();
        $result = ProcessRunner::run_process(['curl', '--silent', '--max-time', '3', $server . '/question']);

        if ($result['exit'] !== 0) {
            return null;
        }

        $data = json_decode($result['stdout'], true);

        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $request) {
            if (!is_array($request)) {
                continue;
            }

            if (($request['sessionID'] ?? null) !== $sessionId) {
                continue;
            }

            $requestID = is_string($request['id'] ?? null) ? $request['id'] : null;
            $questions = is_array($request['questions'] ?? null) ? $request['questions'] : [];

            if ($requestID === null || $questions === []) {
                continue;
            }

            return ['requestID' => $requestID, 'questions' => $questions];
        }

        return null;
    }

    /**
     * Answers the live question for $sessionId via POST /question/{requestID}
     * /reply. $labels is the ordered list of chosen labels, one per question
     * in the opened question's order; each may be a single label or an array
     * for a multi-select. Mirrors the API's `answers: [[label], ...]` shape.
     *
     * @param array<int, array<int, string>|string> $labels
     * @return array{ok:bool, message:string}
     */
    public static function answer(string $sessionId, array $labels): array
    {
        $pending = self::pending_question($sessionId);

        if ($pending === null) {
            return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a question'];
        }

        $answers = [];

        foreach ($labels as $label) {
            $answers[] = is_array($label) ? array_values(array_map('strval', $label)) : [(string)$label];
        }

        $server = Config::opencode_server_url();
        $url = $server . '/question/' . rawurlencode($pending['requestID']) . '/reply';
        $payload = json_encode(['answers' => $answers]);
        $tmp = tempnam(sys_get_temp_dir(), 'csm-ocq-');
        $bodyFile = $tmp !== false ? $tmp : '/dev/null';
        file_put_contents($bodyFile, $payload);

        $result = ProcessRunner::run_process([
            'curl', '--silent', '--max-time', '5',
            '--request', 'POST',
            '--header', 'Content-Type: application/json',
            '--data', '@' . $bodyFile,
            $url,
        ]);

        @unlink($bodyFile);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to answer question: ' . trim($result['stderr'])];
        }

        $ok = json_decode($result['stdout'], true) === true;

        // An error body (e.g. "reply for unknown request") is truthy JSON but
        // not the boolean true the success contract returns - treat any non-true
        // as failure so an orphaned/stale answer surfaces as an error, not a
        // silent success.
        return $ok
            ? ['ok' => true, 'message' => 'Question answered']
            : ['ok' => false, 'message' => 'Question reply rejected by the server (may be stale/orphaned)'];
    }

    /**
     * Convenience for the app's indexed-option shape: converts a pending
     * question's options (label/description) into the canonical
     * {number, label} list the frontend renders, plus the multi/custom flags.
     *
     * @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name:string}
     */
    public static function to_prompt(array $pending): array
    {
        $questions = $pending['questions'];
        $first = $questions[0] ?? [];

        $question = is_string($first['question'] ?? null) ? $first['question'] : 'Waiting on input';
        $header = is_string($first['header'] ?? null) ? $first['header'] : '';
        $options = [];

        foreach (($first['options'] ?? []) as $idx => $opt) {
            if (is_array($opt) && is_string($opt['label'] ?? null)) {
                $options[] = ['number' => $idx + 1, 'label' => $opt['label']];
            }
        }

        // opencode's question tool is a real multi-question shape (each
        // question answered independently). This app's canonical shape only
        // renders ONE question at a time, so for multiple questions we surface
        // the first and answer it; the rest are collapsed into the context so
        // the human is told the full set is being asked.
        $context = $header;
        if (count($questions) > 1) {
            $extra = [];
            foreach (array_slice($questions, 1) as $q) {
                $extra[] = is_string($q['question'] ?? null) ? $q['question'] : '';
            }
            $context .= "\n(+ " . (count($questions) - 1) . " more question(s))";
        }

        return [
            'question' => $question,
            'context' => trim($context),
            'options' => $options,
            'multi_question' => count($questions) > 1,
            'tool_name' => 'question',
        ];
    }
}
