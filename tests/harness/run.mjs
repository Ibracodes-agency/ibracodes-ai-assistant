/**
 * Drives the widget in headless Chrome against a stub of the plugin's REST
 * routes: /chat, the two visitor live-chat routes, and a test hook that plays
 * the manager. The stub keeps one thread's state in memory, the way the
 * server keeps it on the thread row.
 *
 *   node tests/harness/run.mjs <playwright-core dir> <assets dir> [viewport width, default 390]
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
const require = createRequire(process.argv[2] + '/package.json');
const { chromium } = require(process.argv[2]);
const assets = process.argv[3];
const width = parseInt(process.argv[4], 10) || 390;
const here = path.dirname(fileURLToPath(import.meta.url));
const TOKEN = '7.abcdefabcdefabcdefab';
const TEXTS = { waiting: 'נציג יצטרף לשיחה בקרוב. אפשר להמשיך לכתוב בינתיים.', joined: '%s הצטרף/ה לשיחה.', missed: 'אף אחד לא זמין כרגע.', closed: 'השיחה עם %s הסתיימה.' };
const posted = [];
const state = { status: 'ai', manager: '', messages: [], nextId: 1, polls: 0, chat409: 0, visitorLines: [] };
const fill = (key) => TEXTS[key].replace('%s', state.manager);
const line = (role, text) => { const m = { id: state.nextId++, role, text, at: new Date().toISOString() }; state.messages.push(m); return m; };
const json = (res, code, body) => { res.statusCode = code; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(body)); };
const readBody = (req) => new Promise((r) => { let b = ''; req.on('data', (c) => b += c); req.on('end', () => r(b ? JSON.parse(b) : {})); });
const inLive = () => state.status === 'waiting' || state.status === 'live';

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://stub');
  if (req.method === 'POST' && url.pathname === '/chat') {
    const b = await readBody(req); posted.push(b);
    if (inLive()) { state.chat409++; return json(res, 409, { code: 'wsa_live_owned', message: 'owned', data: { status: state.status } }); }
    const text = b.messages.slice(-1)[0].text;
    if (/נציג/.test(text)) {
      state.status = 'waiting'; state.manager = ''; line('system', fill('waiting'));
      return json(res, 200, { reply: 'בטח, נציג יצטרף לשיחה בקרוב.', thread: TOKEN, live: 'waiting', chips: [], products: [], handoff: false });
    }
    const wantsButton = /וואטסאפ/.test(text);
    return json(res, 200, { reply: wantsButton ? 'בטח, לחצו על הכפתור למטה.' : 'יש לנו שלושה קיטים מתאימים.', handoff: wantsButton, thread: TOKEN, live: '', chips: ['מסך או שמע?'],
      products: [{ id: 1, name: 'קיט אינטרקום אלחוטי', url: '/p1', image: '/img.png', price_html: '<span>₪1650</span>', in_stock: true, can_add: true, add_url: '/?add-to-cart=1', on_sale: false }] });
  }
  if (req.method === 'GET' && url.pathname === '/live/thread') {
    state.polls++;
    const since = parseInt(url.searchParams.get('since'), 10) || 0;
    return json(res, 200, { status: state.status === 'closed' ? 'ai' : state.status, manager: state.manager, messages: state.messages.filter((m) => m.id > since && m.role !== 'user') });
  }
  if (req.method === 'POST' && url.pathname === '/live/thread/message') {
    const b = await readBody(req);
    if (!inLive()) return json(res, 409, { code: 'wsa_not_live', message: 'not live', data: { status: 409 } });
    state.visitorLines.push(b.text);
    return json(res, 200, { id: line('user', b.text).id, status: state.status });
  }
  if (req.method === 'POST' && url.pathname === '/__stub/manager') {
    const b = await readBody(req);
    if (b.action === 'claim') { state.status = 'live'; state.manager = 'Dana'; line('system', fill('joined')); }
    if (b.action === 'reply') line('manager', b.text);
    if (b.action === 'close') { state.status = 'closed'; line('system', fill('closed')); }
    if (b.action === 'missed') { state.status = 'missed'; state.manager = ''; line('system', fill('missed')); }
    return json(res, 200, { status: state.status });
  }
  const file = url.pathname.startsWith('/widget.') || url.pathname === '/ibracodes.svg' ? path.join(assets, url.pathname) : path.join(here, 'page.html');
  if (url.pathname === '/img.png') { res.end(''); return; }
  res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : file.endsWith('.svg') ? 'image/svg+xml' : 'text/html');
  res.end(fs.readFileSync(file));
});
await new Promise((r) => server.listen(0, r));
const base = 'http://127.0.0.1:' + server.address().port;
const manager = (action, text) => fetch(base + '/__stub/manager', { method: 'POST', body: JSON.stringify({ action, text }) });
const until = async (fn, ms = 6000) => { const end = Date.now() + ms; while (Date.now() < end) { if (fn()) return true; await new Promise((r) => setTimeout(r, 100)); } return false; };
const placeholderIs = (page, value) => page.waitForFunction((v) => document.querySelector('.wsa-input').placeholder === v, value, { timeout: 6000 }).then(() => true, () => false);
const shot = (name) => path.join(here, name + '-' + width + '.png');

const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const page = await browser.newPage({ viewport: { width, height: width > 600 ? 900 : 844 } });
const open = async () => { await page.waitForSelector('.wsa-launcher'); await page.click('.wsa-launcher'); await page.waitForSelector('.wsa-root.is-open'); };
const count = async () => ({ user: await page.locator('.wsa-msg.is-user').count(), assistant: await page.locator('.wsa-msg.is-assistant').count(), cards: await page.locator('.wsa-card').count(), chips: await page.locator('.wsa-chip').count(), open: await page.locator('.wsa-root.is-open').count() });
const say = async (text) => { await page.fill('.wsa-input', text); await page.press('.wsa-input', 'Enter'); };

// ---- page 1: a product question, the footer
await page.goto(base + '/page.html');
await open();
await page.screenshot({ path: shot('footer') });
await say('אינטרקום לבית');
await page.waitForSelector('.wsa-card');
console.log('page id sent:', posted[0].page);
const footer = await page.locator('.wsa-foot a[href="https://ibracodes.com"]').count();
const note = await page.locator('.wsa-note').count();
const logo = await page.locator('.wsa-foot img[src$="ibracodes.svg"]').count();
console.log('footer:', footer, '| note:', note, '| logo:', logo);
console.log('page 1 after answer:', JSON.stringify(await count()));

// ---- page 2: the conversation survives navigation; the contact button on request
await page.click('#next'); await page.waitForSelector('.wsa-launcher');
console.log('page 2 before open :', JSON.stringify(await count()));
await open(); await page.waitForTimeout(200);
const after = await count();
console.log('page 2 after open  :', JSON.stringify(after));
await page.screenshot({ path: shot('page2-open') });
await say('יש לכם וואטסאפ?'); await page.waitForSelector('.wsa-chip.is-handoff');
const handoffHref = await page.locator('.wsa-chip.is-handoff').last().getAttribute('href');
console.log('handoff chip after asking for the button: href=' + handoffHref);
await page.screenshot({ path: shot('page2-handoff') });
const last = posted[posted.length - 1];
console.log('page 2 second ask  : thread=' + JSON.stringify(last.thread) + ' messages sent=' + last.messages.length);

// ---- page 3: the chip replays; then a person is asked for
await page.click('#next'); await open(); await page.waitForTimeout(200);
const replayed = await page.locator('.wsa-chip.is-handoff').count();
console.log('handoff chip replayed on page 3: ' + replayed);
await say('אפשר לדבר עם נציג?');
const waitingShown = await page.waitForSelector('.wsa-system', { timeout: 6000 }).then(() => true, () => false);
const systemCount = await page.locator('.wsa-system').count();
const systemText = systemCount ? await page.locator('.wsa-system').first().textContent() : '';
const polled = await until(() => state.polls >= 1);
console.log('waiting line shown: ' + waitingShown + ' | system lines: ' + systemCount + ' | text matches the poll: ' + (systemText === TEXTS.waiting) + ' | polled: ' + polled);
const chatsBeforeLive = posted.length;
await say('אני ממתין כאן');
const visitorRouted = await until(() => state.visitorLines.length === 1);
const chatsDuringLive = posted.length;
console.log('visitor line went to the live route: ' + visitorRouted + ' | /chat calls unchanged: ' + (chatsDuringLive === chatsBeforeLive));
await manager('claim'); await manager('reply', 'היי, כאן דנה. איך אפשר לעזור?');
const managerShown = await page.waitForSelector('.wsa-msg.is-manager', { timeout: 6000 }).then(() => true, () => false);
const managerName = managerShown ? await page.locator('.wsa-msg.is-manager .wsa-msg-name').first().textContent() : '';
const writeTo = await placeholderIs(page, 'Write to Dana');
console.log('manager bubble: ' + managerShown + ' | name: ' + JSON.stringify(managerName) + ' | placeholder "Write to Dana": ' + writeTo);
await page.screenshot({ path: shot('live') });

// ---- page 4: reload mid-live with the panel closed; polling resumes on its own, a new line lights the launcher, opening replays everything
const pollsBeforeReload = state.polls;
await page.reload(); await page.waitForSelector('.wsa-launcher');
const resumed = await until(() => state.polls > pollsBeforeReload);
await manager('reply', 'עוד משהו שאפשר לעזור בו?');
const dotShown = await page.waitForSelector('.wsa-launcher-dot', { timeout: 6000 }).then(() => true, () => false);
await open();
const dotGone = (await page.locator('.wsa-launcher-dot').count()) === 0;
const replayedManager = await page.waitForSelector('.wsa-msg.is-manager', { timeout: 3000 }).then(() => page.locator('.wsa-msg.is-manager').count(), () => 0);
const replayedSystem = await page.locator('.wsa-system').count();
const writeToAfterReload = await placeholderIs(page, 'Write to Dana');
console.log('after reload: polling resumed while closed: ' + resumed + ' | dot on a new line: ' + dotShown + ' | dot gone on open: ' + dotGone + ' | manager bubbles ' + replayedManager + ' | system lines ' + replayedSystem + ' | placeholder kept: ' + writeToAfterReload);

// ---- a stale tab: the stored state says ai while the server says live; /chat answers 409 and the line goes to the person
await page.evaluate(() => { const s = JSON.parse(sessionStorage.getItem('wsa-chat')); s.live = { status: 'ai', manager: '', since: (s.live && s.live.since) || 0 }; sessionStorage.setItem('wsa-chat', JSON.stringify(s)); });
await page.reload(); await open();
const chat409Before = state.chat409;
await say('עדיין כאן?');
const rerouted = await until(() => state.chat409 === chat409Before + 1 && state.visitorLines.length === 2);
const writeToAfter409 = await placeholderIs(page, 'Write to Dana');
console.log('stale tab: /chat 409 rerouted the line to the person: ' + rerouted + ' | back in live mode: ' + writeToAfter409);

// ---- the manager closes: the closed line, then the AI again
const chatsBeforeClose = posted.length;
await manager('close');
const closedShown = await page.waitForFunction((t) => Array.prototype.some.call(document.querySelectorAll('.wsa-system'), (n) => n.textContent === t), TEXTS.closed.replace('%s', 'Dana'), { timeout: 6000 }).then(() => true, () => false);
const placeholderRestored = await placeholderIs(page, 'כתבו את השאלה');
await say('עוד שאלה על אינטרקום');
const backToAi = await until(() => posted.length === chatsBeforeClose + 1);
await page.waitForSelector('.wsa-card');
// the model's conversation is the visitor's and the AI's: the person's lines and the system lines stay out of it
const sentAfterLive = posted[posted.length - 1].messages;
const liveTexts = state.messages.filter((m) => m.role !== 'user').map((m) => m.text);
const modelHistoryClean = sentAfterLive.every((m) => (m.role === 'user' || m.role === 'assistant') && !liveTexts.includes(m.text) && !state.visitorLines.includes(m.text));
console.log('closed line shown: ' + closedShown + ' | placeholder restored: ' + placeholderRestored + ' | next message went to /chat: ' + backToAi + ' | no manager or system text in it: ' + modelHistoryClean);
await page.screenshot({ path: shot('closed') });

// ---- nobody comes: the missed line, the contact chip right under it, the AI again; line and chip replay
await say('אפשר לדבר עם נציג?');
await until(() => state.status === 'waiting');
const pollsBeforeMissed = state.polls;
await until(() => state.polls > pollsBeforeMissed); // the waiting line is in before the wait runs out
await manager('missed');
const missedShown = await page.waitForFunction((t) => Array.prototype.some.call(document.querySelectorAll('.wsa-system'), (n) => n.textContent === t), TEXTS.missed, { timeout: 6000 }).then(() => true, () => false);
const chipUnderMissed = (t) => { const row = Array.prototype.find.call(document.querySelectorAll('.wsa-system'), (n) => n.textContent === t); const next = row && row.nextElementSibling; return !!(next && next.querySelector('.wsa-chip.is-handoff')); };
const chipOnMissed = await page.evaluate(chipUnderMissed, TEXTS.missed);
const placeholderAfterMissed = await placeholderIs(page, 'כתבו את השאלה');
await page.reload(); await open(); await page.waitForTimeout(200);
const chipReplayedOnMissed = await page.evaluate(chipUnderMissed, TEXTS.missed);
console.log('missed: line shown: ' + missedShown + ' | contact chip under it: ' + chipOnMissed + ' | placeholder restored: ' + placeholderAfterMissed + ' | chip replayed: ' + chipReplayedOnMissed);

// ---- bare page: no credit, no note
await page.goto(base + '/page.html?bare=1'); await open(); await page.waitForTimeout(200);
const bareFoot = await page.locator('.wsa-foot').count();
console.log('bare page (no credit, no note) footer elements: ' + bareFoot);

const ok = after.user === 1 && after.assistant === 2 && after.cards === 1 && after.chips >= 1 && last.thread === TOKEN && last.messages.length === 3 && handoffHref === 'https://wa.me/972500000000' && replayed === 1
  && posted[0].page === 42 && footer === 1 && note === 1 && logo === 1 && bareFoot === 0
  && waitingShown && systemCount === 1 && systemText === TEXTS.waiting && polled
  && visitorRouted && chatsDuringLive === chatsBeforeLive
  && managerShown && managerName === 'Dana' && writeTo
  && resumed && dotShown && dotGone && replayedManager === 2 && replayedSystem === 2 && writeToAfterReload
  && rerouted && writeToAfter409
  && closedShown && placeholderRestored && backToAi && modelHistoryClean
  && missedShown && chipOnMissed && placeholderAfterMissed && chipReplayedOnMissed;
console.log(ok ? 'PASS: conversation survives navigation, footer right, live mode polls, replays, returns to the AI, and a missed request offers the contact chip' : 'FAIL: see the lines above');
process.exitCode = ok ? 0 : 1;
await browser.close(); server.close();
