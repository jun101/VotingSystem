# Web image. `dev` runs the development server on the mounted source; `prod` is the
# standalone build.
FROM node:24.21.0-trixie-slim AS base
ENV NEXT_TELEMETRY_DISABLED=1 \
    HOME=/tmp \
    npm_config_cache=/tmp/npm-cache
WORKDIR /app

FROM base AS dev
EXPOSE 3000
CMD ["npm", "run", "dev"]

FROM base AS deps
COPY web/package.json web/package-lock.json ./
RUN npm ci

FROM base AS build
COPY --from=deps /app/node_modules ./node_modules
COPY web/ ./
RUN npm run build

FROM node:24.21.0-trixie-slim AS prod
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    HOSTNAME=0.0.0.0 \
    PORT=3000
WORKDIR /app
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public
USER node
EXPOSE 3000
CMD ["node", "server.js"]
