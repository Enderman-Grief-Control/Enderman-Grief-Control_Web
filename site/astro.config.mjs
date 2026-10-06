import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    site: 'https://enderman-grief-control.netlify.app',
    integrations: [react()],
    vite: {
        plugins: [tailwindcss()],
    },
});
