// Automated visual QA of the framed SVGs, in headless Chrome:
//   1. no two text boxes overlap (edge labels, column cells, legend, titles)
//   2. nothing is cut off: every drawn element lies inside the SVG canvas, and inside the nested diagram viewport
// usage: node check-render.mjs <svgDir> <toolsDir> [chrome]
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const [, , svgDir, toolsDir, chromeArg] = process.argv;
const chrome = chromeArg || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const require = createRequire(path.join(path.resolve(toolsDir), 'package.json'));
const puppeteer = require('puppeteer-core');
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox'] });
let failures = 0;

for (const f of fs.readdirSync(svgDir).filter((x) => x.endsWith('.svg')).sort()) {
  const file = path.resolve(svgDir, f);
  const head = fs.readFileSync(file, 'utf8').slice(0, 600);
  const [, W, H] = head.match(/<svg[^>]*\swidth="(\d+)"\s+height="(\d+)"/);
  const page = await browser.newPage();
  await page.setViewport({ width: +W, height: +H });
  await page.goto(pathToFileURL(file).href);
  const res = await page.evaluate(() => {
    const root = document.documentElement;
    const rootBox = root.getBoundingClientRect();
    const nested = root.querySelector('svg');                 // the Mermaid diagram
    const nBox = nested.getBoundingClientRect();
    const texts = [...root.querySelectorAll('text')].filter((t) => t.textContent.trim() !== '' && !t.closest('title'));
    const boxes = texts.map((t) => { const r = t.getBoundingClientRect(); return { s: t.textContent.trim(), x1: r.left, y1: r.top, x2: r.right, y2: r.bottom }; })
      .filter((b) => b.x2 - b.x1 > 0 && b.y2 - b.y1 > 0);
    // 1. overlaps (sweep over x-sorted boxes). Shrink each box a little: glyph boxes touch without being "overlapping text".
    const SH = 1.5;
    const sorted = boxes.map((b) => ({ ...b, x1: b.x1 + SH, x2: b.x2 - SH, y1: b.y1 + SH, y2: b.y2 - SH })).sort((a, b) => a.x1 - b.x1);
    const overlaps = [];
    for (let i = 0; i < sorted.length; i++) {
      for (let j = i + 1; j < sorted.length && sorted[j].x1 < sorted[i].x2; j++) {
        const a = sorted[i], b = sorted[j];
        if (a.y1 < b.y2 && b.y1 < a.y2) overlaps.push([a.s, b.s, Math.round(a.x1), Math.round(a.y1)]);
      }
    }
    // 2. cut-off: anything outside the canvas, or (for the diagram) outside its own viewport
    const outside = [];
    const all = [...root.querySelectorAll('text,rect,path,line,circle')].filter((e) => !e.closest('defs') && !e.closest('marker') && !e.closest('title'));
    for (const e of all) {
      const r = e.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) continue;
      if (r.left < rootBox.left - 1 || r.top < rootBox.top - 1 || r.right > rootBox.right + 1 || r.bottom > rootBox.bottom + 1) outside.push(['canvas', e.tagName, Math.round(r.left), Math.round(r.top)]);
      else if (nested.contains(e) && (r.left < nBox.left - 1 || r.top < nBox.top - 1 || r.right > nBox.right + 1 || r.bottom > nBox.bottom + 1)) outside.push(['diagram viewport', e.tagName, Math.round(r.left), Math.round(r.top)]);
    }
    return { texts: boxes.length, overlaps, outside, nested: [Math.round(nBox.width), Math.round(nBox.height)] };
  });
  await page.close();
  const bad = res.overlaps.length + res.outside.length;
  failures += bad;
  console.log(`${f.padEnd(26)} ${String(res.texts).padStart(5)} text boxes | overlaps: ${res.overlaps.length} | cut-off/outside: ${res.outside.length}${bad ? '  <-- PROBLEM' : '  ok'}`);
  for (const o of res.overlaps.slice(0, 12)) console.log('    overlap:', JSON.stringify(o));
  for (const o of res.outside.slice(0, 8)) console.log('    outside:', JSON.stringify(o));
}
await browser.close();
console.log(failures ? `FAILED: ${failures} problem(s)` : 'RENDER CHECK PASSED: no overlapping text, nothing cut off');
process.exitCode = failures ? 1 : 0;
