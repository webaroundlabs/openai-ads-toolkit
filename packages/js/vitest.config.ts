import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    // Node by default: hashing tests need a real Web Crypto implementation,
    // which jsdom does not provide. Files that exercise the browser SDK opt in
    // with an `@vitest-environment jsdom` docblock.
    environment: 'node',
    include: ['tests/**/*.test.ts'],
  },
});
