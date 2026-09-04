# Shop Agent for WooCommerce

An AI shop assistant for the storefront. It searches the real catalog, recommends products the
customer can add to cart, and answers store questions from facts the owner writes. The store
uses its own OpenAI key and pays OpenAI directly.

Built by [Ibracodes](https://ibracodes.com).

## What it does

- **Finds products.** Every recommendation comes from a tool call against live WooCommerce data,
  so the agent cannot invent a product, a price or stock that does not exist.
- **Answers store questions** from the Store facts field, and says it does not know when the
  answer is not in there.
- **Hands off to a human** with a contact or WhatsApp button under the reply, when the customer asks for a person or it is stuck. With no destination set, it says so in words instead of pointing at a button.
- **Speaks the store's language** automatically, from the site locale. RTL supported, and the
  admin plus the widget ship translated in Hebrew.
- **Reports what customers actually asked**, including the questions the catalogue had no answer
  for, which is usually a product to stock or a word your titles never use.

## What it deliberately does not do

- Never touches the cart itself. The customer clicks the button on the product card, so a
  hallucinated add is impossible.
- Never looks up orders or customer data.
- Never sends email.
- Never acts as a general-purpose chatbot: content generation, roleplay and general knowledge
  questions are refused.

## Requirements

PHP 8.1+, WordPress 6.0+, WooCommerce 8.0+, and an OpenAI API key.

## Setup

1. Activate the plugin.
2. Go to **WooCommerce → Shop Agent**.
3. Paste an OpenAI key and press **Test connection**.
4. Fill in **Store facts** in your store's language: shipping, returns, warranty.
5. Tick **Show the chat to customers**.

The admin has five tabs: **Overview** (usage and reports), **Appearance** (launcher and copy),
**Agent** (key, model, house rules, behaviour, spending), **Catalogue** (what it may recommend)
and **Conversations** (transcripts and retention).

### Keeping the key out of the database

The key is stored in the site's options table by default, which means any administrator can read
it. On sites where that matters, define it in `wp-config.php` instead and the settings field
disappears:

```php
define( 'WSA_OPENAI_KEY', 'sk-...' );
```

## Spending

The store pays for its own usage. Five caps bound it, all editable under **Spending limits**:
per-visitor burst and daily limits, a store-wide daily and monthly cap on API calls, and a
concurrency cap that protects the server rather than the wallet. Usage for today and this month
is shown at the top of the settings screen.

One customer message can use two or three API calls, because the agent searches the catalog
before answering.

## Filters

| Filter | Purpose |
| --- | --- |
| `wsa_show_widget` | Return false to hide the chat on specific pages. |
| `wsa_models` | Add or replace the selectable models. |
| `wsa_currency_tokens` | Words that count as money for the price guard. |
| `wsa_client_ip` | Override how the visitor's address is resolved for rate limiting. |

## Not in this plugin

Some things a shop assistant could plausibly do are deliberately absent, because each one is a
liability the owner would carry:

- **No order lookups.** That means customer records and identity checks, which this does not do.
- **No cart mutation by the agent.** It recommends; the customer clicks.
- **No email.** Nothing is sent to anyone, ever.
- **No embeddings index.** It searches the live catalogue instead, so there is nothing to build,
  nothing to re-sync, and no stale copy of your products.

## Conversations and privacy

Threads are stored so the owner can read what customers asked, and deleted automatically after
the retention window (30 days by default, changeable, and switchable off entirely). What is
stored is the messages and the ids of products the agent showed. What is never stored: IP
address, email, name, user id, or anything about payment.

One boolean is recorded per thread: whether the customer added a recommended product to the
cart. That comes from WooCommerce's own `added_to_cart` event, so it only counts real adds.

In the browser, the conversation lives in the tab's session storage so it survives moving between
pages and following a product card. It ends with the tab; nothing is kept in the browser after
that beyond a flag that the launcher has been opened once.

## Notes

Prices are shown on the product card only, rendered from WooCommerce, and the agent is stopped
from writing any price into its answer. Figures written in Store facts (a free-shipping
threshold, for example) are exempt, because the owner put them there on purpose.
