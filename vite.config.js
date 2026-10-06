import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import * as Vue from 'vue';

/**
 * The Statamic 6 CP exposes its built UI kit on `window.__STATAMIC__`. The
 * `@statamic/cms/ui` entry is just `export const { ... } = __STATAMIC__.ui`, so
 * we alias the bare specifier to the version shipped in vendor — no extra npm
 * dependency, and the components resolve to the CP's own (already-styled)
 * instances at runtime instead of being re-bundled.
 *
 * The addon has statamic/cms as a composer require, so its own vendor/ carries
 * a copy of the file. Resolving relative to `import.meta.url` (this config's
 * directory) makes the build work no matter where the addon lives — symlinked
 * into a consumer project or standalone in its own repo.
 */
const statamicUi = fileURLToPath(
    new URL('./vendor/statamic/cms/resources/dist-package/src/ui.js', import.meta.url)
);

/**
 * The bare `@statamic/cms` entry, resolving `__STATAMIC__.core`. Needed for
 * `InlineEditForm` — the component Statamic's own relationship fieldtypes use to
 * edit an entry's publish form inside a Stack. Same trick as the ui.js alias:
 * point at the copy in our own vendor/ so no npm dependency is required.
 */
const statamicCore = fileURLToPath(
    new URL('./vendor/statamic/cms/resources/dist-package/src/index.js', import.meta.url)
);

/**
 * The `@statamic/cms/inertia` entry, resolving `__STATAMIC__.inertia`. Needed for
 * `Head`: Statamic resolves the CP document title as
 * `title: (title) => title || bladeTitle`, so a page without a `<Head>` falls
 * back to the layout's placeholder ("Here ‹ Statamic"). Must come from the CP's
 * own Inertia instance — bundling @inertiajs/vue3 would create a second one.
 */
const statamicInertia = fileURLToPath(
    new URL('./vendor/statamic/cms/resources/dist-package/src/inertia.js', import.meta.url)
);

/**
 * Rewrites every `import ... from 'vue'` in addon chunks to use `window.Vue`.
 * Mirrors @statamic/cms/vite-plugin so the Statamic 6 CP (which exposes Vue
 * globally) can resolve our addon's bundled imports without an import map.
 *
 * @returns {import('vite').Plugin}
 */
function statamicExternals() {
    const RESOLVED_VIRTUAL_MODULE_ID = '\0vue-external';
    const vueExports = Object.keys(Vue).filter(
        (key) => key !== 'default' && /^[a-zA-Z_$][a-zA-Z0-9_$]*$/.test(key)
    );

    return {
        name: 'statamic-externals',
        enforce: 'pre',
        resolveId(id) {
            if (id === 'vue') {
                return RESOLVED_VIRTUAL_MODULE_ID;
            }
            return null;
        },
        load(id) {
            if (id === RESOLVED_VIRTUAL_MODULE_ID) {
                return `
                    const Vue = window.Vue;
                    export default Vue;
                    export const { ${vueExports.join(', ')} } = Vue;
                `;
            }
            return null;
        },
        configResolved(resolvedConfig) {
            resolvedConfig.build.rollupOptions.plugins =
                resolvedConfig.build.rollupOptions.plugins || [];
            resolvedConfig.build.rollupOptions.plugins.push({
                name: 'statamic-externals-render',
                renderChunk(code) {
                    code = code.replace(
                        /import\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*,\s*(\{[^}]+\})\s+from\s+['"]vue['"];?/g,
                        'const $1 = window.Vue;\nconst $2 = window.Vue;'
                    );
                    return code.replace(
                        /import\s+(.+?)\s+from\s+['"]vue['"];?/g,
                        'const $1 = window.Vue;'
                    );
                }
            });
        }
    };
}

export default defineConfig({
    base: './',
    resolve: {
        // Array form with an anchored regex for the bare specifier — an object
        // alias keyed `'@statamic/cms'` would prefix-match and swallow
        // `@statamic/cms/ui` as well.
        alias: [
            { find: '@statamic/cms/ui', replacement: statamicUi },
            { find: '@statamic/cms/inertia', replacement: statamicInertia },
            { find: /^@statamic\/cms$/, replacement: statamicCore }
        ]
    },
    plugins: [
        laravel({
            input: ['resources/js/addon.js', 'resources/css/addon.css'],
            publicDirectory: 'resources/dist'
        }),
        tailwindcss(),
        statamicExternals(),
        vue()
    ]
});
