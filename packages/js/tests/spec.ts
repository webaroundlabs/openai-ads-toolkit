import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Reads the shared specification from `packages/spec`.
 *
 * Development-time asset reached by relative path, never a package dependency -
 * which is why these tests run from a monorepo checkout only, and why the event
 * catalogue never reaches the browser bundle.
 */
export function loadSpec<T = Record<string, unknown>>(file: string): T {
  const path = fileURLToPath(new URL(`../../spec/${file}`, import.meta.url));

  return JSON.parse(readFileSync(path, 'utf8')) as T;
}

export interface NormalizationCase {
  field: string;
  raw: string;
  normalized: string;
  sha256: string;
  asserts: string;
}

export interface GeographicCase {
  field: string;
  raw: string;
  normalized: string;
  asserts: string;
}

export interface NormalizationFixture {
  valid: NormalizationCase[];
  geographic: {
    valid: GeographicCase[];
    rejected: Array<{ field: string; raw: string; reason: string; asserts: string }>;
  };
  divergence_guard: {
    raw: string;
    wrong: { normalized: string; sha256: string };
    correct: { normalized: string; sha256: string };
  };
  rejected: Array<{ field: string; raw: string; normalized: string; reason: string }>;
}
