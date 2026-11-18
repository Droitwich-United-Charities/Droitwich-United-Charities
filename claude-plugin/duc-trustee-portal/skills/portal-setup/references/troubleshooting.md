# Trustee Portal troubleshooting

| What the person sees | What to do |
| --- | --- |
| Browser says "Not secure", or "This site's certificate is for a different address" | Run **AutoSSL** again from SSL/TLS Status. Make sure the latest **.htaccess** is in the trustees folder, because the old one blocked AutoSSL's checks. Wait a few minutes, then reopen the tab. |
| AutoSSL says "DNS DCV", "no local authority" or "does not resolve" | In cPanel **Zone Editor**, add or check an **A record**: `trustees` → `46.252.76.135`. If the domain's DNS is managed elsewhere (registrar, Cloudflare), add it there. Wait 30 minutes and run AutoSSL again. With Cloudflare, use DNS only (grey cloud) while AutoSSL runs. |
| AutoSSL says "HTTP DCV" error | The Document Root is wrong, or the old .htaccess is still there. Fix both, then run AutoSSL again. |
| AutoSSL shows "pending" or "queued" | Wait. cPanel finishes it automatically, usually within a few hours. |
| The data folder is empty | Normal until the site is opened for the first time. Open the website and setup-code.txt appears. |
| A data folder also appears inside the site folder (…/trustees…/duc-data) | PHP couldn't write to the home folder. Check that the home-folder data folder belongs to the account and has permissions 700 or 755. |
| The site shows the main DUC site, a cPanel default page or "Index of /" | The subdomain's Document Root is wrong. Set it to `public_html/trustees.droitwichunitedcharities.org.uk`. |
| Blank page or "500 Internal Server Error" | Open **error_log** in the trustees folder and send the last lines to the developer. Check the PHP version is 7.4 or newer, with openssl, curl, zip and mbstring turned on (Select PHP Version). |
| Setup asks for a code you can't find | Use the folder named on the setup page. In File Manager, click Home and open that folder, then setup-code.txt. |
| Locked out of the only admin account | Delete users.json in the data folder and open the site. Setup runs again with a new code. Portal users will need adding again. |
| No "Claude connector" in the portal menu | Press Ctrl + F5. If it's still missing, upload and extract the latest zip again, overwriting. |
| "Only the Global Admin can switch the connector on" | Sign in with the Global Admin account made at setup. |
| Check shows ❌ "switched off" | Press **Switch the connector on**. |
| Check shows ❌ with 403, 406 or 501 | ModSecurity is blocking it. In cPanel, open **ModSecurity** and switch it **Off** for trustees.droitwichunitedcharities.org.uk. Run the check again. |
| Check shows ❌ "no answer" (status 0) | In Portal settings, set the Website address to `https://trustees.droitwichunitedcharities.org.uk`, and check the padlock works. |
| Check shows ❌ and an HTML page came back | Old files are still in place. Extract the latest zip again. |
| Claude: "Couldn't register with D.U.C Trustee Portal's sign-in service" | Run the check and fix any ❌. If all are ✅, create a **Client ID** in the portal and paste it into the connector's Advanced settings → OAuth Client ID, leaving the secret empty. The portal's Claude connector page also lists the reasons for recent failed attempts. |
| Claude: "Couldn't reach the MCP server" | Check the URL ends in **/mcp** and the padlock works, then run the check. |
| Claude: "Authorization with the MCP server failed" | Remove the connector and connect again. If you used a Client ID, check it's copied exactly and the secret box is empty. |
| After signing in: "Not available for your account" | That account isn't a trustee or officer. Give it the Trustee, Chairman, Clerk, Data Protection Lead or Global Admin role on the website's Users page. Referral partners can't connect. |
| "This page expired" on the Allow page | Go back to Claude and press Connect again. |
| Claude stopped working after a while | Remove the connector and connect again. Switching the connector off and on disconnects everyone. |
| A tool says "Unknown tool" | That person's role doesn't include it. For example, referrals_summary is for the Clerk only. |
| Emails don't arrive | In Portal settings, press Save & send test and read the error. For Microsoft 365, turn on Authenticated SMTP for the mailbox. "Waiting to send" shows emails that are being retried. |
| Reminders or transcripts never happen | The cron job is missing or wrong. Check Cron Jobs and the command path. Portal settings shows a tick when cron has run in the last 2 days. |
