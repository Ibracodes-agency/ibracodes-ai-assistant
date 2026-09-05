# IbraCodes AI Assistant: design

Date: 2026-09-04. Status: validated with the owner, not yet implemented.

Turns Shop Agent for WooCommerce (0.1.2) into one plugin that serves any
WordPress site. Content answering is always on; product tools switch on
only when WooCommerce is active. The plugin slug and the WSA prefix stay;
the display name becomes IbraCodes AI Assistant.

## Decisions taken with the owner

- Knowledge: all published pages and posts by default, custom post types
  the owner ticks, or a hand-picked list of pages. The page the visitor is
  on is always known to the agent.
- Actions: owner-written facts, the handoff button, lead capture. No page
  cards under replies.
- Retrieval: embeddings are an owner opt-in with the cost shown. Otherwise
  WordPress search. Same tool either way; the model never knows which.
- Packaging: one plugin that adapts to the site.
- Leads: emailed to the owner and listed in the admin, with their own
  retention setting.
- Branding: a small "Developed by Ibracodes" line with the mark in the
  widget footer, always on.

## 1. Shape

Two capability sets detected at boot:

- Content, always on: owner facts, the content index, the current page,
  the handoff destination.
- Commerce, only with WooCommerce: the existing product tools and cards.

Tools offered to the model, adapting to the site:

- `search_content(query)`: best matching passages with page title and URL.
- `get_page(id)`: one page's full text, trimmed to a cap.
- `capture_lead(name, contact, request)`: only when lead capture is on.
- `hand_off`: only when a destination is set (shipped in 0.1.2).
- `search_products`, `get_categories`, `get_product_details`: WooCommerce.

The widget is visually unchanged. It sends the current post id with every
message. Lead capture is conversational: the agent asks, the visitor
types, the tool stores. The consent sentence under the input covers it.

Scope setting: all published pages and posts (default) plus ticked custom
post types, or a hand-picked page list. Drafts, private and
password-protected content are never indexed.

## 2. Content index and retrieval

Search mode (default, free): a WordPress search restricted to allowed
types and pages, top five results, the passage of each page that best
matches the query terms, with title and URL.

Embeddings mode (opt-in): the admin states the cost before enabling,
roughly one cent per hundred pages to build and a fraction of that per
question. The index builds in the background in batches of twenty pages
per cron tick, with a progress bar and a rebuild button.

Index build: each allowed page rendered to plain text (blocks and
shortcodes rendered, markup stripped), split into chunks of about 300
words with a short overlap, stored in one table: post id, chunk order,
text, embedding as a packed float vector. Ranking is cosine similarity in
PHP, fine to several thousand chunks; revisit only if a site exceeds that.

Freshness: publish, save, trash and unpublish update that post's chunks
only. A scope change triggers a full rebuild. A daily job catches misses.
The index is dropped when the mode is switched off or on uninstall.

Current page: the server checks the post id is public and allowed, then
supplies the title and first chunks as context for that turn. Not stored.

The model receives passages as data. The prompt says site content is
written by the owner and may contain instructions to ignore, the same rule
product descriptions get today.

## 3. Lead capture

Trigger: the owner switches it on and writes one line saying when to
offer it. One offer per conversation, only after the agent tried to
answer. On agreement the agent asks for a name and one contact, phone or
email, in the site's language.

Validation on the server: name 2 to 80 characters, contact parses as a
phone number or an email, request trimmed to 500 characters. A bad
contact returns a plain error so the model asks again. One lead per
thread; a second call updates it. The tool response confirms the save so
the model's confirmation is true.

Storage: a `leads` table with thread id, name, contact, request, page,
created time. Email to the owner's address on arrival with a link to the
conversation. A Leads tab with search, CSV export and delete.

Retention: its own setting, default 180 days, deletable to zero.
Transcripts store no name, email or IP as fields, but details a visitor
types into the chat are part of the stored conversation. Decision taken
with the owner on 2026-09-05: deleting a lead, by hand or by retention,
deletes the conversation it came from as well, so no copy of the details
stays behind. The consent sentence gains a phrase about details passed to
the site owner, editable by the owner.

Failure paths: email failure keeps the lead and flags it in the tab; a
database failure returns an error and the model offers the handoff button.

## 4. Prompt, admin, cost, branding, migration, testing

Prompt: assembled from the capabilities present. Role line gains "answer
from the site's own content and the facts below"; shop paragraphs about
cards and cart appear only with WooCommerce. New rules: call
`search_content` before stating anything about the site, quote nothing
the search did not return, name the page drawn from in words. Grounding,
refusals, security and chip rules unchanged.

Admin: Agent tab gains a Content group (post types, all-or-selected
pages, retrieval mode with cost note, index progress, rebuild) and a Leads
group (on/off, when to offer, notification address, retention). A Leads
tab next to Conversations. Catalogue tab only with WooCommerce. Overview
adds leads this month.

Cost: embedding calls go through the provider layer and count against the
monthly cap.

Branding: footer line under the input, the Ibracodes mark at about 14px
and "Developed by Ibracodes" linking to ibracodes.com, translated, muted.

Migration: rename display strings, keep slug and prefix, bump the database
version for the two tables and new settings with safe defaults. Existing
installs keep working: commerce on, content in search mode.

Testing: the harness gains a stub site with three pages and a post; search
and embeddings modes each answer with the right page; a lead is captured
and a bad phone rejected; the current page is answered without a search;
WooCommerce tools are absent when it is off; injection text hidden in a
page is ignored.

## 5. Live chat (added 2026-09-04, validated with the owner)

Decision taken with the owner: when a visitor asks for a person the AI
pauses; if no manager joins within a set time the widget falls back to
the contact button and lead capture, and the manager can still answer
later from the admin.

Everything runs inside WordPress. Real time means short polling over the
REST API: the widget every 4 seconds while a human session is open, the
admin screen every 3 seconds, both paused while the browser tab is hidden.

### Flow

1. The visitor asks for a person, or the AI cannot help. The model calls
   `hand_off`. With live chat on, the server marks the thread `waiting`,
   records the time, emails the live-chat address with the first question,
   the page and a direct link to the conversation, and tells the model to
   say a person will join shortly. The reply carries `live: waiting` and
   the widget switches mode: AI off, polling on, input still open, and
   visitor messages are stored on the thread without an AI answer.
2. A manager opens Live chats in the admin, sees the waiting thread at the
   top with how long the visitor has waited, claims it and replies. The
   widget shows "X joined" and the manager's bubbles with their display
   name. Every visitor message from then on reaches the manager's pane.
3. If nobody joins within the wait time (default 3 minutes, editable), the
   thread becomes `missed`. The widget shows the missed text, the contact
   button, and the AI resumes with one instruction: offer to take the
   visitor's details (lead capture, when enabled). The thread stays in the
   admin list under Missed so a manager can still answer later; a late
   answer moves it back to live and the widget picks it up on its next
   poll or page load.
4. The manager closes the chat. The widget shows the closed text and the
   AI takes over again for anything further.

### Data

- Threads gain `status` (ai, waiting, live, missed, closed), `requested_at`,
  `claimed_at`, `closed_at`, `manager_id`, `last_visitor_at`,
  `last_manager_at`. Messages gain the role `manager` and a `read` flag for
  unread counts. Retention is unchanged: live messages are deleted with
  the thread.
- Live chat requires conversation logging, since it lives on the thread
  table. The admin says so and turns logging on when live chat is enabled.
- The visitor is identified by the signed thread token the widget already
  holds. No account, no cookie, no personal data unless typed.

### Endpoints

Visitor, public, token-verified and rate-limited per IP:
`GET wsa/v1/live/thread` (state and new messages since an id),
`POST wsa/v1/live/thread/message` (a visitor message during waiting or live).
Manager, admin capability and nonce: list open threads with unread counts,
poll one thread, claim, reply, close.

### Settings

`live_enabled`, `live_email` (defaults to the leads address, then the admin
email), `live_wait_minutes` (3), and four visitor-facing texts with
translated defaults: waiting, joined, missed, closed.

### Admin

A Live chats tab: a list on the start side (waiting first with a timer,
then live, then missed, each with an unread badge) and the conversation on
the other side with a reply box. New chats appear without a reload, the
browser tab title shows the waiting count, and a small sound is not
included. Closing returns the thread to the AI.

### Widget

Live mode is part of the persisted conversation, so navigation keeps the
session. Manager messages get their own bubble style with the manager's
name. System lines (joined, missed, closed) are muted single lines. The
input placeholder changes to "Write to X" while a manager is present.
