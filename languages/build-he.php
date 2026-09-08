<?php
/**
 * Builds the Hebrew catalogue from the POT.
 *
 * Dev-only: never loaded by the plugin. Run it after regenerating the POT and
 * it fails loudly, listing every msgid it has no translation for, so a new
 * string cannot silently ship untranslated.
 *
 *   wp i18n make-pot . languages/ibracodes-ai-assistant.pot --exclude=docs,tests,node_modules
 *   php languages/build-he.php
 *   wp i18n make-mo languages/
 */

$dict = [
    // plugin header
    'Shop Agent for WooCommerce' => 'Shop Agent for WooCommerce',
    'https://ibracodes.com/resources/ibracodes-ai-assistant/' => 'https://ibracodes.com/resources/ibracodes-ai-assistant/',
    'https://ibracodes.com' => 'https://ibracodes.com',
    'An AI shop assistant for the storefront. It searches the real catalog, recommends products the customer can add to cart, and answers store questions from facts you write. Uses your own OpenAI key.' => 'עוזר חנות חכם לאתר. מחפש מוצרים אמיתיים בקטלוג, ממליץ על מוצרים שהלקוח יכול להוסיף לסל, ועונה על שאלות מתוך עובדות שאתם כותבים. עובד עם מפתח OpenAI שלכם.',
    'Ibracodes' => 'Ibracodes',
    'Shop Agent' => 'עוזר חנות',
    'Settings' => 'הגדרות',

    // admin shell
    'Testing…' => 'בודק…',
    'Test connection' => 'בדיקת חיבור',
    'You are not allowed to do that.' => 'אין לכם הרשאה לפעולה הזו.',
    'Live on the storefront' => 'פעיל באתר',
    'Not live' => 'לא פעיל',
    'Overview' => 'סקירה',
    'Appearance' => 'מראה',
    'Agent' => 'הסוכן',
    'Setup' => 'הגדרה',
    'Catalogue' => 'קטלוג',
    'Conversations' => 'שיחות',
    'Settings saved.' => 'ההגדרות נשמרו.',
    'The agent is off because no API key is connected. Customers see nothing until you add one.' => 'הסוכן כבוי כי לא חובר מפתח API. הלקוחות לא רואים כלום עד שתוסיפו מפתח.',
    'The agent is switched off. Customers see nothing until you turn it on.' => 'הסוכן כבוי. הלקוחות לא רואים כלום עד שתדליקו אותו.',
    'Connect now' => 'חברו עכשיו',
    'The last request to OpenAI failed with HTTP %1$d. %2$s (%3$s)' => 'הבקשה האחרונה ל-OpenAI נכשלה עם שגיאה %1$d. %2$s (%3$s)',
    'Save settings' => 'שמירת הגדרות',

    // overview
    'last 30 days' => 'ב-30 הימים האחרונים',
    'Replies sent' => 'תשובות שנשלחו',
    'Products shown' => 'מוצרים שהוצגו',
    'recommendations made' => 'המלצות שניתנו',
    'Added to cart' => 'נוסף לסל',
    'chats that led to a cart' => 'שיחות שהובילו לסל',
    'Questions the agent could not answer' => 'שאלות שהסוכן לא ידע לענות עליהן',
    'The catalogue search came back empty. Each one is a product you do not stock, or a word your product titles never use.' => 'החיפוש בקטלוג חזר ריק. כל שורה כאן היא מוצר שאין לכם, או מילה שלא מופיעה בשמות המוצרים שלכם.',
    'Nothing yet. Unanswered questions show up here as customers ask them.' => 'עדיין אין. שאלות ללא מענה יופיעו כאן כשלקוחות ישאלו אותן.',
    'Monthly usage' => 'שימוש חודשי',
    'of %s API calls' => 'מתוך %s קריאות API',
    '%1$s today of %2$s. At this pace the month ends near %3$s calls.' => '%1$s היום מתוך %2$s. בקצב הזה החודש ייגמר סביב %3$s קריאות.',
    'Top products shown' => 'המוצרים שהוצגו הכי הרבה',
    'No recommendations yet.' => 'עדיין אין המלצות.',
    '%s ago' => 'לפני %s',

    // appearance
    'Launcher' => 'כפתור הצ׳אט',
    'How the closed widget appears on the storefront.' => 'איך נראה הצ׳אט הסגור באתר.',
    'Corner' => 'פינה',
    'Bottom right' => 'ימין למטה',
    'Bottom left' => 'שמאל למטה',
    'side of the screen' => 'צד המסך',
    'Show a label next to the icon' => 'הצגת טקסט ליד האייקון',
    'A labelled pill gets noticed more than a bare bubble. It collapses to a circle on phones either way.' => 'כפתור עם טקסט מושך יותר תשומת לב מבועה ריקה. בנייד הוא מתכווץ לעיגול בכל מקרה.',
    'Label' => 'טקסט',
    'Accent colour' => 'צבע ראשי',
    'Used for the launcher, the customer\'s own messages and the add-to-cart button.' => 'משמש לכפתור הצ׳אט, להודעות הלקוח ולכפתור ההוספה לסל.',
    'Copy' => 'טקסטים',
    'Write these in your store\'s language. The agent replies in it automatically.' => 'כתבו אותם בשפת החנות. הסוכן עונה בה אוטומטית.',
    'Header title' => 'כותרת',
    'Header subtitle' => 'כותרת משנה',
    'Opening message' => 'הודעת פתיחה',
    'Opening chips' => 'שאלות מוצעות',
    'One per line, up to four. Shown as buttons under the opening message.' => 'אחת בכל שורה, עד ארבע. מוצגות ככפתורים מתחת להודעת הפתיחה.',
    'Changes go live on the storefront as soon as you save.' => 'השינויים עולים לאתר ברגע השמירה.',

    // agent
    'Connection' => 'חיבור',
    'The store uses its own OpenAI account and pays OpenAI directly for what the chat uses.' => 'החנות משתמשת בחשבון OpenAI שלה ומשלמת ל-OpenAI ישירות על השימוש בצ׳אט.',
    'Show the chat to customers' => 'הצגת הצ׳אט ללקוחות',
    'It only appears once a working key is saved.' => 'מופיע רק אחרי שנשמר מפתח תקין.',
    'OpenAI API key' => 'מפתח API של OpenAI',
    'Set in wp-config.php' => 'מוגדר ב-wp-config.php',
    'IBRAAI_OPENAI_KEY is defined, so the constant wins and this field is hidden.' => 'הקבוע IBRAAI_OPENAI_KEY מוגדר, ולכן הוא גובר והשדה הזה מוסתר.',
    'Stored in this site\'s database, so any administrator can read it. Where that matters, define IBRAAI_OPENAI_KEY in wp-config.php instead.' => 'נשמר במסד הנתונים של האתר, כך שכל מנהל יכול לקרוא אותו. אם זה משנה לכם, הגדירו במקום זאת IBRAAI_OPENAI_KEY ב-wp-config.php.',
    'Model' => 'מודל',
    'Shop questions rarely need the expensive model. Start cheap and move up only if answers disappoint.' => 'שאלות של לקוחות כמעט אף פעם לא צריכות את המודל היקר. התחילו בזול ועלו רק אם התשובות מאכזבות.',
    'House rules' => 'כללי הבית',
    'Plain sentences work better than a wall of instructions. Sent with every conversation.' => 'משפטים פשוטים עובדים טוב יותר מקיר של הוראות. נשלחים בכל שיחה.',
    'Store facts' => 'עובדות על החנות',
    'Free shipping over 50. Returns within 14 days. One year warranty.' => 'משלוח חינם מעל 399 ש"ח. החזרות תוך 14 יום. אחריות שנה.',
    'The only non-product information the agent may state as fact. Anything not written here, it will say it does not know.' => 'המידע היחיד שאינו על מוצרים שהסוכן רשאי למסור כעובדה. כל מה שלא כתוב כאן, הוא יגיד שאינו יודע.',
    'Behaviour' => 'התנהגות',
    'Products per reply' => 'מוצרים בכל תשובה',
    'Three or fewer keeps a reply readable on a phone.' => 'שלושה או פחות שומרים על תשובה קריאה בנייד.',
    'Ask one question before recommending' => 'שאלה אחת לפני המלצה',
    'On a vague request the agent asks a single clarifying question first. Better matches, one extra exchange.' => 'בבקשה כללית הסוכן ישאל קודם שאלת הבהרה אחת. התאמה טובה יותר, במחיר עוד סבב.',
    'Prices in the reply text' => 'מחירים בטקסט התשובה',
    'Card only' => 'רק בכרטיס',
    'recommended' => 'מומלץ',
    'Allow in text' => 'מותר בטקסט',
    'may go stale' => 'עלול להתיישן',
    'The card price is rendered from WooCommerce and is always correct. A price written into a sentence can be repeated later after it changed.' => 'המחיר בכרטיס נלקח מווקומרס והוא תמיד נכון. מחיר שנכתב בתוך משפט עלול לחזור בהמשך השיחה אחרי שהשתנה.',
    'Handoff to a person' => 'מעבר לנציג אנושי',
    'Offered as a button when the agent cannot help.' => 'מוצע ככפתור כשהסוכן לא מצליח לעזור.',
    'Chip label' => 'טקסט הכפתור',
    'Destination' => 'יעד',
    'Your contact page, or a wa.me link for WhatsApp.' => 'עמוד יצירת הקשר שלכם, או קישור wa.me לוואטסאפ.',
    'Spending limits' => 'מגבלות הוצאה',
    'Every conversation costs you money at OpenAI. These caps are what stands between a bored bot and a large invoice. One reply can use two or three calls.' => 'כל שיחה עולה לכם כסף ב-OpenAI. המגבלות האלה הן מה שעומד בין בוט משועמם לחשבונית גדולה. תשובה אחת יכולה לצרוך שתיים-שלוש קריאות.',
    'Messages per visitor, per 10 minutes' => 'הודעות לכל מבקר, ב-10 דקות',
    'Messages per visitor, per day' => 'הודעות לכל מבקר, ביום',
    'Stops one person using up the store\'s budget.' => 'מונע ממשתמש אחד לשרוף את התקציב של החנות.',
    'API calls for the whole store, per day' => 'קריאות API לכל החנות, ביום',
    'The hard daily cost bound.' => 'תקרת העלות היומית הקשיחה.',
    'API calls for the whole store, per month' => 'קריאות API לכל החנות, בחודש',
    'Requests at the same time' => 'בקשות במקביל',
    'Protects your server rather than your wallet.' => 'מגן על השרת שלכם, לא על הארנק.',

    // catalogue
    'Which products the agent may recommend' => 'על אילו מוצרים הסוכן רשאי להמליץ',
    'Exclude anything you do not want a bot selling unattended. Excluded categories are invisible to the agent: it cannot find them, mention them or link to them.' => 'החריגו כל מה שאתם לא רוצים שבוט ימכור בלי השגחה. קטגוריות שהוחרגו אינן נראות לסוכן: הוא לא ימצא אותן, לא יזכיר אותן ולא יקשר אליהן.',
    'Only recommend products in stock' => 'המלצה רק על מוצרים במלאי',
    'Off means the agent may show out-of-stock products, marked as such on the card.' => 'כשכבוי, הסוכן עשוי להציג מוצרים שאזלו, עם סימון בכרטיס.',
    'Excluded categories' => 'קטגוריות מוחרגות',
    'This store has no product categories yet.' => 'אין עדיין קטגוריות מוצרים בחנות.',
    'Child categories are excluded along with their parent.' => 'קטגוריות משנה מוחרגות יחד עם קטגוריית האב.',
    'How the agent searches' => 'איך הסוכן מחפש',
    'It searches your live catalogue on every question: product titles, descriptions and SKUs. There is no index to build and nothing to keep in sync, so a product you publish is findable immediately, and one you unpublish disappears the same second.' => 'הוא מחפש בקטלוג החי שלכם בכל שאלה: שמות מוצרים, תיאורים ומק"טים. אין אינדקס לבנות ואין מה לסנכרן, כך שמוצר שתפרסמו יימצא מיד, ומוצר שתורידו ייעלם באותו רגע.',

    // conversations
    'Threads are kept %s days, then deleted. No email, name or payment detail is stored: only the messages and the product ids the agent showed.' => 'שיחות נשמרות %s ימים ואז נמחקות. לא נשמרים אימייל, שם או פרטי תשלום: רק ההודעות ומזהי המוצרים שהוצגו.',
    'No conversations yet.' => 'עדיין אין שיחות.',
    'First question' => 'שאלה ראשונה',
    'When' => 'מתי',
    'Turns' => 'סבבים',
    'Shown' => 'הוצגו',
    'Outcome' => 'תוצאה',
    '(no question recorded)' => '(לא נרשמה שאלה)',
    'No match' => 'אין התאמה',
    'Recommended' => 'ניתנה המלצה',
    'Answered' => 'נענתה',
    'Previous' => 'הקודם',
    'Page %1$s of %2$s' => 'עמוד %1$s מתוך %2$s',
    'Next' => 'הבא',
    'Recording' => 'תיעוד',
    'Keep a record of conversations' => 'שמירת תיעוד של שיחות',
    'Off means nothing is written at all. You lose the reports on the overview.' => 'כשכבוי, לא נשמר כלום. תאבדו את הדוחות בעמוד הסקירה.',
    'Keep for (days)' => 'לשמור למשך (ימים)',
    'Older threads are deleted automatically once a day.' => 'שיחות ישנות נמחקות אוטומטית פעם ביום.',
    'Delete every stored conversation when I save' => 'מחקו את כל השיחות השמורות בשמירה',
    'All conversations' => 'כל השיחות',
    'That conversation is gone. It may have passed the retention window.' => 'השיחה הזו כבר לא קיימת. ייתכן שעברה את תקופת השמירה.',
    'Conversation' => 'שיחה',
    '%1$s · %2$s turns' => '%1$s · %2$s סבבים',
    '(deleted product)' => '(מוצר שנמחק)',

    // agent runtime
    'No message received.' => 'לא התקבלה הודעה.',
    'Here is what I found that might suit you:' => 'הנה מה שמצאתי שיכול להתאים:',
    'I could not answer that one. Try rephrasing, or get in touch and a person will help.' => 'לא הצלחתי לענות על זה. נסו לנסח מחדש, או פנו אלינו ונציג יעזור.',
    'That is a lot of messages at once. Try again in a few minutes.' => 'זה הרבה הודעות בבת אחת. נסו שוב בעוד כמה דקות.',
    'You have reached today\'s chat limit. Try again tomorrow, or use the contact page.' => 'הגעתם למכסת ההודעות היומית. נסו שוב מחר, או פנו אלינו דרך עמוד יצירת הקשר.',
    'The chat is busy right now. Please try again later.' => 'הצ׳אט עמוס כרגע. נסו שוב מאוחר יותר.',
    'The chat is unavailable right now. Please use the contact page.' => 'הצ׳אט לא זמין כרגע. פנו אלינו דרך עמוד יצירת הקשר.',
    'The chat is busy right now. Try again in a moment.' => 'הצ׳אט עמוס כרגע. נסו שוב בעוד רגע.',
    'the contact option' => 'עמוד יצירת הקשר',
    'The chat is not configured.' => 'הצ׳אט לא מוגדר.',
    'OpenAI rejected that key.' => 'OpenAI דחה את המפתח הזה.',
    'The key works, but the account is out of quota or rate limited.' => 'המפתח תקין, אבל לחשבון נגמרה המכסה או שהוא מוגבל בקצב.',
    'The key works, but this account cannot use the selected model.' => 'המפתח תקין, אבל החשבון הזה לא יכול להשתמש במודל שנבחר.',
    'OpenAI returned an error (HTTP %d).' => 'OpenAI החזיר שגיאה (HTTP %d).',
    'The chat is unavailable right now. Please try again.' => 'הצ׳אט לא זמין כרגע. נסו שוב.',
    'The chat is not available right now.' => 'הצ׳אט לא זמין כרגע.',
    'Add a key first.' => 'הוסיפו קודם מפתח.',
    'Connected. The key works and the model is available.' => 'מחובר. המפתח עובד והמודל זמין.',
    '(price is on the card below)' => '(המחיר בכרטיס למטה)',

    // widget defaults and UI
    'Ask us anything' => 'שאלו אותנו הכל',
    'Have a question?' => 'יש לכם שאלה?',
    'Ask me anything' => 'אפשר לשאול אותי הכל',
    'Hi, can I help you find a product or answer a question?' => 'היי, אפשר לעזור למצוא מוצר או לענות על שאלה?',
    'gpt-5-mini (recommended, cheapest)' => 'gpt-5-mini (מומלץ, הזול ביותר)',
    'gpt-5 (best answers, costs more)' => 'gpt-5 (התשובות הטובות ביותר, יקר יותר)',
    'gpt-4.1-mini' => 'gpt-4.1-mini',
    'Contact us' => 'דברו איתנו',
    'Open chat' => 'פתיחת הצ׳אט',
    'Close chat' => 'סגירת הצ׳אט',
    'Type your question' => 'כתבו את השאלה',
    'Send' => 'שליחה',
    'Typing' => 'מקליד',
    'Add to cart' => 'הוספה לסל',
    'View product' => 'לצפייה במוצר',
    'Out of stock' => 'אזל מהמלאי',
    'Sale' => 'מבצע',
    'Something went wrong. Please try again.' => 'משהו השתבש. נסו שוב.',
    'Chat conversation' => 'שיחת צ׳אט',

    // activation
    'Shop Agent requires WooCommerce 8.0 or later to be installed and active. The plugin has been deactivated.' => 'עוזר חנות דורש ווקומרס 8.0 ומעלה מותקן ופעיל. התוסף כובה.',
    'Shop Agent requires PHP 8.1 or later.' => 'עוזר חנות דורש PHP 8.1 ומעלה.',
    'Plugin activation error' => 'שגיאה בהפעלת התוסף',
    'Shop Agent requires WooCommerce 8.0 or later. Install and activate WooCommerce first, then reactivate this plugin.' => 'עוזר חנות דורש ווקומרס 8.0 ומעלה. התקינו והפעילו קודם ווקומרס, ואז הפעילו מחדש את התוסף.',

    // 0.2.0: the general assistant
    'IbraCodes AI Assistant' => 'IbraCodes AI Assistant',
    'An AI assistant for any WordPress site. It answers from your pages and posts and the facts you write, captures leads, and on WooCommerce stores recommends products the customer can add to cart. Uses your own OpenAI key.' => 'עוזר AI לכל אתר וורדפרס. עונה מתוך העמודים והפוסטים שלכם ומתוך עובדות שאתם כותבים, אוסף לידים, ובחנויות ווקומרס ממליץ על מוצרים שהלקוח יכול להוסיף לסל. עובד עם מפתח OpenAI שלכם.',
    'Another copy of IbraCodes AI Assistant is already active. Deactivate one of them.' => 'עותק נוסף של IbraCodes AI Assistant כבר פעיל. כבו אחד מהם.',
    'IbraCodes AI Assistant requires PHP 8.1 or later.' => 'IbraCodes AI Assistant דורש PHP 8.1 ומעלה.',
    'AI Assistant' => 'עוזר AI',
    'Rebuilding…' => 'בונה מחדש…',
    'Request failed.' => 'הבקשה נכשלה.',

    // content
    'Content' => 'תוכן',
    'The pages and posts the assistant may read and answer from. Drafts, private and password-protected content are never included.' => 'העמודים והפוסטים שהעוזר רשאי לקרוא ולענות מתוכם. טיוטות, תוכן פרטי ותוכן מוגן בסיסמה לעולם לא נכללים.',
    'Content types' => 'סוגי תוכן',
    'Which pages' => 'אילו עמודים',
    'All published pages and posts' => 'כל העמודים והפוסטים שפורסמו',
    'Only the pages listed below' => 'רק העמודים שברשימה למטה',
    'Pages' => 'עמודים',
    'Page ids, comma separated. The id is in the address bar when you edit a page.' => 'מזהי עמודים, מופרדים בפסיקים. המזהה מופיע בשורת הכתובת כשעורכים עמוד.',
    'How the assistant finds content' => 'איך העוזר מוצא תוכן',
    'WordPress search (free)' => 'חיפוש וורדפרס (חינם)',
    'Embeddings index (better answers)' => 'אינדקס embeddings (תשובות טובות יותר)',
    'Building the index costs about one cent per hundred pages once, then a fraction of that per question. It uses your OpenAI key and counts against your daily and monthly limits.' => 'בניית האינדקס עולה בערך סנט אחד לכל מאה עמודים, פעם אחת, ואחר כך שבריר מזה לכל שאלה. היא משתמשת במפתח OpenAI שלכם ונספרת במגבלות היומיות והחודשיות.',
    'Indexed %1$s of %2$s pages, %3$s waiting.' => 'באינדקס %1$s מתוך %2$s עמודים, %3$s ממתינים.',
    'Rebuild index' => 'בנייה מחדש של האינדקס',
    'Rebuilding, %s pages queued.' => 'בונה מחדש, %s עמודים בתור.',
    'Switch retrieval to the embeddings index first.' => 'קודם העבירו את החיפוש לאינדקס ה-embeddings.',
    'Add an OpenAI key first.' => 'הוסיפו קודם מפתח OpenAI.',
    'The embeddings service returned the wrong number of vectors.' => 'שירות ה-embeddings החזיר מספר וקטורים שגוי.',

    // leads
    'Leads' => 'לידים',
    'Leads, 30 days' => 'לידים, 30 יום',
    'visitors who left their details' => 'מבקרים שהשאירו פרטים',
    'Offer to take the visitor\'s details' => 'הצעה לקחת את פרטי המבקר',
    'The assistant asks for a name and a phone or email, saves the lead and emails you.' => 'העוזר מבקש שם וטלפון או אימייל, שומר את הליד ושולח לכם אימייל.',
    'When to offer' => 'מתי להציע',
    'One line, for example: when someone wants a quote or a callback.' => 'שורה אחת, למשל: כשמישהו רוצה הצעת מחיר או שיחה חוזרת.',
    'Send leads to' => 'לשלוח לידים אל',
    'Keep leads for (days)' => 'לשמור לידים למשך (ימים)',
    'Older leads are deleted once a day, along with the conversation they came from.' => 'לידים ישנים נמחקים פעם ביום, יחד עם השיחה שממנה הגיעו.',
    'Note under the chat input' => 'הערה מתחת לשדה הכתיבה בצ׳אט',
    'Say that details typed here are passed to the site owner and kept with the conversation.' => 'ציינו שפרטים שנכתבים כאן מועברים לבעל האתר ונשמרים יחד עם השיחה.',
    'Leads are kept %s days, then deleted along with the conversation they came from.' => 'לידים נשמרים %s ימים ואז נמחקים יחד עם השיחה שממנה הגיעו.',
    'Search leads' => 'חיפוש לידים',
    'Search' => 'חיפוש',
    'Export CSV' => 'ייצוא CSV',
    'No leads yet. Turn on lead capture on the Agent tab.' => 'עדיין אין לידים. הפעילו איסוף לידים בלשונית הסוכן.',
    'No leads match that search.' => 'אין לידים שמתאימים לחיפוש.',
    'Name' => 'שם',
    'Contact' => 'פרטי קשר',
    'Request' => 'בקשה',
    'Page' => 'עמוד',
    'Email' => 'אימייל',
    'Sent' => 'נשלח',
    'Failed' => 'נכשל',
    'Delete this lead and the conversation it came from?' => 'למחוק את הליד הזה ואת השיחה שממנה הגיע?',
    'Delete' => 'מחיקה',
    'Lead deleted, along with its conversation.' => 'הליד נמחק, יחד עם השיחה שלו.',
    'Updated lead from the AI Assistant: %s' => 'ליד מעודכן מעוזר ה-AI: %s',
    'New lead from the AI Assistant: %s' => 'ליד חדש מעוזר ה-AI: %s',
    'Name: %s' => 'שם: %s',
    'Contact: %s' => 'פרטי קשר: %s',
    'Request: %s' => 'בקשה: %s',
    'Page: %s' => 'עמוד: %s',
    'Conversation: %s' => 'שיחה: %s',
    'All leads: %s' => 'כל הלידים: %s',

    // live chat, settings
    'Live chat' => 'צ׳אט חי',
    'A visitor who asks for a person waits for you in the Live chats tab. The AI pauses until you answer or the wait runs out.' => 'מבקר שמבקש נציג ממתין לכם בלשונית צ׳אטים חיים. ה-AI עוצר עד שתענו או עד שזמן ההמתנה ייגמר.',
    'Let visitors ask for a person' => 'לאפשר למבקרים לבקש נציג',
    'Turning it on also turns conversation logging on: a live chat lives on the conversation record.' => 'הפעלה שלו מפעילה גם את תיעוד השיחות: צ׳אט חי נשמר על רשומת השיחה.',
    'Send requests to' => 'לשלוח בקשות אל',
    'One email per request, with a link to the conversation.' => 'אימייל אחד לכל בקשה, עם קישור לשיחה.',
    'Wait for a person (minutes)' => 'המתנה לנציג (דקות)',
    'If nobody joins in time, the visitor is offered lead capture and the contact option. The request stays in the list, so you can still answer later.' => 'אם אף אחד לא מצטרף בזמן, המבקר מקבל הצעה להשאיר פרטים ואת אפשרות יצירת הקשר. הבקשה נשארת ברשימה, כך שעדיין אפשר לענות מאוחר יותר.',
    'Waiting text' => 'טקסט המתנה',
    'Joined text' => 'טקסט הצטרפות',
    'Missed text' => 'טקסט אי-מענה',
    'Closed text' => 'טקסט סיום',
    'In the joined and closed texts, %s becomes the name of the person who joined.' => 'בטקסטים של ההצטרפות והסיום, %s הופך לשם של מי שהצטרף.',

    // live chat, console
    'Live chats' => 'צ׳אטים חיים',
    'Live chat is off. With it on, a visitor who asks for a person waits here for you to answer, and the AI pauses until you do.' => 'צ׳אט חי כבוי. כשהוא פעיל, מבקר שמבקש נציג ממתין כאן שתענו, וה-AI עוצר עד שתעשו זאת.',
    'Turn it on in the Agent tab' => 'הפעילו אותו בלשונית הסוכן',
    'Pick a conversation from the list.' => 'בחרו שיחה מהרשימה.',
    'No one is waiting.' => 'אף אחד לא ממתין.',
    'Waiting under a minute' => 'ממתין פחות מדקה',
    'Waiting %s min' => 'ממתין %s דק׳',
    'Waiting %s h' => 'ממתין %s שע׳',
    'Waiting %s d' => 'ממתין %s ימים',
    '%s unread' => '%s שלא נקראו',
    'Session expired. Reload the page.' => 'פג תוקף ההתחברות. טענו את העמוד מחדש.',
    'That conversation no longer exists.' => 'השיחה הזו כבר לא קיימת.',
    'Claim' => 'הצטרפות לשיחה',
    'Write a reply' => 'כתבו תשובה',
    'Claim the chat to reply' => 'הצטרפו לשיחה כדי לענות',
    'Replying takes over from %s' => 'תשובה תעביר את השיחה מ-%s אליכם',
    'Visitor' => 'מבקר',
    'Assistant' => 'העוזר',
    'Manager' => 'נציג',
    'Loading…' => 'טוען…',
    'Waiting' => 'בהמתנה',
    'Live' => 'לייב',
    'Missed' => 'לא נענתה',
    'Closed' => 'נסגרה',
    'AI' => 'AI',
    'A visitor is waiting for a person' => 'מבקר ממתין לנציג',
    'Answer here: %s' => 'לענות כאן: %s',
    'Question: %s' => 'שאלה: %s',

    // live chat, runtime
    'A person has this conversation right now.' => 'נציג מטפל בשיחה הזו כרגע.',
    'No person is on this chat right now.' => 'אין נציג בשיחה הזו כרגע.',
    'Claim the chat before replying.' => 'הצטרפו לשיחה לפני שעונים.',
    'This conversation could not be verified.' => 'לא ניתן היה לאמת את השיחה הזו.',
    'Too many requests. Slow down a little.' => 'יותר מדי בקשות. האטו קצת.',
    'Live chat is not available right now.' => 'צ׳אט חי לא זמין כרגע.',
    'Write something first.' => 'כתבו משהו קודם.',
    'A person will join this chat shortly.' => 'נציג יצטרף לשיחה בקרוב.',
    'A person will join this chat shortly. You can keep writing in the meantime.' => 'נציג יצטרף לשיחה בקרוב. אפשר להמשיך לכתוב בינתיים.',
    '%s joined the chat.' => '%s הצטרף/ה לשיחה.',
    'Nobody is available right now. Leave your details and we will get back to you, or use the contact option below.' => 'אף אחד לא זמין כרגע. השאירו פרטים ונחזור אליכם, או השתמשו באפשרות יצירת הקשר למטה.',
    'The chat with %s has ended. I can keep helping here.' => 'השיחה עם %s הסתיימה. אפשר להמשיך לשאול אותי כאן.',
    'Write to %s' => 'כתבו ל-%s',

    // credit line
    'Show "Developed by Ibracodes" under the chat' => 'הצגת "פותח על ידי Ibracodes" מתחת לצ׳אט',
    'A small credit line linking to ibracodes.com. Off by default.' => 'שורת קרדיט קטנה עם קישור ל-ibracodes.com. כבויה כברירת מחדל.',
    'Developed by Ibracodes' => 'פותח על ידי Ibracodes',
];

$pot = __DIR__ . '/ibracodes-ai-assistant.pot';
$lines = file($pot, FILE_IGNORE_NEW_LINES);
$msgids = [];
foreach ($lines as $line) {
    if (str_starts_with($line, 'msgid "') && $line !== 'msgid ""') {
        $msgids[] = stripcslashes(substr($line, 7, -1));
    }
}

$missing = array_values(array_filter($msgids, static fn ($id) => ! isset($dict[$id])));
if ($missing) {
    fwrite(STDERR, 'MISSING TRANSLATIONS (' . count($missing) . "):\n");
    foreach ($missing as $id) {
        fwrite(STDERR, '  - ' . $id . "\n");
    }
    exit(1);
}

$out = [
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: IbraCodes AI Assistant\n"',
    '"Language: he_IL\n"',
    '"MIME-Version: 1.0\n"',
    '"Content-Type: text/plain; charset=UTF-8\n"',
    '"Content-Transfer-Encoding: 8bit\n"',
    '"Plural-Forms: nplurals=2; plural=(n != 1);\n"',
    '',
];
foreach ($msgids as $id) {
    $out[] = 'msgid "' . addcslashes($id, "\"\\\n") . '"';
    $out[] = 'msgstr "' . addcslashes($dict[$id], "\"\\\n") . '"';
    $out[] = '';
}

file_put_contents(__DIR__ . '/ibracodes-ai-assistant-he_IL.po', implode("\n", $out));
echo 'PO written: ' . count($msgids) . " translations\n";
