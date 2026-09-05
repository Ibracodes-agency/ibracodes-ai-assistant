import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
const require = createRequire(process.argv[2] + '/package.json');
const { chromium } = require(process.argv[2]);
const assets = process.argv[3];
const here = path.dirname(fileURLToPath(import.meta.url));
const posted = [];
const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/chat') {
    let b = ''; req.on('data', c => b += c); req.on('end', () => {
      posted.push(JSON.parse(b));
      const wantsPerson = /נציג/.test(posted[posted.length - 1].messages.slice(-1)[0].text);
      res.setHeader('Content-Type', 'application/json');
      res.end(JSON.stringify({ reply: wantsPerson ? 'בטח, לחצו על הכפתור למטה.' : 'יש לנו שלושה קיטים מתאימים.', handoff: wantsPerson, thread: '7.abcdefabcdefabcdefab', chips: ['מסך או שמע?'],
        products: [{ id: 1, name: 'קיט אינטרקום אלחוטי', url: '/p1', image: '/img.png', price_html: '<span>₪1650</span>', in_stock: true, can_add: true, add_url: '/?add-to-cart=1', on_sale: false }] }));
    }); return;
  }
  const file = req.url.startsWith('/widget.') || req.url === '/ibracodes.svg' ? path.join(assets, req.url.split('?')[0]) : path.join(here, 'page.html');
  if (req.url === '/img.png') { res.end(''); return; }
  res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : file.endsWith('.svg') ? 'image/svg+xml' : 'text/html');
  res.end(fs.readFileSync(file));
});
await new Promise(r => server.listen(0, r));
const base = 'http://127.0.0.1:' + server.address().port;
const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
await page.goto(base + '/page.html');
await page.click('.wsa-launcher');
await page.screenshot({ path: path.join(here, 'footer-390.png') });
await page.fill('.wsa-input', 'אינטרקום לבית');
await page.press('.wsa-input', 'Enter');
await page.waitForSelector('.wsa-card');
console.log('page id sent:', posted[0].page);
const footer = await page.locator('.wsa-foot a[href="https://ibracodes.com"]').count();
const note = await page.locator('.wsa-note').count();
const logo = await page.locator('.wsa-foot img[src$="ibracodes.svg"]').count();
console.log('footer:', footer, '| note:', note, '| logo:', logo);
const count = async () => ({ user: await page.locator('.wsa-msg.is-user').count(), assistant: await page.locator('.wsa-msg.is-assistant').count(), cards: await page.locator('.wsa-card').count(), chips: await page.locator('.wsa-chip').count(), open: await page.locator('.wsa-root.is-open').count() });
console.log('page 1 after answer:', JSON.stringify(await count()));
await page.click('#next'); await page.waitForSelector('.wsa-launcher');
console.log('page 2 before open :', JSON.stringify(await count()));
await page.click('.wsa-launcher'); await page.waitForTimeout(200);
const after = await count();
console.log('page 2 after open  :', JSON.stringify(after));
await page.screenshot({ path: path.join(here, 'page2-open.png') });
await page.fill('.wsa-input', 'אפשר לדבר עם נציג?'); await page.press('.wsa-input', 'Enter'); await page.waitForSelector('.wsa-chip.is-handoff');
const handoffHref = await page.locator('.wsa-chip.is-handoff').last().getAttribute('href');
console.log('handoff chip after asking for a person: href=' + handoffHref);
await page.screenshot({ path: path.join(here, 'page2-handoff.png') });
await page.click('#next'); await page.waitForSelector('.wsa-launcher'); await page.click('.wsa-launcher'); await page.waitForTimeout(200);
const replayed = await page.locator('.wsa-chip.is-handoff').count();
console.log('handoff chip replayed on page 3: ' + replayed);
const last = posted[posted.length - 1];
console.log('page 2 second ask  : thread=' + JSON.stringify(last.thread) + ' messages sent=' + last.messages.length);
const ok = after.user === 1 && after.assistant === 2 && after.cards === 1 && after.chips >= 1 && last.thread === '7.abcdefabcdefabcdefab' && last.messages.length === 3 && handoffHref === 'https://wa.me/972500000000' && replayed === 1
  && posted[0].page === 42 && footer === 1 && note === 1 && logo === 1;
console.log(ok ? 'PASS: conversation survives navigation, page id and footer present' : 'FAIL: conversation lost on navigation, or page id / footer missing');
process.exitCode = ok ? 0 : 1;
await browser.close(); server.close();
