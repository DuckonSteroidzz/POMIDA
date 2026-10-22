// Wraps a Mermaid-rendered SVG in a frame: title, subtitle (database + date), a legend drawn with
// real crow's-foot glyphs, and - for module diagrams - the panel of links that point INTO the module.
import { MODULES } from './erd-model.mjs';

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const FONT = "font-family=\"Segoe UI, Arial, sans-serif\"";
const INK = '#111827', MUTED = '#475569', RULE = '#cbd5e1';

/** Mermaid cannot parse a comma inside a type, so decimal(10,2) is rendered as decimal(10_2); undo it in the text. */
export function fixDecimals(svg) {
  return svg.replace(/decimal\((\d+)_(\d+)\)/g, 'decimal($1,$2)');
}

function parseInner(svg) {
  const open = svg.match(/<svg\b[^>]*>/)[0];
  const vb = open.match(/viewBox="([-\d.]+) ([-\d.]+) ([\d.]+) ([\d.]+)"/);
  if (!vb) throw new Error('inner svg has no viewBox');
  const [x, y, w, h] = vb.slice(1).map(Number);
  return { open, viewBox: vb[0].replace('viewBox=', ''), x, y, w, h, body: svg.slice(open.length, svg.lastIndexOf('</svg>')) };
}

// -- legend glyphs: a horizontal line that ENDS at (xe, y); the symbol sits on the end, as in Mermaid
function glyph(kind, xe, y, k, dashed) {
  const sw = 2 * k;
  const dash = dashed ? ` stroke-dasharray="${6 * k} ${5 * k}"` : '';
  const out = [`<line x1="${xe - 90 * k}" y1="${y}" x2="${xe}" y2="${y}" stroke="#334155" stroke-width="${sw}"${dash}/>`];
  const bar = (dx) => `<line x1="${xe - dx * k}" y1="${y - 9 * k}" x2="${xe - dx * k}" y2="${y + 9 * k}" stroke="#334155" stroke-width="${sw}"/>`;
  const ring = (dx) => `<circle cx="${xe - dx * k}" cy="${y}" r="${5.5 * k}" fill="#ffffff" stroke="#334155" stroke-width="${sw}"/>`;
  if (kind === 'one') out.push(bar(8), bar(16));
  if (kind === 'zero-one') out.push(bar(9), ring(21));
  if (kind === 'zero-many') {
    out.push(`<line x1="${xe - 16 * k}" y1="${y}" x2="${xe}" y2="${y - 9 * k}" stroke="#334155" stroke-width="${sw}"/>`,
      `<line x1="${xe - 16 * k}" y1="${y}" x2="${xe}" y2="${y + 9 * k}" stroke="#334155" stroke-width="${sw}"/>`,
      `<line x1="${xe - 16 * k}" y1="${y}" x2="${xe}" y2="${y}" stroke="#334155" stroke-width="${sw}"/>`,
      ring(27));
  }
  return out.join('');
}

function text(x, y, s, k, { size = 14, weight = 400, fill = INK, anchor = 'start' } = {}) {
  return `<text x="${x}" y="${y}" ${FONT} font-size="${size * k}" font-weight="${weight}" fill="${fill}" text-anchor="${anchor}">${esc(s)}</text>`;
}

function wrap(s, maxChars) {
  const words = s.split(' ');
  const lines = [];
  let cur = '';
  for (const w of words) {
    if ((cur + ' ' + w).trim().length > maxChars && cur) { lines.push(cur); cur = w; } else cur = (cur + ' ' + w).trim();
  }
  if (cur) lines.push(cur);
  return lines;
}

/**
 * opts: { svg, title, subtitle, isFull, module, incoming:[{parent,child,col,label,kind}], moduleOf }
 * returns { svg, width, height }
 */
export function frameDiagram(opts) {
  const inner = parseInner(opts.svg);
  const k = Math.max(1, Math.min(2.2, inner.w / 2600));      // text scales with the diagram so the frame stays legible
  const PAD = 48 * k;

  const legendW = 2080 * k;
  const W = Math.max(inner.w + 2 * PAD, legendW + 2 * PAD);

  let y = PAD;
  const parts = [];

  parts.push(text(PAD, y + 30 * k, opts.title, k, { size: 36, weight: 700 }));
  y += 30 * k + 12 * k;
  for (const line of opts.subtitle) {
    parts.push(text(PAD, y + 18 * k, line, k, { size: 17, fill: MUTED }));
    y += 25 * k;
  }
  y += 14 * k;

  // ---- legend box
  const LH = 214 * k;
  parts.push(`<rect x="${PAD}" y="${y}" width="${legendW}" height="${LH}" rx="${8 * k}" fill="#f8fafc" stroke="${RULE}" stroke-width="${1.5 * k}"/>`);
  const col = [PAD + 24 * k, PAD + 24 * k + 540 * k, PAD + 24 * k + 1120 * k, PAD + 24 * k + 1700 * k];
  const top = y + 34 * k, rh = 29 * k;
  const head = (i, s) => parts.push(text(col[i], top, s, k, { size: 15, weight: 700, fill: MUTED }));

  head(0, 'LINES');
  parts.push(glyph('none', col[0] + 70 * k, top + 1 * rh + 2 * k, k, false));
  parts.push(text(col[0] + 88 * k, top + 1 * rh + 6 * k, 'Solid = real database foreign key', k));
  parts.push(glyph('none', col[0] + 70 * k, top + 2 * rh + 2 * k, k, true));
  parts.push(text(col[0] + 88 * k, top + 2 * rh + 6 * k, 'Dotted = model-only (no database FK)', k));
  parts.push(text(col[0], top + 3 * rh + 8 * k, 'Labels read parent → child, e.g.', k, { fill: MUTED }));
  parts.push(text(col[0], top + 4 * rh + 6 * k, '"branches receives orders".', k, { fill: MUTED }));
  parts.push(text(col[0], top + 5 * rh + 6 * k, opts.isFull ? 'Every relationship is drawn.' : 'Each line is drawn where the FK column lives.', k, { fill: MUTED }));

  head(1, 'LINE ENDS (CARDINALITY)');
  parts.push(glyph('one', col[1] + 70 * k, top + 1 * rh + 2 * k, k, false));
  parts.push(text(col[1] + 88 * k, top + 1 * rh + 6 * k, 'exactly one parent (NOT NULL FK)', k));
  parts.push(glyph('zero-one', col[1] + 70 * k, top + 2 * rh + 2 * k, k, false));
  parts.push(text(col[1] + 88 * k, top + 2 * rh + 6 * k, 'zero or one parent (nullable FK)', k));
  parts.push(text(col[1] + 88 * k, top + 3 * rh + 2 * k, 'or at most one child (UNIQUE FK, 1:1)', k, { fill: MUTED }));
  parts.push(glyph('zero-many', col[1] + 70 * k, top + 4 * rh + 6 * k, k, false));
  parts.push(text(col[1] + 88 * k, top + 4 * rh + 10 * k, 'zero or many children', k));

  head(2, 'COLUMNS');
  parts.push(text(col[2], top + 1 * rh + 6 * k, 'PK primary key · FK declared foreign key · UK unique key', k));
  parts.push(text(col[2], top + 2 * rh + 6 * k, '"UK with x" = unique only together with x', k));
  parts.push(text(col[2], top + 3 * rh + 6 * k, '"no DB FK (model link)" = a model relation', k));
  parts.push(text(col[2], top + 4 * rh + 6 * k, 'exists but the database has no FK', k, { fill: MUTED }));
  parts.push(text(col[2], top + 5 * rh + 6 * k, 'UNSIGNED, int widths, enum values: see dictionary', k, { fill: MUTED }));

  head(3, opts.isFull ? 'MODULE COLOURS' : 'TABLE COLOURS');
  const sw = (i, fill, stroke, label, dashed) => {
    const sy = top + i * rh - 8 * k;
    parts.push(`<rect x="${col[3]}" y="${sy}" width="${34 * k}" height="${19 * k}" rx="${3 * k}" fill="${fill}" stroke="${stroke}" stroke-width="${2 * k}"${dashed ? ` stroke-dasharray="${5 * k} ${4 * k}"` : ''}/>`);
    parts.push(text(col[3] + 46 * k, top + i * rh + 6 * k, label, k));
  };
  if (opts.isFull) {
    MODULES.forEach((m, i) => sw(i + 1, m.fill, m.stroke, m.title));
  } else {
    sw(1, opts.module.fill, opts.module.stroke, `${opts.module.title} (this module)`);
    sw(2, '#f1f5f9', '#94a3b8', 'Table of another module', true);
    parts.push(text(col[3], top + 3 * rh + 6 * k, '(id only: it is just a link target)', k, { fill: MUTED }));
  }
  y += LH + 30 * k;

  // ---- the diagram itself (nested svg, centred)
  const dx = (W - inner.w) / 2;
  const nestedOpen = inner.open
    .replace(/\swidth="[^"]*"/, '').replace(/\sheight="[^"]*"/, '')
    .replace(/\sstyle="[^"]*"/, '')
    .replace('<svg', `<svg x="${dx}" y="${y}" width="${inner.w}" height="${inner.h}"`);
  parts.push(nestedOpen + inner.body + '</svg>');
  y += inner.h + 30 * k;

  // ---- incoming panel (module diagrams)
  if (!opts.isFull) {
    const fs = 15 * k;
    const maxChars = Math.floor((W - 2 * PAD - 40 * k) / (fs * 0.53));
    const byParent = new Map();
    for (const l of opts.incoming) {
      if (!byParent.has(l.parent)) byParent.set(l.parent, new Map());
      const mod = opts.moduleOf[l.child].title;
      const m = byParent.get(l.parent);
      if (!m.has(mod)) m.set(mod, []);
      m.get(mod).push(`${l.child}.${l.col} (${l.label})${l.kind === 'model' ? ' [model-only]' : ''}`);
    }
    const rows = [];
    for (const [parent, mods] of byParent) {
      rows.push({ bold: parent });
      for (const [mod, items] of mods) {
        for (const [i, ln] of wrap(`${mod}: ${items.join(', ')}`, maxChars - 6).entries()) rows.push({ plain: (i ? '    ' : '') + ln });
      }
    }
    const heading = 'REFERENCED FROM OTHER MODULES  (these links point INTO this module; they are drawn in the other module’s diagram and in the full ERD)';
    const lh = 21 * k;
    const ph = 46 * k + (rows.length ? rows.length * lh : lh) + 18 * k;
    parts.push(`<rect x="${PAD}" y="${y}" width="${W - 2 * PAD}" height="${ph}" rx="${8 * k}" fill="#ffffff" stroke="${RULE}" stroke-width="${1.5 * k}"/>`);
    parts.push(text(PAD + 20 * k, y + 30 * k, heading, k, { size: 15, weight: 700, fill: MUTED }));
    let ry = y + 56 * k;
    if (!rows.length) parts.push(text(PAD + 20 * k, ry, 'No table in another module has a foreign key or model relationship into this module.', k, { size: 15 }));
    for (const r of rows) {
      if (r.bold) parts.push(text(PAD + 20 * k, ry, r.bold, k, { size: 15, weight: 700 }));
      else parts.push(`<text x="${PAD + 40 * k}" y="${ry}" ${FONT} font-size="${fs}" fill="${INK}" xml:space="preserve">${esc(r.plain)}</text>`);
      ry += lh;
    }
    y += ph + 30 * k;
  }

  const H = y + 4 * k;
  const svg = `<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="${Math.ceil(W)}" height="${Math.ceil(H)}" viewBox="0 0 ${Math.ceil(W)} ${Math.ceil(H)}">\n<title>${esc(opts.title)}</title>\n<rect width="100%" height="100%" fill="#ffffff"/>\n${parts.join('\n')}\n</svg>\n`;
  return { svg, width: Math.ceil(W), height: Math.ceil(H) };
}
