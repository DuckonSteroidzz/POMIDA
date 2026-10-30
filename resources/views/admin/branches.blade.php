@extends('admin.layout')

@section('title', 'Branches - Peachy Admin')

{{--
    Branch Management — redesign to docs/design-refs/peachy-branch.html
    (Defect Report 5, item 2, Oct 2026).

    Rows are the REAL branches (AdminController::showBranches()), rendered here;
    search, the All/Open/Inactive filter and paging run in the page over those
    rows. The "Viewing:" bar narrows the list to one branch and opens its
    details. Routes are the same three as before: admin.branches.store,
    admin.branches.update (now also carries GCash Payment Settings) and
    admin.branches.toggle. Branches are never deleted.

    Every style is scoped under .bm so the design's generic names (.btn,
    .modal, .badge, .card) cannot touch the admin layout.
--}}

@php
    $initials = function (string $name): string {
        $words = preg_split('/\s+/', trim(preg_replace('/peachy\s*caf[eé]/iu', '', $name))) ?: [];
        $letters = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_filter($words)));

        return mb_strtoupper(mb_substr($letters, 0, 2)) ?: 'PC';
    };

    $hours = function ($branch): string {
        if (!$branch->opening_time || !$branch->closing_time) {
            return '—';
        }

        return \Carbon\Carbon::parse($branch->opening_time)->format('g:i A')
            . ' – ' . \Carbon\Carbon::parse($branch->closing_time)->format('g:i A');
    };

    $openCount = $branches->where('is_active', true)->count();

    // Re-open the right form after a failed save, with what was typed.
    $oldForm = old('_form');
    $oldInput = collect(session()->getOldInput())->only([
        '_form', '_branch_id', 'name', 'code', 'address', 'contact_number', 'email',
        'opening_time', 'closing_time', 'facebook_url', 'instagram_url', 'tiktok_url',
        'other_social_url', 'gcash_number', 'gcash_enabled', 'is_active',
    ]);

    // What the page does on arrival: re-open a failed form, or open the
    // branch chosen in the Viewing bar.
    $bootData = [
        'open' => $openBranchId,
        'form' => $errors->any() ? $oldForm : null,
        'old' => $errors->any() ? $oldInput : (object) [],
    ];
@endphp

@section('content')
<div class="bm">
<style>
.bm{
  --peach:#F4845F; --peach-deep:#E06A44; --peach-soft:#FDF1E6; --peach-tint:#FBE7E4;
  --cream:#FFFBF7; --sand:#FDF6EF; --line:#F0E2D5;
  --brown:#3B2A24; --brown-soft:#8B7A72; --brown-mute:#C4B6AE;
  --ok:#2E7D5B; --ok-bg:#E6F4EC; --danger:#C0392B; --danger-bg:#FBE7E4;
  --radius:16px; --shadow:0 1px 2px rgba(59,42,36,.04),0 8px 24px -12px rgba(59,42,36,.18);
  --shadow-lg:0 24px 60px -20px rgba(59,42,36,.35);
  --display:"Fraunces",Georgia,serif;
  color:var(--brown);font-size:14px;max-width:1240px;margin:0 auto;padding-bottom:3rem;
}
.bm.bm-portal{max-width:none;margin:0;padding:0}
.bm *{box-sizing:border-box}
.bm [hidden]{display:none!important}
.bm button{font-family:inherit;cursor:pointer}
.bm .bm-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.bm .bm-offscreen{position:absolute;top:-9999px;left:-9999px}

.bm .bm-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem}
.bm .bm-eyebrow{margin:0 0 .25rem;font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--peach-deep)}
.bm .bm-title{font-family:var(--display);font-size:clamp(1.8rem,4vw,2.6rem);margin:0;line-height:1.05;letter-spacing:-.01em;font-weight:700;color:var(--brown)}
.bm .bm-hero-sub{margin:.4rem 0 0;color:var(--brown-soft)}

.bm .bm-alert{display:flex;gap:.5rem;align-items:center;background:var(--danger-bg);color:var(--danger);border:1px solid #F3C9C2;padding:.8rem 1rem;border-radius:12px;margin-bottom:1rem;font-weight:500}
.bm .bm-form-errors{background:var(--danger-bg);color:var(--danger);border:1px solid #F3C9C2;border-radius:12px;padding:.7rem .9rem;margin:0 0 1rem;font-weight:500;font-size:.82rem}
.bm .bm-form-errors ul{margin:0;padding-left:1.1rem}

.bm .bm-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-bottom:1.25rem}
.bm .bm-stat{display:flex;gap:.9rem;align-items:center;background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:1rem 1.1rem;box-shadow:var(--shadow)}
.bm .bm-stat-ico{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--peach-soft);color:var(--peach-deep);font-size:1.2rem;flex-shrink:0}
.bm .bm-stat-ico.ok{background:var(--ok-bg);color:var(--ok)} .bm .bm-stat-ico.off{background:#F3EEEA;color:var(--brown-soft)}
.bm .bm-stat-num{font-family:var(--display);font-size:1.6rem;margin:0;line-height:1;font-weight:700}
.bm .bm-stat-lbl{margin:.2rem 0 0;font-size:.75rem;color:var(--brown-soft)}

.bm .bm-card{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow);overflow:hidden}
.bm .bm-list-head{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center;padding:1.1rem 1.25rem;border-bottom:1px solid var(--line);background:linear-gradient(180deg,var(--cream),#fff)}
.bm .bm-list-head>div:first-child{display:flex;align-items:center;gap:.6rem}
.bm .bm-card-title{font-family:var(--display);font-size:1.25rem;margin:0;font-weight:700}
.bm .bm-chip{background:var(--peach-soft);color:var(--peach-deep);font-weight:700;font-size:.72rem;padding:.25rem .65rem;border-radius:999px}
.bm .bm-filters{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.bm .bm-search{position:relative;min-width:260px;flex:1}
.bm .bm-search i{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--brown-mute)}
.bm .bm-search input{width:100%;padding:.6rem .9rem .6rem 2.3rem;border:1px solid var(--line);border-radius:12px;background:#fff;font:inherit;color:var(--brown);outline:none;transition:.15s}
.bm .bm-search input:focus,.bm .bm-input:focus{border-color:var(--peach);box-shadow:0 0 0 3px rgba(244,132,95,.18)}
.bm .bm-seg{display:inline-flex;background:var(--sand);border:1px solid var(--line);border-radius:12px;padding:3px}
.bm .bm-seg-btn{border:0;background:transparent;padding:.45rem .8rem;border-radius:9px;font-weight:600;font-size:.8rem;color:var(--brown-soft)}
.bm .bm-seg-btn.active{background:#fff;color:var(--brown);box-shadow:0 1px 3px rgba(59,42,36,.12)}

.bm .bm-btn{display:inline-flex;align-items:center;gap:.45rem;justify-content:center;border:1px solid transparent;border-radius:12px;padding:.6rem 1rem;font-weight:600;font-size:.85rem;transition:.15s;white-space:nowrap;text-decoration:none}
.bm .bm-btn-primary{background:linear-gradient(135deg,var(--peach),var(--peach-deep));color:#fff;box-shadow:0 6px 16px -6px rgba(224,106,68,.6)}
.bm .bm-btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 20px -8px rgba(224,106,68,.7)}
.bm .bm-btn-ghost{background:#fff;border-color:var(--line);color:var(--brown)}
.bm .bm-btn-ghost:hover{background:var(--sand)}
.bm .bm-btn-open{background:var(--ok-bg);color:var(--ok);border-color:#C8E6D5}
.bm .bm-btn-danger{background:var(--brown);color:#fff}
.bm .bm-btn-danger:hover{background:#2a1d18}
.bm .bm-icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid transparent;background:transparent;color:var(--brown-soft);display:grid;place-items:center;font-size:1rem;transition:.15s}
.bm .bm-icon-btn:hover,.bm .bm-icon-btn[aria-expanded=true]{background:var(--peach-soft);color:var(--peach-deep)}

.bm .bm-table-wrap{overflow-x:auto}
.bm .bm-table{width:100%;border-collapse:collapse}
.bm .bm-table th{text-align:left;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--brown-soft);padding:.8rem 1.25rem;background:var(--cream);border-bottom:1px solid var(--line)}
.bm .bm-table td{padding:.9rem 1.25rem;border-bottom:1px solid #F7EDE4;vertical-align:middle}
.bm .bm-table .c{text-align:center}
.bm .bm-row{cursor:pointer;transition:background .12s}
.bm .bm-row:hover{background:var(--cream)}
.bm .bm-row:focus-visible{outline:2px solid var(--peach);outline-offset:-2px}
.bm .bm-name{display:flex;align-items:center;gap:.7rem;font-weight:700}
.bm .bm-av{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--peach-soft),var(--peach-tint));color:var(--peach-deep);display:grid;place-items:center;font-weight:700;font-size:.75rem;flex-shrink:0}
.bm .bm-code{font-family:ui-monospace,monospace;font-size:.78rem;background:var(--sand);border:1px solid var(--line);padding:.15rem .45rem;border-radius:6px;color:var(--brown)}
.bm .bm-addr{color:var(--brown-soft);max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bm .soft{color:var(--brown-soft)} .bm .nowrap{white-space:nowrap}
.bm .bm-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700}
.bm .bm-badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.bm .bm-badge-ok{background:var(--ok-bg);color:var(--ok)} .bm .bm-badge-off{background:#F3EEEA;color:var(--brown-soft)}
.bm .bm-badge-main{background:var(--peach);color:#fff} .bm .bm-badge-main::before{display:none}

.bm .bm-empty{text-align:center;padding:3rem 1rem;color:var(--brown-soft)}
.bm .bm-empty i{font-size:2rem;color:var(--peach)} .bm .bm-empty p{font-weight:700;color:var(--brown);margin:.5rem 0 .2rem}

.bm .bm-pager{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;padding:.9rem 1.25rem;background:var(--cream)}
.bm .bm-pager-info{display:flex;gap:1rem;align-items:center;color:var(--brown-soft);font-size:.8rem}
.bm .bm-pager-size{display:flex;gap:.4rem;align-items:center}
.bm .bm-pager-nav{display:flex;gap:.35rem;align-items:center}
.bm .bm-page-list{display:flex;gap:.25rem}
.bm .bm-page-btn{border:1px solid var(--line);background:#fff;border-radius:9px;min-width:34px;height:34px;padding:0 .6rem;font-weight:600;font-size:.8rem;color:var(--brown);display:inline-flex;align-items:center;justify-content:center;gap:.2rem}
.bm .bm-page-btn:hover:not(:disabled){border-color:var(--peach);color:var(--peach-deep)}
.bm .bm-page-btn.active{background:var(--peach);border-color:var(--peach);color:#fff}
.bm .bm-page-btn:disabled{opacity:.4;cursor:not-allowed}
.bm .bm-page-dots{padding:0 .3rem;color:var(--brown-mute)}

.bm .bm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}
.bm .bm-span-2{grid-column:span 2}
.bm .bm-field{display:flex;flex-direction:column;gap:.35rem;min-width:0}
.bm .bm-label{font-size:.75rem;font-weight:600;color:var(--brown)}
.bm .bm-input{width:100%;padding:.62rem .8rem;border:1px solid var(--line);border-radius:10px;background:#fff;font:inherit;color:var(--brown);outline:none;transition:.15s}
.bm .bm-input-sm{width:auto;padding:.3rem .5rem;font-size:.8rem}
.bm .bm-upper{text-transform:uppercase}
.bm .bm-hint{font-size:.72rem;color:var(--brown-mute);margin:0}
.bm .bm-input-ico{position:relative}
.bm .bm-input-ico i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--peach)}
.bm .bm-input-ico .bm-input{padding-left:2.2rem}
.bm .bm-divider{height:1px;background:var(--line);margin:1.25rem 0}
.bm .bm-section-title{font-weight:700;margin:0 0 .25rem;display:flex;gap:.45rem;align-items:center;flex-wrap:wrap}
.bm .bm-section-title i{color:var(--peach)}
.bm .bm-switch-row{display:flex;align-items:center;gap:.7rem;cursor:pointer;font-weight:500}
.bm .bm-switch{appearance:none;-webkit-appearance:none;width:42px;height:24px;border-radius:999px;background:var(--brown-mute);position:relative;cursor:pointer;transition:.2s;margin:0;flex-shrink:0}
.bm .bm-switch::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.bm .bm-switch:checked{background:var(--ok)} .bm .bm-switch:checked::after{left:21px}

.bm .bm-menu{position:fixed;z-index:1120;min-width:200px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow-lg);padding:.35rem;animation:bmPop .14s ease-out}
.bm .bm-menu button{display:flex;gap:.6rem;align-items:center;width:100%;border:0;background:transparent;padding:.6rem .7rem;border-radius:8px;font-size:.85rem;color:var(--brown);text-align:left}
.bm .bm-menu button:hover{background:var(--peach-soft)}
.bm .bm-menu button.danger{color:var(--danger)} .bm .bm-menu button.danger:hover{background:var(--danger-bg)}
.bm .bm-menu button.okc{color:var(--ok)} .bm .bm-menu button.okc:hover{background:var(--ok-bg)}
.bm .bm-menu-sep{height:1px;background:var(--line);margin:.3rem 0}

.bm .bm-modal{position:fixed;inset:0;z-index:1100;display:none;align-items:center;justify-content:center;padding:1rem}
.bm .bm-modal.open{display:flex}
.bm .bm-modal-backdrop{position:absolute;inset:0;background:rgba(59,42,36,.45);backdrop-filter:blur(3px);animation:bmFade .2s}
.bm .bm-modal-panel{position:relative;background:#fff;border-radius:22px;width:100%;max-width:560px;max-height:calc(100vh - 2rem);display:flex;flex-direction:column;box-shadow:var(--shadow-lg);animation:bmRise .22s cubic-bezier(.2,.8,.2,1);overflow:hidden}
.bm .bm-modal-panel>form,.bm .bm-modal-panel>div.bm-dview{display:flex;flex-direction:column;min-height:0;flex:1}
.bm .bm-modal-lg{max-width:720px} .bm .bm-modal-sm{max-width:420px}
.bm .bm-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:1.25rem 1.4rem;border-bottom:1px solid var(--line)}
.bm .bm-modal-head h3{font-family:var(--display);font-size:1.35rem;margin:0;font-weight:700}
.bm .bm-detail-head{background:linear-gradient(135deg,var(--peach-soft),var(--cream))}
.bm .bm-ident{display:flex;gap:.9rem;align-items:center;min-width:0}
.bm .bm-avatar{width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,var(--peach),var(--peach-deep));color:#fff;display:grid;place-items:center;font-weight:700;font-size:1.05rem;flex-shrink:0;box-shadow:0 8px 18px -8px rgba(224,106,68,.7)}
.bm .bm-d-badges{display:flex;gap:.35rem;margin-top:.35rem}
.bm .bm-head-actions{display:flex;gap:.2rem;flex-shrink:0}
.bm .bm-modal-body{padding:1.25rem 1.4rem;overflow-y:auto;flex:1}
.bm .bm-modal-foot{display:flex;justify-content:flex-end;gap:.6rem;padding:1rem 1.4rem;border-top:1px solid var(--line);background:var(--cream);flex-wrap:wrap}
.bm .bm-dview .bm-modal-foot{justify-content:space-between}
.bm .bm-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;margin:1rem 0 1.4rem}
.bm .bm-info-grid div{background:var(--sand);border:1px solid var(--line);border-radius:12px;padding:.75rem .9rem}
.bm .bm-info-grid dt{font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--brown-soft)}
.bm .bm-info-grid dd{margin:.25rem 0 0;font-weight:600;word-break:break-word}
.bm .bm-socials{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem}
.bm .bm-social{display:inline-flex;gap:.45rem;align-items:center;padding:.5rem .8rem;border-radius:10px;border:1px solid var(--line);color:var(--brown);text-decoration:none;font-weight:600;font-size:.8rem}
.bm .bm-social i{color:var(--peach)} .bm .bm-social:hover{border-color:var(--peach)}
.bm .bm-social.none{color:var(--brown-mute);font-weight:500}
.bm .bm-confirm-body{text-align:center;padding-top:1.75rem}
.bm .bm-confirm-body h3{font-family:var(--display);margin:.9rem 0 .4rem;font-size:1.3rem;font-weight:700}
.bm .bm-confirm-body p{color:var(--brown-soft);margin:0;line-height:1.5}
.bm .bm-confirm-ico{width:56px;height:56px;border-radius:50%;display:inline-grid;place-items:center;font-size:1.4rem;background:var(--danger-bg);color:var(--danger)}
.bm .bm-confirm-ico.ok{background:var(--ok-bg);color:var(--ok)}

@keyframes bmFade{from{opacity:0}} @keyframes bmRise{from{opacity:0;transform:translateY(12px) scale(.98)}}
@keyframes bmPop{from{opacity:0;transform:scale(.96)}}

.bm .bm-gc-title{margin-top:1.4rem}
.bm .bm-gc-view{display:flex;gap:1rem;align-items:center;margin-top:.75rem;background:var(--sand);border:1px solid var(--line);border-radius:12px;padding:.8rem}
.bm .bm-gc-view img{width:72px;height:72px;border-radius:8px;object-fit:contain;background:#fff;border:1px solid var(--line)}
.bm .bm-gc-view dd{margin:0;font-weight:700} .bm .bm-gc-view dt{font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--brown-soft)}
.bm .bm-gc-view.off{opacity:.65}
.bm .bm-gc-head{display:flex;gap:.85rem;align-items:flex-start;margin-bottom:1rem}
.bm .bm-gc-head-text{flex:1;min-width:0}
.bm .bm-gc-ico{width:42px;height:42px;border-radius:12px;background:var(--peach-soft);color:var(--peach-deep);display:grid;place-items:center;font-size:1.2rem;flex-shrink:0}
.bm .bm-gc-toggle{font-size:.8rem;font-weight:700;flex-shrink:0;padding-top:.3rem}
.bm .bm-gc-body{display:grid;grid-template-columns:180px minmax(0,1fr);gap:1rem;transition:opacity .2s}
.bm .bm-gc-body.disabled{opacity:.45;filter:grayscale(.4)}
.bm .bm-gc-qr{background:var(--cream);border:1px solid var(--line);border-radius:14px;padding:.85rem;text-align:center}
.bm .bm-gc-qr-lbl{margin:0 0 .6rem;font-size:.66rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--brown-soft)}
.bm .bm-gc-qr-box{aspect-ratio:1;border:1.5px dashed var(--line);border-radius:10px;background:#fff;display:grid;place-items:center;overflow:hidden;color:var(--brown-mute);font-size:.75rem;padding:.4rem}
.bm .bm-gc-qr-box img{width:100%;height:100%;object-fit:contain}
.bm .bm-gc-qr-box i{font-size:1.8rem;display:block;margin-bottom:.25rem}
.bm .bm-gc-qr-state{margin:.55rem 0 0;font-size:.75rem;font-weight:700;display:flex;gap:.3rem;justify-content:center;align-items:center}
.bm .bm-gc-qr-state.ok{color:var(--ok)} .bm .bm-gc-qr-state.new{color:var(--peach-deep)} .bm .bm-gc-qr-state.none{color:var(--brown-mute)}
.bm .bm-gc-fields{display:flex;flex-direction:column;gap:1rem;min-width:0}
.bm .bm-drop{display:flex;flex-direction:column;align-items:center;gap:.25rem;text-align:center;border:1.5px dashed #F1CDB8;background:var(--cream);border-radius:12px;padding:1rem;cursor:pointer;transition:.15s;font-size:.82rem}
.bm .bm-drop i{font-size:1.4rem;color:var(--peach)}
.bm .bm-drop:hover,.bm .bm-drop.over{border-color:var(--peach);background:var(--peach-soft)}
.bm .bm-drop.err{border-color:var(--danger)} .bm .bm-drop.err small{color:var(--danger)}
.bm .bm-link-btn{align-self:flex-start;border:0;background:none;color:var(--peach-deep);font-weight:600;font-size:.78rem;padding:0;display:inline-flex;gap:.3rem}
.bm .bm-gc-off-note{display:flex;gap:.5rem;align-items:center;background:#F3EEEA;color:var(--brown-soft);border-radius:10px;padding:.6rem .8rem;font-size:.8rem;margin:.9rem 0 0}

@media (max-width:860px){
  .bm .bm-search{min-width:0;flex-basis:100%}
  .bm .bm-filters{width:100%}
}
@media (max-width:720px){
  .bm .bm-hero .bm-btn{width:100%}
  .bm .bm-stats{gap:.5rem}
  .bm .bm-stat{flex-direction:column;align-items:flex-start;gap:.5rem;padding:.8rem}
  .bm .bm-stat-ico{width:36px;height:36px;font-size:1rem}
  .bm .bm-table thead{display:none}
  .bm .bm-table,.bm .bm-table tbody{display:block}
  .bm .bm-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.35rem .75rem;padding:.9rem 1rem;border-bottom:1px solid #F7EDE4}
  .bm .bm-table td{display:block;padding:0;border:0;text-align:left!important}
  .bm .bm-row td[data-label=Branch]{grid-column:1}
  .bm .bm-row td[data-label=Actions]{grid-column:2;grid-row:1}
  .bm .bm-row td[data-label=Code],.bm .bm-row td[data-label=Status]{display:inline-block}
  .bm .bm-row td[data-label=Code]{grid-column:1}
  .bm .bm-row td[data-label=Status]{grid-column:2;grid-row:2;justify-self:end}
  .bm .bm-row td[data-label=Address],.bm .bm-row td[data-label=Contact],.bm .bm-row td[data-label=Hours]{grid-column:1/-1;font-size:.8rem}
  .bm .bm-row td[data-label=Contact]::before{content:"\F5C1  ";font-family:bootstrap-icons;color:var(--peach)}
  .bm .bm-row td[data-label=Hours]::before{content:"\F293  ";font-family:bootstrap-icons;color:var(--peach)}
  .bm .bm-addr{max-width:none;white-space:normal}
  .bm .bm-pager{flex-direction:column;align-items:stretch}
  .bm .bm-pager-nav{justify-content:space-between}
  .bm .bm-page-btn span{display:none}
  .bm .bm-grid,.bm .bm-info-grid{grid-template-columns:minmax(0,1fr)}
  .bm .bm-gc-body{grid-template-columns:minmax(0,1fr)}
  .bm .bm-gc-qr{max-width:220px;width:100%;margin:0 auto}
  .bm .bm-gc-head{flex-wrap:wrap}
  .bm .bm-span-2{grid-column:auto}
  .bm .bm-modal{padding:0;align-items:flex-end}
  .bm .bm-modal-panel{max-height:92vh;border-radius:22px 22px 0 0;max-width:none}
  .bm .bm-modal-foot .bm-btn{flex:1}
  .bm .bm-dview .bm-modal-foot form{flex:1;display:flex} .bm .bm-dview .bm-modal-foot form .bm-btn{flex:1}
}
</style>

  <header class="bm-hero">
    <div>
      <p class="bm-eyebrow"><i class="bi bi-shop-window"></i> Peachy Cafe</p>
      <h1 class="bm-title">Branch Management</h1>
      <p class="bm-hero-sub">Search, filter and manage all branches</p>
    </div>
    <button type="button" class="bm-btn bm-btn-primary" data-open="bmAddModal" data-testid="add-branch"><i class="bi bi-plus-lg"></i> Add New Branch</button>
  </header>

  @if($errors->any())
    <div class="bm-alert" role="alert" data-testid="branch-error"><i class="bi bi-exclamation-circle"></i> <span>{{ $errors->first() }}</span></div>
  @endif
  @if(session('error'))
    <div class="bm-alert" role="alert"><i class="bi bi-exclamation-circle"></i> <span>{{ session('error') }}</span></div>
  @endif

  <section class="bm-stats" aria-label="Branch totals">
    <div class="bm-stat"><span class="bm-stat-ico"><i class="bi bi-shop"></i></span><div><p class="bm-stat-num" data-stat="total">{{ $branches->count() }}</p><p class="bm-stat-lbl">Total branches</p></div></div>
    <div class="bm-stat"><span class="bm-stat-ico ok"><i class="bi bi-door-open"></i></span><div><p class="bm-stat-num" data-stat="open">{{ $openCount }}</p><p class="bm-stat-lbl">Open</p></div></div>
    <div class="bm-stat"><span class="bm-stat-ico off"><i class="bi bi-door-closed"></i></span><div><p class="bm-stat-num" data-stat="inactive">{{ $branches->count() - $openCount }}</p><p class="bm-stat-lbl">Inactive</p></div></div>
  </section>

  <section class="bm-card">
    <div class="bm-list-head">
      <div>
        <h2 class="bm-card-title">Branch List</h2>
        <span class="bm-chip" id="bmCount">{{ $branches->count() }} {{ $branches->count() === 1 ? 'branch' : 'branches' }}</span>
      </div>
      <div class="bm-filters">
        <div class="bm-search">
          <i class="bi bi-search"></i>
          <input id="bmSearch" type="search" placeholder="Search name, code, address, contact…" aria-label="Search branches" autocomplete="off">
        </div>
        <div class="bm-seg" role="group" aria-label="Status filter">
          <button type="button" class="bm-seg-btn active" data-status="">All</button>
          <button type="button" class="bm-seg-btn" data-status="open">Open</button>
          <button type="button" class="bm-seg-btn" data-status="closed">Inactive</button>
        </div>
        <button type="button" class="bm-btn bm-btn-ghost" id="bmReset"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      </div>
    </div>

    <div class="bm-table-wrap">
      <table class="bm-table">
        <thead>
          <tr>
            <th>Branch</th><th>Code</th><th>Address</th>
            <th class="c">Contact</th><th class="c">Hours</th><th class="c">Status</th><th class="c"><span class="bm-sr">Actions</span></th>
          </tr>
        </thead>
        <tbody id="bmBody">
          @foreach($branches as $branch)
            <tr class="bm-row" tabindex="0"
                data-branch-row="{{ $branch->id }}"
                data-status="{{ $branch->is_active ? 'open' : 'closed' }}"
                data-search="{{ mb_strtolower(implode(' ', [$branch->name, $branch->code, $branch->address, $branch->contact_number, $branch->email])) }}">
              <td data-label="Branch">
                <div class="bm-name">
                  <span class="bm-av">{{ $initials($branch->name) }}</span>
                  <span>{{ $branch->name }}</span>
                  @if($branch->is_main_branch)<span class="bm-badge bm-badge-main">Main</span>@endif
                </div>
              </td>
              <td data-label="Code"><span class="bm-code">{{ $branch->code }}</span></td>
              <td data-label="Address"><div class="bm-addr">{{ $branch->address ?: '—' }}</div></td>
              <td data-label="Contact" class="c soft">{{ $branch->contact_number ?: '—' }}</td>
              <td data-label="Hours" class="c soft nowrap">{{ $hours($branch) }}</td>
              <td data-label="Status" class="c">
                <span class="bm-badge {{ $branch->is_active ? 'bm-badge-ok' : 'bm-badge-off' }}">{{ $branch->is_active ? 'Open' : 'Inactive' }}</span>
              </td>
              <td data-label="Actions" class="c">
                <button type="button" class="bm-icon-btn bm-row-menu" aria-label="Actions for {{ $branch->name }}" aria-haspopup="menu" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="bm-empty" id="bmEmpty" @if($branches->isNotEmpty()) hidden @endif>
        <i class="bi bi-shop"></i><p>No branches yet</p><span>Add your first branch with “Add New Branch”.</span>
      </div>
      <div class="bm-empty" id="bmNoResults" hidden>
        <i class="bi bi-search"></i><p>No branches match your search</p><span>Try a different keyword or clear the filters.</span>
      </div>
    </div>

    <div class="bm-pager" id="bmPager" @if($branches->isEmpty()) hidden @endif>
      <div class="bm-pager-info">
        <span id="bmPagerRange"></span>
        <label class="bm-pager-size">Rows
          <select id="bmPageSize" class="bm-input bm-input-sm" aria-label="Rows per page">
            <option value="15" selected>15</option><option value="25">25</option><option value="50">50</option>
          </select>
        </label>
      </div>
      <div class="bm-pager-nav">
        <button type="button" class="bm-page-btn" id="bmPrev"><i class="bi bi-chevron-left"></i><span> Prev</span></button>
        <span class="bm-page-list" id="bmPageList"></span>
        <button type="button" class="bm-page-btn" id="bmNext"><span>Next </span><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
  </section>

  {{-- ============ ACTION MENU (shared) ============ --}}
  <div class="bm-menu" id="bmMenu" role="menu" hidden>
    <button type="button" role="menuitem" data-act="view"><i class="bi bi-eye"></i> View details</button>
    <button type="button" role="menuitem" data-act="edit"><i class="bi bi-pencil-square"></i> Edit branch</button>
    <div class="bm-menu-sep"></div>
    <button type="button" role="menuitem" data-act="toggle" id="bmMenuToggle"><i class="bi bi-door-closed"></i> Set as Inactive</button>
  </div>

  {{-- ============ ADD MODAL ============ --}}
  <div class="bm-modal" id="bmAddModal" aria-hidden="true">
    <div class="bm-modal-backdrop" data-close></div>
    <div class="bm-modal-panel" role="dialog" aria-modal="true" aria-labelledby="bmAddTitle">
      <div class="bm-modal-head">
        <div><p class="bm-eyebrow">New</p><h3 id="bmAddTitle">Add New Branch</h3></div>
        <button type="button" class="bm-icon-btn" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form id="bmAddForm" action="{{ route('admin.branches.store') }}" method="POST" autocomplete="off">
        <input type="text" name="prevent_autofill_username" value="" class="bm-offscreen" tabindex="-1" aria-hidden="true" autocomplete="off">
        <input type="password" name="prevent_autofill_password" value="" class="bm-offscreen" tabindex="-1" aria-hidden="true" autocomplete="off">
        @csrf
        <input type="hidden" name="_form" value="create">
        <div class="bm-modal-body">
          @if($oldForm === 'create' && $errors->any())
            <div class="bm-form-errors" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
          @endif
          <div class="bm-grid">
            <div class="bm-field bm-span-2"><label class="bm-label" for="bmAddName">Branch Name *</label><input type="text" id="bmAddName" name="name" class="bm-input" required maxlength="255" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field"><label class="bm-label" for="bmAddCode">Branch Code *</label><input type="text" id="bmAddCode" name="code" class="bm-input bm-upper" required maxlength="10" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"><small class="bm-hint">Unique short code</small></div>
            <div class="bm-field"><label class="bm-label" for="bmAddContact">Contact Number</label><input type="text" id="bmAddContact" name="contact_number" class="bm-input" maxlength="20" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field bm-span-2"><label class="bm-label" for="bmAddAddress">Address *</label><input type="text" id="bmAddAddress" name="address" class="bm-input" required maxlength="500" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field bm-span-2"><label class="bm-label" for="bmAddEmail">Email</label><input type="email" id="bmAddEmail" name="email" class="bm-input" maxlength="255" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field"><label class="bm-label" for="bmAddOpen">Opening Time</label><input type="time" id="bmAddOpen" name="opening_time" class="bm-input"></div>
            <div class="bm-field"><label class="bm-label" for="bmAddClose">Closing Time</label><input type="time" id="bmAddClose" name="closing_time" class="bm-input"></div>
          </div>
        </div>
        <div class="bm-modal-foot">
          <button type="button" class="bm-btn bm-btn-ghost" data-close>Cancel</button>
          <button type="submit" class="bm-btn bm-btn-primary"><i class="bi bi-plus-circle"></i> Add Branch</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ============ DETAILS / EDIT MODAL ============ --}}
  <div class="bm-modal" id="bmDetailModal" aria-hidden="true">
    <div class="bm-modal-backdrop" data-close></div>
    <div class="bm-modal-panel bm-modal-lg" role="dialog" aria-modal="true" aria-labelledby="bmDTitle">
      <div class="bm-modal-head bm-detail-head">
        <div class="bm-ident">
          <span class="bm-avatar" id="bmDAvatar">PC</span>
          <div>
            <p class="bm-eyebrow" id="bmDCode">CODE</p>
            <h3 id="bmDTitle">Branch</h3>
            <div class="bm-d-badges"><span class="bm-badge" id="bmDStatus">Open</span><span class="bm-badge bm-badge-main" id="bmDMain" hidden>Main</span></div>
          </div>
        </div>
        <div class="bm-head-actions">
          <button type="button" class="bm-icon-btn" id="bmDMenuBtn" aria-label="Branch actions" aria-haspopup="menu" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>
          <button type="button" class="bm-icon-btn" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>

      {{-- VIEW MODE --}}
      <div class="bm-dview" id="bmDView">
        <div class="bm-modal-body">
          <p class="bm-section-title"><i class="bi bi-shop"></i> Store Information</p>
          <p class="bm-hint">Customers will see this information in More.</p>
          <dl class="bm-info-grid">
            <div><dt>Address</dt><dd id="bmVAddress"></dd></div>
            <div><dt>Contact Number</dt><dd id="bmVContact"></dd></div>
            <div><dt>Email</dt><dd id="bmVEmail"></dd></div>
            <div><dt>Hours</dt><dd id="bmVHours"></dd></div>
          </dl>
          <p class="bm-section-title"><i class="bi bi-share"></i> Social Media</p>
          <div class="bm-socials" id="bmVSocials"></div>
          <p class="bm-section-title bm-gc-title"><i class="bi bi-qr-code"></i> GCash Payment <span class="bm-badge" id="bmVGcBadge">Enabled</span></p>
          <div class="bm-gc-view" id="bmVGcash"></div>
        </div>
        <div class="bm-modal-foot">
          <button type="button" class="bm-btn" id="bmDToggleBtn"></button>
          <button type="button" class="bm-btn bm-btn-primary" id="bmDEditBtn"><i class="bi bi-pencil-square"></i> Edit Branch</button>
        </div>
      </div>

      {{-- EDIT MODE — posts PUT to admin.branches.update (action set per branch) --}}
      <form id="bmDEdit" action="" method="POST" enctype="multipart/form-data" autocomplete="off" hidden>
        <input type="text" name="prevent_autofill_username" value="" class="bm-offscreen" tabindex="-1" aria-hidden="true" autocomplete="off">
        <input type="password" name="prevent_autofill_password" value="" class="bm-offscreen" tabindex="-1" aria-hidden="true" autocomplete="off">
        @csrf
        @method('PUT')
        <input type="hidden" name="_form" value="edit">
        <input type="hidden" name="_branch_id" value="">
        <div class="bm-modal-body">
          @if($oldForm === 'edit' && $errors->any())
            <div class="bm-form-errors" role="alert" data-testid="edit-errors"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
          @endif
          <p class="bm-section-title"><i class="bi bi-shop"></i> Store Information</p>
          <p class="bm-hint">Customers will see this information in More.</p>
          <div class="bm-grid" style="margin-top:.75rem">
            <div class="bm-field bm-span-2"><label class="bm-label" for="bmEName">Business / Branch Name *</label><input type="text" id="bmEName" name="name" class="bm-input" required maxlength="255" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field bm-span-2"><label class="bm-label" for="bmEAddress">Address *</label><input type="text" id="bmEAddress" name="address" class="bm-input" required maxlength="500" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field"><label class="bm-label" for="bmEContact">Contact Number</label><input type="text" id="bmEContact" name="contact_number" class="bm-input" maxlength="20" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field"><label class="bm-label" for="bmEEmail">Email</label><input type="email" id="bmEEmail" name="email" class="bm-input" maxlength="255" readonly onfocus="this.removeAttribute('readonly');" autocomplete="one-time-code" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"></div>
            <div class="bm-field"><label class="bm-label" for="bmEOpen">Opening Time</label><input type="time" id="bmEOpen" name="opening_time" class="bm-input"></div>
            <div class="bm-field"><label class="bm-label" for="bmEClose">Closing Time</label><input type="time" id="bmEClose" name="closing_time" class="bm-input"></div>
          </div>
          <div class="bm-divider"></div>
          <p class="bm-section-title"><i class="bi bi-share"></i> Social Media</p>
          <div class="bm-grid" style="margin-top:.75rem">
            <div class="bm-field"><label class="bm-label" for="bmEFb">Facebook</label><div class="bm-input-ico"><i class="bi bi-facebook"></i><input type="url" id="bmEFb" name="facebook_url" class="bm-input" maxlength="500" autocomplete="off" placeholder="https://facebook.com/…"></div></div>
            <div class="bm-field"><label class="bm-label" for="bmEIg">Instagram</label><div class="bm-input-ico"><i class="bi bi-instagram"></i><input type="url" id="bmEIg" name="instagram_url" class="bm-input" maxlength="500" autocomplete="off" placeholder="https://instagram.com/…"></div></div>
            <div class="bm-field"><label class="bm-label" for="bmETt">TikTok</label><div class="bm-input-ico"><i class="bi bi-tiktok"></i><input type="url" id="bmETt" name="tiktok_url" class="bm-input" maxlength="500" autocomplete="off" placeholder="https://tiktok.com/@…"></div></div>
            <div class="bm-field"><label class="bm-label" for="bmEOther">Other Social Media</label><div class="bm-input-ico"><i class="bi bi-link-45deg"></i><input type="url" id="bmEOther" name="other_social_url" class="bm-input" maxlength="500" autocomplete="off" placeholder="https://…"></div></div>
          </div>
          <div class="bm-divider"></div>

          {{-- GCash Payment Settings — owner only (this page is owner-only, and
               updateBranch() checks again). Validated on the server too. --}}
          <div class="bm-gc-head">
            <span class="bm-gc-ico"><i class="bi bi-qr-code"></i></span>
            <div class="bm-gc-head-text">
              <p class="bm-section-title">GCash Payment Settings <span class="bm-badge" id="bmEGcBadge">Enabled</span></p>
              <p class="bm-hint">Manage the QR code and number shown to customers paying through GCash.</p>
            </div>
            <label class="bm-switch-row bm-gc-toggle" title="Enable or disable GCash for this branch">
              <input type="hidden" name="gcash_enabled" value="0">
              <input type="checkbox" name="gcash_enabled" value="1" class="bm-switch" id="bmEGcToggle" aria-label="GCash for this branch">
              <span id="bmEGcToggleLbl">On</span>
            </label>
          </div>
          <div class="bm-gc-body" id="bmEGcBody">
            <div class="bm-gc-qr">
              <p class="bm-gc-qr-lbl">Current QR Code</p>
              <div class="bm-gc-qr-box" id="bmEQrBox"></div>
              <p class="bm-gc-qr-state" id="bmEQrState"></p>
            </div>
            <div class="bm-gc-fields">
              <div class="bm-field">
                <label class="bm-label" for="bmEQrFile">Change GCash QR</label>
                <label class="bm-drop" for="bmEQrFile" id="bmEDrop">
                  <i class="bi bi-cloud-arrow-up"></i>
                  <span><strong>Upload QR image</strong> or drag it here</span>
                  <small class="bm-hint" id="bmEQrName">JPG, JPEG, PNG, or WEBP · Maximum 5 MB</small>
                </label>
                <input type="file" id="bmEQrFile" name="gcash_qr" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="bm-offscreen">
                <button type="button" class="bm-link-btn" id="bmEQrUndo" hidden><i class="bi bi-arrow-counterclockwise"></i> Keep current QR</button>
              </div>
              <div class="bm-field">
                <label class="bm-label" for="bmEGcNumber">GCash Phone Number</label>
                <div class="bm-input-ico"><i class="bi bi-phone"></i><input type="tel" id="bmEGcNumber" name="gcash_number" class="bm-input" inputmode="tel" maxlength="20" placeholder="09XX XXX XXXX" autocomplete="off" data-lpignore="true"></div>
                <small class="bm-hint">Displayed below the GCash QR code during checkout.</small>
              </div>
            </div>
          </div>
          <p class="bm-gc-off-note" id="bmEGcOff" hidden><i class="bi bi-info-circle"></i> GCash is disabled — customers won't see it as a payment option for this branch.</p>
          <div class="bm-divider"></div>
          <label class="bm-switch-row">
            <input type="checkbox" name="is_active" value="1" class="bm-switch">
            <span>Accept orders from this branch</span>
          </label>
        </div>
        <div class="bm-modal-foot">
          <button type="button" class="bm-btn bm-btn-ghost" id="bmDCancelEdit">Cancel</button>
          <button type="submit" class="bm-btn bm-btn-primary"><i class="bi bi-check2"></i> Save Store Information</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ============ CONFIRM MODAL (replaces native confirm()) ============ --}}
  <div class="bm-modal" id="bmConfirmModal" aria-hidden="true">
    <div class="bm-modal-backdrop" data-close></div>
    <div class="bm-modal-panel bm-modal-sm" role="alertdialog" aria-modal="true" aria-labelledby="bmCTitle">
      <div class="bm-modal-body bm-confirm-body">
        <span class="bm-confirm-ico" id="bmCIco"><i class="bi bi-door-closed"></i></span>
        <h3 id="bmCTitle">Set as inactive?</h3>
        <p id="bmCMsg"></p>
      </div>
      <div class="bm-modal-foot">
        <button type="button" class="bm-btn bm-btn-ghost" data-close>Cancel</button>
        <button type="button" class="bm-btn bm-btn-primary" id="bmCOk">Confirm</button>
      </div>
    </div>
  </div>

  {{-- The real Open / Inactive switch: one PUT form, aimed at the chosen branch. --}}
  <form id="bmToggleForm" action="" method="POST" hidden>
    @csrf
    @method('PUT')
  </form>

  <script type="application/json" id="bmData">@json($branchData)</script>
  <script type="application/json" id="bmBoot">@json($bootData)</script>
</div>

<script>
(function () {
  "use strict";

  var branches = JSON.parse(document.getElementById("bmData").textContent || "[]");
  var boot = JSON.parse(document.getElementById("bmBoot").textContent || "{}");

  // The menu and the modals move to <body>: the layout's content column is its
  // own stacking context, which kept them UNDER the phone-width top bar.
  var portal = document.createElement("div");
  portal.className = "bm bm-portal";
  ["bmMenu", "bmAddModal", "bmDetailModal", "bmConfirmModal", "bmToggleForm"].forEach(function (id) {
    portal.appendChild(document.getElementById(id));
  });
  document.body.appendChild(portal);

  // Every id and class here is bm-prefixed, so the whole document is the scope.
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[c]; }); }
  function fmtTime(t) { if (!t) return ""; var p = t.split(":").map(Number); var ap = p[0] >= 12 ? "PM" : "AM"; return ((p[0] % 12) || 12) + ":" + String(p[1]).padStart(2, "0") + " " + ap; }
  function hours(b) { return b.opening_time && b.closing_time ? fmtTime(b.opening_time) + " – " + fmtTime(b.closing_time) : "—"; }
  function initials(n) { return (String(n).replace(/peachy\s*caf[eé]/i, "").trim().split(/\s+/).map(function (w) { return w.charAt(0); }).join("").slice(0, 2).toUpperCase()) || "PC"; }
  function byId(id) { return branches.find(function (b) { return b.id === Number(id); }) || null; }
  // Only http(s) links are ever rendered as hrefs.
  function safeUrl(u) { return /^https?:\/\//i.test(String(u || "")) ? String(u) : ""; }

  // ---------- List: search, status filter, paging (over the real rows) ----------
  var page = 1, status = "", query = "";
  var body = $("#bmBody");

  function apply() {
    var rows = $$(".bm-row", body);
    var matched = rows.filter(function (r) { return (!status || r.dataset.status === status) && (!query || r.dataset.search.indexOf(query) !== -1); });
    var size = Number($("#bmPageSize").value);
    var pages = Math.max(1, Math.ceil(matched.length / size));
    page = Math.min(Math.max(1, page), pages);
    var start = (page - 1) * size;
    rows.forEach(function (r) { r.hidden = true; });
    matched.slice(start, start + size).forEach(function (r) { r.hidden = false; });

    var filtered = status || query;
    $("#bmCount").textContent = filtered ? matched.length + " of " + rows.length + " branches" : rows.length + " " + (rows.length === 1 ? "branch" : "branches");
    $("#bmEmpty").hidden = rows.length > 0;
    $("#bmNoResults").hidden = !(rows.length && !matched.length);
    $("#bmPager").hidden = matched.length === 0;
    $("#bmPagerRange").textContent = matched.length ? "Showing " + (start + 1) + "–" + Math.min(start + size, matched.length) + " of " + matched.length : "";
    $("#bmPrev").disabled = page <= 1;
    $("#bmNext").disabled = page >= pages;

    var list = $("#bmPageList"); list.innerHTML = "";
    var nums = [];
    for (var i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - page) <= 1) nums.push(i);
    nums.forEach(function (n, idx) {
      if (idx && n - nums[idx - 1] > 1) list.insertAdjacentHTML("beforeend", '<span class="bm-page-dots">…</span>');
      var btn = document.createElement("button");
      btn.type = "button"; btn.className = "bm-page-btn" + (n === page ? " active" : ""); btn.textContent = n;
      btn.addEventListener("click", function () { go(n); });
      list.appendChild(btn);
    });
  }
  function go(p) { page = p; apply(); }

  $("#bmSearch").addEventListener("input", function (e) { query = e.target.value.trim().toLowerCase(); page = 1; apply(); });
  $$(".bm-seg-btn").forEach(function (b) { b.addEventListener("click", function () { $$(".bm-seg-btn").forEach(function (x) { x.classList.toggle("active", x === b); }); status = b.dataset.status; page = 1; apply(); }); });
  $("#bmReset").addEventListener("click", function () { $("#bmSearch").value = ""; query = ""; status = ""; $$(".bm-seg-btn").forEach(function (x) { x.classList.toggle("active", x.dataset.status === ""); }); page = 1; apply(); });
  $("#bmPageSize").addEventListener("change", function () { page = 1; apply(); });
  $("#bmPrev").addEventListener("click", function () { go(page - 1); });
  $("#bmNext").addEventListener("click", function () { go(page + 1); });

  // ---------- Modals ----------
  var lastFocus = null;
  function openModal(id) {
    lastFocus = document.activeElement;
    var m = document.getElementById(id); m.classList.add("open"); m.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
  }
  function closeModal(id) {
    var m = document.getElementById(id); m.classList.remove("open"); m.setAttribute("aria-hidden", "true");
    if (!$(".bm-modal.open")) document.body.style.overflow = "";
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  $$("[data-open]").forEach(function (b) { b.addEventListener("click", function () { openModal(b.dataset.open); }); });
  $$(".bm-modal").forEach(function (m) { m.addEventListener("click", function (e) { if (e.target.closest("[data-close]")) closeModal(m.id); }); });
  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    if (!menu.hidden) { closeMenu(); return; }
    var open = $$(".bm-modal.open"); if (open.length) closeModal(open[open.length - 1].id);
  });

  // ---------- Details / Edit ----------
  var current = null;
  var edit = $("#bmDEdit");
  var FIELDS = ["name", "address", "contact_number", "email", "opening_time", "closing_time", "facebook_url", "instagram_url", "tiktok_url", "other_social_url"];

  function openDetails(id, editing) {
    current = byId(id); if (!current) return;
    fillDetails(); setEdit(!!editing);
    if (!$("#bmDetailModal").classList.contains("open")) openModal("bmDetailModal");
  }

  function fillDetails() {
    var b = current;
    $("#bmDAvatar").textContent = initials(b.name);
    $("#bmDCode").textContent = b.code;
    $("#bmDTitle").textContent = b.name;
    var st = $("#bmDStatus"); st.textContent = b.is_active ? "Open" : "Inactive"; st.className = "bm-badge " + (b.is_active ? "bm-badge-ok" : "bm-badge-off");
    $("#bmDMain").hidden = !b.is_main_branch;
    $("#bmVAddress").textContent = b.address || "—";
    $("#bmVContact").textContent = b.contact_number || "—";
    $("#bmVEmail").textContent = b.email || "—";
    $("#bmVHours").textContent = hours(b);

    var socials = [["facebook_url", "facebook", "Facebook"], ["instagram_url", "instagram", "Instagram"], ["tiktok_url", "tiktok", "TikTok"], ["other_social_url", "link-45deg", "Other"]]
      .filter(function (s) { return safeUrl(b[s[0]]); });
    $("#bmVSocials").innerHTML = socials.length
      ? socials.map(function (s) { return '<a class="bm-social" href="' + esc(safeUrl(b[s[0]])) + '" target="_blank" rel="noopener"><i class="bi bi-' + s[1] + '"></i>' + s[2] + "</a>"; }).join("")
      : '<span class="bm-social none"><i class="bi bi-dash-circle"></i> No social links yet</span>';

    var vb = $("#bmVGcBadge"); vb.textContent = b.gcash_enabled ? "Enabled" : "Disabled"; vb.className = "bm-badge " + (b.gcash_enabled ? "bm-badge-ok" : "bm-badge-off");
    var gv = $("#bmVGcash");
    gv.className = "bm-gc-view" + (b.gcash_enabled ? "" : " off");
    gv.innerHTML = (b.gcash_qr_url ? '<img src="' + esc(b.gcash_qr_url) + '" alt="GCash QR code">' : '<span class="bm-gc-qr-box" style="width:72px;height:72px"><i class="bi bi-qr-code"></i></span>')
      + '<dl style="margin:0"><dt>GCash Number</dt><dd>' + esc(b.gcash_number || "—") + '</dd><dt style="margin-top:.4rem">QR Code</dt><dd>' + (b.gcash_qr_url ? "Uploaded" : "Not uploaded") + "</dd></dl>";

    var tb = $("#bmDToggleBtn");
    tb.className = "bm-btn " + (b.is_active ? "bm-btn-ghost" : "bm-btn-open");
    tb.innerHTML = b.is_active ? '<i class="bi bi-door-closed"></i> Set as Inactive' : '<i class="bi bi-door-open"></i> Set as Open';
    tb.title = b.is_active ? "Stop accepting orders" : "Start accepting orders";

    edit.action = b.update_url;
    edit.elements._branch_id.value = b.id;
    FIELDS.forEach(function (k) { edit.elements[k].value = b[k] || ""; });
    edit.elements.is_active.checked = !!b.is_active;
    edit.elements.gcash_number.value = b.gcash_number || "";
    $("#bmEGcToggle").checked = !!b.gcash_enabled;
    resetQr(); syncGcash();
  }

  // ---------- GCash (edit) ----------
  var pendingQr = null; // object URL of a newly chosen file
  var QR_TYPES = ["image/jpeg", "image/png", "image/webp"], QR_MAX = 5 * 1024 * 1024;

  function syncGcash() {
    var on = $("#bmEGcToggle").checked;
    $("#bmEGcToggleLbl").textContent = on ? "On" : "Off";
    var bd = $("#bmEGcBadge"); bd.textContent = on ? "Enabled" : "Disabled"; bd.className = "bm-badge " + (on ? "bm-badge-ok" : "bm-badge-off");
    $("#bmEGcBody").classList.toggle("disabled", !on);
    $("#bmEGcOff").hidden = on;
  }
  function showQr() {
    var src = pendingQr || (current && current.gcash_qr_url), st = $("#bmEQrState");
    $("#bmEQrBox").innerHTML = src ? '<img src="' + esc(src) + '" alt="GCash QR code">' : '<div><i class="bi bi-qr-code"></i>No QR uploaded</div>';
    if (pendingQr) { st.className = "bm-gc-qr-state new"; st.innerHTML = '<i class="bi bi-arrow-repeat"></i> New – not saved yet'; }
    else if (src) { st.className = "bm-gc-qr-state ok"; st.innerHTML = '<i class="bi bi-check-circle-fill"></i> Saved'; }
    else { st.className = "bm-gc-qr-state none"; st.innerHTML = '<i class="bi bi-dash-circle"></i> None'; }
    $("#bmEQrUndo").hidden = !pendingQr;
  }
  function resetQr() {
    if (pendingQr) URL.revokeObjectURL(pendingQr);
    pendingQr = null; $("#bmEQrFile").value = "";
    $("#bmEQrName").textContent = "JPG, JPEG, PNG, or WEBP · Maximum 5 MB"; $("#bmEDrop").classList.remove("err");
    showQr();
  }
  function pickQr(file) {
    var drop = $("#bmEDrop"), name = $("#bmEQrName");
    if (!file) { resetQr(); return; }
    if (QR_TYPES.indexOf(file.type) === -1 || file.size > QR_MAX) {
      $("#bmEQrFile").value = ""; drop.classList.add("err");
      name.textContent = QR_TYPES.indexOf(file.type) === -1 ? "Please choose a JPG, JPEG, PNG, or WEBP image." : "That image is over 5 MB.";
      return;
    }
    drop.classList.remove("err");
    if (pendingQr) URL.revokeObjectURL(pendingQr);
    pendingQr = URL.createObjectURL(file); name.textContent = file.name; showQr();
  }
  $("#bmEGcToggle").addEventListener("change", syncGcash);
  $("#bmEQrFile").addEventListener("change", function (e) { pickQr(e.target.files[0]); });
  $("#bmEQrUndo").addEventListener("click", resetQr);
  var drop = $("#bmEDrop");
  ["dragenter", "dragover"].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add("over"); }); });
  ["dragleave", "drop"].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove("over"); }); });
  drop.addEventListener("drop", function (e) {
    var f = e.dataTransfer && e.dataTransfer.files[0]; if (!f) return;
    try { var dt = new DataTransfer(); dt.items.add(f); $("#bmEQrFile").files = dt.files; } catch (err) {}
    pickQr(f);
  });

  function setEdit(on) {
    $("#bmDView").hidden = on; edit.hidden = !on;
    // The header menu would offer "View details" while already there; hide it in edit.
    if (on) setTimeout(function () { edit.elements.name.focus(); }, 30);
  }
  $("#bmDEditBtn").addEventListener("click", function () { setEdit(true); });
  $("#bmDCancelEdit").addEventListener("click", function () { fillDetails(); setEdit(false); });
  $("#bmDToggleBtn").addEventListener("click", function () { askToggle(current.id); });
  $("#bmDMenuBtn").addEventListener("click", function (e) { openMenu(e.currentTarget, current.id); });

  // ---------- Toggle with confirmation (PUT admin.branches.toggle) ----------
  function askToggle(id) {
    var b = byId(id); if (!b) return; var off = b.is_active;
    $("#bmCTitle").textContent = off ? "Set as inactive?" : "Open for orders?";
    $("#bmCMsg").textContent = off ? "Set " + b.name + " as inactive? Customers will no longer be able to order from this branch." : "Open " + b.name + " for orders?";
    var ico = $("#bmCIco"); ico.className = "bm-confirm-ico" + (off ? "" : " ok"); ico.innerHTML = '<i class="bi bi-door-' + (off ? "closed" : "open") + '"></i>';
    var ok = $("#bmCOk"); ok.className = "bm-btn " + (off ? "bm-btn-danger" : "bm-btn-primary"); ok.textContent = off ? "Set as Inactive" : "Set as Open";
    ok.onclick = function () {
      ok.disabled = true;
      var form = $("#bmToggleForm"); form.action = b.toggle_url; form.submit();
    };
    openModal("bmConfirmModal");
  }

  // ---------- Three-dot menu ----------
  // position:fixed + JS placement (the table scrolls sideways, which would clip
  // an absolutely-positioned menu). It is REPOSITIONED on scroll/resize, not
  // closed: a scroll event lands a frame after the click that opened it.
  var menu = $("#bmMenu"), menuId = null, menuBtn = null;
  function placeMenu() {
    if (!menuBtn || menu.hidden) return;
    var r = menuBtn.getBoundingClientRect();
    if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) { closeMenu(); return; }
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var top = r.bottom + 6; if (top + mh > innerHeight - 8) top = r.top - mh - 6;
    menu.style.top = Math.max(8, top) + "px";
    menu.style.left = Math.max(8, Math.min(r.right - mw, innerWidth - mw - 8)) + "px";
  }
  function openMenu(btn, id) {
    if (menuBtn === btn && !menu.hidden) { closeMenu(); return; }
    closeMenu(); menuId = id; menuBtn = btn; btn.setAttribute("aria-expanded", "true");
    var b = byId(id); var t = $("#bmMenuToggle");
    t.className = b.is_active ? "danger" : "okc";
    t.innerHTML = b.is_active ? '<i class="bi bi-door-closed"></i> Set as Inactive' : '<i class="bi bi-door-open"></i> Set as Open';
    $('[data-act="view"]', menu).hidden = $("#bmDetailModal").classList.contains("open") && !$("#bmDView").hidden;
    $('[data-act="edit"]', menu).hidden = $("#bmDetailModal").classList.contains("open") && !edit.hidden;
    menu.hidden = false;
    placeMenu();
    var first = $("button:not([hidden])", menu); if (first) first.focus();
  }
  function closeMenu() { menu.hidden = true; if (menuBtn) menuBtn.setAttribute("aria-expanded", "false"); menuBtn = null; }
  menu.addEventListener("click", function (e) {
    var a = e.target.closest("[data-act]"); if (!a) return; var id = menuId; closeMenu();
    if (a.dataset.act === "view") openDetails(id);
    if (a.dataset.act === "edit") openDetails(id, true);
    if (a.dataset.act === "toggle") askToggle(id);
  });
  document.addEventListener("click", function (e) { if (!menu.hidden && !menu.contains(e.target) && !e.target.closest(".bm-row-menu,#bmDMenuBtn")) closeMenu(); });
  window.addEventListener("resize", placeMenu);
  window.addEventListener("scroll", placeMenu, true);

  // Row click → details; ⋮ → menu (and the ⋮ click must NOT also open the row).
  body.addEventListener("click", function (e) {
    var row = e.target.closest(".bm-row"); if (!row) return;
    var mb = e.target.closest(".bm-row-menu");
    if (mb) { e.stopPropagation(); openMenu(mb, row.dataset.branchRow); return; }
    openDetails(row.dataset.branchRow);
  });
  body.addEventListener("keydown", function (e) {
    var row = e.target.closest(".bm-row");
    if (row && e.target === row && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); openDetails(row.dataset.branchRow); }
  });

  apply();

  // ---------- On arrival ----------
  // A failed save re-opens the form it came from, with what was typed.
  if (boot.form === "create") {
    var add = $("#bmAddForm");
    ["name", "code", "contact_number", "address", "email", "opening_time", "closing_time"].forEach(function (k) {
      if (boot.old[k] != null) { add.elements[k].value = boot.old[k]; add.elements[k].removeAttribute("readonly"); }
    });
    openModal("bmAddModal");
  } else if (boot.form === "edit" && byId(boot.old._branch_id)) {
    openDetails(boot.old._branch_id, true);
    FIELDS.concat(["gcash_number"]).forEach(function (k) {
      if (boot.old[k] != null) { edit.elements[k].value = boot.old[k]; edit.elements[k].removeAttribute("readonly"); }
    });
    edit.elements.is_active.checked = boot.old.is_active === "1";
    $("#bmEGcToggle").checked = boot.old.gcash_enabled === "1";
    syncGcash();
  } else if (boot.open && byId(boot.open)) {
    // A branch chosen in the Viewing bar: its details open straight away.
    openDetails(boot.open);
  }
})();
</script>
@endsection
