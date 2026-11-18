# D.U.C. Trustee Portal plugin

Use the Droitwich United Charities Trustee Portal from Claude.

## What's included

| Part | What it does |
| --- | --- |
| **D.U.C. Trustee Portal connector** | Connects Claude to https://trustees.droitwichunitedcharities.org.uk/mcp. Each person signs in with their own portal account, and Claude only sees what their role allows. |
| **trustee-portal** skill | Answers everyday questions: your actions, the next meeting, what was decided, decisions waiting, papers to read. It can also update your actions and attendance. |
| **meeting-briefing** skill | Builds a one-page briefing before a meeting: agenda, matters arising, decisions waiting and papers to read. |
| **portal-setup** skill | Step-by-step help installing the portal on cPanel, getting the padlock, switching on and connecting the Claude connector, and troubleshooting. |

## Before you install

The Global Admin must switch the connector on in the portal: Portal → ✳️ Claude connector → **Switch the connector on**. Then press **Run the check**, and all four steps should show ✅.

## After installing

When Claude asks you to connect **duc-trustee-portal**, press **Connect**, sign in with your portal username and password, and press **Allow**.

Try asking:
- "What actions do I have?"
- "Brief me for the next trustees' meeting."
- "What did we decide about the Christmas hampers?"
- "Mark my hampers action as done."

## Privacy

Claude never sees the names or addresses of people referred for help, only reference numbers and initials. It can't vote, approve minutes or send letters. Everything it does is recorded in the portal's audit log, and the Global Admin can disconnect anyone.

Built by Saltway Digital for Droitwich United Charities (registered charity 205536).
