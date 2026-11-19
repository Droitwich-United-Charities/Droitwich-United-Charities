---
name: meeting-briefing
description: >
  This skill should be used when a D.U.C. trustee or the Clerk asks to "prepare for the next meeting",
  "brief me for the trustees' meeting", "what's on the agenda", "catch me up on the last meeting",
  "what's happened since the last meeting" or "write a summary of the meeting for trustees". It builds a
  short briefing from the D.U.C. Trustee Portal: agenda, matters arising, decisions waiting and papers to read.
---

# Meeting briefing

Build a one-page briefing a trustee can read in two minutes before a D.U.C. meeting. Use the **duc-trustee-portal** tools and follow the rules in the **trustee-portal** skill, especially on privacy and not inventing details.

## Steps

1. Call `get_dashboard` to get today's date, the next meeting, and the person's own actions and votes.
2. Call `list_meetings`. Pick the next upcoming meeting, or the one the person named, and the most recent meeting whose status is `minutes_approved`.
3. Call `get_meeting` for both meetings.
4. Call `list_actions` with scope `open` for matters arising.
5. Call `list_decisions` for requests waiting for a vote.
6. Call `list_board_papers` for papers the person still has to read and confirm.

## Write the briefing

Use these headings, and leave out any section with nothing in it:

- **Next meeting**: title, day and date, time and venue, and whether the person has said they're attending. If they haven't, offer to send apologies or confirm attendance.
- **Agenda**: numbered items with who's leading each one. Mark confidential items "(confidential – not recorded)".
- **Since last time**: three to five bullets from the last approved minutes: the key decisions, with the meeting date.
- **Matters arising**: a table of open actions with what, who, due date and status. Put overdue ones first and mark them **Overdue**. Highlight the person's own actions.
- **Decisions waiting**: each request by reference number, type of help, amount, the tally so far, and whether the person has voted. Remind them that voting happens in the portal.
- **To read first**: papers needing "read and confirm", plus any papers attached to the agenda.
- **Questions to think about**: up to three short, neutral questions drawn from the agenda and open actions. Don't give opinions on grant decisions.

End with the portal link for the meeting.

Keep it under about 350 words. Use UK dates. Never include the names or details of people asking for help.

If the person is the Clerk and asks for a summary to send to trustees, write it in the same structure, addressed "Dear Trustees", signed by the Clerk, and in the D.U.C. voice: warm, plain and discreet. Remind them that Claude doesn't send it. They can copy it into an email, or post a short version with `post_notice` if they confirm.
