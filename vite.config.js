import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Bootstrap 5.3 still uses legacy Sass APIs internally.
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function', 'mixed-decls'],
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
