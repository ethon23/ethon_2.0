/**
 * Production entrypoint.
 *
 * The HTTP runtime is intentionally isolated in server.runtime.js so the
 * public entrypoint stays dependency-free and predictable under ESM.
 * Architectural seams live under src/ and are consumed incrementally without
 * changing the existing API contract.
 */
import './server.runtime.js';
