FROM node:24-alpine AS dependencies
RUN corepack enable
WORKDIR /app
COPY package.json pnpm-workspace.yaml ./
RUN pnpm install --no-frozen-lockfile

FROM node:24-alpine AS development
RUN corepack enable
WORKDIR /app
COPY --from=dependencies /app/node_modules ./node_modules
COPY . .
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 \
  CMD wget -qO- http://127.0.0.1:3000/ >/dev/null || exit 1
CMD ["pnpm", "dev", "--hostname", "0.0.0.0"]
