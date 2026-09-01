---
id: agent-abstraction
based_on: 44e4caab492481c850d4dceec97d5c65e41a5b53
generated_at: 2026-08-25
---

# Agent abstraction audit

## Re-verification

DETAILS.md was generated at HEAD `44e4caa` (its `last_scanned_commit`).
The owned seam files (`host-agent/lib/Agents/*`, `tests/test_agent_adapter.php`)
are **clean and unchanged** at that commit — `git diff HEAD -- host-agent/lib/Agents/
tests/test_agent_adapter.php` is empty. I re-verified every owned file:line claim
against the current tree (the working tree, which is what matters) and found
the DETAILS.md map accurate. Specific checks:

- `AgentAdapter.php` interface methods and docblocks match (`id():32`,
  `label():38`, `session_name_prefix():47`, `build_spawn_argv():66`,
  `check_hooks():78`, `install_hooks():86`, `permission_mode_map():99`). ✓
- `AgentRegistry.php`: `ADAPTERS` at `:18-22`, cached `get()` at `:27-34`,
  `default_agent_id()` `'claude'` at `:41-44`, `known_agent_ids()` at `:47-50`. ✓
- `ClaudeCodeAdapter.php:44-65` builds `[claude_bin,'--session-id',<uuid>]` +
  optional `--allowedTools`/`--permission-mode`. ✓
- `AntigravityAdapter.php:73-100` `STARTING_MODE_FLAGS` at `:32-35`,
  `assigned_id` always `null` at `:99`. ✓
- `OpenCodeAdapter.php:53-78`, `permission_mode_map()` `[]` at `:106-109`. ✓
- `Config.php:37,49,60` bins; `:327` antigravity_hooks_path; `:337-355`
  antigravity hook commands. ✓
- `SqliteDb.php:124` `agent TEXT`; `SidecarStore.php:32` add_column_if_missing,
  `:60-66` read comment (null -> claude), `:107` write default `'claude'`. ✓

**One caveat worth recording:** the working tree at audit time is **dirty**
(`git status --short` shows ~40 modified files plus untracked `.claude/` and new
`AntigravityPromptParser.php`/`PermissionStore.php`/OpenCode plugin dirs). The
modified files are all **consumers** or nearby subsystems (`Sessions.php`,
`SessionService.php`, `Config.php`, `SidecarStore.php`, `SqliteDb.php`, views,
controllers), **not** the owned seam. I have re-read the relevant consumer lines
in the working tree (not just at HEAD), so the findings below reflect current
behavior. DETAILS.md is not meaningfully out of date for this subsystem; its
accuracy-note claims about consumers (`Sessions.php` still calling `HookService`
directly; health box iterating `HookService::app_hooks_status()`) hold in the
working tree too.

## Ranked findings

### 1. Adapter hook + permission-mode surface is production-unwired, and the interface docblock overstates its callers — **Medium**

**Recommendation:** `tweak` — the methods are correct and test-covered and should
stay; the real defect is a docblock that claims callers that don't exist. Fix the
docblock to describe the intended (not current) wiring, and only wire the
production paths through the registry once a per-agent hook UI/health box actually
needs it.

**Evidence:**
- `check_hooks()`/`install_hooks()` have **no production caller**. The only call
  sites are the test file (`tests/test_agent_adapter.php:97-102, 144, 184-194`).
  Production paths call the services directly: `Sessions.php:179-183`
  (`check_session_hook`/`install_session_hook` -> `HookService::*` directly) and
  `PushHealthService.php:194` (`HookService::app_hooks_status($settings)`), plus
  `:220-221` (`opencode_serve_check()`/`opencode_plugin_check()`).
- `permission_mode_map()` has **no production caller** either. Claude's own hook
  scripts call `PermissionMode::normalize_hook_permission_mode()` directly —
  `host-agent/hooks/permission_request.php:71`,
  `host-agent/hooks/user_prompt_submit.php:45`,
  `host-agent/hooks/stop.php:52` — never through the adapter.
- `AgentAdapter.php:73-74` docblock: "so callers (the dashboard's health box,
  agent.php's dispatch_action()) don't need to know which agent they're asking
  about" — both named callers route through `HookService`/`app_hooks_status()`
  directly today.

**Current complexity / invalid states:** The interface advertises three surfaces
(spawn argv, hook registration, permission vocabulary) but only `build_spawn_argv()`
+ `id()/label()/session_name_prefix()` are actually reached by the runtime. The
remaining two are ahead of the wiring. This is *not* dead code to delete — it's a
contract that's ahead of its consumers. The concrete risk is that a docblock
already overstates reality, and an unwired surface can drift silently (e.g. a
future change to `AntigravityHookService`'s shape wouldn't be caught by the
adapter's delegation until something actually calls it).

**Proposed representation:** Keep the methods; change the docblock at
`AgentAdapter.php:73-74` (and the `check_hooks`/`install_hooks` "callers" sentence)
to say the current callers are *none yet* (test-only) and that the intended wiring
is the health box / `dispatch_action()` once those route per-agent. No interface
shape change needed.

**Smallest credible scope:** `AgentAdapter.php` docblock only (2 sentences).

**Regression risks / migration:** None — docblock-only change.

**Validation:** `tests/test_agent_adapter.php` continues to prove the delegation;
no new test needed for a comment fix. If/when production wiring lands, add a test
proving `dispatch_action('check_session_hook')` and the health box actually
round-trip an adapter.

**Confidence:** `high`

### 2. Resume path bypasses the adapter seam — resume argv is hardcoded inline — **Medium**

**Recommendation:** `research-more` — this is a genuine seam gap, but there's no
immediate 4th agent, so the right move is to decide explicitly rather than patch
now. Either (a) add a resume-aware method/option to the seam, or (b) document that
resume intentionally stays inline because its argv shape genuinely differs
(resume is not a "spawn").

**Evidence:**
- `SessionLifecycleService::resume_cc_session()` builds the per-agent argv inline
  at `SessionLifecycleService.php:244-246`:
  `$resumeArgv = $isOpencodeResume ? [Config::opencode_bin(), '--session', $claudeSessionId]
  : [Config::claude_bin(), '--resume', $claudeSessionId];`
- It resolves an adapter only for the session-name prefix and the failure-message
  label (`:241-242, :267`), then deliberately **does not** call
  `build_spawn_argv()` for the argv.

**Current complexity / invalid states:** The seam's stated job is "one place that
knows the agent binary + flags," but create and resume now each own a separate
copy of that knowledge. Create routes through `build_spawn_argv()`; resume routes
through inline ternaries. If `Config::opencode_bin()`/`claude_bin()` or the
resume flag shape ever changes, the two paths diverge silently. A future 4th agent
cannot be resumed through the seam without editing this inline branch.

**Proposed representation:** A single conceptual "how to launch this agent" method
that takes an explicit mode (fresh vs resume), e.g.
`build_spawn_argv(array $options, bool $resuming = false)` returning the resume
flag instead of `--session-id`, or a separate `build_resume_argv(string $id)` on
the interface. Both are small; the seam currently has no resume vocabulary at all.

**Smallest credible scope:** Interface method on `AgentAdapter.php`, three adapter
implementations, and replace the ternary at
`SessionLifecycleService.php:244-246` with an adapter call.

**Regression risks / migration:** Non-trivial — resume is exercised by
`test_sessions_lifecycle.php` and the real app (dormant-session resume). Any change
must keep the exact `--resume <id>` / `--session <id>` shapes. Because OpenCode's
resume is currently distinguished by `OpenCodeTranscriptService::is_opencode_id()`
(a string-prefix heuristic on the session id), and only the two known agents are
resume-able today, the risk is contained but real (a resume that stops matching
`is_opencode_id()` would silently route to `claude --resume`).

**Validation:** Add resume-path assertions mirroring the create-path ones in
`test_sessions_lifecycle.php` (bad binary, unknown id fallback, happy path for
both opencode and claude resume) before swapping the ternary.

**Confidence:** `high`

### 3. `create_cc_session()` never forwards `model`/`effort`/`workdir`/OpenCode-`agent` to `build_spawn_argv()` — those adapter capabilities are unreachable — **Medium**

**Recommendation:** `refactor` (small, or deliberate documentation). The adapters
document and test options no production caller can supply. Either extend
`create_cc_session()` to accept and forward them, or explicitly mark them
"future wiring" in the adapter docblocks. Also disambiguate OpenCode's
`$options['agent']` (its own `--agent` concept) from the registry's agent id —
the same word currently means two different things across the seam.

**Evidence:**
- The **only** production caller of `build_spawn_argv()` is
  `SessionLifecycleService.php:77`:
  `$spawn = $agent->build_spawn_argv(['enable_task_tools' => $enableTaskTools, 'starting_mode' => $startingMode]);`
  — exactly two keys, hardcoded.
- The request action that feeds it (`Sessions.php:98-107`) reads only `workdir`,
  `enable_task_tools`, `starting_mode`, and `agent` (the agent *id*), and passes
  `workdir` to `create_cc_session`'s **positional** param, not into the options.
- Yet `AntigravityAdapter.php:77-97` reads `$options['model']`/`$options['effort']`,
  and `OpenCodeAdapter.php:57-75` reads `$options['workdir']`/`$options['directory']`/
  `$options['model']`/`$options['agent']`. These are exercised only by
  `tests/test_agent_adapter.php:116-125, 158-173` — never by the runtime.

**Current complexity / invalid states:** For Antigravity, `--model`/`--effort` are
never emitted on a real spawn (defaults only). For OpenCode, the positional
`[project]` arg is never emitted on a real spawn — the session is rooted only by
tmux's `-c $workdir` (`SessionLifecycleService.php:89`), which OpenCode happens to
treat as the project cwd, so it *works today* but only by relying on OpenCode's
cwd-defaulting rather than the adapter's documented positional-arg contract.
When the New Session UI grows a model/agent picker (AntigravityAdapter docblock
anticipates this at `AntigravityAdapter.php:58-61`), this will need the create
signature + call site updated, and the `workdir`-as-positional vs `workdir`-as-cwd
duplication will need resolving.

**Proposed representation:** Extend
`SessionLifecycleService::create_cc_session()` with optional `?string $model = null`,
`?string $effort = null`, `?string $opencodeAgent = null` and fold them into the
`build_spawn_argv()` options array (renaming OpenCode's `agent` option to e.g.
`opencode_agent` to break the name collision). `Sessions.php`'s `create` action
then forwards the corresponding request fields.

**Smallest credible scope:** `SessionLifecycleService::create_cc_session()`
signature + `Sessions.php` `create` case + option rename in `OpenCodeAdapter.php`.

**Regression risks / migration:** Defaults preserve today's behavior (all optional
params null -> identical argv). Renaming OpenCode's `agent` option is
back-compat-safe if no external caller passes it (none does; only tests use it,
which would be updated in the same change).

**Validation:** Add a create-round-trip assertion (mirroring
`test_sessions_lifecycle.php:882-927`) that spawns an OpenCode session with a
`model` and asserts `--model` reaches the argv; same for Antigravity `--effort`.

**Confidence:** `high`

### 4. DRY: the "append flag + value" idiom is repeated ~6× across the three adapters — **Low**

**Recommendation:** `refactor` (very small) — extract a tiny flag-pushing helper per
adapter (or a shared trait), keeping the guards at the call site. Do **not** build a
shared indirection layer; that would conflict with the project's stated preference
for small single-purpose files.

**Evidence (8 conditional appends, 6 with the "non-empty string" shape):**
- `ClaudeCodeAdapter.php:49-52` (`--allowedTools`, truthy guard), `:59-62`
  (`--permission-mode`, non-null guard).
- `AntigravityAdapter.php:79-82` (`--model`), `:86-89` (`--effort`, whitelist guard),
  `:94-97` (`--mode`).
- `OpenCodeAdapter.php:59-61` (positional workdir), `:65-68` (`--model`),
  `:72-75` (`--agent`).

**Current complexity / invalid states:** Around 30 lines of near-identical
"is it an acceptable value? then push flag, push value" scaffolding, with the
guard subtly varying (non-empty string vs whitelist vs truthy bool vs non-null).
The variation is legitimate, but the push-the-pair plumbing is duplicated.

**Proposed representation:** A helper like
`@{php} private static function push_flag(array &$argv, string $flag, mixed $value): void`,
or a shared trait `UsesFlagAppend`. Guards stay at the call site so each adapter's
whitelisting discipline is untouched.

**Smallest credible scope:** The three adapter files only. No interface or
consumer change.

**Regression risks / migration:** None — pure mechanical; argv output unchanged.
Test file pins the exact argv shapes, so any behavioral slip fails immediately.

**Validation:** `tests/test_agent_adapter.php` already asserts every flag shape.

**Confidence:** `high`

### 5. Interface over-constrains: forces `permission_mode_map()` on every agent even where one genuinely has no mode vocabulary — **Low**

**Recommendation:** `skip` (accept the trade-off) or `tweak` — the uniform contract
is a deliberate design and the honest-`[]`/honest-speculative implementations are
correct. If you want to distinguish "no such concept" (OpenCode) from "not yet
observed" (Antigravity), let `permission_mode_map()` return `?array` (`null` =
none), but that is optional polish.

**Evidence:**
- `OpenCodeAdapter.php:106-109` returns `[]` (only the boolean `--auto` exists).
- `AntigravityAdapter.php:131-134` returns `array_flip(self::STARTING_MODE_FLAGS)`,
  with a docblock (`:118-130`) explicitly admitting no captured hook payload carries
  a mode field yet.
- `ClaudeCodeAdapter.php:77-80` returns the fully-backed
  `PermissionMode::HOOK_PERMISSION_MODE_MAP`.

**Current complexity / invalid states:** Because the interface cannot express
"this agent has no such thing," OpenCode must fake it with `[]` and Antigravity
with a speculative flip. Any consumer writing `$adapter->permission_mode_map()[...]`
cannot tell "no modes" from "modes not yet discovered." Harmless today (no
production consumer, see finding 1), but it's a semantic dead-end that will matter
the moment a consumer reads this map.

**Proposed representation:** `?array` return (`null` = none). Keeps Claude's real
map, makes OpenCode return `null`, and lets Antigravity keep its honest placeholder
or return `null` until observed.

**Smallest credible scope:** `AgentAdapter.php:97-99` signature + three
implementations + the test assertions at `test_agent_adapter.php:66, 182`.

**Regression risks / migration:** `tests/test_agent_adapter.php` asserts `[]` for
OpenCode (`:182`); would change to `null`. No production code reads the map, so no
runtime impact.

**Validation:** Update the two test assertions.

**Confidence:** `medium`

### 6. Stale / misleading comments and docblocks — **Low**

**Recommendation:** `tweak` — correct two comments that assert the wrong current
state. They're not behavior, but they will mislead a future reader (and were
already flagged as "phase" comments that outlived their phase).

**Evidence:**
- `tests/test_agent_adapter.php:14-16` header claims: "AntigravityAdapter's own
  hooks are deliberately unimplemented stubs so far (Phase 3)" — **false**.
  Antigravity's `check_hooks()`/`install_hooks()` delegate to
  `AntigravityHookService` (`AntigravityAdapter.php:105-116`) and the same file
  at `:191-194` *proves* `install_hooks()` writes `~/.gemini/config/hooks.json`.
  Only **OpenCode's** hooks are stubs (`OpenCodeAdapter.php:83-94`).
- `ClaudeCodeAdapter.php:13` docblock: "The first (and, until Antigravity ships,
  only) AgentAdapter implementation" — Antigravity (`a1e0214`) and OpenCode
  (`10e9ccb`) both shipped; stale.

**Current complexity / invalid states:** Both comments encode a snapshot from
earlier in the 2026-08-24/25 rollout instead of the current state. Not a bug, but
the Antigravity one in particular directly contradicts the same file's own
assertions, which is the kind of thing that makes readers distrust the suite.

**Proposed representation:** Reword the test header to say Antigravity's hooks are
implemented and only OpenCode's are stubs; reword the ClaudeCodeAdapter class
docblock to "the first implementation; see Antigravity/OpenCode adapters."

**Smallest credible scope:** Two comment edits.

**Regression risks / migration:** None.

**Validation:** None needed (comment-only).

**Confidence:** `high`

### 7. `ClaudeCodeAdapter` <-> `SessionLifecycleService` uuid cycle — confirmed benign, optional to break — **Low**

**Recommendation:** `research-more` — genuinely benign, so only worth breaking if
you care about a clean class graph (or if a real cycle problem appears later).

**Evidence (cycle confirmed at class level):**
- `SessionLifecycleService.php:7` imports `HostAgent\Agents\AgentRegistry`.
- `AgentRegistry.php:18-22` maps `'claude' => ClaudeCodeAdapter::class`.
- `ClaudeCodeAdapter.php:10` imports `HostAgent\Services\SessionLifecycleService`
  and calls `SessionLifecycleService::generate_uuid_v4()` at `:46`.
- So `SessionLifecycleService -> AgentRegistry -> ClaudeCodeAdapter -> SessionLifecycleService`.

**Current complexity / invalid states:** At runtime the cycle is inert: `generate_uuid_v4()`
(`SessionLifecycleService.php:27-35`) is a pure static utility with no back-reference,
and `AgentRegistry::get()` caches singletons (`AgentRegistry.php:33`) so there is no
construction recursion. `tests/test_agent_adapter.php:72` and
`tests/test_sessions_lifecycle.php:414-415` prove the id is a real v4 and fresh per
call. The only cost is the conceptual dependency for a future reader/static analyzer.

**Proposed representation:** Extract `generate_uuid_v4()` (plus its RFC 4122
comment) into a neutral utility (e.g. `HostAgent\Support\Uuid`), have
`ClaudeCodeAdapter` and `SessionLifecycleService` both call it. Breaks the cycle.

**Smallest credible scope:** New tiny `Uuid` helper + `ClaudeCodeAdapter.php:46` +
optionally `SessionLifecycleService.php:27-35` delegates to it.

**Regression risks / migration:** `test_sessions_lifecycle.php:412-415` asserts
`SessionLifecycleService::generate_uuid_v4()` — if you move/retain the method there,
keep it (delegate); if you remove it, update that test. Because the id is only
consumed by `create_cc_session()` and the SessionStart hook (keyed off the sidecar's
`claude_session_id`), the value is interchangeable as long as it stays a v4 UUID.

**Validation:** Existing assertions continue to pass; no new coverage strictly needed.

**Confidence:** `high`

## What's done well

- **The seam is genuinely narrow and honest.** The interface deliberately excludes
  hook-payload parsing and tmux/SQLite plumbing (`AgentAdapter.php:7-24`), and each
  adapter documents exactly which `$options` it understands and which it ignores.
  The "read only what you understand" contract (`AgentAdapter.php:62-63`) is applied
  consistently and is well evidenced (Claude-only `enable_task_tools` silently
  ignored by Antigravity/OpenCode; `starting_mode` whitelisted per agent).
- **Whitelisting discipline is consistent and test-pinned.** Unrecognized `model`/
  `effort`/`starting_mode` values are silently dropped, never passed through raw —
  exactly the "re-validate, don't trust client input" convention in CLAUDE.md — and
  `tests/test_agent_adapter.php` asserts each (unknown mode `:89-93`, bad effort
  `:121-122`, empty model `:167-168`, `manual`/`auto` omitting `--mode` `:132-136`).
- **Sad-path coverage in the owned test is genuinely solid**, not a happy-path
  skim: unknown registry id throws, unrecognized mode/effort omitted, empty model
  omitted, honest stub reporting, and real-install proves for both
  `~/.claude/settings.json` and `~/.gemini/config/hooks.json`.
- **No redundant second copy of the permission map.** ClaudeCodeAdapter returns
  `PermissionMode::HOOK_PERMISSION_MODE_MAP` directly (`ClaudeCodeAdapter.php:77-80`),
  not a duplicated array — single source of truth preserved.
- **Cross-subsystem integration is test-backed at the seam boundary** via
  `test_sessions_lifecycle.php:882-927` (bad Antigravity binary fails, unknown id
  falls back to `cc-`, happy path writes `agent=antigravity` and a null
  `claude_session_id`).
- **Honesty about unimplemented parts.** OpenCode's hook stubs report `ok=true,
  installed=false, message=...` rather than pretending success
  (`OpenCodeAdapter.php:83-94`), and Antigravity's speculative permission map is
  documented as not-yet-observed (`AntigravityAdapter.php:118-130`).

## Out-of-scope

Left to other subsystems (seen, not owned):

- **Per-agent transcript / search / resume-id heuristics** — `OpenCodeTranscriptService`,
  `AntigravityTranscriptService`, `ArchiveStatusService` (transcript/archive
  boundary). The only seam-adjacent part I noted is the `is_opencode_id()` prefix
  heuristic used to pick the resume agent (`SessionLifecycleService.php:239-240`),
  which is a string-shape assumption owned outside the seam.
- **Hook payload parsing / hook scripts** — `host-agent/hooks/*.php`,
  `host-agent/hooks/antigravity/*.php`, `AntigravityHookService`,
  `HookService`, `SessionStatusStore`, and `PermissionStore` (the OpenCode
  `permission.ask` bridge). The seam explicitly excludes payload parsing.
- **Prompt parsing / interaction** — `PermissionMode`, `PromptParser`,
  `AntigravityPromptParser`, `OpenCodePromptParser`, `PromptInteractionService`,
  `AntigravitySelectableModel`, `SelectableModel`, `OpenCodeQuestionService`.
  `PermissionMode` is imported by `ClaudeCodeAdapter.php:9` for spawn-argv
  translation and is also the source of the direct `normalize_hook_permission_mode()`
  calls in the Claude hook scripts.
- **Health box / quota / push** — `PushHealthService`, `QuotaService`,
  `PushDeliveryService`. The adapter hook methods are *not* in the health box's
  hook-status loop (`PushHealthService.php:194` iterates `HookService::app_hooks_status()`).

## Cross-cutting observations

Described, not solved (each touches a known neighboring subsystem):

- **Hand-maintained registry mirror (`session-view`).** `PageView::AGENT_OPTIONS`
  (`src/lib/Views/PageView.php:27`) is a deliberate, documented, hand-synced mirror
  of `AgentRegistry::ADAPTERS`, because the container UI can't reach host-agent's
  env/config. This is a genuine drift risk: adding a 4th agent needs edits in
  `AgentRegistry.php`, `PageView.php`, and (for hook/plugin health) `PushHealthService`.
  Consider a shared source (e.g. a generated/checked constant or a test that
  asserts the two lists match) as a later, cross-subsystem concern — not owned here.

- **`permission_mode_map()` duplicates a `prompt-interaction` concept that the real
  wiring bypasses.** For Claude, `permission_mode_map()` returns the exact
  `PermissionMode::HOOK_PERMISSION_MODE_MAP` (`ClaudeCodeAdapter.php:77-80`); the
  actual hook scripts bypass the adapter and call `normalize_hook_permission_mode()`
  directly. So the seam's permission surface runs parallel to (not through) the
  prompt-interaction subsystem it semantically mirrors. Moot until the adapter
  method gains a real consumer (see finding 1).

- **`build_spawn_argv` option-name collision across agents.** OpenCode's
  `$options['agent']` (its own `--agent` concept) shares the word with the
  registry-level agent id and Antigravity/Claude's `$options['starting_mode']`
  meaning drifts from OpenCode's ignored `starting_mode`. This ambiguity is inside
  the seam but its resolution likely touches `SessionLifecycleService`/
  `prompt-interaction` (the model/agent pickers) — see finding 3.

Fresh write; no prior `## Human Notes` section existed to preserve.
