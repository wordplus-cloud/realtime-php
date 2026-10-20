import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // The shop page, the same in every example, and its look.
            input: ['resources/css/shop.css', 'resources/js/shop.js'],
            refresh: true,
        }),
    ],
    build: { target: 'es2022' },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
