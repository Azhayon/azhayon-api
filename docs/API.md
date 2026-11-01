# Azhayon Media public API (v1)

Base URL: `https://api.azhayonmedia.com/api/v1` (local development: `http://127.0.0.1:8000/api/v1`).
Every endpoint here needs no login and returns JSON. All of them are `GET` except one `POST`, the view counter. The signed-in write endpoints at the end of this file are the only exception.
in `app/Support/Api/Contract.php`; the TypeScript version is `docs/api-types.ts` (generated, never edited by hand).
Authentication endpoints (`/auth/*`, `/me`) are server-to-server only and are not part of this public contract.

## Conventions

- **Live posts only.** A post is visible when it is published, its publish time has passed and it is not deleted.
  Drafts, scheduled and deleted posts never appear anywhere.
- **Dates** are ISO 8601 in UTC, like `2026-10-18T06:44:00Z`. Format them in the frontend.
- **Slugs** are lowercase letters, digits and hyphens. Every `path` is a site path such as `/blog/some-post`;
  the frontend serves it on its own domain.
- **Images** are `ApiImage` objects: `url` (the largest variant), that variant's `width` and `height`, `alt`, and
  `variants` (every size, widest first, each with its own `width`, `height` and `url`). Always render with the stored
  `width` and `height` so space is reserved and nothing shifts.
- **Lists** return `data` plus `meta` (`page`, `per_page`, `total`, `last_page`). A page past the last one is a 404,
  so empty duplicate pages never exist.
- **Strict input.** Unknown query parameters, unknown filter values and out-of-range numbers return 422.
- **noindex posts** are served by every endpoint except the sitemap and the search suggestions.
- **Changes are additive.** New fields may appear within v1; fields are never removed or renamed (that needs `/v2`).
  The contract tests fail when a response gains or loses a field, so every change is deliberate.

## Caching

| Endpoints | `Cache-Control` |
|---|---|
| Everything public except the two below | `public, max-age=60, s-maxage=300, stale-while-revalidate=600` |
| `/site`, `/redirects` | `public, max-age=30, s-maxage=60, stale-while-revalidate=120` |
| `/health`, `POST /posts/{slug}/view`, errors | never marked public |

Successful responses carry a weak `ETag`; send it back as `If-None-Match` and an unchanged response is a
bodyless `304`. After content changes the API tells the frontend which pages to refresh (see Revalidation).

## Errors

| Status | Body | When |
|---|---|---|
| 404 | `{"message": "Not found."}` | Unknown or hidden resource, or a page past the last |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` | Bad or unknown parameter |
| 429 | `{"message": "..."}` plus `Retry-After` | Rate limit reached |

## Rate limits (per visitor)

300 requests a minute across the API, plus 30 a minute for `/search`, 60 a minute for `/search/suggest` and 30 a minute for the view counter.
The frontend's server calls the API from one address, so it must identify the visitor: send the secret header
`X-Frontend-Key` together with `X-Client-IP` (the visitor's IP; behind Cloudflare use `CF-Connecting-IP`).
The IP is only trusted when the key is correct. Never put the key in browser code.

## Endpoints

### Site
| | |
|---|---|
| `GET /site` | Site name, tagline, frontend URL, category navigation and the public ad settings. |

`ads` is `{ "enabled": false }` when ads are off (nothing about the ad account is published), otherwise the publisher
`client`, each placement's `enabled` and `slot`, and `rules` (`in_article_every`, `in_article_max`).

### Home
| | |
|---|---|
| `GET /home` | `trending` (6), `latest` (8), `popular` (6) lists of post cards. |

Trending counts views from today and yesterday; when too few posts have recent views it is topped up with the most-viewed posts.

### Posts
| | |
|---|---|
| `GET /posts` | Post cards. `category`, `tag`, `series` (slugs that must exist), `type` (`news` `review` `explainer` `list`), `sort` (`newest` default, `popular`, `rating`, `oldest`), `page` (1–500), `per_page` (1–24, default 12). |
| `GET /posts/{slug}` | Everything for a post page. |

`rating` sort only includes posts with at least 3 ratings. The detail adds `content`, `author.name`, `tags`, `series`
(title, path, `total`, `previous` and `next` in publishing order), up to 6 `related` cards, and `seo`
(`title`, `description`, `canonical_url`, `noindex`, `image`). A renamed post's old address is a 404 here; the
redirect list handles it.
`POST /posts/{slug}/view` counts one view of a live post and always answers `204` with no body, whether or not the view
was counted (drafts, deleted posts, unknown slugs and crawlers look the same). It is meant to be called by the visitor's
browser, so the rate limit counts the real visitor, and it needs no secret key. A visitor counts once per post per day:
the server keeps only a salted hash of address and browser, never the address. It adds to `posts.view_count` and to
`post_views_daily`, so `popular` and `trending` both move. It never changes `updated_at`.
`GET /posts/{slug}/comments` lists the approved comments of a live post, newest first, each with its approved replies
(oldest first, one level only). `page` (1–500) and `per_page` (1–50, default 20) page the top-level comments, and
`meta.comments_total` also counts replies. A post with no comments returns an empty list; a page past the last, or a post
that is not live, is a 404. Authors are shown by display name only, and deleted or banned users appear as
`[Deleted User]`. Bodies are plain text, never HTML. The list is cached like the other public endpoints, so a newly
approved comment can take a few minutes to appear.

### Categories, tags, series
| | |
|---|---|
| `GET /categories`, `GET /categories/{slug}` | All categories with live post counts; one category with `seo`. |
| `GET /tags`, `GET /tags/{slug}` | Only tags with live posts. `type` (`general` `genre` `studio`), `sort` (`name`, `popular`), `page`, `per_page` (1–100, default 50). |
| `GET /series`, `GET /series/{slug}` | Only series with live posts, with cover image and `latest_post_at`. `sort` (`latest`, `title`), `page`, `per_page`. |

A tag page with fewer than 3 live posts has `seo.noindex: true`. The posts of a category, tag or series come from
`/posts?category=…`, `/posts?tag=…` or `/posts?series=…&sort=oldest`.

### Search
| | |
|---|---|
| `GET /search?q=` | Post cards best match first. `q` is 2–80 characters with at least one letter or number; `page`, `per_page`. `meta.query` echoes the cleaned query. |
| `GET /search/suggest?q=` | Up to 8 suggestions of `type` `series`, `tag`, `subject` or `post`, each with `label` and `path` (a subject's path is a search address). |

Matches titles, subjects, subtitles, excerpts, tag names and series titles. Every word must match (as a prefix).
The frontend's `/search` page must be `noindex`.

### Sitemap and redirects
| | |
|---|---|
| `GET /sitemap` | `posts.total`, `posts.per_page` (2000), `posts.pages`, `last_modified`. |
| `GET /sitemap/posts?page=` | `{path, lastmod, image}` entries, stable order. |
| `GET /sitemap/taxonomy` | Categories, tags (3 or more live posts) and series (these carry a cover `image`). |
| `GET /redirects` | `{from, to, status}` for the frontend to apply; `meta.truncated` is true past 5,000. |

Posts marked noindex are left out of the sitemap. A redirect that starts at a live post's address is never listed:
live posts win. The redirect middleware must drop a trailing slash from the request path before looking it up.

### Health
`GET /health` returns `{"status":"ok","database":"ok"}`, or `503` with `degraded` and `error` when the database is down.

### Draft preview (signed, never cached)
`GET /preview/posts/{id}?expires=…&signature=…`

Not part of the public cached API. Staff create the link with the admin's **Preview** button; it is valid for one hour.
The frontend's `/preview/{id}` page forwards `expires` and `signature` unchanged and renders the result with the normal post template.

- The response is a `PostDetail` plus `meta.preview` (`status`, `expires_at`). `seo.noindex` is always true.
- A draft has no publish date: `published_at` is the time of the request, and series navigation treats the post as published now.
- Any invalid, expired or altered link returns `403 {"message":"Invalid signature."}`; a correctly signed link to a deleted or missing post returns `404`.
- Nothing is cached (`private, no-store`). The frontend page must also be `noindex`, send `Referrer-Policy: no-referrer`
  (the link is a credential), use `cache: "no-store"` for the fetch and show a visible "Preview" banner.
- Preview links are signed with the application key: rotating `APP_KEY` invalidates all of them.

## Signed-in writes (server-to-server only)

These need `Authorization: Bearer <token>` and are called by the frontend's server, never by the browser, so they are not part of the cached public contract. They also need the `X-Frontend-Key` and `X-Client-IP` headers, so rate limits count the real visitor.

`POST /posts/{slug}/comments` with `{ "body": "...", "parent_id": 12, "is_spoiler": false }` (`parent_id` and `is_spoiler` are optional)
creates a comment and answers `201` with `{ "data": { id, status, parent_id, body, is_spoiler, created_at } }`. A new comment
is always `pending`: it appears in the public list only after staff approve it. The body is plain text of 2 to 2,000
characters with at most 2 links; invisible characters are removed. A reply's `parent_id` must be an approved top-level
comment on the same post. The same text from the same user on the same post within 10 minutes is refused. Limits per user: 3 a minute and 30 a day;
per visitor address: 20 an hour. Errors: `401` not signed in, `403` banned, `404` post not live, `422` bad input, `429` limit reached.
`GET /posts/{slug}/rating` answers `{ "data": { "score": 4, "average": 4.25, "count": 12 } }`: the signed-in user's own
score (`null` when they have not rated), with the post's current average and number of ratings. `PUT` with
`{ "score": 1 to 5 }` (a JSON whole number) sets or changes it, and `DELETE` removes it; all three answer `200` with the
same shape, and deleting twice is fine. A user has one rating per post, and the post's `rating_avg` and `rating_count`
are recomputed after every change without touching `updated_at` or queueing a revalidation, so numbers on cached pages
catch up later. Changes are limited to 10 a minute and 200 a day per user, and 60 an hour per visitor address.

## Post content

`content` is a document: `{"type":"doc","content":[...]}`. The node types are in `docs/api-types.ts`.

| Node | Notes |
|---|---|
| `paragraph`, `heading` (levels 2–4), `bulletList`, `orderedList`, `blockquote`, `horizontalRule` | Standard |
| `text` with marks `bold`, `italic`, `link` | A link's `href` is either relative (our own site: navigate without a reload) or `http(s)` / `mailto:` |
| `customBlock` `image` | `media` is the ready-to-render `ApiImage`; `caption` is optional |
| `customBlock` `youtube` | `video` is the 11-character ID |
| `customBlock` `x-post` | `url`, `handle`, `id` and an optional `text` quote |
| `customBlock` `spoiler` | `label`, `text`, optional image (`media`) |
| `customBlock` `ad-break` | `config` is an empty list |

### Rendering rules the frontend implements

- **Table of contents:** built from the `heading` nodes (levels 2–4); generate the anchor ids in the frontend.
- **Images:** use `media` with a custom `next/image` loader that picks the smallest variant at least as wide as requested.
  The first image above the fold gets `priority`; all others lazy-load.
- **YouTube:** show a thumbnail and load the player only on click (privacy-enhanced domain).
- **X post:** a static card linking to `url`; never load X's own scripts.
- **Spoiler:** hidden until activated; operable with the keyboard.
- **External links:** `rel="noopener noreferrer"`.
- **In-article ads** (only when `ads.enabled` and the `in_article` placement is enabled): by default after every
  `in_article_every`-th top-level paragraph, at most `in_article_max` per article. If the document contains any
  `ad-break` block, skip automatic placement and use only those blocks (also capped at `in_article_max`).
  Reserve each slot's height up front so ads never shift the layout.

## Revalidation (API → frontend)

After public content changes, the API queues cache tags and sends them once a minute to
`POST {FRONTEND_URL}/api/revalidate` with the body `{"tags": ["posts", "home"]}`.

Headers: `X-Revalidate-Timestamp` (Unix seconds) and `X-Revalidate-Signature: sha256=<hex>`, where the hex is
HMAC-SHA256 of `timestamp + "." + raw body` using `REVALIDATE_SECRET`. The receiver must reject timestamps more than
5 minutes away, compare the signature in constant time, accept 1–300 tags, and accept only tags matching
`^[a-z0-9][a-z0-9:_-]{0,189}$`. Respond `200` after calling `revalidateTag` for each; any other status is retried.

| Tag | Fetches to tag with it |
|---|---|
| `site` | `/site` |
| `home` | `/home` |
| `posts` | `/posts`, `/posts/{slug}`, lists |
| `post:{slug}` | `/posts/{slug}` |
| `taxonomy` | categories, tags, series endpoints |
| `sitemap` | `/sitemap*` |
| `redirects` | `/redirects` |
| `all` | everything above |

`/search*` is never cached by the frontend.

## TypeScript types

```
php artisan api:types
```

writes `docs/api-types.ts`. Copy it to `azhayon-web/src/lib/api-types.ts`. A test fails if the committed file is out of date.