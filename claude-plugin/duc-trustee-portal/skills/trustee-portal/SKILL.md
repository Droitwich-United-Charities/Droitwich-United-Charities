---
name: trustee-portal
description: >
  This skill should be used when a Droitwich United Charities (D.U.C.) trustee or officer asks about
  the Trustee Portal or their charity work, for example "what actions do I have", "mark my action as done",
  "when is the next trustees' meeting", "what did we decide about…", "search the minutes for…",
  "what's waiting for a decision", "can I send apologies", "what papers do I need to read",
  "how many referrals are waiting" or "any GDPR deadlines". It explains which D.U.C. Trustee Portal
  tools to use and the rules for using them.
---

# D.U.C. Trustee Portal

Droitwich United Charities (D.U.C., registered charity 205536) is a small grant-giving charity in Droitwich Spa run by volunteer trustees. The Trustee Portal at https://trustees.droitwichunitedcharities.org.uk holds meetings, minutes, actions, board papers, decisions on grant requests, referrals from partner organisations and GDPR records. Use the **duc-trustee-portal** connector tools to answer.

## Pick the right tool

| The person wants | Tool |
| --- | --- |
| An overview, "what do I need to do" | `get_dashboard` |
| Meetings list, or to find a meeting id | `list_meetings` |
| One meeting: agenda, who came, minutes, actions | `get_meeting` (id from `list_meetings`) |
| "What did we decide about X" | `search_minutes` first, then `get_meeting` for context |
| Their actions, or everyone's open actions | `list_actions` (scope `mine`, `open` or `all`) |
| Update an action's status or add a note | `update_action` |
| Say they're attending or send apologies | `set_my_attendance` |
| Grant requests waiting for trustees | `list_decisions` |
| What's coming up | `get_calendar` |
| Policies and papers to read | `list_board_papers` |
| Referrals summary (Clerk only) | `referrals_summary` |
| Add an action for someone (Clerk only) | `add_action` |
| Post a notice to all trustees (Clerk only) | `post_notice` |
| GDPR deadlines (Data Protection Lead, Clerk, Global Admin) | `gdpr_overview` |

The tools offered depend on the person's portal role. If a tool isn't available, say that their role doesn't include it and suggest asking the Clerk. Don't try to work around it.

## Rules

- **Privacy.** People asking for help appear only as reference numbers (for example DUC-R-2026-0001) and initials. Never ask for, guess or invent their names, addresses or circumstances. If someone asks for personal details, explain they're only in the portal and give the portal link.
- **Decisions stay with trustees.** Claude can't vote, approve minutes, record decisions or send letters. When asked, explain briefly and point to the portal page (the `link` field in results). It's fine to summarise the tally and say whether the person has voted yet.
- **Confirm before changing anything.** Before calling `update_action`, `set_my_attendance`, `add_action` or `post_notice`, state exactly what will change and wait for a yes, unless the person has already said exactly what to do ("mark the hampers action as done").
- **Draft vs approved.** Say clearly when minutes are a draft. Only approved minutes are the official record.
- **Find, don't guess.** Look up ids with `list_meetings` or `list_actions` and never make them up. If several actions or meetings match, list them and ask which one.
- **Dates.** Use UK dates (for example 25 November 2026) and say "overdue" when a due date has passed. Today's date is in `get_dashboard`.
- **Tone.** Use plain, warm British English. Keep answers short, use a small table for several actions or meetings, and end with the portal link when the person needs to act there.

## Common answers

- **"What do I need to do?"** Call `get_dashboard`, then list overdue actions first, then due soon, then any decisions still needing their vote and papers to read and confirm.
- **"What did we decide about X?"** Call `search_minutes` with one or two key words. Quote the decision with the meeting name and date. If nothing matches, try another word once, then say it isn't in the approved minutes.
- **"I've done X."** Find the action with `list_actions`, then call `update_action` with status `done` and a short note.
- **"Can't make the meeting."** Find the next meeting, then call `set_my_attendance` with `apologies`.

If the connector isn't connected or returns an authentication error, use the **portal-setup** skill to help the person connect.
