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

## Already built on the frontend (do not redo)
Root layout (metadataBase, noindex while ALLOW_INDEXING=false, self-hosted fonts), light/dark theme (inline script plus the azm_theme cookie,
toggle in the header), validated server environment (src/lib/env-parse.ts), typed API client with cache tags (src/lib/api/*),
generated API types (src/lib/api-types.ts), custom image loader and <ApiImage> (src/components), header with the sign-in dialog,
avatar menu and error notice, Google/Discord sign-in routes under /auth, and the signed POST /api/revalidate endpoint
(tested end to end against Laravel's revalidate:flush, using { expire: 0 }). Home page is still a temporary test page.

## Frontend plan (approved)
Hosting gate first (before 6.3), to find Hostinger limits while changes are cheap:
1. Part E: Hostinger preparation checklist (no code): database, PHP 8.3.36+ for api subdomain, SSH, Node 22/24 for the web app, SSL.
2. Deploy the API (env, migrate, base seeder, Filament assets, optimize, cron every minute, admin account, health check).
3. Deploy the web app (env vars, build, start) and confirm signed revalidation works between the two real servers.

Then, one small part at a time:
- 6.3 Site frame: header with category navigation (from /site), search box with suggestions (browser calls /search/suggest directly, debounced, keyboard accessible), mobile menu, footer (About, Contact, Privacy, Terms, Cookie settings). No cookies or headers read on the server.
- 6.4 Home /: trending, latest, popular from /home. ISR, tags home, site, posts.
- 6.5 Post page /blog/[slug]: renderer for every content node and block, table of contents (ids generated in the frontend), related posts, series navigation,
  reading time and updated date, ad slot placeholders with reserved height (rules in docs/API.md), notFound() on 404, tags posts and post:{slug}.
  Each page sets its own generateMetadata (title, description, absolute canonical, noindex from the API's seo block).
- 6.6 Listing pages: /blog and /blog/page/N, /category/{slug}, /tag/{slug}, /series/{slug} (each with /page/N, series oldest first), all ISR with path-based paging;
  /browse (filters and sort, dynamic, noindex, canonical to /blog); /search?q= (dynamic, noindex, forwards the visitor IP). Page past the last is a 404. May be split in two.
- 6.7 Legal and system pages: About, Contact, Privacy, Terms, Cookies (templates for the owner to review, not legal advice), custom 404, error boundary, loading skeletons.
- 6.8 SEO layer: sitemap.ts (from /sitemap, /sitemap/posts?page, /sitemap/taxonomy plus fixed pages), robots.ts (disallow /search, /browse, /auth/, /api/, /preview/),
  proxy.ts applying /redirects (Next 16 name for middleware; drop trailing slash before lookup; skip /_next and files), JSON-LD (Article, Review for review posts, BreadcrumbList, WebSite with SearchAction),
  default social image.
- 6.9 Preview /preview/[id]: dynamic, no-store, noindex, Referrer-Policy no-referrer, visible "Preview" banner, forwards expires and signature to /preview/posts/{id}.
- 6.10 Performance pass: Lighthouse on the production build and on Hostinger; targets LCP under 2.5 s, INP under 200 ms, CLS about 0.

Phase 5 is slotted in between: view counting (API endpoint and a small browser beacon) after 6.5; comments and star ratings (API and UI) after 6.9;
newsletter sign-up with email confirmation after that. Then ads (AdSlot component, ads.txt, Google's certified consent tool), the launch checklist,
then flip ALLOW_INDEXING to true. There is no separate login/account step: sign-in already exists. Content: AdSense reviewers expect 20 to 30 real articles.

## Rules for Next 16
middleware.ts is called proxy.ts. revalidateTag needs a second argument (we use { expire: 0 }). next/image uses preload, not priority.
Node 20.9 or newer is required. Anything cacheable must not read cookies, headers or searchParams.