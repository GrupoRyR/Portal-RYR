# Noticias (titulares RSS por empresa y pestaña)

Engram mirror: `odd/noticias/tasks` (project `portal_ryr`), updated after each task.

## Objective

The home prototype approved by management (28 Oct 2026) shows a «Noticias»
section with three tabs: «Colombia», «Internacional», «Sector». This line
builds the backend and its Admin page; wiring the home page is a later lane.

## Problem and why

Headlines come from RSS/Atom feeds on third-party hosts. The portal must
fetch them on a schedule without becoming an SSRF tool (the fetcher makes
outbound requests from the production server) and without storing any HTML
or third-party markup.

## Scope

- Tables `news_sources` and `news_items` (migrations `2026_11_05_2*`).
- Models, factories, `config/news.php`.
- `App\News`: URL and address policy, injectable host resolver, safe
  fetcher (pinned IP, no redirects beyond 3 re-validated hops, 10 s, 1 MB),
  RSS 2.0 / Atom parser (no DOCTYPE, no entities, no network), ingestion
  (newest 30 per source, 60-day retention), `NewsBoard` read model.
- Command `portal:fetch-news`, hourly (America/Bogota, without overlapping).
- Admin › Noticias (Livewire), super admin only, audited.
- Docs: `docs/despliegue.md` §1.17, `docs/manual-administracion.md` §2.11.

Out of scope: home page wiring (`resources/views/home.blade.php`,
`app/View/Home/**`), roles matrix.

## Constraints

- No real network in tests (`Http::fake()` + `Http::preventStrayRequests()`,
  fake resolver).
- MySQL identifiers ≤ 64 characters, explicit short names.
- Do not change `App\Catalog\AppUrlPolicy` behavior.
- Code in English; docs and UI copy in Spanish.

## Checklist

- [x] N1 Schema, models, config, URL/address policy, fetcher, parser,
      ingestion, command and schedule, with tests and `docs/despliegue.md`
      §1.17. Route: delegated direct (this writer).
- [ ] N2 `App\News\NewsBoard` read model (one query, newest 5 per tab) with
      a query-count test. Route: delegated direct (this writer).
- [ ] N3 Admin › Noticias (Livewire, policy, route, nav, audit labels) with
      tests and `docs/manual-administracion.md` §2.11. Route: delegated
      direct (this writer).

Route declaration: the parent delegated the whole line to one bounded
writer (writer trigger: 2+ non-trivial files).

## Acceptance criteria

- Only `https`, port 443, no userinfo, DNS names with an alphabetic TLD
  (IP literals refused).
- Every resolved address is public: loopback, private, link-local, CGNAT,
  multicast, reserved, documentation, IPv4-mapped/NAT64/6to4/Teredo IPv6
  refused; the connection is pinned to the checked address.
- Redirects: at most 3, each re-validated with the same rules.
- Timeout 10 s; a body over 1 MB is refused.
- DOCTYPE/ENTITY refused before parsing; parser runs with `LIBXML_NONET`
  and without entity substitution.
- Only title (plain text ≤ 300), https link (≤ 2048) and date are stored.
- Newest 30 per source kept; items older than 60 days deleted.
- A failing source stores a short error code and never stops the others.
- `NewsBoard` returns the newest 5 per tab for one company in one query.
- Admin › Noticias: super admin only (route, mount, every action, render).

## Checks

- `APP_KEY=... php artisan test --compact`
- `vendor/bin/pint --test`

## Progress

- N1: done in two work-unit commits: `0d59ed5` feat(news): add SSRF-safe
  feed fetcher and RSS/Atom parser; then the storage/command commit (its
  identity is the next commit on `feat/noticias`, recorded at N2).
- N2: pending
- N3: pending

Decisions:
- `App\Catalog\AppUrlPolicy` only accepts allowlisted catalog hosts, so it
  does not fit arbitrary news hosts; `App\News\FeedUrlPolicy` follows the
  same shape and additionally refuses every IP literal (alphabetic TLD).
- Guzzle 8 refuses CURLOPT_PROXY/PROTOCOLS in `curl`: proxies are bypassed
  with `proxy => ['no' => ['*']]`, size is capped with the `progress`
  callback (abort) plus a body length check; the IP is pinned with
  CURLOPT_RESOLVE on a fresh, non-reused connection. Checked locally: the
  pinned request to 127.0.0.1 fails with cURL error 7 (no DNS lookup).
- `HostResolver` is bound with `#[Bind(DnsHostResolver::class)]`, so no
  service provider change is needed.
- `last_fetched_at` records every attempt; `last_error_code` is null after
  a success. A missing or future date counts as the fetch time.
- Any `<!DOCTYPE`/`<!ENTITY` byte sequence refuses the feed, also inside
  CDATA (accepted trade-off), and UTF-16/32 bodies are refused.

## Verification evidence

- N1 RED: 122 tests, 122 errors (classes missing).
- N1 GREEN: `php artisan test --compact tests/Unit/News tests/Feature/News`:
  122 passed. Full suite: 2397 passed. `vendor/bin/pint --test`: passed.

## Next step

N2: `NewsBoard` read model, test-first with a query-count test.
