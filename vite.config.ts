import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        // Listen on every interface so the browser can reach Vite inside Docker,
        // but tell it to connect to localhost for hot reloading.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            // Docker on Windows/macOS does not forward file events through bind
            // mounts, so compose.yaml turns on (slow, cheap) polling.
            usePolling: process.env.VITE_USE_POLLING === 'true',
            interval: 1000,
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/.git/**',
                '**/vendor/**',
                '**/storage/**',
                '**/bootstrap/**',
                '**/database/**',
                '**/docs/**',
                '**/tests/**',
                '**/public/**',
            ],
        },
    },
});
