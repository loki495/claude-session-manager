<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Stores\SidecarStore;

/** Canonical transcript adapter for Codex app-server thread history. */
class CodexTranscriptService
{
    private const PREFIX = 'codex:';

    public static function find_transcript_path(string $threadId): ?string
    {
        $sidecar = SidecarStore::read_sidecar($threadId);
        return ($sidecar['agent'] ?? null) === 'codex' ? self::PREFIX . $threadId : null;
    }

    public static function is_codex_path(string $path): bool
    {
        return str_starts_with($path, self::PREFIX);
    }

    private static function thread_id(string $path): string
    {
        return substr($path, strlen(self::PREFIX));
    }

    /** @return array<int,array<string,mixed>> */
    private static function entries(string $path): array
    {
        $reply = (new CodexBridgeClient())->request('thread/read', ['threadId' => self::thread_id($path), 'includeTurns' => true]);
        $turns = is_array($reply['result']['thread']['turns'] ?? null) ? $reply['result']['thread']['turns'] : [];
        $entries = [];

        foreach ($turns as $turn) {
            if (!is_array($turn)) continue;
            $timestamp = isset($turn['startedAt']) ? gmdate('c', (int)$turn['startedAt']) : null;
            foreach (($turn['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $type = (string)($item['type'] ?? '');
                $role = null;
                $blocks = [];

                if ($type === 'userMessage') {
                    $role = 'user';
                    foreach (($item['content'] ?? []) as $content) {
                        if (is_array($content) && is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                            $blocks[] = ['kind' => 'text', 'text' => $content['text']];
                        }
                    }
                } elseif ($type === 'agentMessage') {
                    $role = 'assistant';
                    if (is_string($item['text'] ?? null) && trim($item['text']) !== '') $blocks[] = ['kind' => 'text', 'text' => $item['text']];
                } elseif ($type === 'plan') {
                    $role = 'assistant';
                    if (is_string($item['text'] ?? null)) $blocks[] = ['kind' => 'plan', 'text' => $item['text']];
                } elseif ($type === 'commandExecution') {
                    $role = 'assistant';
                    $command = (string)($item['command'] ?? '');
                    $blocks[] = ['kind' => 'tool_use', 'text' => $command, 'tool_name' => 'Bash', 'command' => $command];
                    if (is_string($item['aggregatedOutput'] ?? null) && $item['aggregatedOutput'] !== '') $blocks[] = ['kind' => 'tool_result', 'text' => $item['aggregatedOutput']];
                } elseif ($type === 'fileChange') {
                    $role = 'assistant';
                    $blocks[] = ['kind' => 'tool_use', 'text' => json_encode($item['changes'] ?? []), 'tool_name' => 'FileChange'];
                } elseif ($type === 'mcpToolCall' || $type === 'dynamicToolCall') {
                    $role = 'assistant';
                    $tool = (string)($item['tool'] ?? 'tool');
                    $blocks[] = ['kind' => 'tool_use', 'text' => $tool . ': ' . json_encode($item['arguments'] ?? []), 'tool_name' => $tool];
                    $output = $item['result'] ?? $item['contentItems'] ?? null;
                    if ($output !== null) $blocks[] = ['kind' => 'tool_result', 'text' => is_string($output) ? $output : (string)json_encode($output)];
                }

                if ($blocks === []) continue;
                $entries[] = [
                    'type' => $role === 'user' ? 'USER_INPUT' : 'PLANNER_RESPONSE',
                    'role' => $role,
                    'timestamp' => $timestamp,
                    'blocks' => $blocks,
                    'line' => count($entries) + 1,
                ];
            }
        }

        return $entries;
    }

    /** @return array<string,mixed> */
    public static function read_transcript_page(string $path, ?int $before, int $limit, bool $untilRealUserMessage = false): array
    {
        $all = self::entries($path);
        $upper = $before !== null ? max(0, min($before - 1, count($all))) : count($all);
        $start = max(0, $upper - $limit);
        return ['ok' => true, 'entries' => array_slice($all, $start, $upper - $start), 'next_before' => $start > 0 ? $start + 1 : null, 'has_more' => $start > 0];
    }

    /** @return array<string,mixed> */
    public static function read_transcript_page_since(string $path, int $afterLine, int $limit): array
    {
        return ['ok' => true, 'entries' => array_slice(self::entries($path), max(0, $afterLine), $limit)];
    }

    /** @return array<string,mixed> */
    public static function read_attachment(string $path, int $line, string $fileUuid): array
    {
        return ['ok' => false, 'message' => 'Codex transcript attachments are not available yet'];
    }
}
