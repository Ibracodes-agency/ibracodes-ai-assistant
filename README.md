# IbraCodes AI Assistant

An AI assistant for any WordPress site. It answers visitors from your own pages and posts and
from facts you write, so it never invents what your business offers; it captures leads when a
visitor wants a quote or a callback; and it hands the conversation to a person when one is
asked for. The site uses its own OpenAI key and pays OpenAI directly.

On a WooCommerce store it also searches the live catalog and shows product cards, with the
real price and stock, that the customer can add to the cart from inside the chat.

Built by [Ibracodes](https://ibracodes.com).

## What it does

- **Answers from your content.** You choose which pages and post types it may read. Drafts,
  private and password-protected content are never used.
- **Knows the page the visitor is on**, so a question about "this service" or "this package"
  works without a search.
- **Answers site questions** from the facts field (shipping, returns, opening hours) and says
  it does not know when the answer is not in there.
- **Captures leads.** When a visitor wants a quote or a callback, it asks for a name and a phone
  or email, saves the lead and emails you.
- **Hands off to a person.** A visitor who asks for someone waits for you in the Live chats tab
  of the WordPress admin; you get an email with a link, reply in real time and close the chat.
- **Offers your contact option** as a button under the reply when it is stuck, or when nobody
  joined a live chat in time.
- **Recommends products on a WooCommerce store.** Every product it mentions comes from a tool
  call against live catalog data, so it cannot invent a product, a price or stock.
- **Speaks the site's language** automatically, from the site locale. RTL supported; the admin
  and the widget are translated through WordPress.org language packs.
- **Reports what visitors asked**, including the questions it could not answer.

## What it deliberately does not do

- Never touches the cart itself. The customer clicks the button on the product card, so a
  hallucinated add is impossible.
- Never places orders, applies discounts or looks up orders or customer records.
- Never sends email on the visitor's behalf. The only emails are the owner's own: a new lead,
  and a visitor waiting for a person.
- Never acts as a general-purpose chatbot: content generation, roleplay and general knowledge
  questions are refused.

## Requirements

PHP 8.1 or later, WordPress 6.2 or later, and an OpenAI API key. WooCommerce is optional: with it
active the assistant gains product search and cart cards, without it the Catalogue tab is simply
absent.

## Setup

1. Activate the plugin.
2. Open **AI Assistant** in the admin menu (under **WooCommerce** on a store, otherwise its own
   menu item).
3. On the **Agent** tab, paste an OpenAI key and press **Test connection**.
4. Choose which content the assistant may read, and write your facts in the site's language.
5. Switch on lead capture and live chat if you want them.
6. Tick **Show the chat to customers**.

The tabs: **Overview** (usage and reports), **Appearance** (launcher, copy, the credit line),
**Agent** (key, model, rules, facts, content, leads, live chat, spending), **Catalogue**
(WooCommerce only: what it may recommend), **Conversations** (transcripts and retention),
**Live** (the chats waiting for a person) and **Leads**.

### Keeping the key out of the database

The key is stored in the site's options table by default, which means any administrator can read
it. On sites where that matters, define it in `wp-config.php` instead and the settings field
disappears:

```php
define( 'IBRAAI_OPENAI_KEY', 'sk-...' );
```

## Content

**Scope.** Pick the post types (pages and posts by default) and either every published item or
a list of page ids. Only published, public, password-free content is ever read, and page-builder
markup, shortcodes and scripts are rendered or stripped to plain text first.

**Search or embeddings.** By default the assistant finds passages with WordPress search, which
costs nothing. For better answers, switch retrieval to the embeddings index: the plugin then
keeps a vector for every passage of the allowed content, updated as you edit and reconciled
once a day. Building the index costs about one cent per hundred pages once, then a fraction of
that per question, all on your own key and counted against your daily and monthly limits.
Changing the scope or pressing **Rebuild index** rebuilds it.

## Leads

With lead capture on, the assistant offers to take a visitor's details when your one-line rule
says so ("when someone wants a quote or a callback"). What is stored per lead: the name, one
phone number or email address, the request in the visitor's words (up to 500 characters), the
page it happened on, whether your notification email went out, and the time. One lead per
conversation: a correction updates it rather than adding a second.

Leads have their own retention (180 days by default) and are purged once a day. **Deleting a lead
deletes its conversation** too, whether you delete it by hand or the purge does, so a lead never
leaves its transcript behind. The Leads tab lists, searches and exports them as CSV.

## Live chat

1. A visitor asks for a person. The assistant stops answering, the widget shows your waiting
   text, and one email goes to the live-chat address (falling back to the leads address, then
   the site admin) with a link to the conversation.
2. You open the **Live** tab, claim the chat and reply. The visitor sees your name and your
   lines in the widget; the widget input now writes to you, not to the AI.
3. You close the chat, or it closes itself after 30 minutes without a line from either side.
   The visitor sees your closed text and the AI answers again.

**Wait time.** If nobody claims the chat within the configured minutes (3 by default) the
request is **missed**: the visitor sees your missed text, gets the contact button under it, and
the AI returns with lead capture on offer. The request stays in your list, and a late claim
brings the visitor back to you with their next message.

Turning live chat on also turns conversation logging on, because a live chat lives on the
conversation record. Both sides poll every few seconds; there is no socket to host.

## Spending

The site pays for its own usage. Five caps bound it, all editable under **Spending limits**:
per-visitor burst and daily limits, a site-wide daily and monthly cap on API calls, and a
concurrency cap that protects the server rather than the wallet. Usage for today and this month
is shown at the top of the settings screen. One visitor message can use two or three API calls,
because the assistant searches before answering, and index builds count against the same caps.

Every counter is one row in the `ibraai_counters` table, moved by a single
`INSERT ... ON DUPLICATE KEY UPDATE`, so requests that arrive together queue behind the row
lock instead of all reading the same number: no increment is lost and no cap can be raced.

## Hooks

| Filter | Purpose |
| --- | --- |
| `ibraai_has_commerce` | Override whether the plugin treats the site as a store (default: WooCommerce active). |
| `ibraai_show_widget` | Return false to hide the chat on specific pages. |
| `ibraai_content_text` | Change the plain text taken from a post before it is chunked (`$text, $post`). |
| `ibraai_content_excluded_ids` | Post ids the assistant must never read, on top of the scope settings. |
| `ibraai_search_args` | Adjust the `WP_Query` arguments the search retrieval uses. |
| `ibraai_currency_tokens` | Words that count as money for the price guard (`$tokens, $currency`). |
| `ibraai_client_ip` | Override how the visitor's address is resolved for rate limiting. |
| `ibraai_models` | Add or replace the selectable models. |
| `ibraai_pre_embed` | Return vectors instead of calling the embeddings API (`$texts`). |
| `ibraai_pre_complete` | Return an assistant message instead of calling the completion API (`$messages, $tools`). |

The two `ibraai_pre_` filters answer before the key check and the spending guards run, so they
bypass both. They exist for the tests and for alternative providers; do not use them to route
real traffic around your own limits.

## Conversations and privacy

Conversations are stored so you can read what visitors asked, and deleted automatically after
the retention window (30 days by default, changeable, and switchable off entirely unless live
chat is on). What is stored is the messages, the ids of products shown, and the lines a person
wrote in a live chat. No IP address, email, name or user id is stored as a field, but **details
a visitor types into the chat are part of the stored conversation**, and a lead holds the name
and contact the visitor gave on purpose. Deleting a lead deletes its conversation.

One boolean is recorded per thread on a store: whether the customer added a recommended product
to the cart. That comes from WooCommerce's own `added_to_cart` event, so it only counts real
adds.

In the browser, the conversation lives in the tab's session storage so it survives moving between
pages and following a product card. It ends with the tab; nothing is kept in the browser after
that beyond a flag that the launcher has been opened once.

Nothing is sent anywhere but OpenAI's API, with your key: the visitor's messages, the passages
found relevant, your facts, and, when the index is on, the text of the pages you allowed. No data
goes to Ibracodes.

## The credit line

**Appearance** has an off-by-default switch that shows "Developed by Ibracodes" with a small
mark under the chat input, linking to ibracodes.com. Nothing is shown unless you turn it on.

## Releasing

Publishing is automated; a tag is the release:

    # 1. bump the version in three places (all must agree)
    #    ibracodes-ai-assistant.php: Version: and define('IBRAAI_VERSION', ...)
    #    readme.txt: Stable tag: and a changelog entry
    # 2. verify locally
    bin/check-version.sh
    # 3. release
    git tag 0.2.0 && git push origin main --tags

The `deploy` workflow rejects the release if those versions disagree, then builds the plugin
(honouring `.distignore`), commits it to SVN trunk, creates the matching SVN tag, and uploads
the banners, icon and screenshots from `.wordpress-org/`. Changes to those files or to
`readme.txt` alone run the `assets` workflow on push to `main`, no release needed.

One-time setup after WordPress.org approves the plugin: add the repository secrets
`SVN_USERNAME` and `SVN_PASSWORD` (the wordpress.org login) under Settings, Secrets and
variables, Actions. Details in `docs/wordpress-org-submission.md`.

## Translations

Every locale is served by WordPress.org, as a language pack built from
[translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/ibracodes-ai-assistant/).
WordPress loads it for a directory-hosted plugin on its own, so nothing in `languages/` is
shipped in the zip and the plugin makes no `load_plugin_textdomain()` call.

The folder stays in the repo as the source for that site. `languages/ibracodes-ai-assistant-he_IL.po`
is the Hebrew to import there; `languages/build-he.php` rebuilds it from the POT and fails loudly
on any string it has no translation for:

    wp i18n make-pot . languages/ibracodes-ai-assistant.pot --exclude=docs,tests,node_modules
    php languages/build-he.php

## Tests

PHP scripts that run inside a local WordPress through WP-CLI, and a Playwright harness that
drives the widget against a stub of the REST routes. See [tests/README.md](tests/README.md).

## Upgrading from 0.1.x

The plugin folder was renamed from `woocommerce-shop-agent` to `ibracodes-ai-assistant` in 0.2.0,
so an install created before 0.2.0 shows as inactive after the update and has to be activated
once more on the Plugins screen. Settings, conversations, leads and the content index are kept:
0.2.0 also moved every option, table and cron hook onto the `ibraai_` prefix WordPress.org
requires, and the first request after the update carries the old rows across by itself.

Two things do not survive the move. The admin page address changed, so an old bookmark to it
404s; reach it from the menu instead. And the token that identifies a conversation to a
returning visitor is hashed with a new salt, so a chat left open in a browser starts fresh.
Nothing stored is lost either way.

## Notes

Prices are shown on the product card only, rendered from WooCommerce, and the assistant is
stopped from writing any price into its answer. Figures written in the facts field (a
free-shipping threshold, for example) are exempt, because you put them there on purpose.
