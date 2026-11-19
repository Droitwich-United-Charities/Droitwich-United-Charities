---
name: portal-setup
description: >
  This skill should be used when someone asks how to install, set up, fix or connect the D.U.C. Trustee
  Portal, for example "how do I put the trustee portal on cPanel", "the site says not secure",
  "the certificate is for a different address", "there's no data in the data folder", "how do I switch on
  the Claude connector", "Couldn't register with D.U.C Trustee Portal's sign-in service",
  "Couldn't reach the MCP server", "how do I invite trustees" or "set up the cron job". It gives
  step-by-step setup and troubleshooting help for the portal on cPanel and the Claude connector.
---

# Trustee Portal setup and troubleshooting

Help the person get the D.U.C. Trustee Portal running on cPanel and connected to Claude. Give one numbered step at a time, using the exact cPanel menu names and addresses. Ask what they see after any step that can fail, and ask for a screenshot or the exact error text when something goes wrong. Don't mention code or file formats unless they ask.

## Key facts

| What | Value |
| --- | --- |
| Website | https://trustees.droitwichunitedcharities.org.uk |
| Site folder | /home/droitwichunitedc/public_html/trustees.droitwichunitedcharities.org.uk |
| Data folder | /home/droitwichunitedc/duc-data.trustees.droitwichunitedcharities.org.uk (next to public_html, not inside it; a folder named duc-data-trustees… with a dash is also found) |
| Upload file | DUC-Website-cPanel.zip, extracted **inside** the site folder |
| Server IP | 46.252.76.135 |
| Claude connector URL | https://trustees.droitwichunitedcharities.org.uk/mcp |
| Cron job, every 15 minutes | /usr/local/bin/php /home/droitwichunitedc/public_html/trustees.droitwichunitedcharities.org.uk/index.php cron |

## Order of setup

1. **Upload and extract** the zip in the site folder. Check that index.php, .htaccess, app and assets sit directly inside it.
2. **Point the subdomain at the folder.** In cPanel, open Domains, then Manage, untick "Share document root", and set the Document Root to `public_html/trustees.droitwichunitedcharities.org.uk`.
3. **Get the padlock.** Open SSL/TLS Status and press Run AutoSSL. Don't enter passwords until it shows a padlock.
4. **First visit.** Open the site, find the setup code in setup-code.txt in the data folder, and create the Global Admin account.
5. **Portal settings.** Set the website address, email (SMTP) with a test, and the Gemini key with a test. Check who signs letters and the quorum.
6. **Cron job.** Open Cron Jobs and add the line above to run every 15 minutes.
7. **Claude connector.** In the portal, open Claude connector, press Switch the connector on, then Run the check. All four steps should show ✅.
8. **Connect Claude.** In Settings → Connectors, choose Add custom connector, paste the URL ending in /mcp, press Connect, sign in, and press Allow.
9. **Invite trustees.** In the portal, open Trustees & officers and press Invite.

The full wording for each step is in `references/setup-guide.md`. Give the person only the part they need.

## Troubleshooting

Match what the person describes to `references/troubleshooting.md` and give that fix. The most common causes, in order:

- **Old files still in place**, especially the old .htaccess, which blocked AutoSSL and the connector. Fix: extract the latest zip again, overwriting.
- **Padlock not issued yet.** Fix: run AutoSSL again, check the DNS A record `trustees` → 46.252.76.135, and wait.
- **Connector switched off.** Fix: switch it on in the portal under Claude connector.
- **ModSecurity blocking Claude's sign-in.** The portal check shows 403. Fix: in cPanel, open ModSecurity and switch it off for the trustees subdomain.
- **"Couldn't register…" in Claude** even though every check shows ✅. Fix: create a Client ID in the portal, then paste it into the connector's Advanced settings → OAuth Client ID, leaving the secret empty.

When a fix needs the Global Admin, such as switching the connector on, creating a Client ID or changing roles, say so. If nothing in the troubleshooting list matches, ask for the **Run the check** results and the exact Claude message, and suggest sending them to the developer (Saltway Digital).
