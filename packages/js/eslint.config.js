// @ts-check
import eslint from '@eslint/js';
import tseslint from 'typescript-eslint';

/**
 * Lint rules for the browser package.
 *
 * Type-aware rather than syntactic, because the rules worth having here need
 * types: a forgotten `await` on `hashUser()` produces a Promise where a digest
 * was expected, and the Pixel accepts it without complaint.
 *
 * `recommendedTypeChecked` rather than `strictTypeChecked`. The strict preset's
 * extra rules are mostly about style, and two of them are actively wrong here:
 * it reads TypeScript's DOM lib as promising that `globalThis.crypto` always
 * exists, so the fallbacks that keep this package from sending raw identifiers
 * on a plain-HTTP page look like dead code. Rules that catch real defects -
 * floating promises, misused promises, awaiting a non-thenable - are kept and
 * are the reason this is type-aware at all.
 */
export default tseslint.config(
  {
    // dist is generated; the config and vitest setup are not part of the
    // TypeScript project, so type-aware rules have nothing to read for them.
    ignores: ['dist/**', 'node_modules/**', 'eslint.config.js', 'vitest.config.ts'],
  },
  eslint.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,
  {
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      // The official SDK's queue is a global function with a `q` array bolted
      // on. `declare global { var oaiq }` is the only way to type it, and a
      // global declaration must use `var`.
      'no-var': 'off',
      // The SDK's command queue takes (...args: unknown[]); calls into it are
      // unavoidably untyped at the boundary.
      '@typescript-eslint/no-unsafe-argument': 'off',
    },
  },
  {
    files: ['tests/**/*.ts'],
    rules: {
      // Test doubles for browser globals need to lie about types on purpose.
      '@typescript-eslint/no-unsafe-assignment': 'off',
      '@typescript-eslint/no-unsafe-member-access': 'off',
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-non-null-assertion': 'off',
    },
  },
);
