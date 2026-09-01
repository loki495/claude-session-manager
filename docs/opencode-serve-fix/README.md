# OpenCode Serve Fix: Global Provider State

## Problem

`opencode serve` throws `ProviderModelNotFoundError` for any model when the
session's directory differs from the serve process's working directory.

**Root cause:** The provider state is cached per-directory via `InstanceState`
(a `ScopedCache` keyed by directory). In serve mode:

- `GET /config/providers` resolves to `process.cwd()` (the serve's cwd,
  e.g. `/home/user`)
- `POST /session/{id}/prompt_async` resolves to the session's stored directory
  (e.g. `/home/user/www/claude-session-manager`)

Different directories → different cache keys → different provider init runs →
models visible in `/config/providers` but "not found" during prompt execution.

**Why TUI works:** The TUI always runs in one directory, so there's only one
cache entry and one provider state.

## Fix

Add a `fixedKey` option to `InstanceState.make()`. When set, `get()` uses this
key instead of the directory, so all directories share one cached state. Only
the provider init uses this; all other `InstanceState` consumers keep their
per-directory behavior.

**Files modified:**
1. `packages/opencode/src/effect/instance-state.ts` — add `fixedKey` plumbing
2. `packages/opencode/src/provider/provider.ts` — use `fixedKey: "provider-global"`

## Applying

```bash
# From the Sessioneer repo:
python3 docs/opencode-serve-fix/apply-opencode-patch.py

# Or with custom OpenCode source path:
OPENCODE_SRC=/path/to/opencode python3 docs/opencode-serve-fix/apply-opencode-patch.py
```

After patching, restart the serve:
```bash
systemctl --user restart opencode-serve
```

## Verification

```bash
# 1. Check models are available:
curl -s http://localhost:4096/config/providers | python3 -c "
import sys,json
d=json.load(sys.stdin)
for p in d['providers']:
    if p['id'] in ('openai','opencode-go'):
        print(f\"{p['id']}: {len(p.get('models',{}))} models\")
"

# 2. Create session and send with openai model:
curl -s -X POST http://localhost:4096/api/session \
  -H 'Content-Type: application/json' \
  -d '{"location":{"directory":"/tmp"}}' | python3 -c "
import sys,json; d=json.load(sys.stdin); print(d['data']['id'])
"

# 3. Set model and send — should NOT get ProviderModelNotFoundError:
# (check ~/.local/share/opencode/log/opencode.log for errors)
```

## Observer: Detect if patch gets overwritten

A systemd path unit watches the patched files and re-applies the fix
automatically:

```bash
# Install the observer:
cp docs/opencode-serve-fix/opencode-patch-watcher.path ~/.config/systemd/user/
cp docs/opencode-serve-fix/opencode-patch-watcher.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now opencode-patch-watcher.path
```

When OpenCode updates and the patch is lost, the watcher triggers
`apply-opencode-patch.py` automatically.

## Reverting

Backups are stored in `docs/opencode-serve-fix/backups/`:
```bash
cp backups/YYYYMMDD-HHMMSS/instance-state.ts.orig /tmp/opencode/packages/opencode/src/effect/instance-state.ts
cp backups/YYYYMMDD-HHMMSS/provider.ts.orig /tmp/opencode/packages/opencode/src/provider/provider.ts
```

## Status

- **Applies to:** OpenCode 1.18.21 (and likely other versions — the
  `InstanceState` caching pattern has been stable)
- **Upstream status:** Not yet reported. This should ideally be fixed upstream
  by making global providers (API-key based) use a shared state regardless of
  directory.
- **Risk:** Low. The fix only affects provider state caching. Other
  `InstanceState` consumers (tools, plugins, permissions, etc.) continue using
  per-directory caching.
