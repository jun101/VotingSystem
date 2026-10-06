import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  globalIgnores([
    '.next/**',
    'node_modules/**',
    'next-env.d.ts',
    // Generated
    'src/lib/api/schema.d.ts',
    // Browser acceptance tests and their configuration are fixed by the slice's harness
    'e2e/**',
    'playwright.config.ts',
  ]),
  {
    // No text in a component: it comes from the message files (NFR-UX-01).
    files: ['src/**/*.tsx'],
    ignores: ['src/**/*.test.tsx'],
    rules: {
      'react/jsx-no-literals': ['error', { noStrings: false, allowedStrings: ['—'] }],
    },
  },
]);
