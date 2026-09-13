import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { mockApiPlugin } from './dev/mockApi.ts'
export default defineConfig({
  plugins: [react(), mockApiPlugin(process.env.OMONGSTAT_MOCK_SCENARIO)],
  server: { host: '127.0.0.1', strictPort: true, cors: false, allowedHosts: ['localhost'] },
  preview: { host: '127.0.0.1', strictPort: true, cors: false },
  base: './',
  publicDir: false,
  build: {
    outDir: 'wordpress_plugins/omongstat/assets/admin',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: { input: 'src/main.tsx' },
  },
})
