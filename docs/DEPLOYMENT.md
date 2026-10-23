# Deployment checklist (azhayon-api, Hostinger Business)

Run through this at the hosting gate. Never run `php artisan test`, `migrate:fresh`
or `db:wipe` on the server: tests wipe the database they run against.

## 1. Server
- [ ] PHP 8.3 selected for `api.azhayonmedia.com` (PHP 8.2 loses security support end of 2026).
- [ ] Extensions: intl, gd, zip, bcmath, mbstring, pdo_mysql, fileinfo, xml, curl, openssl.
- [ ] GD has WebP: `php -r "var_dump(gd_info()['WebP Support']);"` prints `bool(true)`.
- [ ] `memory_limit` 256M or more (24-megapixel images), `upload_max_filesize` and `post_max_size` 8M or more.
- [ ] Subdomain document root is the project's `public` folder.
- [ ] SSL enabled on both domains; Cloudflare in front (SSL mode Full strict).
- [ ] Database and user created; credentials only in `.env`.

## 2. `.env` (production values)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a NEW `APP_KEY` (`php artisan key:generate`; never reuse the local one).
- [ ] `APP_URL=https://api.azhayonmedia.com`, `FRONTEND_URL=https://azhayonmedia.com`,
      `CORS_ALLOWED_ORIGINS=https://azhayonmedia.com`, `UPLOADS_URL=https://api.azhayonmedia.com/uploads`.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
- [ ] `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (12+ characters): remove the password line after the first seed.
- [ ] Google and Discord OAuth: production client IDs and secrets, and
      `https://api.azhayonmedia.com/api/v1/auth/{google,discord}/callback` added as redirect URIs.
- [ ] `ADMIN_TIMEZONE` (default Asia/Dhaka), `AUTH_TOKEN_TTL_DAYS` (default 30).
- [ ] `FRONTEND_API_KEY`: 64 hex characters, identical in the Next.js server environment, never exposed to the browser (no `NEXT_PUBLIC_` prefix).

## 3. First deploy
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan migrate --force`
- [ ] `php artisan db:seed --class=BaseDataSeeder --force` (NEVER DemoDataSeeder)
- [ ] `php artisan filament:assets` (the published admin assets are not in Git)
- [ ] `php artisan optimize`, and re-run it after every deploy.
- [ ] `storage/` and `bootstrap/cache/` writable; `public/uploads` writable and NOT overwritten by deploys.
- [ ] `public/uploads/.htaccess` present (no PHP execution, long cache).
- [ ] Keep Laravel's own `public/.htaccess` (it handles routing). The uploads one is separate.
- [ ] Sign in at `/admin`, set up two-factor immediately, store the recovery codes.

## 4. Scheduled jobs (cron, every minute: `php /path/to/artisan schedule:run`)
- [ ] Confirm Hostinger allows one-minute cron intervals.
- [ ] Scheduled by the app: `posts:publish-due` (every minute), `links:check-external` (every 10 minutes),
      `links:index` (weekly), `images:clean-orphans --force` (weekly), `cache:prune-expired` (hourly),`sanctum:prune-expired` (daily).
- [ ] Phase 5 adds the queue worker for email: `php artisan queue:work --stop-when-empty` every minute.

## 5. Proxy and rate limiting (before launch)
- [ ] Configure Laravel trusted proxies for Cloudflare, otherwise every visitor shares one IP and rate limits misfire.
- [ ] Next.js calls Laravel from one server IP: add a shared-secret header so Laravel rate-limits by the real client IP (Phase 4).
- [ ] Per-visitor limits rely on the IP Next.js forwards. Use Cloudflare's `CF-Connecting-IP` header there, and only trust it when the site really is behind Cloudflare. Until then treat per-IP limits as best-effort.
- [ ] The frontend's `/search` page must send `noindex, follow` and be disallowed in `robots.txt`: internal search results are thin content.
- [ ] Verify BOTH `azhayonmedia.com` and `api.azhayonmedia.com` in Google Search Console: sitemap image entries point at images served from the API domain.
- [ ] The Next.js middleware that applies redirects must normalise the request path (drop a trailing slash) before looking it up, since stored redirects have none.

## 6. After deploy
- [ ] `https://api.azhayonmedia.com/api/v1/health` returns `{"status":"ok","database":"ok"}`.
- [ ] `/admin` loads over HTTPS; responses carry `X-Robots-Tag: noindex, nofollow`.
- [ ] Upload a test image: four WebP variants appear under `public/uploads/YYYY/MM/<id>/`.
- [ ] Daily database backup and a copy of `public/uploads`; uptime monitor pinging `/api/v1/health`.
- [ ] Uploads are not executable: create `public/uploads/probe.php` containing `<?php echo 'executed';`, open `https://api.azhayonmedia.com/uploads/probe.php`: it must return 403 and never print "executed". Delete the file afterwards.
- [ ] Delete the test post and image after checking.
- [ ] Full-text search works on the host's database: `/api/v1/search?q=<a word that appears only in a post's excerpt>` finds that post, and `/api/v1/search/suggest?q=<two letters>` returns suggestions.
- [ ] `/api/v1/sitemap` reports the expected number of indexable posts, and `/api/v1/redirects` lists the expected redirects.