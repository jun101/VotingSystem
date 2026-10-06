import type { NextConfig } from 'next';

const config: NextConfig = {
  // Standalone build for the production image.
  output: 'standalone',
  // No header names the software that runs the site.
  poweredByHeader: false,
  reactStrictMode: true,
};

export default config;
