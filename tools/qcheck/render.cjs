// node render.cjs DIR SITE : renders every answer in DIR/out*.jsonl as the page shows it; writes cards.jsonl and PNGs.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const dir = process.argv[2], site = process.argv[3], shots = new Set((process.argv[4] || '').split(',').filter(Boolean).map(Number));
const qs = JSON.parse(fs.readFileSync(path.join(dir, 'quotes.json')));
const rows = fs.readdirSync(dir).filter(f => /^out\d\.jsonl$/.test(f)).flatMap(f => fs.readFileSync(path.join(dir, f), 'utf8').split('\n').filter(Boolean).map(JSON.parse)).sort((a, b) => a.k - b.k);
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 560, height: 1200 }, acceptDownloads: true, reducedMotion: 'reduce' });
  const pg = await ctx.newPage();
  let body = '{}';
  await pg.route('https://mcp.christianopenlibrary.org/check', r => r.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' }, body }));
  const out = fs.createWriteStream(path.join(dir, 'cards.jsonl'));
  fs.mkdirSync(path.join(dir, 'png'), { recursive: true });
  for (const r of rows) {
    if (r.status !== 200) { out.write(JSON.stringify({ k: r.k, status: r.status }) + '\n'); continue; }
    body = JSON.stringify(r.body);
    await pg.goto(site + '/quote.html');
    await pg.fill('#qc-quote', qs[r.k].quote); await pg.click('#qc-go');
    await pg.waitForSelector('.qc-card', { timeout: 10000 }); await pg.waitForTimeout(300);
    const c = await pg.evaluate(() => { const q = s => { const e = document.querySelector('.qc-card ' + s); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; };
      return { title: q('.qc-title'), line: q('p.qc-line:not(.qc-after)'), words: q('.qc-quote .qc-words'), cite: q('.qc-cite'), after: q('.qc-after') }; });
    out.write(JSON.stringify({ k: r.k, set: qs[r.k].set, q: qs[r.k].quote, a: qs[r.k].author, verdict: r.body.verdict, near: r.body.bible ? r.body.bible.near : null, ...c }) + '\n');
    if (shots.has(r.k)) {
      await (await pg.$('.qc-card')).screenshot({ path: path.join(dir, 'png', r.k + '-card.png') });
      const dl = pg.waitForEvent('download'); await pg.click('button[aria-label="Share image"]');
      await (await dl).saveAs(path.join(dir, 'png', r.k + '-pic.png'));
    }
  }
  out.end(); await b.close();
})().catch(e => { console.error(e); process.exit(1); });
