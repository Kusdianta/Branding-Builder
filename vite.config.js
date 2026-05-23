import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Bind the dev server to IPv4 127.0.0.1. The Vite default host
        // ('localhost') resolves to [::1] on this machine, so the hot-file
        // URL became http://[::1]:5173 — fragile/unreachable for some
        // browsers, which silently dropped app.js and left the JS-injected
        // Places Autocomplete input unmounted on Step 1. 127.0.0.1 matches
        // how the .test domain resolves and is universally reachable.
        host: '127.0.0.1',
        hmr: {
            host: '127.0.0.1',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
