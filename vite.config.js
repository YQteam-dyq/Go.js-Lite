import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
export default defineConfig({
    base: '/gojs/',
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
            '@shared': path.resolve(__dirname, './shared'),
        },
    },
    server: {
        port: 5173,
        proxy: {
            '/gojs/api': {
                target: 'http://localhost:8080',
                changeOrigin: true,
                rewrite: function (path) {
                    var cleanPath = path.replace(/^\/gojs\/api/, '');
                    var _a = cleanPath.split('?'), pathPart = _a[0], queryPart = _a[1];
                    var apiAction = pathPart.replace(/^\//, '');
                    var query = queryPart ? "&".concat(queryPart) : '';
                    return "/api.php?api=".concat(apiAction).concat(query);
                },
            },
        },
    },
    build: {
        outDir: 'dist',
        sourcemap: false,
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ['react', 'react-dom', 'react-router-dom'],
                    query: ['@tanstack/react-query'],
                    icons: ['lucide-react'],
                },
            },
        },
    },
});
