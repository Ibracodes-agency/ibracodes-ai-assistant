=== IbraCodes AI Assistant ===
Contributors: ibracodes
Tags: ai, chat, assistant, leads, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI assistant for your site: answers from your pages, captures leads, hands visitors to a person, and recommends WooCommerce products.

== Description ==

IbraCodes AI Assistant adds a chat widget to any WordPress site. It answers visitors from your own pages and posts and from facts you write, so it never invents what your business offers. On a WooCommerce store it also searches the live catalog and shows product cards the customer can add to the cart.

**What it does**

* Answers from your content. Choose which pages and post types the assistant may read. Drafts, private and password-protected content are never used.
* Knows the page the visitor is on, so questions about "this package" or "this service" work without a search.
* Two ways to find content: WordPress search (free, default) or an embeddings index you can switch on for better answers. The index costs about one cent per hundred pages to build, using your own OpenAI key.
* Captures leads. When a visitor wants a quote or a callback, the assistant asks for a name and a phone or email, saves the lead and emails you. Leads have their own retention setting and deleting a lead deletes its conversation too.
* Hands off to a person. A visitor who asks for someone waits for you in the Live chats screen inside the WordPress admin; you get an email with a link, answer in real time, and close the chat when done. If nobody joins within the wait time, the visitor is offered lead capture and your contact button instead.
* WooCommerce optional. With WooCommerce active, the assistant searches products, shows cards with price and stock, and lets the customer add to cart. It never places orders, applies discounts or looks up customer data.
* Your language, automatically. The assistant answers in the language of your site.
* Spending limits. Daily and monthly caps on API calls, per-visitor rate limits, and a cost overview in the admin.

**Third-party services**

This plugin sends data to OpenAI's API (https://openai.com/) using an API key you enter. What is sent: the visitor's messages, the passages of your pages the assistant found relevant, your written facts, and, when the embeddings index is on, the text of the pages you allowed. Nothing is sent until you add a key. OpenAI's terms: https://openai.com/policies/terms-of-use. OpenAI's privacy policy: https://openai.com/policies/privacy-policy. No data is sent to IbraCodes or to any other service.

**Privacy**

Conversations can be stored on your site so you can read what visitors asked; they are deleted automatically after a retention period you choose, and storage can be turned off. No IP address, email or name is stored as a field. Details a visitor types into the chat are part of the stored conversation. Leads hold the name and contact the visitor gave on purpose.

== Installation ==

1. Install and activate the plugin.
2. Open AI Assistant in the admin menu (under WooCommerce on a store, or as its own menu item).
3. On the Agent tab, add your OpenAI API key and test the connection.
4. Choose which content the assistant may read, write your facts, and switch on lead capture or live chat if you want them.
5. Turn on "Show the chat to visitors".

== Frequently Asked Questions ==

= Does it need WooCommerce? =

No. It works on any WordPress site. With WooCommerce active it also gets product search and cart cards.

= What does it cost to run? =

You pay OpenAI for what the assistant uses. A typical conversation costs a fraction of a cent on the default model. The admin shows usage against your caps.

= Can it invent products, prices or policies? =

No. Products come only from your catalog, content only from the pages you allowed, and facts only from what you wrote. When it finds nothing it says so and offers your contact option.

= Where do leads and conversations go? =

Into your own database, under your retention settings. Nothing leaves your site except the API calls to OpenAI described above.

= How does live chat work? =

A visitor who asks for a person waits in the Live chats screen. You get an email with a link, claim the chat and reply; the visitor sees your replies in the widget. If nobody joins within the wait time, the visitor is offered lead capture and your contact button, and the request stays in your list to answer later.

== Screenshots ==

1. The chat widget answering from the site's pages.
2. Product cards inside the chat on a WooCommerce store.
3. The Agent settings: key, content, leads and live chat.
4. The Live chats screen with a waiting visitor.

== Changelog ==

= 0.2.0 =
* Works on any WordPress site: answers from pages and posts, with an optional embeddings index.
* Knows the page the visitor is on.
* Lead capture with owner email and its own retention.
* Live chat with a person from the WordPress admin.
* WooCommerce becomes optional.
* Optional "Developed by Ibracodes" credit line, off by default.

= 0.1.2 =
* The contact button the agent talks about now exists.

= 0.1.1 =
* The conversation survives page changes.

= 0.1.0 =
* First release: catalog-grounded chat for WooCommerce.
