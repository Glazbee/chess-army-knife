# Security review: member portal and email changes

Method: a read of `class-member-portal.php` and `class-member-requests.php`, and the parts of
`class-membership-store.php` they call (`save_member`, `get_members_by_email`, `erase_member`).
No handler was run against a live site, and no scanner was used. It is a code review, not a
penetration test, and it follows the broader review in `security-review-2026-10.md`, which found
the public forms fine. Nothing here is an unauthenticated way in. These are hardening items.

## What is done well

- A nonce on every form, plus the secret session token in the POST. For logged-out visitors a
  nonce is not tied to a person, so the token is the real protection against forged requests.
- Tokens are 32 random characters (`wp_generate_password`), cleaned to letters and digits before
  use, with short lifetimes and an absolute session maximum of eight hours.
- Every record is looked up through the session's own address (`person_in_session`), so a
  posted person id cannot reach someone else's record.
- Fields that decide membership, payment and date of birth cannot be changed from the portal.
- An email address changes only after the new address confirms, and the old address is told.
- Pages opened from an emailed link are sent with no-cache headers and `Referrer-Policy:
  no-referrer`.
- Queries use `$wpdb->prepare` or the insert and update helpers.
- Erasure goes through the same function as the officers' and WordPress's own tools.

## Findings

| # | Finding | Risk | Suggested change |
|---|---|---|---|
| 1 | **Fixed.** **Whether an address is on file can be guessed from how long the reply takes.** `request_link` and the withdraw request call `wp_mail` straight away only when the address is on file, so a slow SMTP send makes that reply slower. The code says the answer cannot be probed; the content cannot, the timing can | Low to medium | Done: the mail is sent on `shutdown`, after the reply is finished (`Chess_Army_Knife_Member_Requests::send_after_response`). This only helps on PHP-FPM or LiteSpeed, where the reply can be finished early; on other hosts (such as Apache with mod_php) the reply still waits for the send. The `Chess_Army_Knife_send_mail_after_response` filter turns it off |
| 2 | **Fixed.** **Tokens are stored as the transient name, and the session holds the member's email address.** Anyone who can read the options table (a backup, a database dump, a SQL injection elsewhere) can read live tokens and the addresses they belong to | Low (tokens live for about an hour) | Done: the session, email-change and withdraw tokens are stored under a SHA-256 hash (`Chess_Army_Knife_Member_Requests::token_key`), so the stored key is useless without the link. The session still holds the member's address. Links issued before this change stop working |
| 3 | **The token is in the page address**, so it is in server access logs and browser history for as long as they are kept. The Referer leak is already handled | Low | Accept it, or have the link open a page that swaps the token for a cookie and redirects. Not worth it unless logs are shared |
| 4 | **Anyone with the link can delete the record at once**, with only a tick box. A forwarded email or a compromised mailbox is enough. This is by design (it matches the privacy tools) | Low | Optional: a confirmation email before deletion, or a short wait during which the deletion can be cancelled |
| 5 | **The email-change request is limited by visitor address only, not by the address it writes to.** A person with a valid session could send repeated "someone asked..." messages to one target, up to the hourly limit per address | Low | Count requests per target address as `request_link` does |
| 6 | **The visitor limit is shared across the portal, Manage my data and email changes**, and behind a proxy or CDN everyone shares one address (there is a filter for that: `Chess_Army_Knife_visitor_address`). A busy evening could lock members out | Usability | Check the host. If it sits behind a proxy, set the filter |
| 7 | **`session()` trusts the transient's own expiry** and does not compare the stored `expires` to the clock. This is fine with core transients, which expire on read | Informational | Add the comparison as defence in depth if a persistent object cache is ever used |
| 8 | **Portal edits are not length-checked.** `save_details` passes name, phone and guardian fields to `save_member`, which writes them with `$wpdb->update` and does not check the result. WordPress normally runs MySQL in a mode that cuts long values to the column size, so the usual effect is silent truncation, not an error | Low | Cut each value to its column size before saving, and check the result of the update |
| 9 | **The Referer and no-cache headers are set in two places** (`Member_Portal::keep_link_private` and `Member_Requests::protect_token_pages`). The second already covers the first | Tidy | Remove `keep_link_private` and its hook |
| 10 | **Sign-out reports success even if the nonce is wrong.** Nothing is deleted, but the visitor is told they have signed out | Tidy | Report an error when the check fails |

## Suggested order

1. Findings 1 and 2: done.
2. Finding 8, then 5.
3. Findings 9 and 10 whenever the file is next touched.
4. Findings 3, 4 and 7 only if the club wants the extra protection.

Add or update tests in `tests/integration/MemberPortalTest.php` with each change. Those tests
run in GitHub CI, not locally.
