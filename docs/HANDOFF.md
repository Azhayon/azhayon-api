# Azhayon Media: handoff

Movies, anime, manhwa and manga site, earning through Google AdSense. Priorities: SEO, Core Web Vitals, security.
The owner works on Windows with PowerShell and prefers incremental edits over full rewrites.

## How we work
- One small part at a time. The owner runs it, pastes the results, and nothing moves on until tests pass.
- Never ask for or paste secrets (.env values, keys). Print counts or booleans instead.
- Test helper names must not clash with Laravel/PHPUnit methods (get, post, put, patch, delete, json, call, result, run).
- Tests that use random factory data must pin every random field they depend on.
- Commit each part separately. Always check that .env and .env.local are not in `git status`.

## Repos and versions
- azhayon-api: Laravel 12, Filament 5 (admin at /admin, 2FA required for staff), MySQL 8. Local PHP 8.4.27 in C:\php;
  composer.json pins platform.php to 8.3.0 because production targets PHP 8.3 (must be 8.3.36+ or 8.4.27+: October 2026 security fix).
- azhayon-web: Next.js 16.4.3, React 19.1, Tailwind 4, vitest. Node 20.9+ required (choose 22 or 24 on the host, never 18).
- Local: API `php artisan serve` on 127.0.0.1:8000, web `npm run start` on localhost:3000. Test DB azhayon_api_test (the test base class refuses other names).
- Domains: azhayonmedia.com (frontend) and api.azhayonmedia.com (API). Hostinger Business plan is active; NOTHING IS DEPLOYED YET.

## Architecture
- Public API under /api/v1, documented in docs/API.md. Response shapes live in app/Support/Api/Contract.php;
  `php artisan api:types` writes docs/api-types.ts, which is copied to azhayon-web/src/lib/api-types.ts. Tests fail if they drift.
- Post bodies are normalised TipTap JSON (app/Support/Content). Blocks: image, youtube, x-post, spoiler, ad-break.
  Images go through one WebP pipeline (widths 1600/1200/768/480, stored in public/uploads) and reach the frontend via a custom next/image loader.
- Caching: the API sends public ETag/Cache-Control headers; Next.js caches fetches with tags (src/lib/cache-tags.ts).
  Laravel queues tags in an outbox and a scheduled `revalidate:flush` (every minute) sends them as an HMAC-signed POST to /api/revalidate on the frontend.
  Secrets that must match on both sides: FRONTEND_API_KEY and REVALIDATE_SECRET (API .env and web .env.local).
- Frontend rules: cached pages must not read cookies, headers or searchParams. The theme is an inline script plus the azm_theme cookie.
  Search and previews are dynamic and noindex. ALLOW_INDEXING=false until launch.
- Login: Google and Discord through Laravel; tokens stay in an httpOnly cookie (azm_session) on the frontend.

## Decisions already made
- URLs: /blog/{slug}, /category/{slug}, /tag/{slug}, /series/{slug}, /search?q=, paging as /blog/page/N, filter screen at /browse (noindex).
- Dark theme by default with a light toggle; palette #121212 / #1E1E1E / #FF3366 / #00E5FF / #E0E0E0; Space Grotesk + Inter.
- Ads: auto-inserted after every 4th paragraph, at most 3 per article; a manual ad-break block overrides; consent through Google's certified tool.
- Cache lifetimes 60 s / 300 s / 600 s stale; 12 posts per page (max 24).

## Status (tests: API 307, web 40, all passing)
Done: database and models, Google/Discord login, Filament admin, image pipeline, content validator, moderation, redirects,
link checker, the whole public API, draft preview, API contract and types, PHP 8.4 upgrade, frontend foundation
(layout, themes, fonts, API client, image loader), and the signed revalidation endpoint tested end to end.

## Next
1. Part E: Hostinger preparation checklist (database, PHP 8.3 patch level, SSH, api subdomain, Node 22/24 for the web app).
2. Skeleton deploy of the API, then the web app. Verify revalidation between the real servers.
3. Pages 6.3 to 6.10, then Phase 5 (view counts, comments, ratings, newsletter), then ads, then launch.
Rule for Next 16: middleware.ts is now called proxy.ts (needed for the redirect list); revalidateTag needs a second argument ({ expire: 0 } is used).