# Mail through Microsoft Graph

## Objective
The portal sends its mail (company notices, backup alerts) through Microsoft Graph `sendMail` with the existing «Portal RYR» Entra app (client credentials), from `segurex.info@segurex.com`, with no mailbox password.

## Problem / why
`segurex.com` is on Microsoft 365 (MX `segurex-com.mail.protection.outlook.com`); there is no cPanel mailbox for the portal. SMTP AUTH with a password (or app password) is Basic auth, disabled by default in Exchange Online from end of December 2026 and to be removed in 2027 (Microsoft, update of January 2026). Jhon chose Graph (2026-10-09).

## Scope
- A Laravel mail transport `graph` (Symfony Mailer transport registered with `Mail::extend`): app-only token from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` (scope `https://graph.microsoft.com/.default`) using `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET`, cached until shortly before expiry; `POST /v1.0/users/{from}/sendMail` with subject, HTML/text body, to/cc/bcc/reply-to, attachments (base64 fileAttachment; respect the 3 MB inline limit or fail clearly), `saveToSentItems` false.
- Config: `MAIL_MAILER=graph`; mail tenant id (env, e.g. `MICROSOFT_MAIL_TENANT_ID`, Segurex tenant), sender = `MAIL_FROM_ADDRESS`. No secrets in code.
- Errors: Graph/token failures raise a transport exception with a useful, secret-free message (status + Graph error code), so callers' existing «could not be sent» handling and logs keep working; token never logged.
- Prefer no new package; if a maintained, Laravel 13–compatible package clearly beats ~200 lines of code, justify it.
- Docs: `docs/despliegue.md` §1.10 replaces the SMTP block: Entra API permission `Mail.Send` (Application) + admin consent; restrict the app to the one mailbox in Exchange Online (current Microsoft-recommended method — RBAC for Applications, or Application Access Policy if that is still the documented way; verify on Microsoft Learn and cite); `.env` keys; a test command. `docs/manual-administracion.md` if it mentions SMTP.
- An artisan command to send a test mail (e.g. `portal:mail-test <to>`), super useful for the deploy.

## Out of scope
Receiving mail, delegated sending as the signed-in user.

## Constraints
Agent cannot touch `.env*`. Code in English, docs Spanish «usted». Tests never call the network (Http::fake).

## Tasks
- [x] T1 — Graph transport + config + test command + docs; tests. Route: delegated writer (writer trigger: 2+ non-trivial files — transport, token, provider, command, docs). Commit `86ea3b3`.

## Acceptance criteria
- With `MAIL_MAILER=graph`, `Mail::to(...)->send(...)` results in exactly one token request (cached afterwards) and one `sendMail` request with the right sender, recipients, subject and body (Http::fake assertions).
- A 4xx/5xx from Graph surfaces as a mail exception with the Graph error code and no token/secret in the message or logs.
- `php artisan test` passes.

## Delivery
Branch `feat/correo-graph` from `main` (`a34781b`) → PR to `main`.

## Decisions
- No new package: `App\Mail\GraphTransport` (Symfony `AbstractTransport`, JSON `sendMail`) + `App\Mail\GraphAccessToken` (client credentials, cached as `microsoft:mail-token:{tenant}:{client_id}` for `expires_in - 300` s). `ConsentChecker` is not reused: it is an uncached probe that needs raw AADSTS codes.
- JSON format (not MIME) so Bcc survives: envelope recipients not in To/Cc are sent as `bccRecipients`, like Symfony's API transports.
- Attachments: total raw size above 3 MB is refused before calling Graph (larger files need an upload session on a draft and `Mail.ReadWrite`).
- 401 from Graph drops the cached token. Errors carry HTTP status + Graph `error.code` (+ message, max 200 chars) or token `error` + AADSTS codes; no previous exception is chained (its message could echo the request).
- Mailbox restriction: RBAC for Applications in Exchange Online (Microsoft: it *replaces* Application Access Policies). Consequence: **no** `Mail.Send` application permission/admin consent in Entra — an Entra grant is org-wide and additive, so it would void the scope. The plan's «Entra Mail.Send + admin consent» step was replaced by this; docs tell the admin to revoke it if present. Scope: management scope `PrimarySmtpAddress -eq 'segurex.info@segurex.com'` (no mail-enabled group needed), role `Application Mail.Send`, checked with `Test-ServicePrincipalAuthorization`.

## Sources (verified 2026-10-09)
- https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0 — `POST /users/{id|UPN}/sendMail`, Application `Mail.Send`, `saveToSentItems`, `fileAttachment` example, `202 Accepted`.
- https://learn.microsoft.com/en-us/graph/outlook-large-attachments — files under 3 MB in one request; 3–150 MB need an upload session.
- https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac — RBAC for Applications replaces Application Access Policies; `New-ServicePrincipal`, `New-ManagementScope`, `New-ManagementRoleAssignment -App -Role "Application Mail.Send" -CustomResourceScope`, `Test-ServicePrincipalAuthorization`; Entra grants are additive (remove them); 30 min–2 h cache. (`/graph/auth-limit-mailbox-access` now redirects there.)
- https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow — token request.

## Progress
- 2026-10-09: Document created after Jhon chose Graph over SMTP.
- 2026-10-09 T1: RED — `php artisan test tests/Feature/Mail` → 13 errors («Unsupported mail transport []», «command portal:mail-test does not exist»). GREEN — 13 passed (61 assertions). Full suite `php artisan test` → 901 passed (baseline 888 + 13). `vendor/bin/pint --test` passed. `php artisan list portal` shows `portal:mail-test`. Docs: `docs/despliegue.md` §1.10 (SMTP block replaced) and §5 (3 rows); `docs/manual-administracion.md` has no SMTP mention, unchanged. Not verified against a real tenant (needs the Exchange steps and `.env` on the server).

## Next step
Push and open PR.
