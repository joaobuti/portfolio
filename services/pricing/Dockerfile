# Multi-stage, mirroring docker/Dockerfile in the billing core so the two
# services are operated the same way.
#
# The production stage carries no node_modules at all. This service has zero
# runtime dependencies by design, so the final image is the base image plus a
# few kilobytes of compiled JavaScript — nothing to audit and nothing to patch
# when a transitive dependency has a CVE.

# ----------------------------------------------------------------- build

FROM node:22-alpine AS build

WORKDIR /app

# Copied first so a source-only change does not reinstall TypeScript.
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY tsconfig.json ./
COPY src ./src

RUN npm run build

# ----------------------------------------------------------- development

FROM node:22-alpine AS development

WORKDIR /app

ENV NODE_ENV=development
ENV PORT=3000

COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund

COPY tsconfig.json ./
COPY src ./src

EXPOSE 3000

# Compiles then runs. Slower to boot than the production stage and that is the
# point: a local container that runs stale JavaScript teaches the wrong thing.
CMD ["sh", "-c", "npm run build && node dist/index.js"]

# ------------------------------------------------------------ production

FROM node:22-alpine AS production

WORKDIR /app

ENV NODE_ENV=production
ENV PORT=3000

# Never root. The base image ships a `node` user; using it costs nothing.
COPY --from=build --chown=node:node /app/dist ./dist
COPY --chown=node:node package.json ./

USER node

EXPOSE 3000

# Node handles PID 1 signals correctly here because index.ts installs SIGTERM
# and SIGINT handlers; without those this would need an init shim.
CMD ["node", "dist/index.js"]
