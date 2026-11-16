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

## Security

- Passwords are hashed with PBKDF2-SHA256 (210,000 rounds) – never stored in plain text
- 5 wrong passwords from one IP = 15-minute lockout
- HttpOnly, SameSite session cookies (Secure behind HTTPS)
- First-time setup only works on the server itself
- There must always be at least one active Global Admin
- Emergency admin reset: stop the site and run `DUC-Website.exe -reset-admin USERNAME` (recorded in the audit log)

Site data is kept in `duc-data\` next to the .exe – **back this folder up**. It is never committed to this repository.
