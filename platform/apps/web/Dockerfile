# Build context: platform/ (see compose.yaml). Dependencies come from the
# workspace lockfile so every image installs exactly what CI tested.
FROM node:24-alpine AS dependencies
RUN corepack enable
WORKDIR /repo
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml ./
COPY apps/web/package.json apps/web/
RUN pnpm install --frozen-lockfile --filter @progressive/web...

FROM node:24-alpine AS development
RUN corepack enable
WORKDIR /repo
COPY --from=dependencies /repo/node_modules ./node_modules
COPY --from=dependencies /repo/apps/web/node_modules ./apps/web/node_modules
COPY package.json pnpm-workspace.yaml ./
COPY apps/web ./apps/web
WORKDIR /repo/apps/web
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 \
  CMD wget -qO- http://127.0.0.1:3000/ >/dev/null || exit 1
CMD ["pnpm", "dev", "--hostname", "0.0.0.0"]
