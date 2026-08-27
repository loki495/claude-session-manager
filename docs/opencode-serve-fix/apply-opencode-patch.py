#!/usr/bin/env python3
"""
apply-opencode-patch.py — Apply the global provider state fix for opencode serve.

Fixes ProviderModelNotFoundError when serve mode resolves different directories
than TUI (different Instance cache keys => different provider states => models
visible in /config/providers but "not found" during prompt execution).

Safe to run multiple times (idempotent). Backs up originals before patching.
"""
import os
import sys
import shutil
import re
from datetime import datetime
from pathlib import Path

OPENCODE_SRC = os.environ.get("OPENCODE_SRC", "/tmp/opencode")
PATCH_DIR = Path(__file__).parent

RED = "\033[0;31m"
GREEN = "\033[0;32m"
YELLOW = "\033[1;33m"
NC = "\033[0m"

def log(msg): print(f"{GREEN}[opencode-patch]{NC} {msg}")
def warn(msg): print(f"{YELLOW}[opencode-patch]{NC} {msg}")
def err(msg): print(f"{RED}[opencode-patch]{NC} {msg}", file=sys.stderr)

INST_STATE = Path(OPENCODE_SRC) / "packages/opencode/src/effect/instance-state.ts"
PROVIDER_TS = Path(OPENCODE_SRC) / "packages/opencode/src/provider/provider.ts"

# --- Pre-flight ---
for f in [INST_STATE, PROVIDER_TS]:
    if not f.exists():
        err(f"File not found: {f}")
        sys.exit(1)

inst_src = INST_STATE.read_text()
prov_src = PROVIDER_TS.read_text()

# --- Check if already applied ---
if "_fixedKey" in inst_src and "provider-global" in prov_src:
    log("Patch already applied — nothing to do")
    sys.exit(0)

log("Patch not detected — applying...")

# --- Backup ---
backup_dir = PATCH_DIR / "backups" / datetime.now().strftime("%Y%m%d-%H%M%S")
backup_dir.mkdir(parents=True, exist_ok=True)
shutil.copy2(INST_STATE, backup_dir / "instance-state.ts.orig")
shutil.copy2(PROVIDER_TS, backup_dir / "provider.ts.orig")
log(f"Backed up originals to {backup_dir}")

# --- Patch instance-state.ts ---

# 1. Add _fixedKey to InstanceState interface
if "_fixedKey" not in inst_src:
    inst_src = inst_src.replace(
        "readonly [TypeId]: typeof TypeId\n",
        "readonly [TypeId]: typeof TypeId\n  readonly _fixedKey?: string\n",
    )
    log("  Added _fixedKey to InstanceState interface")

# 2. Add options parameter to make()
if "options?.fixedKey" not in inst_src:
    inst_src = inst_src.replace(
        "init: (ctx: InstanceContext) => Effect.Effect<A, E, R | Scope.Scope>,\n): Effect.Effect",
        "init: (ctx: InstanceContext) => Effect.Effect<A, E, R | Scope.Scope>,\n  options?: { fixedKey?: string },\n): Effect.Effect",
    )
    log("  Added options parameter to make()")

# 3. Add _fixedKey to return value
if "_fixedKey: options" not in inst_src:
    inst_src = inst_src.replace(
        "[TypeId]: TypeId,\n      cache,",
        "[TypeId]: TypeId,\n      _fixedKey: options?.fixedKey,\n      cache,",
    )
    log("  Added _fixedKey to return value")

# 4. Fix get() to use fixedKey
if "self._fixedKey" not in inst_src:
    inst_src = inst_src.replace(
        "return yield* ScopedCache.get(self.cache, yield* directory)",
        "const key = self._fixedKey ?? (yield* directory)\n    return yield* ScopedCache.get(self.cache, key)",
    )
    log("  Updated get() to use fixedKey")

# --- Patch provider.ts ---
if "provider-global" not in prov_src:
    # Find: const state = yield* InstanceState.make<State>(() =>
    #   Effect.gen(function* () {
    #     ... (many lines) ...
    #   }),
    # And change the closing }), to: }), { fixedKey: "provider-global" },
    #
    # The pattern: after the init function's closing }), we add the options.
    # We look for the specific closing pattern of the provider state init.

    # Strategy: find the line with InstanceState.make<State>(()= and then find
    # the matching closing }), by counting braces.
    lines = prov_src.split("\n")
    start_idx = None
    for i, line in enumerate(lines):
        if "InstanceState.make<State>(()" in line and "provider" not in line.lower():
            # This is likely the provider state init (not the only one, but
            # we'll match the one in the provider layer)
            start_idx = i
            break

    if start_idx is not None:
        # Count braces to find the matching closing.
        # Start with net parens from the starting line itself (make<...>(() => has 2 open).
        depth = lines[start_idx].count("(") - lines[start_idx].count(")")
        end_idx = None
        for i in range(start_idx + 1, len(lines)):
            depth += lines[i].count("(") - lines[i].count(")")
            if depth == 0:
                end_idx = i
                break

        if end_idx is not None:
            # The closing of InstanceState.make is a bare ")" line.
            # The }),  (closing the Effect.gen callback) is the line BEFORE it.
            # We need to modify the }),  line to add the options arg.
            close_idx = end_idx - 1
            if close_idx >= start_idx:
                closing_line = lines[close_idx].rstrip()
                if "})" in closing_line and "{ fixedKey" not in closing_line:
                    lines[close_idx] = closing_line.replace("}),", '}), { fixedKey: "provider-global" },')
                    prov_src = "\n".join(lines)
                    log("  Added fixedKey to provider state init")
                else:
                    warn(f"  Could not match closing pattern at line {close_idx + 1}: {closing_line}")
            else:
                warn("  Could not find }), line before closing )")
        else:
            warn("  Could not find matching closing brace for InstanceState.make")
    else:
        warn("  Could not find InstanceState.make<State> in provider.ts")

# --- Write patched files ---
INST_STATE.write_text(inst_src)
PROVIDER_TS.write_text(prov_src)

# --- Verify ---
inst_check = INST_STATE.read_text()
prov_check = PROVIDER_TS.read_text()

if "_fixedKey" in inst_check and "provider-global" in prov_check:
    log("Patch applied successfully!")
    log("Restart opencode serve to pick up changes:")
    log("  systemctl --user restart opencode-serve")
    log("  # or: kill $(pgrep -f 'opencode serve') && opencode serve --hostname 0.0.0.0 --port 4096 --mdns &")
else:
    warn("Patch partially applied — check manually:")
    warn(f"  {INST_STATE}")
    warn(f"  {PROVIDER_TS}")
    sys.exit(1)
