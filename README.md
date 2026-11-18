# Droitwich United Charities – website

Website for **Droitwich United Charities (DUC)**, registered charity no. 205536 – small grants for people and organisations in Droitwich Spa. Built by Saltway Digital.

The site runs as a small Windows server program (`DUC-Website.exe`) behind Caddy. It provides:

- **Password protection** – only people with an account can see the site (can be switched off by a Global Admin)
- **Maintenance mode** – an “Under maintenance” page with your own heading, message and expected-back time; Editors and Admins can still use the site
- **User management** – the Global Admin adds users as *Viewer*, *Editor* or *Global Admin*, resets passwords, disables or deletes accounts
- **Audit log** – every sign-in (and failed attempt), lockout, sign-out, user/password change, content publish, enquiry and setting change, with who, when and IP address; searchable and exportable to CSV
- **Admin panel** – edit every part of the website and press **Publish changes** to make it live; manage grant enquiries

## 2-click setup (on the server)

1. Double-click `DUC-Website.exe`.
2. Your browser opens the setup page – create the Global Admin account.

Optional: right-click `Install-Startup.bat` → *Run as administrator* to start the site with Windows, then add `Caddyfile-snippet.txt` to your Caddyfile.

## Building the .exe

Install [Go 1.24+](https://go.dev/dl/) and double-click `build.bat` (or run `go build -o DUC-Website.exe .`).

## Files

| Path | What it is |
|---|---|
| `main.go` | The server: sign-in, sessions, users, maintenance mode, audit log, API |
| `web/site.html` | The website and admin panel (embedded into the .exe) |
| `web/duc-logo.webp` | The DUC badge logo |
| `Install-Startup.bat` / `Remove-Startup.bat` | Start (or stop starting) the site with Windows |
| `Caddyfile-snippet.txt` | Reverse-proxy config for Caddy |

## Trustee Portal, Referral Partners & GDPR tools (cPanel edition)

The cPanel/PHP edition (`cpanel/`) adds a private portal at **/portal**:

- **Trustee Portal** – dashboard, meetings and agendas (with "Matters arising" pulled in automatically), papers emailed with calendar invitations, attendance, board papers with versions and "read and confirm", voting on requests with conflict-of-interest declarations and quorum, shared calendar with a private iCal link, and search across minutes.
- **Meeting recording with Gemini** – an in-room recorder in the browser (consent checklist first, confidential agenda items pause it automatically, keeps working through Wi-Fi drop-outs), or upload a recording. Google Gemini writes the transcript, a summary for each item, decisions, actions (who, what, by when) and draft minutes. The Clerk checks everything against the audio, publishes the actions (owners are emailed, reminded 3 days before and chased when overdue), and the Chairman approves and locks the minutes.
- **Referral partners** – organisations apply at **/partners/apply**; the Clerk approves them; they accept a data sharing agreement, then make and track referrals with evidence uploads, consent records and a secure message thread. Emails to partners never contain personal details. Personal details are encrypted at rest.
- **Letters** – every letter is built on the D.U.C. letterhead (`cpanel/app/templates/letterhead.docx`) from editable templates, downloadable as Word or sent by email over SMTP, and logged against the case.
- **GDPR tools** – retention schedule run nightly with legal holds and 14-day warnings, subject access requests (deadline tracking, "find my data", redaction, AES-encrypted export), erasure, consent register, privacy notice versions, breach log with a 72-hour ICO countdown, record of processing (ROPA) and a yearly report.

Build the upload zip with `build-cpanel.bat` (Windows) or `./build-cpanel.sh`, then follow `cpanel/INSTALL.txt`. The Go/.exe edition runs the website and admin panel only.

| Path | What it is |
|---|---|
| `cpanel/index.php` | Website server (sign-in, users, maintenance, audit log) – loads the portal |
| `cpanel/app/portal_api.php` | Portal API: meetings, recording, actions, papers, voting, calendar, settings |
| `cpanel/app/partners.php` | Partner sign-up, logins, referrals, evidence, messages |
| `cpanel/app/letters.php`, `docx.php`, `templates.php` | Letters on the letterhead, templates, calendar files |
| `cpanel/app/gemini.php` | Gemini transcription and AI drafts |
| `cpanel/app/gdpr.php` | GDPR tools |
| `cpanel/app/cron.php` | Scheduled jobs (`php index.php cron`) |
| `cpanel/app/smtp.php` | SMTP email |
| `cpanel/app/portal.html` | Portal front end |

## Security

- Passwords are hashed with PBKDF2-SHA256 (210,000 rounds) – never stored in plain text
- 5 wrong passwords from one IP = 15-minute lockout
- HttpOnly, SameSite session cookies (Secure behind HTTPS)
- First-time setup only works on the server itself
- There must always be at least one active Global Admin
- Emergency admin reset: stop the site and run `DUC-Website.exe -reset-admin USERNAME` (recorded in the audit log)

Site data is kept in `duc-data\` next to the .exe – **back this folder up**. It is never committed to this repository.
