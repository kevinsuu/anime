FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY app ./app
COPY nuxt.config.ts tsconfig.json ./
COPY public ./public

ARG NUXT_PUBLIC_API_BASE_URL
ARG NUXT_PUBLIC_GOOGLE_CLIENT_ID
ENV NUXT_PUBLIC_API_BASE_URL=$NUXT_PUBLIC_API_BASE_URL
ENV NUXT_PUBLIC_GOOGLE_CLIENT_ID=$NUXT_PUBLIC_GOOGLE_CLIENT_ID

RUN npm run generate

FROM nginx:1.27-alpine

COPY --from=build /app/.output/public /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
