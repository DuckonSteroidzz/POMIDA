// Self-contained HTML data dictionary (inline CSS, one tiny inline script, no external requests).
import { MODULES, PURPOSE, FRAMEWORK, IMPLICIT } from './erd-model.mjs';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function defaultCell(c) {
  if (c.extra && /auto_increment/i.test(c.extra)) return '<span class="dim">auto_increment</span>';
  if (c.default === null || c.default === undefined) return c.null ? '<span class="dim">NULL</span>' : '<span class="dim">&mdash;</span>';
  const extra = c.extra && /on update/i.test(c.extra) ? ` <span class="dim">${esc(c.extra)}</span>` : '';
  if (c.default === 'NULL') return '<span class="dim">NULL</span>' + extra;
  return `<code>${esc(c.default)}</code>${extra}`;
}

function tableCard(model, name, meta) {
  const T = model.schema.tables[name];
  const fkByCol = new Map(T.fks.flatMap((f) => f.columns.map((c, i) => [c, f])));
  const modelByCol = new Map(model.modelOnly.filter((l) => l.child === name).map((l) => [l.col, l]));
  const incoming = model.links.filter((l) => l.parent === name && l.child !== name);
  const compIdx = T.uniqueComposite;

  const rows = T.columns.map((c, i) => {
    const keys = [];
    if (T.pkCols.includes(c.name)) keys.push('<span class="k pk">PK</span>');
    if (T.fkCols.has(c.name)) keys.push('<span class="k fk">FK</span>');
    if (T.uniqueSingle.has(c.name)) keys.push('<span class="k uk">UK</span>');
    if (compIdx.some((x) => x.columns.includes(c.name))) keys.push('<span class="k uc" title="part of a composite unique key">UK*</span>');
    let ref = '';
    const fk = fkByCol.get(c.name);
    if (fk) ref = `&rarr; <a href="#t-${fk.ref_table}">${esc(fk.ref_table)}</a>.${esc(fk.ref_columns[0])} <span class="dim">ON DELETE ${esc(fk.on_delete)}</span>`;
    else if (modelByCol.has(c.name)) {
      const l = modelByCol.get(c.name);
      ref = `&rarr; <a href="#t-${l.parent}">${esc(l.parent)}</a>.id <span class="warn">model-only, no database FK</span>`;
    } else if (name === 'free_dessert_rewards' && c.name === 'used_branch_id') ref = '<span class="dim">&rarr; branches.id (implied, not declared anywhere)</span>';
    else if (name === 'stock_movements' && c.name === 'reference_id') ref = '<span class="dim">&rarr; orders.id when source = &ldquo;order&rdquo; (implied)</span>';
    const note = c.type.startsWith('enum(') ? '' : '';
    return `<tr><td class="n">${i + 1}</td><td><b>${esc(c.name)}</b></td><td class="ty"><code>${esc(c.type).replace(/,/g, ',<wbr>')}</code>${note}</td>`
      + `<td class="c">${c.null ? 'YES' : 'NO'}</td><td class="c">${keys.join(' ')}</td><td>${defaultCell(c)}</td><td>${ref}</td></tr>`;
  }).join('\n');

  const notes = [];
  for (const idx of compIdx) notes.push(`<b>UK*</b> unique together: (${idx.columns.map(esc).join(', ')}) <span class="dim">&mdash; index ${esc(idx.name)}</span>`);
  if (incoming.length) {
    const byChild = incoming.map((l) => `<a href="#t-${l.child}">${esc(l.child)}</a>.${esc(l.col)}${l.kind === 'model' ? ' <span class="warn">(model-only)</span>' : ''}`);
    notes.push(`<b>Referenced by:</b> ${byChild.join(', ')}`);
  }
  const impl = IMPLICIT.filter((x) => x.from.startsWith(name + ' ') || x.to.startsWith(name + ' ') || x.from.startsWith(name + '.') || x.to.startsWith(name + '.'));
  for (const x of impl) notes.push(`<b>Implied link (no FK, no model relation):</b> ${esc(x.from)} &harr; ${esc(x.to)} <span class="dim">&mdash; ${esc(x.note)}</span>`);

  return `<section class="tbl" id="t-${name}" data-name="${name}">
<h3>${esc(name)} <span class="pill" style="background:${meta.fill};border-color:${meta.stroke}">${esc(meta.title)}</span></h3>
<p class="purpose">${esc(PURPOSE[name])}</p>
<p class="meta">${T.columns.length} columns &middot; ${T.fks.length} foreign key${T.fks.length === 1 ? '' : 's'} &middot; engine ${esc(T.engine)}</p>
<div class="scroll"><table class="cols"><thead><tr><th>#</th><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>References</th></tr></thead>
<tbody>
${rows}
</tbody></table></div>
${notes.length ? '<ul class="notes">' + notes.map((n) => `<li>${n}</li>`).join('') + '</ul>' : ''}
</section>`;
}

export function buildDictionary(model, ctx) {
  const { schema } = model;
  const appCols = model.appNames.reduce((a, n) => a + schema.tables[n].columns.length, 0);
  const allCols = Object.values(schema.tables).reduce((a, t) => a + t.columns.length, 0);
  const fkCount = model.links.filter((l) => l.kind === 'fk').length;
  const delRules = {};
  for (const l of model.links.filter((x) => x.kind === 'fk')) delRules[l.onDelete] = (delRules[l.onDelete] || 0) + 1;

  const nav = MODULES.map((m) => `<div class="navmod"><a class="navh" href="#m-${m.key}" style="border-left-color:${m.stroke}">${esc(m.title)}</a>`
    + m.tables.map((t) => `<a class="navt" href="#t-${t}" data-name="${t}">${esc(t)}</a>`).join('') + '</div>').join('\n')
    + '<div class="navmod"><a class="navh" href="#appendix" style="border-left-color:#64748b">Appendix</a>'
    + ['framework', 'modelonly', 'dead', 'implicit', 'nomodel', 'rules'].map((id, i) => `<a class="navt" href="#ap-${id}">${['A. Framework tables', 'B. Model-only relationships', 'C. Dead model relationships', 'D. Implied links', 'E. FKs without a model relation', 'F. ON DELETE rules'][i]}</a>`).join('')
    + '</div>';

  const modulesHtml = MODULES.map((m) => `<h2 id="m-${m.key}" style="border-left-color:${m.stroke}">${esc(m.title)} <span class="cnt">${m.tables.length} tables</span></h2>\n`
    + m.tables.map((t) => tableCard(model, t, m)).join('\n')).join('\n');

  const fwRows = model.frameworkInDb.map((n) => `<tr><td><b>${esc(n)}</b></td><td>${schema.tables[n].columns.length}</td><td>${esc(FRAMEWORK[n])}</td></tr>`).join('');
  const absentFw = Object.keys(FRAMEWORK).filter((n) => !schema.tables[n]);
  const moRows = model.modelOnly.map((l) => `<tr><td><b>${esc(l.child)}.${esc(l.col)}</b></td><td>${esc(l.parent)}.id</td><td>${l.nullable ? 'NULL' : 'NOT NULL'}</td><td>${esc(l.via.join(', '))}</td></tr>`).join('');
  const deadRows = model.deadModel.map((l) => `<tr><td>${esc(l.via.join(', '))}</td><td>${esc(l.child)}.${esc(l.col)}</td><td>No such column exists in <b>${esc(l.child)}</b>, so the relationship cannot work (it would fail with &ldquo;Unknown column&rdquo; if used).</td></tr>`).join('');
  const implRows = IMPLICIT.map((x) => `<tr><td>${esc(x.from)}</td><td>${esc(x.to)}</td><td>${esc(x.note)}</td></tr>`).join('');
  const nomodelRows = model.fksWithoutModel.map((k) => `<tr><td>${esc(k)}</td></tr>`).join('');
  const ruleRows = Object.entries(delRules).sort().map(([r, n]) => `<tr><td>${esc(r)}</td><td>${n}</td></tr>`).join('');

  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>POMIDA Data Dictionary</title>
<style>
:root{--ink:#111827;--muted:#475569;--rule:#cbd5e1;--bg:#ffffff;--soft:#f8fafc;--accent:#1d4ed8}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 "Segoe UI",Arial,sans-serif;color:var(--ink);background:var(--bg);display:flex}
nav{position:sticky;top:0;align-self:flex-start;height:100vh;overflow:auto;width:270px;flex:none;padding:18px 14px;border-right:1px solid var(--rule);background:var(--soft);font-size:13.5px}
nav input{width:100%;padding:7px 9px;border:1px solid var(--rule);border-radius:6px;margin-bottom:12px;font:inherit}
.navmod{margin-bottom:12px}
.navh{display:block;font-weight:700;color:var(--ink);text-decoration:none;border-left:5px solid;padding:2px 8px;margin-bottom:2px}
.navt{display:block;color:var(--muted);text-decoration:none;padding:1px 8px 1px 18px}
.navt:hover,.navh:hover{color:var(--accent)}
main{flex:1;min-width:0;padding:28px 36px 80px;max-width:1280px}
h1{margin:0 0 4px;font-size:30px}
.sub{color:var(--muted);margin:0 0 18px}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0 24px}
.summary div{border:1px solid var(--rule);border-radius:8px;padding:10px 12px;background:var(--soft)}
.summary b{display:block;font-size:24px}
.summary span{color:var(--muted);font-size:13px}
h2{margin:42px 0 10px;font-size:23px;border-left:7px solid;padding-left:12px}
h2 .cnt{font-size:13px;font-weight:400;color:var(--muted);margin-left:8px}
.tbl{margin:0 0 30px;padding-top:6px;scroll-margin-top:8px}
h3{margin:0;font-size:19px;font-family:Consolas,"Courier New",monospace}
.pill{font:600 11px "Segoe UI",Arial,sans-serif;border:1.5px solid;border-radius:999px;padding:1px 9px;margin-left:8px;vertical-align:middle}
.purpose{margin:4px 0 2px}
.meta{margin:0 0 8px;color:var(--muted);font-size:13px}
.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%}
table.cols th{background:#e2e8f0;text-align:left;font-size:12.5px;text-transform:uppercase;letter-spacing:.03em}
table.cols th,table.cols td{border:1px solid var(--rule);padding:4px 8px;vertical-align:top}
table.cols td.n{color:var(--muted);text-align:right;width:34px}
table.cols td.c{text-align:center;white-space:nowrap}
table.cols td.ty{max-width:300px}
table.cols td:last-child{min-width:230px}
table.cols tbody tr:nth-child(even){background:#f8fafc}
code{font-family:Consolas,"Courier New",monospace;font-size:13px}
.k{display:inline-block;font:700 11px "Segoe UI",Arial,sans-serif;border-radius:4px;padding:1px 5px;color:#fff}
.pk{background:#b45309}.fk{background:#1d4ed8}.uk{background:#047857}.uc{background:#0f766e}
.dim{color:var(--muted)}
.warn{color:#b91c1c;font-weight:600;font-size:12.5px}
.notes{margin:6px 0 0;padding-left:20px;color:var(--ink);font-size:13.5px}
a{color:var(--accent)}
.ap table{margin:8px 0 18px}
.ap th,.ap td{border:1px solid var(--rule);padding:5px 9px;text-align:left;vertical-align:top}
.ap th{background:#e2e8f0}
.legend{font-size:13.5px;color:var(--muted)}
.hidden{display:none}
@media print{nav{display:none}body{display:block}main{padding:0;max-width:none}.tbl{break-inside:avoid-page}h2{break-after:avoid}}
@media (max-width:820px){body{display:block}nav{position:static;height:auto;width:auto}main{padding:18px}}
</style></head>
<body>
<nav><input id="q" type="search" placeholder="Filter tables&hellip;" aria-label="Filter tables">${nav}</nav>
<main>
<h1>POMIDA Database &mdash; Data Dictionary</h1>
<p class="sub">Database <b>${esc(schema.database)}</b> (${esc(schema.server_version)}) &middot; read from <code>information_schema</code> on <b>${esc(ctx.date)}</b> &middot; ${esc(ctx.source)}</p>
<div class="summary">
<div><b>${model.appNames.length}</b><span>application tables</span></div>
<div><b>${appCols}</b><span>columns in those tables</span></div>
<div><b>${fkCount}</b><span>database foreign keys</span></div>
<div><b>${model.modelOnly.length}</b><span>model-only relationships (no FK)</span></div>
<div><b>${model.frameworkInDb.length}</b><span>Laravel framework tables (appendix)</span></div>
<div><b>${Object.keys(schema.tables).length}</b><span>tables in the database in total</span></div>
</div>
<p class="legend">Key column: <span class="k pk">PK</span> primary key &middot; <span class="k fk">FK</span> declared foreign key &middot; <span class="k uk">UK</span> unique key on this column alone &middot; <span class="k uc">UK*</span> part of a composite unique key (listed under the table). &ldquo;References&rdquo; shows the parent table and the <b>ON DELETE</b> rule. Types are the exact MariaDB column types (including UNSIGNED and enum values).</p>
${modulesHtml}
<h2 id="appendix" style="border-left-color:#64748b">Appendix</h2>
<div class="ap">
<h3 id="ap-framework" style="font-family:inherit">A. Laravel framework tables (left out of the diagrams)</h3>
<table><thead><tr><th>Table</th><th>Columns</th><th>Purpose</th></tr></thead><tbody>${fwRows}</tbody></table>
${absentFw.length ? `<p class="dim">Not present in this database: ${absentFw.map(esc).join(', ')}.</p>` : ''}
<h3 id="ap-modelonly" style="font-family:inherit">B. Model-only relationships (declared in an Eloquent model, no database foreign key)</h3>
<table><thead><tr><th>Column</th><th>Points to</th><th>Nullable</th><th>Declared by</th></tr></thead><tbody>${moRows || '<tr><td colspan="4" class="dim">None: every relationship a model declares is backed by a database foreign key.</td></tr>'}</tbody></table>
<h3 id="ap-dead" style="font-family:inherit">C. Dead model relationships (declared in code, no backing column at all)</h3>
<table><thead><tr><th>Declared by</th><th>Expected column</th><th>Finding</th></tr></thead><tbody>${deadRows || '<tr><td colspan="3" class="dim">None: every relationship a model declares has its column in the database.</td></tr>'}</tbody></table>
<h3 id="ap-implicit" style="font-family:inherit">D. Implied links (values match, but there is neither a foreign key nor a model relationship)</h3>
<table><thead><tr><th>From</th><th>To</th><th>Note</th></tr></thead><tbody>${implRows}</tbody></table>
<h3 id="ap-nomodel" style="font-family:inherit">E. Foreign keys that no model declares as a relationship (still real FKs, drawn solid)</h3>
<table><thead><tr><th>child.column &rarr; parent</th></tr></thead><tbody>${nomodelRows}</tbody></table>
<h3 id="ap-rules" style="font-family:inherit">F. ON DELETE rules used by the ${fkCount} foreign keys</h3>
<table><thead><tr><th>Rule</th><th>Foreign keys</th></tr></thead><tbody>${ruleRows}</tbody></table>
<p class="dim">Every foreign key uses ON UPDATE RESTRICT. Counts above: ${model.appNames.length} application + ${model.frameworkInDb.length} framework = ${Object.keys(schema.tables).length} tables; ${appCols} application columns + ${allCols - appCols} framework columns = ${allCols}.</p>
</div>
</main>
<script>
(function(){var q=document.getElementById('q');if(!q)return;q.addEventListener('input',function(){var v=q.value.trim().toLowerCase();
document.querySelectorAll('.tbl').forEach(function(s){s.classList.toggle('hidden',v&&s.getAttribute('data-name').indexOf(v)<0);});
document.querySelectorAll('.navt[data-name]').forEach(function(a){a.classList.toggle('hidden',v&&a.getAttribute('data-name').indexOf(v)<0);});});})();
</script>
</body></html>
`;
}
