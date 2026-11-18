<?php
/**
 * Default letter and email wording, written to the D.U.C. "How we sound" rules:
 * warm and respectful, plain and simple, proudly local, discreet.
 * The Global Admin can edit every template in the portal (Letters > Templates).
 */
declare(strict_types=1);

function default_letter_templates(): array {
    return [
        'meeting_invite' => ['name' => 'Meeting invitation and papers', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'clerk',
            'subject' => '{meeting_title} – {meeting_date}',
            'body' => "The papers for our next meeting are now ready.\n\n**{meeting_title}**\n{meeting_date}\n{meeting_venue}\n\nPlease let me know whether you can attend by choosing Attending or Apologies in the Trustee Portal. A calendar invitation is attached.\n\n{portal_link}"],
        'minutes_approved' => ['name' => 'Approved minutes', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'chairman',
            'subject' => 'Approved minutes – {meeting_title}, {meeting_date}',
            'body' => "The minutes of our meeting on {meeting_date} have been approved and are attached. They are also in the Trustee Portal.\n\nThank you for your time and help.\n\n{portal_link}"],
        'actions' => ['name' => 'Your actions from the meeting', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'clerk',
            'subject' => 'Your actions from {meeting_title}',
            'body' => "Thank you for coming to the meeting on {meeting_date}. These are the actions agreed for you:\n\n{action_list}\n\nYou can update them in the Trustee Portal under My actions. They will come back to the next meeting under Matters arising.\n\n{portal_link}"],
        'action_reminder' => ['name' => 'Action reminder', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'system',
            'subject' => 'Reminder: action due {due_date}',
            'body' => "This is a friendly reminder that this action is due on {due_date}:\n\n**{action_text}**\n\nIf it's done, please mark it as Done in the Trustee Portal.\n\n{portal_link}"],
        'action_overdue' => ['name' => 'Action overdue', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'system',
            'subject' => 'Action now overdue: {action_text}',
            'body' => "This action was due on {due_date} and is still open:\n\n**{action_text}**\n\nPlease update it in the Trustee Portal, or let the Clerk know if you need more time.\n\n{portal_link}"],
        'grant_award' => ['name' => 'Grant award', 'group' => 'Grants', 'channel' => 'letter', 'sign' => 'chairman',
            'subject' => 'Your grant from Droitwich United Charities (ref {reference})',
            'body' => "I am pleased to tell you that the trustees of Droitwich United Charities (D.U.C.) have agreed a grant of {grant_amount} towards {grant_purpose}.\n\nThe grant will be paid by {payment_method}. If anything has changed since the request was made, please contact our Clerk, Mrs Janet Clarke, on 01905 775226.\n\nD.U.C. is run by local volunteers, and every gift we receive stays in Droitwich Spa. We are glad to be able to help."],
        'grant_decline' => ['name' => 'Unable to help on this occasion', 'group' => 'Grants', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your request to Droitwich United Charities (ref {reference})',
            'body' => "Thank you for asking Droitwich United Charities (D.U.C.) for help. The trustees have looked carefully at your request, and I am sorry to tell you that we are not able to help on this occasion.\n\n{decline_reason}\n\nThis does not stop you asking again in the future if your situation changes. If you would like to talk this through, please call me on 01905 775226."],
        'more_info' => ['name' => 'More information needed', 'group' => 'Grants', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your request to Droitwich United Charities (ref {reference})',
            'body' => "Thank you for your request for help. Before the trustees can look at it, we need a little more information:\n\n{info_requested}\n\nIt would help if we could have this by {respond_by}. You can post it to me at 26 Blackfriars Avenue, Droitwich Spa WR9 8RQ, or call me on 01905 775226."],
        'referral_received' => ['name' => 'Referral received (to the person helped)', 'group' => 'Referral Partners', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'We have received a request for help for you (ref {reference})',
            'body' => "{organisation_name} has asked Droitwich United Charities (D.U.C.) to consider helping you. We will look at the request and let you, or {organisation_name}, know the outcome.\n\nWe keep your details safely and only use them to deal with this request. You can read how we look after your information at {privacy_link}, or ask me for a paper copy.\n\nIf you have any questions, please call me on 01905 775226."],
        'partner_welcome' => ['name' => 'Welcome, referral partner', 'group' => 'Referral Partners', 'channel' => 'both', 'sign' => 'chairman',
            'subject' => 'Welcome to Droitwich United Charities as a referral partner',
            'body' => "Thank you for agreeing to work with Droitwich United Charities (D.U.C.) as a referral partner. By referring local people to us, you help make sure every gift we receive reaches people in Droitwich Spa who need it.\n\nYour organisation now has its own secure login. Please use the link below to set your password. You can then make referrals, follow their progress and message our Clerk, without sending personal details by email.\n\n{portal_link}\n\nIf you have any questions, our Clerk, Mrs Janet Clarke, will be happy to help on 01905 775226."],
        'partner_invite' => ['name' => 'Invitation to a colleague', 'group' => 'Referral Partners', 'channel' => 'email', 'sign' => 'system',
            'subject' => 'Your login for Droitwich United Charities referrals',
            'body' => "{invited_by} has given you a login so you can refer local people to Droitwich United Charities (D.U.C.) for {organisation_name}.\n\nPlease use the link below to set your password. The link works for 7 days.\n\n{portal_link}"],
        'trustee_invite' => ['name' => 'Invitation to the Trustee Portal', 'group' => 'Trustee Portal', 'channel' => 'email', 'sign' => 'clerk',
            'subject' => 'Your login for the D.U.C. Trustee Portal',
            'body' => "You now have a login for the Droitwich United Charities Trustee Portal. It's where you'll find meeting papers, minutes, your actions and requests waiting for a decision.\n\nPlease use the link below to set your password. The link works for 7 days.\n\n{portal_link}"],
        'agreement_confirm' => ['name' => 'Data sharing agreement confirmation', 'group' => 'Referral Partners', 'channel' => 'email', 'sign' => 'clerk',
            'subject' => 'Data sharing agreement – {organisation_name}',
            'body' => "Thank you. This confirms that {agreed_by} agreed version {agreement_version} of our data sharing agreement on behalf of {organisation_name} on {date}.\n\nYou can read the agreement at any time in the portal."],
        'referral_status' => ['name' => 'Referral status update', 'group' => 'Referral Partners', 'channel' => 'email', 'sign' => 'system',
            'subject' => 'Referral {reference}: {status}',
            'body' => "There is an update on referral **{reference}**. Its status is now **{status}**.\n\nFor privacy, we don't include personal details in emails. Please sign in to see more.\n\n{portal_link}"],
        'partner_message' => ['name' => 'New message about a referral', 'group' => 'Referral Partners', 'channel' => 'email', 'sign' => 'system',
            'subject' => 'New message about referral {reference}',
            'body' => "There is a new message about referral **{reference}**.\n\nFor privacy, we don't include messages in emails. Please sign in to read it.\n\n{portal_link}"],
        'sar_ack' => ['name' => 'SAR acknowledgement and ID request', 'group' => 'GDPR', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your request for your personal information',
            'body' => "Thank you for your request, which we received on {sar_received}, for a copy of the personal information Droitwich United Charities (D.U.C.) holds about you.\n\nTo keep your information safe, we need to check who you are before we send anything. Please send us a copy of one document showing your name and address, for example a recent utility bill or bank statement.\n\nWe will reply by {sar_due}. If you have any questions, please call me on 01905 775226."],
        'sar_response' => ['name' => 'SAR response cover letter', 'group' => 'GDPR', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your personal information held by Droitwich United Charities',
            'body' => "Further to your request received on {sar_received}, I enclose a copy of the personal information Droitwich United Charities (D.U.C.) holds about you.\n\nThe information is in a protected file. We will give you the password separately.\n\nThe enclosed report explains what we hold, why we hold it, who we share it with and how long we keep it. If you think anything is wrong, please tell us and we will correct it. You also have the right to complain to the Information Commissioner's Office (ico.org.uk, 0303 123 1113)."],
        'sar_extension' => ['name' => 'SAR extension notice', 'group' => 'GDPR', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your request for your personal information – more time needed',
            'body' => "Thank you for your request received on {sar_received}. I am writing to let you know that we need more time to reply fully, because {extension_reason}.\n\nWe will now reply by {sar_due}. I am sorry for the delay. If you have any questions, please call me on 01905 775226."],
        'erasure_confirm' => ['name' => 'Erasure confirmation', 'group' => 'GDPR', 'channel' => 'letter', 'sign' => 'clerk',
            'subject' => 'Your request to delete your personal information',
            'body' => "Thank you for your request. We have now deleted the personal information Droitwich United Charities (D.U.C.) held about you.\n\n{kept_reason}\n\nIf you have any questions, please call me on 01905 775226."],
        'breach_notice' => ['name' => 'Breach notification to people affected', 'group' => 'GDPR', 'channel' => 'letter', 'sign' => 'chairman',
            'subject' => 'Important: about your personal information',
            'body' => "I am writing to let you know about a problem that has affected some of the personal information Droitwich United Charities (D.U.C.) holds about you.\n\n**What happened:** {breach_summary}\n\n**What information was involved:** {breach_data}\n\n**What we have done:** {breach_actions}\n\n**What you can do:** {breach_advice}\n\nI am very sorry this has happened. If you have any questions, please call our Clerk, Mrs Janet Clarke, on 01905 775226. You also have the right to complain to the Information Commissioner's Office (ico.org.uk, 0303 123 1113)."],
        'privacy_update' => ['name' => 'Updated privacy notice', 'group' => 'GDPR', 'channel' => 'email', 'sign' => 'clerk',
            'subject' => 'We have updated our privacy notice',
            'body' => "We have updated the privacy notice that explains how Droitwich United Charities (D.U.C.) looks after personal information. You can read it here:\n\n{privacy_link}\n\nThe next time you sign in, you'll be asked to confirm you have read it."],
        'blank' => ['name' => 'Blank letter', 'group' => 'Other', 'channel' => 'letter', 'sign' => 'chairman',
            'subject' => '{subject}', 'body' => "{body}"],
    ];
}
function letter_templates(): array {
    $over = read_json('letter-templates.json', []);
    $out = default_letter_templates();
    foreach ($over as $k => $v) if (isset($out[$k])) $out[$k] = array_merge($out[$k], array_intersect_key($v, array_flip(['subject', 'body', 'sign', 'updated', 'by'])));
    return $out;
}

function default_agreement(): string {
    return "# Data sharing agreement\n\nThis agreement is between Droitwich United Charities (D.U.C.), registered charity no. 205536, and your organisation (the referral partner).\n\n## Why we share information\n\nWe share information only so D.U.C. can consider giving a small grant to a person in Droitwich Spa that you have referred, and so we can tell you the outcome.\n\n## What you agree to\n\n- Only refer people who have agreed to be referred and to D.U.C. holding their details.\n- Tell them about the D.U.C. privacy notice, or give them a copy.\n- Send personal details only through this secure portal, never by ordinary email.\n- Keep your login private, and remove colleagues' logins when they leave.\n- Tell D.U.C. straight away if you think personal information has been lost or seen by someone who shouldn't see it.\n\n## What D.U.C. agrees to\n\n- Use the information only to consider and deal with the referral.\n- Keep it secure, let only trustees and the Clerk see it, and delete it when it's no longer needed, in line with our retention schedule.\n- Never include personal details in emails to you.\n\nEither side can end this agreement at any time by telling the other in writing.";
}
function default_privacy_notice(): string {
    return "# How Droitwich United Charities looks after your information\n\nDroitwich United Charities (D.U.C.), registered charity no. 205536, gives small grants to people and organisations in Droitwich Spa. This notice explains what information we hold about you and why.\n\n## What we hold\n\nIf you ask us for help, or someone refers you to us, we hold your name and contact details, details about your household and situation, what help you need, any documents you or your referrer send us, and our decision.\n\n## Why we hold it\n\nWe use it only to consider your request, make any payment, and keep proper records of how we spend our money. The law allows us to do this because it is in our legitimate interests as a charity, and with your consent where we ask for it.\n\n## Who sees it\n\nOnly our Clerk and trustees, and the organisation that referred you. We never sell your information or share it for marketing.\n\n## How long we keep it\n\nIf we give you a grant, we keep the details for 6 years after the grant is paid, because charity accounting rules require it. If we can't help, we keep them for 2 years. After that we delete them.\n\n## Your rights\n\nYou can ask for a copy of your information, ask us to correct it, or ask us to delete it. Contact our Clerk, Mrs Janet Clarke, 26 Blackfriars Avenue, Droitwich Spa, Worcestershire WR9 8RQ, telephone 01905 775226.\n\nIf you are unhappy with how we have handled your information, you can complain to the Information Commissioner's Office: ico.org.uk or 0303 123 1113.";
}
function default_ropa(): array {
    return [
        ['activity' => 'Grant requests and referrals', 'purpose' => 'Considering and paying small grants to people in Droitwich Spa', 'people' => 'People asking for help, their household, referrers', 'data' => 'Name, contact details, household, circumstances, financial need, evidence documents; may include health or disability information', 'basis' => 'Legitimate interests; explicit consent for health information', 'recipients' => 'Clerk, trustees, the referring organisation', 'retention' => '6 years after closure if awarded; 2 years if declined', 'security' => 'Encrypted at rest, role-based access, audit log'],
        ['activity' => 'Referral partner accounts', 'purpose' => 'Letting partner organisations make and track referrals', 'people' => 'Staff at partner organisations', 'data' => 'Name, work email, organisation', 'basis' => 'Legitimate interests; contract (data sharing agreement)', 'recipients' => 'Clerk, Global Admin', 'retention' => '12 months after last login', 'security' => 'Password-protected accounts, audit log'],
        ['activity' => 'Trustee meetings and recordings', 'purpose' => 'Running the charity, keeping minutes and tracking actions', 'people' => 'Trustees, Clerk, guests', 'data' => 'Names, voices in recordings, what was said, actions', 'basis' => 'Legitimate interests; consent to recording given at each meeting', 'recipients' => 'Trustees; Google (Gemini API) as processor for transcription', 'retention' => 'Audio and raw transcripts 90 days after minutes approved; minutes permanently', 'security' => 'Recording paused for confidential items; applicants referred to by reference only'],
        ['activity' => 'Claude connector (optional)', 'purpose' => 'Letting trustees ask Claude about meetings, actions and decisions', 'people' => 'Trustees and officers who connect; people named in minutes', 'data' => 'Names of trustees, meeting minutes and actions; referrals by reference number and initials only', 'basis' => 'Legitimate interests', 'recipients' => 'Anthropic (Claude) as processor, only for trustees who connect', 'retention' => 'Sign-in tokens 30 days; tool use kept in the audit log for 6 years', 'security' => 'Each trustee signs in individually; role-based tools; no applicant personal details; can be switched off by the Global Admin'],
        ['activity' => 'Website accounts and audit log', 'purpose' => 'Security of the website and accountability', 'people' => 'All users', 'data' => 'Name, username, IP address, activity', 'basis' => 'Legitimate interests', 'recipients' => 'Global Admin', 'retention' => '6 years', 'security' => 'Stored outside the web folder'],
    ];
}
