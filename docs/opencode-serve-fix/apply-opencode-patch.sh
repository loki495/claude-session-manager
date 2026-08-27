#!/usr/bin/env bash
# apply-opencode-patch.sh — Check and apply the global provider state fix
# for opencode serve mode. Safe to run multiple times (idempotent).
set -euo pipefail

OPENCODE_SRC="${OPENCODE_SRC:-/tmp/opencode}"
PATCH_DIR="$(cd "$(dirname "$0")" && pwd)"
MARKER="fixedKey.*provider-global"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log()  { echo -e "${GREEN}[opencode-patch]${NC} $*"; }
warn() { echo -e "${YELLOW}[opencode-patch]${NC} $*"; }
err()  { echo -e "${RED}[opencode-patch]${NC} $*" >&2; }

# --- Pre-flight checks ---
if [ ! -d "$OPENCODE_SRC/packages/opencode/src" ]; then
  err "OpenCode source not found at $OPENCODE_SRC"
  err "Set OPENCODE_SRC=/path/to/opencode and re-run"
  exit 1
fi

INST_STATE="$OPENCODE_SRC/packages/opencode/src/effect/instance-state.ts"
PROVIDER_TS="$OPENCODE_SRC/packages/opencode/src/provider/provider.ts"

for f in "$INST_STATE" "$PROVIDER_TS"; do
  if [ ! -f "$f" ]; then
    err "Expected file not found: $f"
    exit 1
  fi
done

# --- Check if patch is already applied ---
if grep -qE "$MARKER" "$INST_STATE" && grep -qE "$MARKER" "$PROVIDER_TS"; then
  log "Patch already applied — nothing to do"
  exit 0
fi

log "Patch not detected — applying..."

# --- Backup originals ---
BACKUP_DIR="$PATCH_DIR/backups/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp "$INST_STATE" "$BACKUP_DIR/instance-state.ts.orig"
cp "$PROVIDER_TS" "$BACKUP_DIR/provider.ts.orig"
log "Backed up originals to $BACKUP_DIR"

# --- Apply instance-state.ts patch ---
# Add _fixedKey to InstanceState interface
if ! grep -q "_fixedKey" "$INST_STATE"; then
  sed -i '/readonly \[TypeId\]: typeof TypeId/a\  readonly _fixedKey?: string' "$INST_STATE"
  log "  Added _fixedKey to InstanceState interface"
fi

# Add options parameter to make()
if ! grep -q "options?.fixedKey" "$INST_STATE"; then
  sed -i 's/export const make = <A, E = never, R = never>(/export const make = <A, E = never, R = never>(/' "$INST_STATE"
  # Add options parameter after init parameter
  sed -i '/^export const make = /{
    N
    s/init: (ctx: InstanceContext) => Effect.Effect<A, E, R | Scope.Scope>,/init: (ctx: InstanceContext) => Effect.Effect<A, E, R | Scope.Scope>,\n  options?: { fixedKey?: string },/
  }' "$INST_STATE"
  log "  Added options parameter to make()"
fi

# Add _fixedKey to return value
if ! grep -q "_fixedKey: options" "$INST_STATE"; then
  sed -i '/\[TypeId\]: TypeId,/a\      _fixedKey: options?.fixedKey,' "$INST_STATE"
  log "  Added _fixedKey to return value"
fi

# Fix get() to use fixedKey
if ! grep -q "self._fixedKey" "$INST_STATE"; then
  sed -i 's/return yield\* ScopedCache.get(self\.cache, yield\* directory)/const key = self._fixedKey ?? (yield* directory)\n    return yield* ScopedCache.get(self.cache, key)/' "$INST_STATE"
  log "  Updated get() to use fixedKey"
fi

# --- Apply provider.ts patch ---
if ! grep -q "fixedKey.*provider-global" "$PROVIDER_TS"; then
  # Find the InstanceState.make line for provider state and add fixedKey
  sed -i '/const state = yield\* InstanceState.make<State>(()=/{
    N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N;N
    s/}),$\n    )/}), { fixedKey: "provider-global" },\n    )/
  }' "$PROVIDER_TS"
  log "  Added fixedKey to provider state init"
fi

# --- Verify ---
if grep -qE "$MARKER" "$INST_STATE" && grep -qE "$MARKER" "$PROVIDER_TS"; then
  log "Patch applied successfully"
  log "Restart opencode serve to pick up changes"
else
  warn "Patch partially applied — check manually:"
  warn "  $INST_STATE"
  warn "  $PROVIDER_TS"
  exit 1
fi
