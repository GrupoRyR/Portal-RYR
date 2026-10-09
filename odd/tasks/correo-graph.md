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
- [ ] T1 — Graph transport + config + test command + docs; tests. Route: delegated writer.

## Acceptance criteria
- With `MAIL_MAILER=graph`, `Mail::to(...)->send(...)` results in exactly one token request (cached afterwards) and one `sendMail` request with the right sender, recipients, subject and body (Http::fake assertions).
- A 4xx/5xx from Graph surfaces as a mail exception with the Graph error code and no token/secret in the message or logs.
- `php artisan test` passes.

## Delivery
Branch `feat/correo-graph` from `main` (`a34781b`) → PR to `main`.

## Progress
- 2026-10-09: Document created after Jhon chose Graph over SMTP.

## Next step
T1.
