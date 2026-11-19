# Trustee Portal & Claude connector – step-by-step setup guide

## Part 1 – Put the site on cPanel

### Upload the files
1. Log in to cPanel and open **File Manager**. In **Settings** (top right), tick **Show Hidden Files**.
2. Go to **public_html** and open **trustees.droitwichunitedcharities.org.uk**. If it doesn't exist, click **+ Folder** and create it with exactly that name.
3. Click **Upload**, choose **DUC-Website-cPanel.zip**, and wait for 100%. Then go back to File Manager.
4. Right-click the zip, choose **Extract**, then **Extract File(s)**. Overwrite if asked.
5. Delete the zip.
6. Check that **index.php**, **.htaccess**, **app** and **assets** sit directly in the folder, not inside another folder.

### Point the subdomain at the folder
1. In cPanel, open **Domains**.
2. Find **trustees.droitwichunitedcharities.org.uk** and press **Manage**. If it isn't listed, press **Create A New Domain**.
3. **Untick** "Share document root".
4. Set **Document Root** to `public_html/trustees.droitwichunitedcharities.org.uk`.
5. Press **Update** (or **Submit**).

### Get the padlock (HTTPS)
1. In cPanel, open **SSL/TLS Status**.
2. Tick **trustees.droitwichunitedcharities.org.uk**, and **www.trustees…** if it's listed.
3. Press **Run AutoSSL**, wait 2–5 minutes, then refresh.
4. Once there's a green padlock, open https://trustees.droitwichunitedcharities.org.uk. Close and reopen the tab if it still says "Not secure".

Don't type passwords into the site until the padlock shows.

### Force HTTPS (after the padlock works)
1. Edit **.htaccess** in the trustees folder.
2. Remove the `# ` from the start of these two lines:
   `# RewriteCond %{HTTPS} !=on`
   `# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]`
3. Press **Save Changes**.

## Part 2 – First-time setup

If the site already shows **Members sign in**, setup has been done before. Skip to Portal settings.

### Create the Global Admin
1. Open the site. It shows **Enter setup code** and says which folder the code is in.
2. In File Manager, click **Home** and open the data folder (duc-data.trustees.droitwichunitedcharities.org.uk). Open **setup-code.txt**.
3. Copy the code (for example AB12-CD34-EF56) into the setup page.
4. Enter a name, a username and a password of at least 10 characters, then press **Create**.

### Portal settings
1. Go to https://trustees.droitwichunitedcharities.org.uk/portal and open **⚙️ Portal settings**.
2. **Website address:** `https://trustees.droitwichunitedcharities.org.uk`, with no slash at the end.
3. **Email (SMTP):** for Microsoft 365, use `smtp.office365.com`, port 587, STARTTLS, and the mailbox's email address and password. The mailbox needs "Authenticated SMTP" turned on. Press **Save & send test**.
4. **Gemini:** paste an API key from aistudio.google.com, from a Google Cloud project with billing on. Press **Save & test**.
5. Check **Who signs letters** and the **quorum**, then press **Save settings**.

### Cron job
1. In cPanel, open **Cron Jobs**.
2. Set it to run every 15 minutes (Minute `*/15`, all other boxes `*`).
3. Command: `/usr/local/bin/php /home/droitwichunitedc/public_html/trustees.droitwichunitedcharities.org.uk/index.php cron`
4. Press **Add New Cron Job**.

## Part 3 – Switch on the Claude connector
1. Sign in as the **Global Admin** and go to `/portal`.
2. In the menu, under **Admin**, click **✳️ Claude connector**. On a phone, tap **☰ Menu** first. If it's missing, press Ctrl + F5. If it's still missing, extract the latest zip again.
3. Press **Switch the connector on**. A green box should say **The connector is on**.
4. Press **Run the check**. All four lines should show ✅:
   - MCP endpoint asks Claude to sign in (401)
   - Protected resource details (200)
   - Sign-in service details (200)
   - Claude registers itself (201)

## Part 4 – Connect Claude
1. In claude.ai, open **Settings → Connectors**. On a Team or Enterprise plan, an owner adds it under Organisation settings → Connectors.
2. Remove any earlier failed copy of the connector.
3. Choose **Add custom connector**. Name: `D.U.C. Trustee Portal`. URL: `https://trustees.droitwichunitedcharities.org.uk/mcp`
4. Leave Advanced settings empty and press **Add**, then **Connect**.
5. Sign in with your portal account, read the permissions page, and press **Allow**.

If Claude says it couldn't register, use a Client ID:
1. In the portal, open **Claude connector** and press **Create a Client ID**. Copy the ID, which starts `duc_claude_`.
2. In Claude, add the connector again. Under **Advanced settings**, paste the ID into **OAuth Client ID** and leave the secret empty.
3. Press **Add**, then **Connect**, sign in, and press **Allow**.

### Invite trustees
1. In the portal, open **👥 Trustees & officers** and press **+ Invite**.
2. Enter their name, email and role, then press **Send invitation**. They get a link to set their password.
3. Each trustee then follows Part 4 with their own account.

## What Claude can and can't do
- Everyone can see the dashboard, meetings and approved minutes. They can search the minutes, see the calendar and board paper titles, see requests by reference number only, update their own actions, and say whether they're attending.
- The Clerk can also see a referrals summary, add actions and post notices. The Data Protection Lead can also see GDPR deadlines.
- Claude never sees the names or addresses of people referred for help. It can't vote, approve minutes or send letters. Everything it does is in the audit log.
