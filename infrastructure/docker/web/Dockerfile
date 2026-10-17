# Build context is expected to be apps/web.

FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# ---- dev: hot-reloading dev server, source is bind-mounted over this ----
FROM deps AS dev
COPY . .
EXPOSE 3000
CMD ["npm", "run", "dev", "--", "-H", "0.0.0.0", "-p", "3000"]

# ---- build: compiles the standalone production bundle ----
FROM deps AS build
COPY . .
# NEXT_PUBLIC_* vars are inlined into the client bundle by `next build`
# itself, not read at container runtime — setting NEXT_PUBLIC_API_URL only
# in docker-compose.prod.yml's `environment:` (which is runtime-only) ships
# browser code with it permanently empty. Must come in as a build arg here.
ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
RUN npm run build

# ---- prod: minimal runtime, no source/dev deps baked in ----
FROM node:24-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production
ENV PORT=3000
ENV HOSTNAME=0.0.0.0
COPY --from=build /app/public ./public
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
EXPOSE 3000
CMD ["node", "server.js"]
