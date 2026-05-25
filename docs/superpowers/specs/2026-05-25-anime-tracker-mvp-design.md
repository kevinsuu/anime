# Anime Tracker MVP Design

## Goal

Build the first usable version of an anime tracking website. The MVP focuses on Google OAuth login, personal anime lists, ratings, watched status, public sharing, responsive mobile support, Docker-based local development, and a deployment path where secrets do not leak into the repository or frontend bundle.

Future AI crawling, seasonal anime ingestion, external popularity ranking, and AI recommendations are intentionally outside the MVP implementation path. The MVP should leave clear extension points for those features.

## Scope

### In Scope

- Vue single-page frontend deployed to GitHub Pages.
- PHP REST API deployed separately on GCP.
- MySQL database.
- Docker Compose for local development.
- Google OAuth login using Google ID token verification on the backend.
- Backend-issued JWT for API authorization.
- Personal anime list management:
  - Add anime to user list.
  - Track watched status.
  - Store user rating.
  - Store optional personal note.
- Anime catalog records:
  - Name.
  - Description.
  - Image URL.
  - Optional aliases.
- Public share page for a user's anime list.
- Responsive layouts for desktop and mobile.
- `.env.example` files and documentation for required environment variables.

### Out of Scope for MVP

- AI-generated recommendations.
- AI web search or web crawling.
- Automated seasonal anime ingestion.
- External popularity ranking.
- Email/password login.
- Password reset.
- Social graph, follows, comments, or likes.
- Native mobile apps.

## Architecture

The system uses a separated frontend and backend.

- Frontend: Vue SPA served from GitHub Pages.
- Backend: PHP REST API running in a container on GCP Cloud Run.
- Database: MySQL. Local development uses a Docker MySQL container. Production should use Cloud SQL MySQL.
- Authentication:
  - Browser completes Google sign-in and receives a Google ID token.
  - Frontend posts the ID token to `POST /auth/google`.
  - Backend verifies the ID token against Google's public keys and configured client ID.
  - Backend upserts the user, then returns an application JWT.
  - Frontend stores the JWT and sends it in `Authorization: Bearer <token>`.

The frontend never receives backend secrets. Public client IDs may be present in the frontend build. Private keys, JWT signing secret, database credentials, and Google verification configuration belong only in backend environment variables or GCP Secret Manager.

The MVP should not issue refresh tokens to the browser. Because the frontend is hosted as a public static app on GitHub Pages, the backend JWT should be short-lived. When it expires, the frontend should ask the user to sign in with Google again.

## Frontend Design

### Pages

- `Login`
  - Google sign-in button.
  - Minimal unauthenticated state.
- `Home`
  - Lightweight anime discovery section using local catalog data.
  - Entry points to search and user's list.
- `Catalog/Search`
  - Search anime by name or alias.
  - Add anime to personal list.
  - If an anime is missing, allow manual creation with name, description, and image URL.
- `My List`
  - Shows user's saved anime.
  - Filters by all, watched, unwatched.
  - Edits watched status, rating, and note.
  - Provides copyable public share link.
- `Public List`
  - Read-only public page by share slug or user public ID.
  - Shows anime name, image, description, watched status, and rating.
- `Settings`
  - Show account profile from Google.
  - Regenerate public share slug.
  - Logout.

### Mobile Behavior

- Navigation collapses to a bottom tab bar or compact header menu.
- Anime cards use stable image dimensions to avoid layout shifts.
- List editing controls remain reachable on small screens without horizontal scrolling.
- Forms use single-column layout on mobile.

## Backend Design

### API Style

Use JSON REST endpoints. All protected routes require a valid application JWT.

Suggested route groups:

- `POST /auth/google`
  - Request: Google ID token.
  - Response: application JWT and user profile.
- `GET /me`
  - Returns current user profile.
- `GET /anime`
  - Query catalog by search term.
- `POST /anime`
  - Create manual catalog entry.
- `GET /my/anime-list`
  - Return authenticated user's list.
- `POST /my/anime-list`
  - Add anime to list.
- `PATCH /my/anime-list/{itemId}`
  - Update watched status, rating, or note.
- `DELETE /my/anime-list/{itemId}`
  - Remove from user's list.
- `GET /public/lists/{slug}`
  - Return public read-only list.
- `POST /me/share-slug/regenerate`
  - Regenerate public share slug.

### Error Handling

- Return structured JSON errors:
  - `code`: stable machine-readable code.
  - `message`: user-safe message.
  - `details`: optional validation details.
- Use appropriate HTTP status codes:
  - `400` for malformed requests.
  - `401` for missing or invalid auth.
  - `403` for forbidden access.
  - `404` for missing records.
  - `409` for duplicate list entries.
  - `422` for validation failures.
  - `500` for unexpected server errors.
- Log internal details on the backend without returning secrets or stack traces to the frontend.

## Data Model

### `users`

- `id` bigint primary key.
- `google_sub` varchar unique, required.
- `email` varchar, required.
- `display_name` varchar.
- `avatar_url` text.
- `public_slug` varchar unique, required.
- `created_at` datetime.
- `updated_at` datetime.

### `anime`

- `id` bigint primary key.
- `name` varchar, required.
- `description` text.
- `image_url` text.
- `source` enum-like varchar, values such as `manual`, `seed`, `future_import`.
- `created_by_user_id` bigint nullable.
- `created_at` datetime.
- `updated_at` datetime.

### `anime_aliases`

- `id` bigint primary key.
- `anime_id` bigint foreign key.
- `alias` varchar, required.

### `user_anime_list_items`

- `id` bigint primary key.
- `user_id` bigint foreign key.
- `anime_id` bigint foreign key.
- `watched` boolean default false.
- `rating` tinyint nullable, expected range `1` to `10`.
- `note` text nullable.
- `created_at` datetime.
- `updated_at` datetime.
- Unique constraint on `(user_id, anime_id)`.

### Indexes

- `users.google_sub` unique.
- `users.public_slug` unique.
- `anime.name`.
- `anime_aliases.alias`.
- `user_anime_list_items.user_id`.
- `user_anime_list_items.anime_id`.
- `user_anime_list_items(user_id, anime_id)` unique.

## Security

- Store JWT signing secret only in backend environment variables.
- Use short-lived application JWTs and do not store refresh tokens in the browser for the MVP.
- Store database credentials only in backend environment variables or GCP Secret Manager.
- Do not commit `.env` files.
- Commit only `.env.example` with placeholder values.
- Validate Google ID token audience against the configured Google OAuth client ID.
- Validate all backend inputs:
  - Rating must be `1` to `10` or null.
  - Anime name must be non-empty and length-limited.
  - Image URL must be a valid HTTP or HTTPS URL when provided.
- Configure CORS to allow the GitHub Pages frontend origin and local dev origin only.
- Use HTTPS in production.
- Avoid placing any private API key in the Vue build.

## Docker and Local Development

Local Docker Compose should include:

- `frontend`: Vue dev server.
- `backend`: PHP API server.
- `mysql`: MySQL database.
- Optional `phpmyadmin` for local inspection.

The backend container should run migrations before or during development setup through an explicit command. Production migrations should be executed as a controlled deploy step, not implicitly on every request.

## Deployment

### Frontend

- Build Vue static assets.
- Deploy to GitHub Pages.
- Configure frontend environment with:
  - Public API base URL.
  - Public Google OAuth client ID.

### Backend

- Build PHP backend container.
- Deploy to GCP Cloud Run.
- Configure runtime environment variables or Secret Manager bindings:
  - Database host, port, name, user, password.
  - JWT signing secret.
  - Google OAuth client ID.
  - Allowed frontend origins.

### Database

- Use Cloud SQL MySQL for production.
- Restrict network access to backend service.
- Backups should be enabled before real user data is stored.

## Future Extension Points

- `anime.source = future_import` allows later ingestion from seasonal anime sources.
- Additional table `anime_popularity_snapshots` can store future rankings.
- Additional table `recommendation_runs` can store future AI recommendation metadata.
- Existing catalog/list split lets AI recommendations reference catalog anime without changing personal list ownership.

## Testing Strategy

### Backend

- Unit tests for validation rules.
- Integration tests for auth-protected list routes.
- Tests for duplicate list item handling.
- Tests for public list access without JWT.
- Tests for Google auth verification should mock Google token validation.

### Frontend

- Component tests for list item edit states.
- Route guard tests for authenticated pages.
- API client tests for authorization header handling.
- Responsive smoke checks for primary pages.

### Deployment Safety

- Verify `.env` is ignored.
- Verify frontend bundle does not contain private backend secrets.
- Verify backend rejects requests from disallowed origins.
- Verify production uses HTTPS API URL.

## Success Criteria

- A user can sign in with Google.
- A user can search or manually create an anime.
- A user can add anime to their list.
- A user can update watched status and rating.
- A user can open and share a public read-only list URL.
- The site works on mobile-sized screens without horizontal scrolling.
- Local development runs through Docker Compose.
- No private key or secret is committed to the repository or bundled into frontend assets.
