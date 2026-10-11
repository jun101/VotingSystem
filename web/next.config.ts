import type { NextConfig } from 'next';

const config: NextConfig = {
  // Standalone build for the production image.
  output: 'standalone',
  // No header names the software that runs the site.
  poweredByHeader: false,
  reactStrictMode: true,
  // The preview image of a published election reads its font from the installed package.
  outputFileTracingIncludes: {
    '/results/[election]/opengraph-image': [
      './node_modules/@fontsource/archivo/files/*-latin-*-normal.woff',
    ],
  },
  // Development only. The browser tests reach the dev server through the proxy by its
  // Compose name; without this Next.js refuses the dev resources (hot reload, and with it
  // the hydration of the page) to any host but localhost.
  allowedDevOrigins: ['proxy'],
};

export default config;
