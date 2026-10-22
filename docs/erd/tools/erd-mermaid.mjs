// Builds the Mermaid erDiagram source text from the model.
import { MODULES } from './erd-model.mjs';

// Mermaid's attribute-type token cannot contain a comma or a quote, so:
//   enum('a','b')  -> enum          (the values are in the data dictionary)
//   decimal(10,2)  -> decimal(10_2) (build.mjs turns this back into decimal(10,2) in the SVG text)
//   integer display widths are dropped (bigint(20) -> bigint); tinyint(1) is kept (boolean flag)
export function typeToken(c) {
  let t = c.type.toLowerCase();
  if (/^enum\(/.test(t)) return 'enum';
  if (/^set\(/.test(t)) return 'set';
  t = t.replace(/\s+unsigned/g, '').replace(/\s+zerofill/g, '');
  if (/^(bigint|int|smallint|mediumint)\(\d+\)$/.test(t)) t = t.replace(/\(\d+\)/, '');
  if (/^tinyint\((?!1\))\d+\)$/.test(t)) t = 'tinyint';
  return t.replace(/\((\d+),(\d+)\)/, '($1_$2)');
}

function attrLines(model, tableName) {
  const T = model.schema.tables[tableName];
  const modelOnlyCols = new Set(model.modelOnly.filter((l) => l.child === tableName).map((l) => l.col));
  return T.columns.map((c) => {
    const keys = [];
    if (T.pkCols.includes(c.name)) keys.push('PK');
    if (T.fkCols.has(c.name)) keys.push('FK');
    if (T.uniqueSingle.has(c.name)) keys.push('UK');
    const notes = [];
    for (const idx of T.uniqueComposite) {
      if (idx.columns.includes(c.name)) notes.push('UK with ' + idx.columns.filter((x) => x !== c.name).join(', '));
    }
    if (modelOnlyCols.has(c.name)) notes.push('no DB FK (model link)');
    return `        ${typeToken(c)} ${c.name}` + (keys.length ? ' ' + keys.join(', ') : '') + (notes.length ? ` "${notes.join(' | ')}"` : '');
  });
}

export function cardinality(l) {
  const left = l.nullable ? '|o' : '||';      // parent end: nullable FK = zero-or-one parent
  const right = l.unique ? 'o|' : 'o{';       // child end: UNIQUE FK = at most one (one-to-one)
  return left + (l.kind === 'fk' ? '--' : '..') + right;
}

const line = (l) => `    ${l.parent} ${cardinality(l)} ${l.child} : "${l.label}"`;
const sortLinks = (a, b) => (a.parent + a.child + a.col).localeCompare(b.parent + b.child + b.col);

/** scope: null = every application table, or a MODULES entry. */
export function buildMermaid(model, scope, headerNote) {
  const local = scope ? scope.tables : model.appNames;
  const localSet = new Set(local);
  // A link is drawn in the module that OWNS the FK column (the child table). The parent, when it lives in
  // another module, is drawn as a grey stub. Links pointing INTO this module from other modules are not
  // drawn (a hub like users or branches would need ~25 stubs); they are returned as `incoming` and listed
  // in a panel under the diagram instead. The full ERD (scope = null) draws every link.
  const links = model.links.filter((l) => localSet.has(l.child)).sort(sortLinks);
  const incoming = model.links.filter((l) => localSet.has(l.parent) && !localSet.has(l.child)).sort(sortLinks);
  const stubs = [...new Set(links.map((l) => l.parent).filter((t) => !localSet.has(t)))].sort();

  const out = [];
  out.push(`%% ${headerNote}`);
  out.push('%% Solid line = database foreign key. Dotted line = model-only relationship (Eloquent, no database FK).');
  out.push('%% In this source "decimal(10_2)" stands for decimal(10,2): Mermaid cannot parse a comma inside a type.');
  out.push('erDiagram');

  for (const t of local) {
    out.push(`    ${t} {`, ...attrLines(model, t), '    }');
  }
  for (const t of stubs) {
    const m = model.moduleOf[t];
    out.push(`    ${t} {`, `        bigint id PK "table of module: ${m.short}"`, '    }');
  }
  out.push('');
  for (const l of links) out.push(line(l));
  out.push('');

  // colour: one class per module in the full diagram; own module colour + grey dashed stubs in a module diagram
  for (const m of MODULES) {
    out.push(`    classDef mod_${m.key} fill:${m.fill},stroke:${m.stroke},stroke-width:2px,color:#111827`);
  }
  out.push('    classDef ext fill:#f1f5f9,stroke:#94a3b8,stroke-width:2px,stroke-dasharray:5 4,color:#475569');
  for (const m of MODULES) {
    const names = local.filter((t) => model.moduleOf[t] === m);
    if (names.length) out.push(`    class ${names.join(',')} mod_${m.key}`);
  }
  if (stubs.length) out.push(`    class ${stubs.join(',')} ext`);
  return { text: out.join('\n') + '\n', local, stubs, links, incoming };
}
