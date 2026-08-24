// Ambient type declarations for globals this app sets up itself, not
// part of any library - lets tsc/checkJs (see tsconfig.json,
// `npm run typecheck`) know about them instead of reporting "Property
// does not exist on Window" everywhere they're read. Never loaded by the
// browser (a .d.ts file has no runtime output at all) - type-checking only.

interface CsmBootstrap {
  session?: string;
  csrfToken?: string;
  newestLine?: number | null;
  claudeSessionId?: string | null;
  jumpLine?: number | null;
  workdir?: string | null;
  agent?: string;
  agentLabel?: string;
}

interface Window {
  CSM_BOOTSTRAP: CsmBootstrap;
}
