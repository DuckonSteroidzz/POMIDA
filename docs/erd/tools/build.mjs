// POMIDA ERD builder.
//   node build.mjs --schema schema-snapshot.json --relations relationships-snapshot.json --out <docs/erd> \
//                  --tools <dir containing node_modules with @mermaid-js/mermaid-cli + puppeteer-core> \
//                  [--chrome "C:/Program Files/Google/Chrome/Application/chrome.exe"] [--source "main @ 93925f8"]
// Inputs are produced by extract_schema.php (information_schema) and extract_relations.php (Eloquent reflection).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { loadModel, MODULES } from './erd-model.mjs';
import { buildMermaid } from './erd-mermaid.mjs';
import { frameDiagram, fixDecimals } from './erd-frame.mjs';
import { buildDictionary } from './erd-dictionary.mjs';

const arg = (n, d) => { const i = process.argv.indexOf('--' + n); return i > 0 ? process.argv[i + 1] : d; };
const schemaPath = path.resolve(arg('schema'));
const relPath = path.resolve(arg('relations'));
const out = path.resolve(arg('out'));
const tools = path.resolve(arg('tools'));
const chrome = arg('chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe');
const source = arg('source', 'main');
const only = arg('only', '');                       // e.g. --only 03-orders-payments (debugging)
const date = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local

const model = loadModel(schemaPath, relPath);
const require = createRequire(path.join(tools, 'package.json'));
const puppeteer = require('puppeteer-core');
const mmdcCli = path.join(tools, 'node_modules', '@mermaid-js', 'mermaid-cli', 'src', 'cli.js');

for (const d of ['mmd', 'svg', 'png']) fs.mkdirSync(path.join(out, d), { recursive: true });
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pomida-erd-'));
fs.writeFileSync(path.join(tmp, 'pp.json'), JSON.stringify({ executablePath: chrome, args: ['--no-sandbox'] }));
fs.writeFileSync(path.join(tmp, 'cfg.json'), JSON.stringify({
  theme: 'base', layout: 'elk', htmlLabels: false,
  themeVariables: { fontFamily: 'Segoe UI, Arial, sans-serif', fontSize: '14px', primaryColor: '#ffffff', primaryTextColor: '#111827',
    primaryBorderColor: '#64748b', lineColor: '#334155', tertiaryColor: '#ffffff' },
  er: { useMaxWidth: false, layoutDirection: 'TB', entityPadding: 8, fontSize: 14, nodeSpacing: 70, rankSpacing: 70 },
}, null, 2));

const fkCount = model.links.filter((l) => l.kind === 'fk').length;
const DIAGRAMS = [
  { slug: '00-full-erd', title: 'POMIDA \u2014 Full ERD', scope: null, isFull: true,
    subtitle: `All ${model.appNames.length} application tables \u00b7 ${model.links.length} relationships (${fkCount} database foreign keys + ${model.modelOnly.length} model-only) \u00b7 Laravel framework tables omitted` },
  ...MODULES.map((m, i) => ({ slug: m.slug, title: `POMIDA \u2014 Module ${i + 1}: ${m.title}`, scope: m, isFull: false,
    subtitle: `${m.tables.length} tables in this module` })),
].filter((d) => !only || d.slug === only);

const dbLine = `Database ${model.schema.database} (${model.schema.server_version}) \u00b7 read from information_schema on ${date} \u00b7 ${source}`;
const results = [];

for (const d of DIAGRAMS) {
  const header = `POMIDA ERD - ${d.title} - generated ${date} from ${model.schema.database}`;
  const m = buildMermaid(model, d.scope, header);
  const mmdFile = path.join(out, 'mmd', d.slug + '.mmd');
  fs.writeFileSync(mmdFile, m.text);

  const rawSvg = path.join(tmp, d.slug + '.raw.svg');
  execFileSync(process.execPath, [mmdcCli, '-q', '-p', path.join(tmp, 'pp.json'), '-c', path.join(tmp, 'cfg.json'), '-i', mmdFile, '-o', rawSvg], { stdio: 'pipe' });
  const inner = fixDecimals(fs.readFileSync(rawSvg, 'utf8'));

  const framed = frameDiagram({
    svg: inner, title: d.title, subtitle: [d.subtitle, dbLine],
    isFull: d.isFull, module: d.scope, incoming: m.incoming, moduleOf: model.moduleOf,
  });
  fs.writeFileSync(path.join(out, 'svg', d.slug + '.svg'), framed.svg);
  results.push({ ...d, m, raw: inner, framed });
  console.log(`rendered ${d.slug}: ${m.local.length} tables, ${m.stubs.length} stubs, ${m.links.length} lines, ${framed.width}x${framed.height}`);
}

// ---------------------------------------------------------------- PNG + PDF (one Chrome)
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox'] });
try {
  for (const r of results) {
    const page = await browser.newPage();
    const { width: W, height: H } = r.framed;
    const scale = Math.min(2, 15000 / Math.max(W, H));
    await page.setViewport({ width: W, height: H, deviceScaleFactor: scale });
    await page.goto(pathToFileURL(path.join(out, 'svg', r.slug + '.svg')).href);
    await page.screenshot({ path: path.join(out, 'png', r.slug + '.png'), type: 'png' });
    await page.close();
    r.pngScale = scale;
    console.log(`png ${r.slug}: ${Math.round(W * scale)}x${Math.round(H * scale)} px (scale ${scale.toFixed(2)})`);
  }

  if (!only) {
    const K = 0.5; // PDF page = diagram frame at half CSS size; it is vector, so zooming never loses detail
    const css = results.map((r, i) => `@page p${i}{size:${r.framed.width * K}px ${r.framed.height * K}px;margin:0}`
      + `.s${i}{page:p${i};width:${r.framed.width * K}px;height:${r.framed.height * K}px;overflow:hidden;${i < results.length - 1 ? 'break-after:page;' : ''}}`).join('\n');
    const body = results.map((r, i) => `<div class="s${i}"><img src="${pathToFileURL(path.join(out, 'svg', r.slug + '.svg')).href}" style="display:block;width:100%;height:100%"></div>`).join('\n');
    const htmlFile = path.join(tmp, 'pdf.html');
    fs.writeFileSync(htmlFile, `<!doctype html><html><head><meta charset="utf-8"><title>POMIDA Entity Relationship Diagrams (${date})</title><style>html,body{margin:0;padding:0}${css}</style></head><body>${body}</body></html>`);
    const page = await browser.newPage();
    await page.goto(pathToFileURL(htmlFile).href, { waitUntil: 'networkidle0' });
    await page.pdf({ path: path.join(out, 'POMIDA-ERD.pdf'), preferCSSPageSize: true, printBackground: true });
    await page.close();
    console.log('pdf written');
  }
} finally {
  await browser.close();
}

if (!only) {
  // ---------------------------------------------------------------- data dictionary
  fs.writeFileSync(path.join(out, 'data-dictionary.html'), buildDictionary(model, { date, source }));
  console.log('data-dictionary.html written');

  // ---------------------------------------------------------------- cross-check (step 9)
  const T = model.schema.tables;
  const dbFks = Object.entries(T).flatMap(([t, d]) => d.fks.map((f) => `${t}.${f.columns.join(',')}->${f.ref_table}`));
  const full = fs.readFileSync(path.join(out, 'mmd', '00-full-erd.mmd'), 'utf8');
  const entities = [...full.matchAll(/^ {4}(\w+) \{$/gm)].map((x) => x[1]);
  const lineRe = /^ {4}(\w+) ([|}][o|])(--|\.\.)([o|][|{]) (\w+) : "([^"]*)"$/gm;
  const fullLines = [...full.matchAll(lineRe)].map((x) => ({ parent: x[1], dash: x[3], child: x[5], label: x[6] }));
  const solidLines = fullLines.filter((l) => l.dash === '--').length;
  const dottedLines = fullLines.filter((l) => l.dash === '..').length;
  const appInDb = Object.keys(T).filter((n) => !model.frameworkInDb.includes(n));
  const missingEntities = appInDb.filter((n) => !entities.includes(n));
  const extraEntities = entities.filter((n) => !appInDb.includes(n));
  // every DB FK must have a solid line between exactly its child and parent
  const fkMissing = [];
  for (const [t, d] of Object.entries(T)) for (const f of d.fks) {
    if (!fullLines.some((l) => l.dash === '--' && l.child === t && l.parent === f.ref_table)) fkMissing.push(`${t}.${f.columns[0]}`);
  }
  // each link must appear in exactly one module diagram (the module that owns the FK column)
  const modLineCount = results.filter((r) => !r.isFull).reduce((a, r) => a + r.m.links.length, 0);
  const modTableCount = results.filter((r) => !r.isFull).reduce((a, r) => a + r.m.local.length, 0);
  // rendered SVG: count drawn relationship lines and entity boxes
  const svgFull = results[0].raw;
  const svgLines = (svgFull.match(/class="[^"]*relationshipLine[^"]*"/g) || []).length;
  const svgDotted = (svgFull.match(/class="[^"]*relationshipLine[^"]*"[^>]*stroke-dasharray|stroke-dasharray[^>]*class="[^"]*relationshipLine/g) || []).length;

  const cc = {
    generated: date, database: model.schema.database, server: model.schema.server_version,
    tables_in_database: Object.keys(T).length, application_tables: appInDb.length, framework_tables: model.frameworkInDb.length,
    columns_total: Object.values(T).reduce((a, t) => a + t.columns.length, 0),
    columns_application: model.appNames.reduce((a, n) => a + T[n].columns.length, 0),
    foreign_keys_in_database: dbFks.length, foreign_keys_drawn_solid: solidLines, model_only_drawn_dotted: dottedLines,
    entities_in_full_erd: entities.length, tables_missing_from_erd: missingEntities, entities_not_in_database: extraEntities,
    fks_without_a_solid_line: fkMissing,
    module_diagram_tables_sum: modTableCount, module_diagram_lines_sum: modLineCount,
    rendered_svg_relationship_paths: svgLines,
    model_only_relationships: model.modelOnly.map((l) => `${l.child}.${l.col} -> ${l.parent} (${l.via.join(', ')})`),
    dead_model_relationships: model.deadModel.map((l) => `${l.via.join(', ')} expects ${l.child}.${l.col}`),
    framework_tables: model.frameworkInDb,
    fks_without_model_relation: model.fksWithoutModel,
    model_relationship_methods_inspected: model.relations.length,
  };
  fs.writeFileSync(path.join(out, 'cross-check.json'), JSON.stringify(cc, null, 2) + '\n');
  console.log(JSON.stringify(cc, null, 2));
  const ok = missingEntities.length === 0 && extraEntities.length === 0 && fkMissing.length === 0
    && solidLines === dbFks.length && dottedLines === model.modelOnly.length
    && modLineCount === model.links.length && modTableCount === appInDb.length
    && entities.length === appInDb.length;
  console.log(ok ? 'CROSS-CHECK PASSED' : 'CROSS-CHECK FAILED');
  if (!ok) process.exitCode = 1;
}
fs.rmSync(tmp, { recursive: true, force: true });
