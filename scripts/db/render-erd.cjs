// Render docs/waffrli/database/erd-*.mmd to SVG with a LOCAL Mermaid in headless Chromium (no external service).
//   npm i --no-save mermaid@11.4.1 playwright   (or reuse an existing Playwright install)
//   node scripts/db/render-erd.cjs node_modules/mermaid/dist/mermaid.min.js docs/waffrli/database/erd-*.mmd
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const [lib, ...files] = process.argv.slice(2);
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setContent('<html><body></body></html>');
  await page.addScriptTag({ content: fs.readFileSync(lib, 'utf8') });
  await page.evaluate(() => mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'strict', maxTextSize: 500000, maxEdges: 2000, er: { useMaxWidth: false } }));
  for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    try {
      const svg = await page.evaluate(async (s) => (await mermaid.render('d' + Math.random().toString(36).slice(2), s)).svg, src);
      fs.writeFileSync(f.replace(/\.mmd$/, '.svg'), svg);
      console.log('ok', f, svg.length);
    } catch (e) { console.log('FAIL', f, String(e).slice(0, 300)); }
  }
  await browser.close();
})();
