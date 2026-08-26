# Design brief: Shop Agent chat widget

Paste this whole file into Claude Design. It describes what to design, every state it must
cover, and the technical contract the result has to satisfy so it can be dropped straight into
the plugin.

---

## What this is

A floating chat widget that sits on the storefront of a WooCommerce shop. A customer opens it,
asks about products in their own words, and the agent replies with a short answer plus real
product cards they can add to the cart.

It is a **product sold to many different shops**, not a one-off for one site. It gets injected
into themes we have never seen, next to whatever else is on the page. So it has to look
deliberate and self-assured on a page it does not control, and it must never look like it
borrowed the host theme's styling by accident.

**Audience:** a shopper mid-purchase, often on a phone, often impatient. The widget's job is to
get them to a product card and a working add-to-cart button in as few beats as possible. It is a
sales surface, not a support console.

---

## Non-negotiable technical contract

These are integration requirements, not style preferences. The design is free everywhere else.

1. **One root element.** Everything lives inside a single `<div class="wsa-root">`. It is
   `position: fixed` and pinned to a bottom corner.
2. **Every class starts with `wsa-`.** Keep the class names listed in the inventory below. If a
   design needs a new element, name it `wsa-something`.
3. **Self-contained CSS.** Plain CSS in one `<style>` block. No Tailwind, no CSS framework, no
   external stylesheet, no CDN, no webfont, no icon font, no build step. Icons must be **inline
   SVG**. The only remote asset is the product image, which comes from the shop.
4. **No JavaScript.** Deliver static HTML per state. The plugin builds this markup in vanilla JS
   at runtime.
5. **Assume a hostile host theme.** Set every property you rely on explicitly (`box-sizing`,
   `font-family`, `font-size`, `line-height`, `color`, `background`, `margin`, `padding`,
   `border-radius`, `text-align`). Never inherit from the page. Never use `!important`.
6. **One accent variable.** The shop owner picks their own accent colour in the plugin settings.
   Expose it as `--wsa-accent` on `.wsa-root` and derive everything from it. **The design must
   still look right when the accent is hot pink, black, or a pale yellow**, so do not rely on the
   accent being dark or being any particular hue. If you need a contrasting on-accent text
   colour, keep it fixed (white or near-black), do not compute it.
7. **RTL and LTR from one stylesheet.** Use logical properties only: `inset-inline-start`,
   `margin-inline-end`, `padding-inline`, `text-align: start`. No `left`, `right`,
   `margin-left`, `margin-right` anywhere. Directional glyphs like a send arrow flip with
   `[dir="rtl"] .wsa-send svg { transform: scaleX(-1); }`.
8. **Position variants.** `.wsa-root[data-position="right"]` and `[data-position="left"]` pin the
   widget to that corner. This is independent of text direction.
9. **Light only.** Do not add a dark-mode media query. A storefront has its own palette and
   guessing from the visitor's OS clashes more often than it helps.
10. **Accessibility floor.** Visible `:focus-visible` on every control, minimum 44x44px touch
    targets, text contrast at least 4.5:1, and a `@media (prefers-reduced-motion: reduce)` block
    that disables animation.

---

## Element inventory

The plugin generates exactly these elements. Style all of them; do not rename them.

### Shell
| Class | Element | Notes |
| --- | --- | --- |
| `.wsa-root` | div | Fixed wrapper. Carries `data-position`, `.is-open`, `.is-busy`. |
| `.wsa-launcher` | button | The closed-state bubble. Hidden while `.wsa-root.is-open`. |
| `.wsa-panel` | div | The chat window. `role="dialog"`, uses the `hidden` attribute when closed. |
| `.wsa-head` | div | Panel header. |
| `.wsa-head-text` | div | Wraps title and subtitle. |
| `.wsa-title` | span | Owner-set text, e.g. "Have a question?" |
| `.wsa-subtitle` | span | Owner-set, optional, may be absent. |
| `.wsa-close` | button | Contains inline SVG. |
| `.wsa-body` | div | Scrolling message area, `role="log"`. |
| `.wsa-form` | form | Input row, pinned to the bottom of the panel. |
| `.wsa-input` | input[type=text] | |
| `.wsa-send` | button | Contains inline SVG arrow. Gets `disabled` while a reply is loading. |

### Messages
| Class | Notes |
| --- | --- |
| `.wsa-msg.is-assistant` | Agent bubble, aligned to the start edge. |
| `.wsa-msg.is-user` | Customer bubble, aligned to the end edge, usually accent-filled. |
| `.wsa-msg-text` | The bubble itself. Must handle `white-space: pre-wrap` and long unbroken strings (`overflow-wrap: anywhere`). |
| `.wsa-typing` | A `.wsa-msg` variant containing three `<span>` dots to animate. |

### Suggestion chips
| Class | Notes |
| --- | --- |
| `.wsa-chips` | Wrapping row. Appears under the opening message and under each reply. |
| `.wsa-chip` | A `<button>`. Short follow-up question, up to about 5 words. |
| `.wsa-chip.is-handoff` | An `<a>`, not a button. The "talk to a person" escape hatch. Should read as more prominent than a normal chip. |

### Product cards
This is the most important part of the design. It is where the money is.

| Class | Notes |
| --- | --- |
| `.wsa-cards` | Container for 1 to 4 cards. |
| `.wsa-card` | One product. |
| `.wsa-card-media` | `<a>` wrapping the product `<img>`. Position context for the badge. |
| `.wsa-badge` | Sale flash. Only present when the product is on sale. |
| `.wsa-card-info` | Text column. |
| `.wsa-card-name` | `<a>`. **Can be very long** (60+ characters is normal in this catalog) and must not be truncated to the point of being useless. |
| `.wsa-card-price` | Contains **WooCommerce's own price HTML**, which you do not control. It may include `<del>` and `<ins>` for sale prices, `<span class="woocommerce-Price-amount">`, and a currency symbol either side of the number. Style `del` (muted, struck) and `ins` (no underline, emphasised). |
| `.wsa-card-stock` | Only present when out of stock. |
| `.wsa-card-btn` | The add-to-cart button. **Must sit flush at the bottom of the card**, so that a column of cards with different name lengths shows all buttons on a consistent baseline. |
| `.wsa-card-btn.is-ghost` | Secondary variant, used for "View product" when the item has options and cannot be added directly. |
| `.added_to_cart` | **WooCommerce injects this link itself** after a successful add ("View cart"). Not our markup, but it lands inside `.wsa-card-info` and must not look broken. |

---

## States to design

Design every one of these. Label each clearly in the deliverable.

1. **Closed.** Just the launcher in the corner.
2. **Open, fresh.** Welcome message plus 2 to 4 opening chips, empty input.
3. **In conversation.** A customer message, an agent reply, a chip row.
4. **Thinking.** The three-dot typing bubble while waiting for an answer.
5. **One product card.** Agent reply plus a single card, in stock, add-to-cart available.
6. **Three product cards.** Deliberately give them **different name lengths**, one on sale with a
   badge and a struck-through price, one out of stock, one "View product" ghost button. This
   state proves the button baseline and the price markup.
7. **Error.** The agent bubble carrying a failure or rate-limit message, followed by the handoff
   chip.
8. **Busy.** Send button disabled while a reply is in flight.
9. **Mobile, under 480px.** The panel should behave like a sheet. Decide whether it goes full
   height; today it is capped so the page stays partly visible.
10. **RTL.** The whole panel mirrored, with Hebrew sample text. Set `dir="rtl"` on a wrapper to
    demonstrate it.
11. **Focus.** Show the `:focus-visible` treatment on the launcher, a chip, and the input.

---

## Current implementation, for reference only

Change any of this if the design is better for it. It is what exists today, not a constraint.

- Panel `width: min(380px, calc(100vw - 32px))`, `height: min(560px, calc(100vh - 110px))`.
- Launcher 56px circle.
- Product thumbnail 66px square, `object-fit: cover`.
- Radii 14px panel, 13px bubbles, 11px cards, 100px chips.
- Body background is a light tint so white bubbles and cards separate from it.
- Panel is pinned 20px from the bottom and side, 12px on mobile.

## Sample content to design with

Use realistic content, not lorem. This is a Hebrew security-camera shop, so the RTL states should
use text like this.

- Title: `עוזר חנות` / Subtitle: `שאלו אותי הכל`
- Welcome: `היי, אפשר לעזור למצוא מוצר או לענות על שאלה?`
- Chips: `מצלמת אבטחה לבית` / `מה זמן האחריות?` / `עוד דגמים?`
- Customer: `אני מחפש מצלמת אבטחה לבית, מה אתם ממליצים?`
- Agent: `מצאתי מצלמת אבטחה ביתית שמתאימה לבית, עם סיבוב וראיית לילה. אפשר להוסיף לסל ישירות מהכרטיס.`
- Product: `מצלמת אבטחה ביתית TP-Link Tapo C210 Pan/Tilt 2K` at `189.00₪`
- Long product name for the alignment test: `מערכת 4 מצלמות אבטחה Reolink NVS16 8MP 4K עם NVR וכונן קשיח`
- Error: `הצ׳אט עמוס כרגע, נסו שוב בעוד רגע.`
- Buttons: `הוספה לסל` / `לצפייה במוצר` / `דברו איתנו`

An English LTR version of the same states should use: "Have a question?", "Ask me anything",
"Add to cart", "View product", "Talk to a person".

---

## Deliverable

**One HTML file.** All CSS inline in a single `<style>` block at the top. Each state as its own
labelled section, stacked down the page, so every state can be seen at once and lifted
individually. Static markup, no scripts.

I will lift the markup and CSS from it directly, so the class names in the inventory above have
to match exactly.
