import { defineConfig } from 'vitest/config';

/**
 * Unit tests for the framework-free model modules (resources/js/editor/model).
 * Deliberately separate from vite.config.js: the build config pulls in the
 * Statamic aliases, the `vue` → `window.Vue` rewrite and laravel-vite-plugin,
 * none of which a pure-JS test needs. The build itself only bundles what
 * resources/js/addon.js imports, so test files never reach the bundle.
 */
export default defineConfig({
    test: {
        include: ['resources/js/**/__tests__/**/*.test.js'],
        environment: 'node',
    },
});
