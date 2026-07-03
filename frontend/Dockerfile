FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY app ./app
COPY server ./server
COPY nuxt.config.ts tsconfig.json ./
COPY public ./public

ARG NUXT_PUBLIC_API_BASE_URL
ARG NUXT_PUBLIC_GOOGLE_CLIENT_ID
ENV NUXT_PUBLIC_API_BASE_URL=$NUXT_PUBLIC_API_BASE_URL
ENV NUXT_PUBLIC_GOOGLE_CLIENT_ID=$NUXT_PUBLIC_GOOGLE_CLIENT_ID

RUN npm run build

FROM node:22-alpine

WORKDIR /app
COPY --from=build /app/.output ./.output

EXPOSE 3000
ENV HOST=0.0.0.0
ENV PORT=3000
CMD ["node", ".output/server/index.mjs"]
