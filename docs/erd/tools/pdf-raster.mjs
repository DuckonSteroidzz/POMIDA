// Rasterises every page of a PDF with pdf.js (independent of the tool that wrote it) and prints the
// page sizes plus the first text lines, so the PDF itself - not just the SVG it came from - is checked.
// usage: node pdf-raster.mjs <file.pdf> <outDir> <targetWidthPx> <toolsDir>
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const [, , pdfPath, outDir, widthArg, toolsDir] = process.argv;
const tools = path.resolve(toolsDir);
const require = createRequire(path.join(tools, 'package.json'));
const puppeteer = require('puppeteer-core');
fs.mkdirSync(outDir, { recursive: true });
const pdf = fs.readFileSync(pdfPath);

const html = `<!doctype html><html><body><script type="module">
import * as pdfjs from '/node_modules/pdfjs-dist/build/pdf.min.mjs';
pdfjs.GlobalWorkerOptions.workerSrc = '/node_modules/pdfjs-dist/build/pdf.worker.min.mjs';
window.run = async (targetW) => {
  const doc = await pdfjs.getDocument('/doc.pdf').promise;
  const out = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const page = await doc.getPage(i);
    const v1 = page.getViewport({ scale: 1 });
    const scale = targetW / v1.width;
    const vp = page.getViewport({ scale });
    const c = document.createElement('canvas'); c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
    await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
    const tc = await page.getTextContent();
    out.push({ n: i, w: v1.width, h: v1.height, png: c.toDataURL('image/png'), text: tc.items.map((x) => x.str).filter(Boolean).slice(0, 14).join(' | '), nText: tc.items.length });
  }
  return out;
};
window.ready = true;
</script></body></html>`;

const server = http.createServer((req, res) => {
  if (req.url === '/') { res.writeHead(200, { 'content-type': 'text/html' }); return res.end(html); }
  if (req.url === '/doc.pdf') { res.writeHead(200, { 'content-type': 'application/pdf' }); return res.end(pdf); }
  const file = path.join(tools, decodeURIComponent(req.url.split('?')[0]));
  if (!file.startsWith(tools) || !fs.existsSync(file)) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { 'content-type': 'text/javascript' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--no-sandbox'] });
const page = await browser.newPage();
page.on('pageerror', (e) => console.error('[page error]', e.message));
await page.goto(`http://127.0.0.1:${server.address().port}/`);
await page.waitForFunction('window.ready === true', { timeout: 60000 });
const pages = await page.evaluate((w) => window.run(w), Number(widthArg));
for (const p of pages) {
  fs.writeFileSync(path.join(outDir, `page-${p.n}.png`), Buffer.from(p.png.split(',')[1], 'base64'));
  console.log(`page ${p.n}: ${Math.round(p.w)}x${Math.round(p.h)} pt (${(p.w / 72).toFixed(1)} x ${(p.h / 72).toFixed(1)} in), ${p.nText} text items; starts: ${p.text.slice(0, 150)}`);
}
await browser.close();
server.close();
