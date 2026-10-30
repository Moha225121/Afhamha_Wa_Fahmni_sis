import { defineConfig } from 'vite';

export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public/build/push',
        emptyOutDir: true,
        lib: {
            entry: 'resources/js/firebase-worker.js',
            name: 'SchoolFirebaseMessaging',
            formats: ['iife'],
            fileName: () => 'firebase-messaging.js',
        },
    },
});
