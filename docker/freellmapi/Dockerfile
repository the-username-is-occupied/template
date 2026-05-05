# syntax=docker/dockerfile:1
# Builds https://github.com/tashfeenahmed/freellmapi (server + static dashboard on PORT).
FROM node:22-bookworm AS build

WORKDIR /app

ARG FREELLMAPI_REF=main

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && git clone --depth 1 --branch "${FREELLMAPI_REF}" https://github.com/tashfeenahmed/freellmapi.git . \
    && rm -rf /var/lib/apt/lists/*

RUN npm ci \
    && npm run build \
    && npm prune --omit=dev

FROM node:22-bookworm-slim AS runtime

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /app /app

ENV NODE_ENV=production

EXPOSE 3001

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:' + (process.env.PORT || 3001) + '/api/ping').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"

CMD ["node", "server/dist/index.js"]
