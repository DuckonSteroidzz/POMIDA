@extends('admin.layout')

@section('title', 'Menu Options - Peachy Admin')

@section('content')

<link href="/vendor/gfonts.css" rel="stylesheet">

<style>
    .pchy{--p1:#F8D7B0;--p2:#F6B49B;--p3:#EF8585;--p4:#F4845F;--p5:#C0392B;--p6:#8B1A1A;font-family:'Karla',system-ui,sans-serif;color:#4a3b36}
    .pchy *{box-sizing:border-box}

    .pchy-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:2px dashed rgba(244,132,95,.35)}
    .pchy-title{font-family:'Fraunces',Georgia,serif;font-size:1.7rem;font-weight:600;line-height:1.15;margin:0;color:var(--p6)}
    .pchy-sub{margin:.3rem 0 0;font-size:.85rem;color:#8d7a73}
    .pchy-chips{display:flex;gap:.45rem;flex-wrap:wrap}
    .pchy-chip{background:linear-gradient(135deg,var(--p1),var(--p2));color:var(--p6);font-size:.74rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.4rem .7rem;border-radius:999px}

    .pchy-alert{background:#fff2f0;border:1px solid rgba(192,57,43,.28);border-left:5px solid var(--p5);color:var(--p6);padding:.75rem 1rem;border-radius:12px;font-size:.85rem;margin-bottom:1.1rem}
    .pchy-alert div+div{margin-top:.25rem}

    .pchy-grid{display:grid;grid-template-columns:minmax(0,1.62fr) minmax(0,1fr);gap:1.1rem}
    .pchy-card{background:#fff;border:1px solid rgba(246,180,155,.45);border-radius:18px;padding:1.15rem;box-shadow:0 10px 26px -18px rgba(139,26,26,.35)}
    .pchy-card-hd{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.9rem}
    .pchy-card-t{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--p6);margin:0;display:flex;align-items:center;gap:.5rem}
    .pchy-ico{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,var(--p2),var(--p4));color:#fff;font-size:.85rem}
    .pchy-count{font-size:.72rem;font-weight:700;color:var(--p5);background:rgba(248,215,176,.55);padding:.25rem .55rem;border-radius:999px}

    .pchy-field{margin-bottom:.8rem}
    .pchy-label{display:block;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9a837b;margin-bottom:.35rem}
    .pchy-in{width:100%;font-family:'Karla',sans-serif;font-size:.92rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:11px;padding:.65rem .8rem;transition:.18s}
    .pchy-in:focus{outline:none;border-color:var(--p4);background:#fff;box-shadow:0 0 0 3px rgba(244,132,95,.18)}

    .pchy-btn{font-family:'Karla',sans-serif;font-size:.9rem;font-weight:700;border:0;cursor:pointer;border-radius:11px;padding:.72rem 1rem;color:#fff;background:linear-gradient(135deg,var(--p4),var(--p5));box-shadow:0 8px 18px -10px rgba(192,57,43,.7);transition:.18s;display:inline-flex;align-items:center;justify-content:center;gap:.45rem}
    .pchy-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
    .pchy-btn-full{width:100%}

    .pchy-tools{display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap}
    .pchy-search{position:relative;display:flex;align-items:center;max-width:210px;width:100%}
    .pchy-search i{position:absolute;left:.65rem;color:var(--p4);font-size:.8rem}
    .pchy-search input{width:100%;font-family:'Karla',sans-serif;font-size:.84rem;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .8rem .45rem 1.9rem}
    .pchy-search input:focus{outline:none;border-color:var(--p4)}
    .pchy-select{font-family:'Karla',sans-serif;font-size:.78rem;color:#4a3b36;background:#fffaf6;border:1.5px solid rgba(246,180,155,.6);border-radius:999px;padding:.45rem .7rem;min-height:34px}
    .pchy-select:focus{outline:none;border-color:var(--p4);box-shadow:0 0 0 3px rgba(244,132,95,.12)}

    /* ── Tables (Options, and the Menu Items picker) ──
       The same table the Categories page (add-category.blade.php) already
       uses: separate borders with a row gap, uppercase micro-headers, and
       the 640px rule further down that turns every row into a card driven
       by each cell's data-l label. Copied rather than extracted into a
       shared file because the two pages' .pchy- blocks are already
       independent copies of each other and this page redefines some of the
       same names (.pchy-edit here is an expandable panel, not a button),
       so importing the other page's block wholesale would collide. */
    .pchy-tblwrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .pchy-tbl{width:100%;border-collapse:separate;border-spacing:0 .4rem;font-size:.88rem}
    .pchy-tbl th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:#9a837b;text-align:left;padding:.35rem .7rem;font-weight:700;white-space:nowrap}
    .pchy-tbl td{background:#fffaf6;padding:.55rem .7rem;vertical-align:middle;border-top:1px solid rgba(246,180,155,.35);border-bottom:1px solid rgba(246,180,155,.35)}
    .pchy-tbl td:first-child{border-left:1px solid rgba(246,180,155,.35);border-radius:12px 0 0 12px}
    .pchy-tbl td:last-child{border-right:1px solid rgba(246,180,155,.35);border-radius:0 12px 12px 0}
    .pchy-tbl th.pchy-col-action,.pchy-tbl td.pchy-col-action{text-align:center}
    .pchy-tbl .pchy-col-pick{width:1%;padding-right:.2rem}
    .pchy-muted{color:#b3a099;font-size:.78rem}

    .pchy-opt-tbl{font-size:.84rem}
    .pchy-opt-tbl th,.pchy-opt-tbl td{padding-left:.45rem;padding-right:.45rem}
    .pchy-opt-tbl td[data-l="Option"],.pchy-opt-tbl td[data-l="Price"]{white-space:nowrap}
    .pchy-opt-tbl td.pchy-col-action{white-space:nowrap;width:1%}
    .pchy-opt-tbl .pchy-ing-toggle{white-space:nowrap}
    .pchy-opt-tbl td[data-l="Used In"]{width:1%}

    /* ── One option = ONE <tr> ──
       This was a <tbody> holding a summary row plus a detail row, because
       the detail row carried three things that had to show, hide and page
       as one unit with the summary: the Used-in chips, the edit form and
       the recipe-ingredient editor.

       All three have left the table. The edit form and the ingredient
       editor live in the one shared modal at the foot of this page; the
       Used-in chips are a fixed-position popover anchored to their own
       button. Nothing is left to group, so the wrapper is gone rather than
       kept as an empty shell around a single row — a hidden second row with
       nothing in it is markup that only exists to keep a selector true.

       Everything that hung off it still works, for the same reason it
       worked before: .option-row and id="opt-N" moved onto the <tr>, so the
       pager still hides an option with one class toggle on one element, and
       every id lookup still lands on the row that shows that option's name,
       price, badges and ingredient count. */
    .pchy-opt-tbl tbody tr.pchy-option:hover td{background:#fff4ec}
    .pchy-option-check{width:17px;height:17px;accent-color:var(--p4);flex:0 0 auto}
    .pchy-actions{display:flex;align-items:center;justify-content:center;gap:.35rem}
    .pchy-option-name{font-size:.84rem;font-weight:700;color:#463430}
    .pchy-price{font-size:.72rem;color:var(--p4);font-weight:700;margin-left:.35rem}
    .pchy-free{font-size:.72rem;color:#aaa;margin-left:.35rem}
    .pchy-delete-btn{background:rgba(239,133,133,.14);color:var(--p5);border:1px solid rgba(192,57,43,.15);border-radius:8px;width:32px;height:30px;cursor:pointer}
    .pchy-delete-btn:hover{background:var(--p5);color:#fff}
    .pchy-edit-btn{background:rgba(244,132,95,.14);color:var(--p4);border:1px solid rgba(244,132,95,.22);border-radius:8px;width:32px;height:30px;cursor:pointer}
    .pchy-edit-btn:hover{background:var(--p4);color:#fff}
    /* Both panels live inside the modal now, so neither collapses — the
       modal itself is the show/hide. They keep their own dashed boxes so the
       two still read as two distinct jobs (rename/reprice vs. recipe) rather
       than one long form. */
    .pchy-edit{padding:.8rem;background:#fff;border:1px dashed rgba(244,132,95,.42);border-radius:11px}
    /* ── Option name / price / button, in proportion ──
       Both forms gave the name column a bare 1fr against a fixed price
       column and an auto-width button, so the name took everything left
       over — about 79% of a full-width card — for values that are in
       practice two short words ("Extra Cheese", "Unli Gravy"). The columns
       are fr units in a 63/15/22 ratio now, so that proportion holds at
       every desktop width instead of only at one.

       The minmax() floors are what stop a narrower desktop from squeezing
       the price input or wrapping the button's label; the name column is
       the one that gives way (minmax(0,…)), being the one with room to
       spare. Below 520px all three stack, exactly as before.

       max-width caps the run on an ultrawide monitor, where 63% of the card
       would still be an absurd amount of room for "Extra Ice". It is a cap,
       not a fixed width, so it cannot overflow a smaller viewport. Shared by
       the Add form and each option's Edit form so the two stay visually
       consistent, which is why the ratio lives in one class. */
    .pchy-name-price-form{display:grid;grid-template-columns:minmax(0,63fr) minmax(110px,15fr) minmax(150px,22fr);align-items:end}
    .pchy-edit-form{gap:.5rem}
    .pchy-add-form{gap:.7rem;max-width:980px}
    @media (max-width:520px){ .pchy-name-price-form{grid-template-columns:1fr} }

    /* ── Recipe ingredients per option ── */
    .pchy-ing-toggle{display:inline-flex;align-items:center;gap:.3rem;background:rgba(248,215,176,.5);color:var(--p5);border:1px solid rgba(244,132,95,.28);border-radius:8px;height:30px;padding:0 .5rem;font-family:'Karla',sans-serif;font-size:.72rem;font-weight:700;cursor:pointer}
    .pchy-ing-toggle:hover{background:var(--p4);color:#fff;border-color:transparent}
    .pchy-ing-count{background:rgba(255,255,255,.65);color:var(--p6);border-radius:999px;padding:0 .32rem;font-size:.66rem}
    .pchy-ing-toggle:hover .pchy-ing-count{background:rgba(255,255,255,.25);color:#fff}

    .pchy-ing{margin-top:.85rem;padding:.8rem;background:#fff;border:1px dashed rgba(244,132,95,.42);border-radius:11px}
    .pchy-ing-hd{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin:0 0 .55rem;font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--p5)}
    .pchy-ing-note{text-transform:none;letter-spacing:0;font-weight:500;color:#9a837b;font-size:.7rem}
    .pchy-ing-list{display:flex;flex-direction:column;gap:.3rem;margin-bottom:.55rem}
    .pchy-ing-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto auto;align-items:center;gap:.5rem;background:#fffaf6;border:1px solid rgba(246,180,155,.4);border-radius:9px;padding:.4rem .55rem}
    .pchy-ing-name{font-size:.78rem;font-weight:600;color:#463430;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    /* Which branch's stock this link deducts from — an option is global, its
       links are not. */
    .pchy-ing-branch{font-size:.66rem;font-weight:700;color:#7a5f55;background:rgba(248,215,176,.45);border-radius:999px;padding:.12rem .45rem;white-space:nowrap}
    /* Stands in for the remove button on another branch's link when the viewer
       is a branch-locked supervisor. The server refuses that delete regardless
       (deleteOptionIngredient(), F6); this only stops offering a dead button. */
    .pchy-ing-lock{color:#b3a099;font-size:.7rem;padding:.15rem .25rem}
    .pchy-ing-qty{font-size:.74rem;font-weight:700;color:var(--p4);white-space:nowrap}
    .pchy-ing-del{background:none;border:0;color:var(--p5);cursor:pointer;font-size:.7rem;padding:.15rem .25rem;border-radius:6px}
    .pchy-ing-del:hover{background:var(--p5);color:#fff}
    .pchy-ing-empty{font-size:.74rem;color:#b3a099;margin:0 0 .55rem}
    .pchy-ing-form{display:grid;grid-template-columns:minmax(0,1fr) 90px auto;gap:.4rem;align-items:center}
    .pchy-ing-select,.pchy-ing-qty-in{font-size:.78rem;padding:.45rem .55rem;margin:0}
    .pchy-ing-add{padding:.5rem .7rem;font-size:.78rem}

    /* ── "Used in" — which menu items this option is assigned to ──
       Answers, right on the option row, what used to take clicking into
       every menu item one at a time to find out. Each item pairs its name
       with a branch chip using .pchy-ing-branch — the exact class (not just
       a look-alike) the Menu Items picker already uses to tag a row with its
       branch — so this reads as the same "item name + branch chip" fact the
       picker shows, not a new visual language. .pchy-ing-branch itself is
       reserved for naming a branch everywhere else on this page; the item
       name stays plain text in its own pill rather than taking that class,
       so a chip still always means "this is a branch". Collapsed behind the
       same toggle shape as the Recipe Ingredients basket button now beside
       it in the Actions column, since a popular option can be assigned to a
       long tail of items and a table cell is not the place for all of them
       at once; the chips themselves live in the option's detail row, so
       expanding one pushes the table apart rather than widening a column. */
    .pchy-usedin-empty{font-size:.72rem;color:#b3a099;margin:0}
    .pchy-usedin-caret{font-size:.62rem;transition:transform .18s}
    .pchy-ing-toggle.open .pchy-usedin-caret{transform:rotate(180deg)}

    /* ── The disclosure itself: position:fixed, placed by script ──
       The list used to render inline in the option's detail row, so opening
       one pushed every row beneath it down the page — the exact complaint
       this pass exists to fix.

       position:fixed and NOT position:absolute, for a reason that is easy
       to get wrong here: the table sits inside .pchy-tblwrap{overflow-x:auto},
       and an overflow container clips absolutely-positioned descendants. A
       popover hanging below its row would have been cut off at the table's
       edge, or would have grown the wrapper's own scrollbars. A fixed
       element is laid out against the viewport instead, so the wrapper
       cannot clip it, and the script that opens one puts it under its
       button and then clamps it back inside the viewport.

       Closed, it is display:none and occupies nothing, so a row's height is
       the same whether its popover has ever been opened or not. */
    .pchy-pop{display:none;position:fixed;z-index:1190;min-width:190px;max-width:min(320px,calc(100vw - 24px));max-height:min(320px,calc(100vh - 24px));overflow-y:auto;background:#fff;border:1px solid rgba(246,180,155,.75);border-radius:12px;box-shadow:0 18px 40px -16px rgba(139,26,26,.45);padding:.5rem}
    .pchy-pop.show{display:flex;flex-direction:column;gap:.3rem}
    .pchy-pop-hd{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9a837b;padding:.1rem .15rem .25rem;border-bottom:1px solid rgba(246,180,155,.4);margin-bottom:.15rem}
    .pchy-usedin-item{display:flex;align-items:center;justify-content:space-between;gap:.5rem;font-size:.76rem;font-weight:600;color:#463430;background:#fffaf6;border:1px solid rgba(246,180,155,.4);border-radius:8px;padding:.3rem .5rem}
    /* The single-item case renders this chip straight into the cell with no
       disclosure at all — one name is not worth a click, and it still costs
       the row no extra height. */
    .pchy-usedin-solo{display:inline-flex;align-items:center;gap:.35rem;font-size:.76rem;font-weight:600;color:#463430;background:#fffaf6;border:1px solid rgba(246,180,155,.4);border-radius:8px;padding:.25rem .5rem;max-width:230px}
    .pchy-usedin-solo .pchy-usedin-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* ── Per-branch INVENTORY STATUS (Phase 3 audit, Finding #3) ──
       Same ok/off colour language as branches.blade.php's .bl-badge, scoped
       to this page's own .pchy- naming.

       One pill per branch, three states. .pchy-branch-empty is the amber
       "Add Inventory First" state that used to be a SECOND pill stacked
       beside a red one (.pchy-branch-hint, and the .pchy-branch-group box
       that held the pair — both gone with it, since nothing renders them
       now). Amber and not red on purpose, unchanged from that hint: a branch
       with no stock at all is a setup step the owner has not reached yet,
       not something they broke.

       Sized up from .68rem/.22rem: this pill is the page's main at-a-glance
       signal for a non-technical owner, and it was set smaller than the body
       text around it. */
    .pchy-branch-status{display:flex;flex-wrap:wrap;gap:.4rem;margin:.6rem 0 0}
    .pchy-branch-badge{display:inline-flex;align-items:center;gap:.35rem;font-size:.74rem;font-weight:700;border-radius:999px;padding:.3rem .65rem;line-height:1.25}
    .pchy-branch-ok{background:#E6F4EC;color:#2E7D5B;border:1px solid #C6E6D5}
    .pchy-branch-off{background:#FBE7E4;color:#C0392B;border:1px solid #F6C9C1}
    .pchy-branch-empty{background:#fff8e1;color:#8a5a00;border:1px solid #f1dc9c}
    @media(max-width:640px){
        .pchy-ing-form{grid-template-columns:1fr}
    }

    .pchy-menu-card{min-height:100%}
    .pchy-menu-hint{font-size:.75rem;color:#8d7a73;margin:-.35rem 0 .8rem}

    /* ── The Menu Items picker, as a branch-grouped table ──
       Was a flat list of look-alike rows: live data carries two items called
       "coke" and two called "roasted chicken", so which branch you were
       assigning an add-on to was a per-row chip you had to read one row at a
       time. The rows are table rows under a branch section header now, the
       same shape menu-items.blade.php already uses (891490d), so the branch
       is read once per section rather than per row.

       The section header states the MENU ITEM's branch. It says nothing
       about the global add-on being assigned — menu_options has no branch_id
       at all, and the option's own branch facts live in its Ingredient
       Mapping badges on the left, which are a different thing entirely.

       .pchy-category / .pchy-menu-items / .pchy-no-items were dropped with
       this: their markup had already stopped being rendered, so they styled
       nothing. */
    .pchy-menu-tbl td{cursor:pointer}
    .pchy-menu-tbl tbody tr.menu-item-row:hover td{background:#fff4ec;border-color:rgba(244,132,95,.5)}
    .pchy-menu-tbl tbody tr.menu-item-row.active td{background:#fce0d0;border-color:var(--p4)}
    .pchy-menu-item-name{font-size:.84rem;font-weight:600;color:#463430}
    .pchy-menu-item-count{font-size:.72rem;font-weight:700;color:var(--p4);white-space:nowrap}

    /* ── The Assigned Add-ons cell (replaced the Category cell) ──
       Chips rather than a comma-separated sentence: an item can carry a
       handful of add-ons, and a run-on line of them is read word by word
       while a row of chips is counted at a glance. The price sits inside its
       own chip, dimmer than the name, so the name is what the eye lands on
       and the price is there when it is wanted.

       .pchy-noaddons is deliberately grey and quiet — "nothing assigned yet"
       is a normal state for a new menu item, not a warning, and the red/amber
       language on this page is reserved for the Inventory Status pill, which
       means something an owner has to act on.

       .pchy-menu-item-cat went with the Category cell. The category itself
       did not: it is still on the row as data-category, which is what the
       "All categories" dropdown filters on. */
    .pchy-addon-list{display:flex;flex-wrap:wrap;gap:.3rem}
    .pchy-addon-chip{display:inline-flex;align-items:center;gap:.25rem;font-size:.74rem;font-weight:600;color:#463430;background:#fffaf6;border:1px solid rgba(246,180,155,.5);border-radius:8px;padding:.22rem .5rem}
    .pchy-addon-price{font-weight:700;color:var(--p4)}
    /* One add-on still shows as its own chip — a single name is not worth a
       click. Two or more collapse behind this count button, which opens the
       same .pchy-pop popover the Used In column uses, so a heavily-used item
       cannot stretch its row. It is deliberately NOT .pchy-ing-toggle: that
       button shape means "expand a panel on this page", and this one means
       "open a floating list". */
    .pchy-addon-more{display:inline-flex;align-items:center;gap:.3rem;background:rgba(248,215,176,.5);color:var(--p5);border:1px solid rgba(244,132,95,.28);border-radius:8px;min-height:30px;padding:0 .55rem;font-family:'Karla',sans-serif;font-size:.74rem;font-weight:700;cursor:pointer;white-space:nowrap}
    .pchy-addon-more:hover{background:var(--p4);color:#fff;border-color:transparent}
    .pchy-addon-wrap{display:inline-flex}
    .pchy-addon-more .pchy-usedin-caret{font-size:.62rem}
    .pchy-addon-more.open .pchy-usedin-caret{transform:rotate(180deg)}
    .pchy-pop .pchy-addon-chip{justify-content:space-between}
    .pchy-noaddons{font-size:.74rem;color:#b3a099;font-style:italic}

    /* The per-row branch chip is kept alongside the section header, not
       replaced by it, for two reasons: the list is paged client-side, so a
       branch section can be split across two pages, and selectMenuItem()
       mirrors this exact value into "Assign selected options to: <item>
       <branch>", so the row and the confirmation say the same thing. It is
       the same neutral name chip a saved ingredient row uses — a branch
       NAME — never the green/red Mapped/Unmapped badge, which means
       something else. */
    .pchy-menu-item-branch{margin-left:.4rem}
    /* No margin-left any more: the branch used to be a chip appended after
       the item name and needed to be pushed off it. It sits inside literal
       parentheses now, where a left margin would open a gap after the "(". */
    .pchy-selected-branch{vertical-align:baseline}

    /* Branch section header row. Reuses this page's own "which branch"
       language — the building icon and bold serif branch name the top-of-page
       branch bar uses — as an in-table divider, matching the sibling
       menu-items.blade.php header rather than inventing a third look.

       NOT .pchy-branch-group: that name is already taken on this page by the
       badge+hint pair on an OPTION's Ingredient Mapping, which is the other
       branch concept entirely. Reusing it here would have silently restyled
       those badges into full-width blocks. */
    .pchy-branch-section{display:flex;align-items:center;gap:.5rem;background:#fff4ec;border:1px solid rgba(192,57,43,.16);border-radius:10px;padding:.5rem .8rem;color:var(--p6)}
    .pchy-branch-section .value{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--p6);font-size:.92rem}
    .pchy-branch-section .count{margin-left:auto;font-size:.7rem;font-weight:600;color:#8d7a73;background:#fff;border-radius:999px;padding:.15rem .55rem;white-space:nowrap}
    .pchy-menu-tbl tr.pchy-branch-head td{background:transparent!important;border:0!important;border-radius:0!important;padding:.7rem .2rem .15rem;cursor:default}
    .pchy-menu-tbl tbody tr.pchy-branch-head:first-child td{padding-top:0}

    /* ── The assignment banner ──
       Was a .75rem grey line above the button — smaller than the body text
       around it, for the one sentence that names the row a save overwrites.
       Given a left rule and a real heading size so it reads as a banner and
       not as another caption, and the item line set in the same serif the
       page uses for its own headings.

       The branch parenthetical is muted rather than hidden: it is what tells
       two menu items of the same name apart, so it has to stay legible while
       the NAME stays the thing the eye lands on first. It is not
       .pchy-ing-branch — that chip means "this belongs to branch X" in a list
       of chips, and here the branch is running text inside a sentence. */
    .pchy-assign{display:none;margin-top:.85rem;padding:.9rem 1rem;background:linear-gradient(135deg,#fff4ec,#fffaf6);border:1px solid rgba(244,132,95,.3);border-left:4px solid var(--p4);border-radius:12px}
    .pchy-assign.show{display:block}
    .pchy-assign-title{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--p5);margin:0 0 .2rem}
    .pchy-assign-item{font-family:'Fraunces',Georgia,serif;font-size:1.12rem;line-height:1.3;color:var(--p6);margin:0 0 .5rem;word-break:break-word}
    .pchy-selected-name{font-weight:700;color:var(--p6)}
    .pchy-assign-branch-wrap{font-weight:600;color:#8d7a73;font-size:.92rem}
    .pchy-assign-hint{font-size:.78rem;color:#8d7a73;margin:0 0 .65rem}
    .pchy-assign-count{display:inline-block;font-weight:700;color:var(--p5);background:#fff;border:1px solid rgba(246,180,155,.55);border-radius:999px;padding:.1rem .5rem;margin-left:.25rem;white-space:nowrap}
    /* High-contrast and finger-sized: this is the button the whole panel is
       for, and it sat at the same weight as the pager arrows. */
    .pchy-assign-save{min-height:48px;font-size:.95rem;font-weight:800;letter-spacing:.01em}

    .pchy-pager{display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;margin-top:.8rem;padding-top:.75rem;border-top:1px solid rgba(246,180,155,.35);font-size:.74rem;color:#8d7a73}
    .pchy-page-nav{display:flex;align-items:center;gap:.25rem}
    .pchy-page-btn{min-width:31px;height:31px;padding:0 .45rem;border:1px solid rgba(246,180,155,.55);border-radius:8px;background:#fff;color:#5b4740;font-family:'Karla',sans-serif;font-size:.75rem;font-weight:700;cursor:pointer}
    .pchy-page-btn:hover:not(:disabled){border-color:var(--p4);color:var(--p5)}
    .pchy-page-btn:disabled{opacity:.4;cursor:not-allowed}
    .pchy-page-btn.active{background:linear-gradient(135deg,var(--p4),var(--p3));color:#fff;border-color:transparent}
    .pchy-page-gap{padding:0 .15rem;color:#c4b6ae}
    .pchy-row-hidden{display:none!important}

    /* ── The Edit Option / Add-on modal ──
       One shell for the whole page, filled from the clicked row. Opening the
       edit form or the recipe editor used to expand a second table row,
       which pushed every option beneath it down the page and left the table
       taller than the screen after two or three clicks.

       z-index sits above this page's own popovers (1190) so a popover left
       open behind the modal cannot sit on top of it. The backdrop is a
       sibling rather than a background on the dialog's own wrapper, so a
       click on it can be told apart from a click inside the dialog without
       comparing coordinates.

       The dialog is a column with a fixed header and a scrolling body: on a
       short screen the recipe list scrolls INSIDE the modal, so the header
       and the close button never leave the viewport and the page behind
       never scrolls with it. max-height uses svh with a vh fallback, since
       a mobile browser's toolbars make 100vh taller than what is actually
       on screen. */
    .pchy-modal{display:none;position:fixed;inset:0;z-index:1200}
    .pchy-modal.show{display:block}
    .pchy-modal-backdrop{position:absolute;inset:0;background:rgba(48,22,18,.55);backdrop-filter:blur(2px)}
    .pchy-modal-wrap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;pointer-events:none}
    .pchy-modal-dialog{pointer-events:auto;position:relative;width:100%;max-width:720px;max-height:calc(100vh - 2rem);max-height:calc(100svh - 2rem);display:flex;flex-direction:column;background:#fffaf6;border:1px solid rgba(246,180,155,.6);border-radius:18px;box-shadow:0 30px 70px -24px rgba(139,26,26,.6);overflow:hidden}
    .pchy-modal-hd{display:flex;align-items:center;gap:.6rem;padding:.9rem 1rem;background:linear-gradient(135deg,#fff4ec,#fffaf6);border-bottom:1px solid rgba(246,180,155,.5);flex:0 0 auto}
    .pchy-modal-t{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--p6);margin:0;display:flex;align-items:center;gap:.5rem;min-width:0}
    .pchy-modal-sub{display:block;font-family:'Karla',sans-serif;font-size:.74rem;font-weight:600;color:#8d7a73;margin-top:.1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    /* 40px square: this is the only way out of the modal on a phone that
       does not involve guessing where the backdrop starts. */
    .pchy-modal-x{margin-left:auto;flex:0 0 auto;width:40px;height:40px;display:grid;place-items:center;background:#fff;color:var(--p5);border:1px solid rgba(192,57,43,.2);border-radius:10px;font-size:1rem;cursor:pointer}
    .pchy-modal-x:hover{background:var(--p5);color:#fff;border-color:transparent}
    .pchy-modal-bd{padding:1rem;overflow-y:auto;-webkit-overflow-scrolling:touch;flex:1 1 auto}
    .pchy-empty{text-align:center;color:#b3a099;padding:2rem .5rem;font-size:.84rem}
    .pchy-empty i{display:block;font-size:2rem;color:#eadbd3;margin-bottom:.45rem}

    /* Raised from 900px with the restructure. Two card lists could share
       a 900px-wide row; two tables cannot — below this the Options table's
       six columns start squeezing the Actions column off the card, so the
       panels stack and each gets the full width instead. The page's real
       mobile breakpoint is still 640px, further down, where the tables turn
       into cards. */
    @media(max-width:1180px){
        /* minmax(0,1fr), NOT a bare 1fr. A grid track sized 1fr floors at the
           item's MIN-CONTENT width, so the stacked card refused to shrink
           below its widest unbreakable row and pushed the whole document
           wider than the viewport — a horizontal scrollbar on the page
           itself, measured on a tablet-width viewport, from a rule whose
           only job was to stack the panels. The two-column track above
           already spells it minmax(0,...) for the same reason; this one
           quietly dropped it. */
        .pchy-grid{grid-template-columns:minmax(0,1fr)}
    }

    @media(max-width:640px){
        .pchy-title{font-size:1.35rem}
        .pchy-card{padding:1rem;border-radius:15px}
        .pchy-tools{justify-content:stretch}
        .pchy-search{max-width:none}
        .pchy-select{flex:1;min-width:110px}

        /* ── Tables become cards ──
           The same transformation the Categories page uses at this exact
           breakpoint: the header row is dropped and each cell prints its own
           label from data-l, so no column is lost and nothing has to be
           scrolled sideways on a phone. Every control inside a cell keeps its
           real size, so the checkbox, the three action buttons and both
           toggles stay touch-targets rather than shrinking into the row. */
        .pchy-tbl,.pchy-tbl tbody,.pchy-tbl tr,.pchy-tbl td{display:block;width:100%}
        .pchy-tbl thead{display:none}
        .pchy-tbl tr{background:#fffaf6;border:1px solid rgba(246,180,155,.45);border-radius:14px;padding:.7rem .8rem;margin-bottom:.6rem}
        .pchy-tbl td{background:transparent!important;border:0!important;border-radius:0!important;padding:.25rem 0;display:flex;align-items:center;justify-content:space-between;gap:.75rem;text-align:right}
        .pchy-tbl td::before{content:attr(data-l);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:#9a837b;text-align:left;flex:0 0 auto}

        /* An option is one <tr> now, so the generic ".pchy-tbl tr" card rule
           three lines up already draws its card — the tbody-specific block
           that used to hold a summary row and a detail row together has
           nothing left to hold, and is gone rather than left styling an
           element that no longer exists. */

        /* A branch section header spans its card list instead of sitting in
           a cell, and prints no data-l label — its content is already the
           label. */
        .pchy-menu-tbl tr.pchy-branch-head{background:transparent;border:0;border-radius:0;padding:0;margin-bottom:.4rem}
        .pchy-menu-tbl tr.pchy-branch-head td{display:block;text-align:left;padding:.5rem 0 .2rem}
        .pchy-menu-tbl tr.pchy-branch-head td::before{content:none}

        /* Cells whose content is a wrapping group of chips/buttons read
           better stacked under their label than squeezed beside it. */
        .pchy-tbl td.pchy-cell-stack{display:block;text-align:left}
        .pchy-tbl td.pchy-cell-stack::before{display:block;margin-bottom:.3rem}
        .pchy-tbl td.pchy-col-action{justify-content:flex-end}

        /* width:1% is a shrink-to-fit hint to the TABLE layout algorithm and
           means nothing once a cell is display:block — there it is a literal
           1% of the row, which nowrap content then overflowed, widening the
           document and pushing every 100%-wide element (including the Add
           New Option form) past the viewport. Reset with the cells. */
        .pchy-tbl td.pchy-col-pick,
        .pchy-tbl td.pchy-col-action,
        .pchy-opt-tbl td[data-l="Used In"]{width:auto}
        .pchy-opt-tbl td.pchy-col-action{white-space:normal}
        .pchy-actions{justify-content:flex-end}

        /* Edge to edge on a phone, less the gutter the rest of the page
           keeps, and taller: 720px of dialog centred in a 390px viewport
           would otherwise waste most of the screen on backdrop. */
        .pchy-modal-wrap{padding:.6rem;align-items:flex-end}
        .pchy-modal-dialog{max-height:calc(100vh - 1.2rem);max-height:calc(100svh - 1.2rem)}
        .pchy-modal-bd{padding:.8rem}
        /* The recipe entry row already stacks at this width (.pchy-ing-form
           above); the edit form's own three columns stack at 520px. */
    }
</style>

<div class="pchy">

    <div class="pchy-head">
        <div>
            <h1 class="pchy-title">Menu Options</h1>
            <p class="pchy-sub">Manage add-ons and assign them to menu items.</p>
        </div>

        <div class="pchy-chips">
            <span class="pchy-chip">{{ count($options) }} Options</span>
            <span class="pchy-chip">{{ count($categories) }} Categories</span>
            {{-- Archived add-ons live off this list on purpose; this is how you get
                 back to them. Always shown, even at zero — see menu-items.blade.php
                 for why the entry point cannot be conditional. --}}
            <a href="{{ route('admin.archived') }}" class="pchy-chip" style="text-decoration:none;">
                <i class="bi bi-archive"></i> {{ $archivedCount ?? 0 }} Archived
            </a>
        </div>
    </div>

    @if ($errors->any())
        <div class="pchy-alert">
            @foreach ($errors->all() as $error)
                <div>
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    {{ $error }}
                </div>
            @endforeach
        </div>
    @endif

    {{-- ADD OPTION --}}
    <div class="pchy-card" style="margin-bottom:1.1rem;">
        <div class="pchy-card-hd">
            <p class="pchy-card-t">
                <span class="pchy-ico"><i class="bi bi-plus-circle"></i></span>
                Add New Option
            </p>
        </div>

        <form action="{{ route('admin.menu-options.post') }}" method="POST">
            @csrf

            <div class="pchy-name-price-form pchy-add-form">

                {{-- "Option / Add-on Name": the page's own heading calls these
                     Options, the customer-facing menu and every button on this
                     page call them add-ons, and an owner reading one label had
                     to work out that the two words are the same thing. Both
                     words, once, in the label that introduces the field.

                     The placeholder is an instruction ("Enter Add-on Name")
                     rather than an example ("e.g. Extra Cheese"): a greyed-out
                     product name inside an empty box reads as a value that is
                     already filled in. --}}
                <div class="pchy-field" style="margin:0;">
                    <label class="pchy-label">Option / Add-on Name *</label>
                    <input type="text"
                           name="name"
                           class="pchy-in"
                           placeholder="Enter Add-on Name"
                           required
                           autocomplete="off">
                </div>

                {{-- Copy only. The price rule is NOT relaxed here: the input
                     keeps required + min="0.01", and storeMenuOption() still
                     validates 'required|numeric|gt:0|max:99999999.99' server
                     side, which is the check that actually holds. --}}
                <div class="pchy-field" style="margin:0;">
                    <label class="pchy-label">Price (₱) *</label>
                    <input type="number"
                           name="price"
                           class="pchy-in"
                           placeholder="0.00"
                           step="0.01"
                           min="0.01"
                           max="99999999.99"
                           required>
                </div>

                <button type="submit" class="pchy-btn">
                    <i class="bi bi-plus-lg"></i>
                    Add Option
                </button>

            </div>
        </form>
    </div>

    <div class="pchy-grid">

        {{-- OPTIONS --}}
        <div class="pchy-card">

            <div class="pchy-card-hd">

                <p class="pchy-card-t">
                    <span class="pchy-ico">
                        <i class="bi bi-list-check"></i>
                    </span>
                    Options
                    <span class="pchy-count">{{ count($options) }}</span>
                </p>

                <div class="pchy-tools">

                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text"
                               id="optionSearch"
                               aria-label="Search options"
                               autocomplete="off">
                    </div>

                    <select id="optionPriceFilter" class="pchy-select">
                        <option value="">All prices</option>
                        <option value="free">Free</option>
                        <option value="paid">Paid</option>
                    </select>

                    <select id="optionPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>

                </div>
            </div>

            <p style="font-size:.74rem;color:#8d7a73;margin:-.35rem 0 .8rem;">
                Select options, then click a menu item on the right to assign them.
            </p>

            {{--
                OPTIONS, AS A TABLE.

                This was a list of stacked cards. Every fact an option carries
                — its price, how many menu items use it, whether each of those
                branches has an ingredient mapping, and four controls — sat in
                a free-form block, so comparing two options on any one of those
                meant reading two whole cards. They are columns now, the same
                table the Categories page uses, so a column can be scanned down
                instead.

                GLOBAL, AND STILL GLOBAL. menu_options has no branch_id (see
                the create_menu_options_table migration) and nothing here adds
                one: the table is one row per option, never one row per
                option-and-branch, and it is NOT grouped by branch the way the
                Menu Items picker beside it is. An option's two branch-shaped
                columns say two different things about a single global row:

                  Used In            — the MENU ITEMS this option is assigned
                                       to, each tagged with the branch of that
                                       ITEM (menu_item_options -> menu_items
                                       .branch_id).
                  Ingredient Mapping — whether this option has an ingredient
                                       link into each of those branches'
                                       INVENTORY (menu_option_ingredients ->
                                       inventory.branch_id), which is what
                                       MenuOption::isMappedForBranch() decides.

                "Used by a Branch 2 menu item" and "mapped to Branch 2
                inventory" are independent: unli gravy is used by a Branch 2
                item while being mapped only to Main Branch stock. The two
                columns are kept apart, and headed apart, for that reason.

                ONE <tr> PER OPTION. This was a <tbody> per option, holding a
                summary row plus a detail row, because the detail row carried
                the Used-in chips, the edit form and the recipe-ingredient
                editor and all three had to show, hide and page with their
                summary. All three have left the table — the two editors into
                the shared modal at the foot of this file, the chips into a
                popover anchored to their own button — so there is no second
                row left to group and the wrapper is gone with it, rather
                than kept as an empty shell.

                The hooks did not need preserving separately: .option-row and
                id="opt-N" simply moved onto the <tr>, so the pager still
                hides an option with one class toggle on one element and every
                id lookup still lands on the row that shows that option's
                name, price, badges and ingredient count. The one script that
                walked UP the tree, closest('.pchy-option'), could not survive
                the move on its own terms (its form is inside the modal now,
                not inside any row) and was rewritten to look the row up by id
                — the shape the delete path beside it already used.
            --}}
            <div class="pchy-tblwrap">
            <table class="pchy-tbl pchy-opt-tbl" id="optionsList">

                <thead>
                    <tr>
                        <th class="pchy-col-pick"><span class="visually-hidden">Select</span></th>
                        <th>Option</th>
                        <th>Price</th>
                        <th>Used In</th>
                        <th>Inventory Status</th>
                        <th class="pchy-col-action">Actions</th>
                    </tr>
                </thead>

                {{-- One <tbody> for the whole list, the same shape the Menu
                     Items picker below uses, now that an option no longer
                     needs a grouping element of its own. --}}
                <tbody>

                @if(count($options) > 0)

                    @foreach($options as $option)

                        {{--
                            USED IN — which menu items this option is assigned
                            to (menu_item_options), so an admin can tell at a
                            glance without opening every menu item to check.
                            Same $option->menuItems relation the per-branch
                            status block below already reads to build
                            $optionBranchIds — no new query, one more selected
                            column (name) from showMenuOptions()'s eager load.

                            BRANCH SCOPE. This is a list of concrete, named
                            menu-item rows — the same shape of information as
                            the Menu Items picker on the right, which 1e9df73
                            already scoped to $lockedBranchId (and, deliberately,
                            excludes a NULL-branch/"All Branches" item for a
                            locked supervisor too, since resolveRecordInScope()
                            would 404 on that as well). Left unscoped, this
                            would have quietly reopened the same "picker shows
                            rows the viewer cannot act on" shape that fix closed
                            — a supervisor would learn another branch's menu
                            item names from this line even though the picker
                            right next to it withholds those exact rows. Filtered
                            the same way here so the two agree.

                            The Mapped/Unmapped badges below used to stay
                            unscoped on purpose ("aggregate branch-name/status
                            only, no item identity"). Branch parity audit B8
                            (2026-09-27) reversed that: the aggregate itself
                            still names another branch ("Branch 2: Needs
                            Inventory"), which is exactly the fact this page's
                            other branch-scoped elements withhold from a
                            locked supervisor — so $optionBranchIds below is
                            now filtered the same way $usedInItems is.

                            $mi->branch_id !== null is deliberate and not
                            redundant with the equality check after it: a
                            branchless supervisor's lockedBranchId() is 0 (deny
                            everything — see AdminOrderAccess), and PHP's
                            (int) null === 0, so without this a NULL-branch
                            "All Branches" item would wrongly compare equal to
                            that supervisor's 0 lock. SQL's own
                            where('branch_id', 0), which every other scoped
                            query on this page uses, never matches a NULL
                            column either — this mirrors that, rather than
                            reintroducing the gap in PHP.

                            Both of the PHP blocks below are lifted above the
                            row purely because the summary row now renders these
                            facts as columns and needs them before it opens.
                            Neither reads anything not already eager-loaded.

                            (Spelled out rather than written as the directive:
                            Blade compiles statements before it strips
                            comments, so a literal directive name inside a
                            comment is still compiled.)
                        --}}
                        @php
                            $usedInItems = $option->menuItems
                                ->filter(fn ($mi) => $lockedBranchId === null || ($mi->branch_id !== null && (int) $mi->branch_id === $lockedBranchId))
                                ->sortBy('name')
                                ->values();
                        @endphp
                        {{--
                            PER-BRANCH INGREDIENT-MAPPING STATUS (Phase 3 audit,
                            Finding #3). Listed for every branch this option is
                            actually ASSIGNED to (via menu_item_options), not
                            every branch that exists — a branch that doesn't
                            offer this option at all has nothing to flag.
                            "Mapped" = at least one ingredient link
                            (MenuOptionIngredient) whose inventory belongs to
                            that branch; see MenuOption::isMappedForBranch().
                            An "Unmapped" branch is exactly the one this fix
                            hides the option from on that branch's customer
                            menu, so this is where the admin sees the gap
                            before a customer would have hit it.

                            LIVE, not render-once: the badges are re-derived
                            from the ingredient rows on screen after every
                            add/remove (optSyncBranchBadges() in the script
                            below), so they never need a page reload to match
                            reality. The two titles ride on the container as
                            data-* so the script and this markup share one
                            wording.

                            The amber hint ("add inventory first") is rendered
                            for every listed branch that has NO active
                            inventory at all — nothing to link to, so the badge
                            can never turn green until stock is added on the
                            Inventory page. It stays in the markup, `hidden`,
                            while the branch is Mapped, so the script can bring
                            it back if the last link is removed.
                            $emptyInventoryBranchIds is computed by
                            showMenuOptions() from the actor's visible
                            inventory (see the note there).
                        --}}
                        @php
                            $optionBranchIds = $option->menuItems
                                ->filter(fn ($mi) => $lockedBranchId === null || ($mi->branch_id !== null && (int) $mi->branch_id === $lockedBranchId))
                                ->pluck('branch_id')->filter()->unique()->sort()->values();
                            $badgeTitleOk = 'Has an ingredient link for this branch.';
                            $badgeTitleOff = 'No ingredient link for this branch yet — hidden from this branch\'s customer menu.';
                            $badgeTitleEmpty = 'This branch has no active inventory yet, so there is nothing here to link. Add inventory items for that branch first.';
                        @endphp

                        {{--
                            THIS OPTION'S RECIPE, AS DATA RATHER THAN MARKUP.

                            Every option used to render its own copy of the
                            whole ingredient editor into the page: the saved
                            rows, an error box, an add form, and — the
                            expensive part — a <select> listing EVERY
                            inventory item the viewer can see. That is one
                            select per option, so the page grew with
                            options x inventory items and a shop with 20
                            add-ons and 60 inventory rows shipped 1,200
                            <option> elements nobody had asked to see.

                            There is one editor now, in the modal, and it is
                            filled from this attribute when a row is opened.
                            What stays per-option is the data the editor
                            needs, which is what it always was.

                            EVERY FIELD IS DECIDED HERE, ON THE SERVER,
                            including can_remove: the browser is told whether
                            to draw a remove button or the lock, it does not
                            work that out for itself. The expression is the
                            same one that used to sit beside the rendered
                            row, and it mirrors what deleteOptionIngredient()
                            will actually honour — an admin
                            ($lockedBranchId null) may remove any link, a
                            branch-locked supervisor only their own branch's,
                            and a link whose inventory row has gone missing
                            reads (int) null === 0, which is never a real
                            locked branch, so it is withheld. Cosmetic
                            either way: the endpoint refuses regardless, and
                            a test proves that separately.

                            The key names match the `ingredient` payload
                            addOptionIngredient() returns, so ONE builder in
                            the script draws both a row restored from here
                            and a row just added over fetch(), and the two
                            cannot drift apart.
                        --}}
                        @php
                            $optionIngredientRows = $option->ingredients->map(function ($ing) use ($option, $branches, $lockedBranchId) {
                                $ingBranchId = $ing->inventory?->branch_id;

                                $canRemoveLink = ($lockedBranchId ?? null) === null
                                    || (int) $ingBranchId === (int) $lockedBranchId;

                                return [
                                    'id' => $ing->id,
                                    'name' => $ing->inventory->item_name ?? 'Deleted item',
                                    'quantity_used' => rtrim(rtrim(number_format((float) $ing->quantity_used, 3), '0'), '.'),
                                    'unit' => $ing->inventory->unit ?? '',
                                    'branch_id' => $ingBranchId !== null ? (int) $ingBranchId : null,
                                    'branch_name' => $ingBranchId !== null
                                        ? ($branches[$ingBranchId]->name ?? ('Branch #' . $ingBranchId))
                                        : null,
                                    // Withheld, not merely unused, when the
                                    // actor may not remove this link. The
                                    // markup this replaced rendered no
                                    // delete button AND no delete URL for
                                    // another branch's link, and handing one
                                    // over now — to be ignored by the
                                    // builder — would have quietly widened
                                    // what the page tells a locked
                                    // supervisor. Still cosmetic: the
                                    // endpoint refuses them either way, and
                                    // a test proves that separately.
                                    'delete_url' => $canRemoveLink
                                        ? route('admin.menu-options.ingredients.delete', [$option->id, $ing->id])
                                        : null,
                                    'can_remove' => $canRemoveLink,
                                ];
                            })->values();
                        @endphp

                        <tr class="pchy-option option-row"
                            id="opt-{{ $option->id }}"
                            data-name="{{ strtolower($option->name) }}"
                            data-price="{{ $option->additional_price > 0 ? 'paid' : 'free' }}"
                            {{-- What the modal needs to become THIS option.
                                 data-option-name/-price are the raw values
                                 for the edit form's inputs, which is not the
                                 same string as the rendered cells above
                                 (those carry "+" and a thousands separator).
                                 data-update-url is generated by route() here
                                 rather than assembled from an id in the
                                 script, so the one place that knows this
                                 page's URLs stays the router. --}}
                            data-option-name="{{ $option->name }}"
                            data-option-price="{{ $option->additional_price }}"
                            data-update-url="{{ route('admin.menu-options.update', $option->id) }}"
                            data-ingredient-add-url="{{ route('admin.menu-options.ingredients.add', $option->id) }}"
                            data-ingredients="{{ json_encode($optionIngredientRows) }}">

                            <td class="pchy-col-pick" data-l="Select">
                                <input type="checkbox"
                                       class="option-check pchy-option-check"
                                       aria-label="Select {{ $option->name }}"
                                       data-id="{{ $option->id }}">
                            </td>

                            <td data-l="Option">
                                <span class="pchy-option-name">
                                    {{ $option->name }}
                                </span>
                            </td>

                            <td data-l="Price">
                                @if($option->additional_price > 0)

                                    <span class="pchy-price">
                                        +₱{{ number_format($option->additional_price, 2) }}
                                    </span>

                                @else

                                    <span class="pchy-free">
                                        Free
                                    </span>

                                @endif
                            </td>

                            {{--
                                 USED IN, AS A DISCLOSURE.

                                 The branch on each chip is the MENU
                                 ITEM's branch, not the option's — this
                                 column and Inventory Status beside it
                                 stay two different facts, as they have
                                 since they were split apart.

                                 THREE STATES, and the middle one is not
                                 a disclosure at all:

                                   0 items  a quiet grey line. There is
                                            nothing to open.
                                   1 item   the item itself, as a chip,
                                            right in the cell. A click to
                                            reveal a single name buys
                                            nothing, and one chip costs
                                            the row no more height than
                                            the button would have.
                                   2+       a count button that opens the
                                            popover below it.

                                 The list is a sibling of the button
                                 inside this cell, but it is
                                 position:fixed (see .pchy-pop) and the
                                 script places it against the button's
                                 own rectangle, so it is laid out against
                                 the viewport and cannot be clipped by
                                 .pchy-tblwrap's overflow-x:auto — which
                                 is exactly what would have happened to an
                                 absolutely-positioned one. Closed, it is
                                 display:none, so a row is the same height
                                 whether it has ever been opened or not.
                            --}}
                            <td data-l="Used In" class="pchy-cell-stack">
                                @if($usedInItems->isEmpty())
                                    <p class="pchy-usedin-empty">Not assigned to any menu item yet.</p>
                                @elseif($usedInItems->count() === 1)
                                    @php
                                        $soloItem = $usedInItems->first();
                                        $soloBranchName = $soloItem->branch_id === null
                                            ? 'All Branches'
                                            : ($branches->get($soloItem->branch_id)?->name ?? ('Branch #'.$soloItem->branch_id));
                                    @endphp
                                    <span class="pchy-usedin-solo" title="The one menu item this option is assigned to">
                                        <span class="pchy-usedin-name">{{ $soloItem->name }}</span>
                                        <span class="pchy-ing-branch">{{ $soloBranchName }}</span>
                                    </span>
                                @else
                                    <button type="button"
                                            class="pchy-ing-toggle"
                                            id="usedin-btn-{{ $option->id }}"
                                            onclick="toggleUsedIn({{ $option->id }})"
                                            aria-expanded="false"
                                            aria-controls="usedin-list-{{ $option->id }}"
                                            title="Menu items this option is assigned to">
                                        Used in {{ $usedInItems->count() }} items
                                        <i class="bi bi-chevron-down pchy-usedin-caret"></i>
                                    </button>

                                    <div class="pchy-pop pchy-usedin-list"
                                         id="usedin-list-{{ $option->id }}"
                                         role="group"
                                         aria-label="Menu items using {{ $option->name }}">
                                        @foreach($usedInItems as $usedItem)
                                            @php
                                                $usedItemBranchName = $usedItem->branch_id === null
                                                    ? 'All Branches'
                                                    : ($branches->get($usedItem->branch_id)?->name ?? ('Branch #'.$usedItem->branch_id));
                                            @endphp
                                            <span class="pchy-usedin-item">
                                                {{ $usedItem->name }}
                                                <span class="pchy-ing-branch">{{ $usedItemBranchName }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>

                            {{--
                                 INVENTORY STATUS — one badge per branch.

                                 The branch on each badge is the branch
                                 whose INVENTORY this option does or does
                                 not have an ingredient link into. That is
                                 a different fact from the Used In column
                                 before it, which names the branch of each
                                 MENU ITEM using the option, and the two
                                 stay two columns, headed apart, for
                                 exactly that reason. Nothing here is
                                 merged into Used In and nothing there is
                                 merged into this.

                                 WHAT CHANGED. An unmapped branch with no
                                 stock used to render TWO pills side by
                                 side — a red "<branch>: Unmapped" badge
                                 and an amber "No active inventory — add
                                 inventory first" hint — so a page holding
                                 a few options showed a wall of pills the
                                 reader had to pair up by eye, and
                                 "Unmapped" was jargon for a state the
                                 owner fixes on a different page anyway.
                                 One pill per branch now, and it says what
                                 to do rather than what is wrong:

                                   Ready                mapped
                                   Needs Inventory      unmapped, and this
                                                        branch does have
                                                        stock to link to
                                   Add Inventory First  unmapped, and this
                                                        branch has no
                                                        active inventory
                                                        at all, so the
                                                        Inventory page is
                                                        the first stop

                                 The third state IS the old amber hint,
                                 folded into the badge rather than dropped.

                                 LIVE, not render-once. The badges are
                                 re-derived from the ingredient rows on
                                 screen after every add/remove
                                 (optSyncBranchBadges() below), so they
                                 never need a reload to match reality. The
                                 three titles ride on the container as
                                 data-*, and "does this branch have any
                                 active inventory" rides on each badge as
                                 data-empty-inventory, so the script can
                                 rebuild all three states from the DOM
                                 alone. That last attribute is not
                                 redundant with the rendered state: an
                                 option mapped through an ARCHIVED
                                 inventory row is Ready while having zero
                                 ACTIVE inventory, so removing its last
                                 link has to land on "Add Inventory First"
                                 and not on "Needs Inventory", and only
                                 the attribute still knows that.

                                 $emptyInventoryBranchIds is computed by
                                 showMenuOptions() from the actor's
                                 VISIBLE inventory — see the note there. --}}
                            <td data-l="Inventory Status" class="pchy-cell-stack">
                                @if($optionBranchIds->isNotEmpty())
                                <div class="pchy-branch-status"
                                     id="opt-branch-status-{{ $option->id }}"
                                     data-title-ok="{{ $badgeTitleOk }}"
                                     data-title-off="{{ $badgeTitleOff }}"
                                     data-title-empty="{{ $badgeTitleEmpty }}">
                                    @foreach($optionBranchIds as $bid)
                                        @php
                                            $mapped = $option->isMappedForBranch((int) $bid);
                                            $bidName = $branches[$bid]->name ?? ('Branch #' . $bid);
                                            $bidHasNoInventory = in_array((int) $bid, $emptyInventoryBranchIds ?? [], true);

                                            $bidState = $mapped ? 'ok' : ($bidHasNoInventory ? 'empty' : 'off');
                                            $bidLabel = ['ok' => 'Ready', 'off' => 'Needs Inventory', 'empty' => 'Add Inventory First'][$bidState];
                                            $bidIcon = ['ok' => 'bi-check-circle-fill', 'off' => 'bi-exclamation-triangle-fill', 'empty' => 'bi-info-circle-fill'][$bidState];
                                            $bidTitle = ['ok' => $badgeTitleOk, 'off' => $badgeTitleOff, 'empty' => $badgeTitleEmpty][$bidState];
                                        @endphp
                                        <span class="pchy-branch-badge pchy-branch-{{ $bidState }}"
                                              data-branch-id="{{ $bid }}"
                                              data-branch-name="{{ $bidName }}"
                                              data-empty-inventory="{{ $bidHasNoInventory ? '1' : '0' }}"
                                              title="{{ $bidTitle }}">
                                            <i class="bi {{ $bidIcon }}"></i>
                                            <span class="pchy-branch-badge-text">{{ $bidName }} {{ $bidLabel }}</span>
                                        </span>
                                    @endforeach
                                </div>
                                @else
                                    <span class="pchy-muted">—</span>
                                @endif
                            </td>

                            <td class="pchy-col-action" data-l="Actions">
                                <div class="pchy-actions">

                                {{--
                                    BOTH BUTTONS OPEN THE ONE MODAL, and
                                    they are still two buttons on purpose.

                                    They used to expand two different
                                    panels in a detail row beneath this
                                    one. The panels are two sections of
                                    the same dialog now, so either button
                                    could have been dropped — but the
                                    basket carries this option's live
                                    ingredient COUNT, which is a fact the
                                    row states whether or not anyone opens
                                    anything, and the pencil is the
                                    affordance an owner looks for to
                                    rename something. Collapsing them into
                                    one control would have cost the count
                                    or cost the pencil.

                                    The count element keeps its class and
                                    its place in this row: the add and
                                    remove paths write to it by looking
                                    this row up by id, which is why it can
                                    stay here while the editor that
                                    changes it sits in the modal.
                                --}}
                                <button type="button"
                                        class="pchy-ing-toggle"
                                        onclick="openOptionModal({{ $option->id }}, 'recipe')"
                                        title="Recipe ingredients">
                                    <i class="bi bi-basket"></i>
                                    <span class="pchy-ing-count">{{ $option->ingredients->count() }}</span>
                                </button>

                                <button type="button"
                                        class="pchy-edit-btn"
                                        onclick="openOptionModal({{ $option->id }}, 'edit')"
                                        title="Edit option">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <form action="{{ route('admin.menu-options.delete', $option->id) }}"
                                      method="POST"
                                      onsubmit="return confirm('Delete this option?')"
                                      style="display:inline;margin:0;">

                                    @csrf
                                    @method('DELETE')

                                    <button type="submit"
                                            class="pchy-delete-btn"
                                            title="Delete option">
                                        <i class="bi bi-trash3"></i>
                                    </button>

                                </form>

                                </div>
                            </td>

                        </tr>

                    @endforeach

                @else

                    {{-- The placeholder was a <tbody> of its own because
                         every option was one too. It is an ordinary row in
                         the shared <tbody> now. .pchy-empty already strips
                         the cell's own chrome, so it needs no class of its
                         own to sit flat. --}}
                    <tr>
                        <td colspan="6">
                            <div class="pchy-empty">
                                <i class="bi bi-list-check"></i>
                                No options yet. Add one above.
                            </div>
                        </td>
                    </tr>

                @endif

                </tbody>

            </table>
            </div>

            <div class="pchy-pager" id="optionPager" hidden>

                <span id="optionPagerInfo"></span>

                <div class="pchy-page-nav">
                    <button type="button"
                            class="pchy-page-btn"
                            id="optionPrev">
                        ‹
                    </button>

                    <span id="optionPages"></span>

                    <button type="button"
                            class="pchy-page-btn"
                            id="optionNext">
                        ›
                    </button>
                </div>

            </div>

            {{--
                THE ASSIGNMENT BANNER.

                This used to be one small grey line — "Assign selected options
                to: <item> <branch>" — above a button, in the same type size
                as the pager beneath it. It is the only thing on the page that
                says which row a save is about to overwrite, and it was the
                quietest thing on the page.

                It is a banner now: a headline that names the item and, in
                parentheses after it, the branch that item belongs to, both
                large enough to read without leaning in. The parentheses are
                real characters in their own spans, not CSS ::before/::after,
                so the line survives being copied, read aloud by a screen
                reader, or hit by a browser's own find-in-page.

                The branch is NOT decoration. Live data carries two menu items
                called "coke" and two called "roasted chicken", so the item
                name alone does not identify the row a save is about to
                rewrite. selectMenuItem() fills both from the clicked row's
                own data-*, so the banner and the row it came from cannot
                disagree.

                THE CHECKBOXES ARE THE OPTION ROWS. There is deliberately no
                second list of add-ons in here. Every option on the left is
                already a row with its own checkbox, and selectMenuItem()
                ticks the ones this item already has; a duplicate list inside
                the banner would be a second set of checkboxes to keep in step
                with the first, across the search box, the category filter and
                the pager. What the banner adds instead is the one thing that
                list of ticks could not say for itself — a running count of
                how many are ticked right now, so the number is visible at the
                moment of saving rather than something to scroll back and
                recount.
            --}}
            <div id="assignSection" class="pchy-assign">

                <p class="pchy-assign-title">
                    <i class="bi bi-arrow-right-circle-fill"></i>
                    Assigning Add-ons to:
                </p>

                <p class="pchy-assign-item">
                    <span id="selectedItemName" class="pchy-selected-name">Item</span><span class="pchy-assign-branch-wrap"> (<span id="selectedItemBranch" class="pchy-selected-branch">Branch</span>)</span>
                </p>

                <p class="pchy-assign-hint">
                    Tick the add-ons you want on this item in the list above,
                    then save.
                    <span id="assignCount" class="pchy-assign-count">0 ticked</span>
                </p>

                <button type="button"
                        onclick="saveAssignment()"
                        class="pchy-btn pchy-assign-save">

                    <i class="bi bi-check-circle-fill"></i>
                    Save Add-ons

                </button>

            </div>

        </div>


        {{-- MENU ITEMS --}}
        <div class="pchy-card pchy-menu-card">

            <div class="pchy-card-hd">

                <p class="pchy-card-t">
                    <span class="pchy-ico">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </span>
                    Menu Items
                </p>

                <div class="pchy-tools">

                    <div class="pchy-search">
                        <i class="bi bi-search"></i>
                        <input type="text"
                               id="menuSearch"
                               aria-label="Search menu items"
                               autocomplete="off">
                    </div>

                    <select id="menuCategoryFilter" class="pchy-select">

                        <option value="">
                            All categories
                        </option>

                        @foreach($categories as $catFilter)

                            <option value="{{ strtolower($catFilter->name) }}">
                                {{ $catFilter->name }}
                            </option>

                        @endforeach

                    </select>

                    <select id="menuPageSize" class="pchy-select">
                        <option value="10">10 / page</option>
                        <option value="25" selected>25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>

                </div>

            </div>

            <p class="pchy-menu-hint">
                Click a menu item to load its assigned options.
            </p>


            {{--
                THE MENU ITEMS PICKER, GROUPED BY THE ITEM'S BRANCH.

                This was a flat list of look-alike rows. Live data carries two
                menu items called "coke" (Main Branch and Branch 1) and two
                called "roasted chicken" (Main Branch and Branch 2) — real,
                separate, branch-scoped menu_items rows, not duplicates — so
                "which one am I assigning this add-on to?" was a per-row chip
                you had to read one row at a time. The rows sit under a branch
                section header now, the same shape menu-items.blade.php uses
                (891490d), so the branch is read once per section.

                WHAT THE HEADER MEANS. It is the branch of the MENU ITEMS
                below it, and nothing else. It says nothing about the global
                add-ons in the table on the left — menu_options has no
                branch_id at all. An option appearing under a heading here
                would be an assignment target, never a statement that the
                option belongs to that branch.

                ORDERING is worked out here rather than in the controller
                because $categories is already loaded and grouping it costs no
                query — the same in-memory grouping menu-items.blade.php does.
                Non-null branches first, by branch id, then the shared
                "All Branches" (branch_id IS NULL) items last: the convention
                891490d established on the sibling page. Name breaks the tie
                inside a branch, so the two "coke" rows land in their own
                sections rather than next to each other.

                BRANCH SCOPE is unchanged and still the controller's:
                showMenuOptions() narrows $categories->menuItems to
                $lockedBranchId for a branch-locked supervisor, so a locked
                viewer simply has one section. Nothing here widens that — this
                only re-orders and labels rows the controller already chose,
                and assignOptions() re-checks the target server-side regardless
                of what is on screen.
            --}}
            @php
                $pickerRows = $categories
                    ->flatMap(fn ($cat) => $cat->menuItems->map(fn ($mi) => (object) ['item' => $mi, 'category' => $cat]))
                    ->sortBy(fn ($row) => sprintf(
                        '%d|%010d|%s',
                        $row->item->branch_id === null ? 1 : 0,
                        $row->item->branch_id ?? 0,
                        strtolower($row->item->name)
                    ))
                    ->values();

                $pickerBranchCounts = $pickerRows
                    ->groupBy(fn ($row) => $row->item->branch_id ?? 'unassigned')
                    ->map->count();

                $currentPickerBranch = null;
            @endphp

            <div class="pchy-tblwrap">
            <table class="pchy-tbl pchy-menu-tbl" id="menuItemsList">

                <thead>
                    <tr>
                        <th>Menu Item</th>
                        <th>Assigned Add-ons</th>
                        <th class="pchy-col-action">Options</th>
                    </tr>
                </thead>

                <tbody>

                @if($pickerRows->isNotEmpty())

                    @foreach($pickerRows as $row)

                        @php
                            $item = $row->item;
                            $category = $row->category;
                            $pickerBranchKey = $item->branch_id ?? 'unassigned';

                            /*
                             * Which branch this menu item belongs to.
                             *
                             * The name comes from $branches (already loaded
                             * for the per-branch mapping badges on the left),
                             * not from an $item->branch relation, so this
                             * costs no query per row.
                             *
                             * A NULL branch_id is a shared item offered by
                             * every branch — labelled as such rather than left
                             * blank, so a blank never has to be read as
                             * "unknown".
                             *
                             * ->get() rather than $branches[$id]:
                             * Collection::offsetGet() on a key that is not
                             * there raises an undefined-key warning before the
                             * ?? can catch it, and a menu item pointing at a
                             * deleted branch should degrade to "Branch #n",
                             * not warn.
                             */
                            $itemBranchName = $item->branch_id === null
                                ? 'All Branches'
                                : ($branches->get($item->branch_id)?->name ?? ('Branch #' . $item->branch_id));
                        @endphp

                        @if($pickerBranchKey !== $currentPickerBranch)
                            @php
                                $currentPickerBranch = $pickerBranchKey;
                                $pickerBranchCount = $pickerBranchCounts[$pickerBranchKey];
                            @endphp
                            <tr class="pchy-branch-head" data-branch-group="{{ $pickerBranchKey }}">
                                <td colspan="3">
                                    <div class="pchy-branch-section">
                                        <i class="bi bi-building"></i>
                                        <span class="value">{{ $itemBranchName }}</span>
                                        <span class="count">{{ $pickerBranchCount }} item{{ $pickerBranchCount === 1 ? '' : 's' }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        <tr class="menu-item-row menu-filter-row"
                            data-id="{{ $item->id }}"
                            data-name="{{ strtolower($item->name) }}"
                            data-branch-name="{{ $itemBranchName }}"
                            data-branch-group="{{ $pickerBranchKey }}"
                            data-category="{{ strtolower($category->name) }}"
                            data-options="{{ $item->options->pluck('id')->toJson() }}"
                            onclick="selectMenuItem(this)">

                            <td data-l="Menu Item">
                                <span class="pchy-menu-item-name">{{ $item->name }}</span>

                                {{-- Kept alongside the section header, not
                                     replaced by it: the list is paged
                                     client-side, so a branch section can split
                                     across two pages, and selectMenuItem()
                                     mirrors this exact value into "Assign
                                     selected options to: <item> <branch>".
                                     Same neutral branch NAME chip the saved
                                     ingredient rows use — never the green/red
                                     Mapped/Unmapped badge, which means
                                     something else. --}}
                                <span class="pchy-ing-branch pchy-menu-item-branch">{{ $itemBranchName }}</span>
                            </td>

                            {{--
                                 ASSIGNED ADD-ONS, where Category used to be.

                                 Category was the one fact on this row that
                                 answered a question nobody asks while
                                 assigning add-ons, and it is not lost: the
                                 "All categories" dropdown above still filters
                                 on it (it reads the ROW's data-category
                                 attribute, never this cell), and the Menu
                                 Items admin page still shows it as a column
                                 of its own.

                                 What the row could NOT answer was the only
                                 question this page exists for: what is
                                 already on this item. That was the bare
                                 "N option(s)" count in the next cell, which
                                 gives an owner a number and then makes them
                                 click the row to find out which ones.

                                 Names and prices, not a count. $item->options
                                 is already eager-loaded by showMenuOptions()
                                 (the ->with('options') inside the $categories
                                 load) and is the same relation the count
                                 beside it reads, so this costs no extra query
                                 per row.

                                 A zero-price add-on reads "Free" rather than
                                 "+₱0.00" -- the same word the Options table
                                 on the left prints for the same fact. New
                                 options cannot be free (storeMenuOption()
                                 validates price as gt:0), but rows created
                                 before that rule can be, and a price column
                                 that renders one of those as a peso amount of
                                 nothing would be the page disagreeing with
                                 itself.
                            --}}
                            {{--
                                 ONE CHIP, OR A COUNT THAT OPENS A POPOVER.

                                 Listing every add-on inline answered the
                                 question this page exists for, and then
                                 created a new one: an item carrying six of
                                 them wrapped its cell over three lines and
                                 made its row three times the height of the
                                 row above it, so a picker meant to be
                                 scanned became a list to be scrolled.

                                 One add-on still shows as its own chip —
                                 that is the common case, it fits, and a
                                 click to reveal a single name buys nothing.
                                 Two or more collapse behind a count that
                                 opens the same .pchy-pop popover the Used In
                                 column uses, so every row in the picker is
                                 the same height no matter how loaded the
                                 item is.

                                 The price string is built in PHP, not with
                                 an inline @if/@else around the parentheses.
                                 Blade reads a directive's arguments from the
                                 parenthesis that FOLLOWS it, so "@else(Free)"
                                 compiles as @else with an argument of "Free"
                                 and prints nothing at all — a legacy
                                 zero-priced add-on rendered as an empty pair
                                 of tags. Same family of trap as a directive
                                 written inside a Blade comment.
                            --}}
                            <td data-l="Assigned Add-ons" class="pchy-cell-stack">
                                @if($item->options->isEmpty())

                                    <span class="pchy-noaddons">No add-ons assigned</span>

                                @else

                                    @php
                                        $assignedSorted = $item->options->sortBy('name')->values();
                                    @endphp

                                    @if($assignedSorted->count() === 1)

                                        @php
                                            $soloAddon = $assignedSorted->first();
                                            $soloAddonPrice = $soloAddon->additional_price > 0
                                                ? '(+₱' . number_format($soloAddon->additional_price, 2) . ')'
                                                : '(Free)';
                                        @endphp
                                        <span class="pchy-addon-list">
                                            <span class="pchy-addon-chip">{{ $soloAddon->name }} <span class="pchy-addon-price">{{ $soloAddonPrice }}</span></span>
                                        </span>

                                    @else

                                        {{-- The button and its panel are
                                             siblings inside one wrapper, which
                                             is how toggleAssignedAddons() finds
                                             the panel from the button without a
                                             second id to keep in step across a
                                             repaint. The wrapper is NOT the
                                             popover's positioning context —
                                             .pchy-pop is position:fixed and the
                                             script places it — it only pairs
                                             the two. --}}
                                        <span class="pchy-addon-wrap">
                                            {{-- The event is passed on so the
                                                 handler can stop it: this
                                                 button sits inside a row whose
                                                 own onclick picks that item as
                                                 the assignment target, and
                                                 looking at what is already on
                                                 an item must not quietly
                                                 re-aim the next save at it. --}}
                                            <button type="button"
                                                    class="pchy-addon-more"
                                                    onclick="toggleAssignedAddons(this, event)"
                                                    aria-expanded="false"
                                                    title="Add-ons assigned to this item">
                                                {{ $assignedSorted->count() }} Add-ons
                                                <i class="bi bi-chevron-down pchy-usedin-caret"></i>
                                            </button>

                                            <span class="pchy-pop pchy-addon-pop"
                                                  role="group"
                                                  aria-label="Add-ons assigned to {{ $item->name }}">
                                                @foreach($assignedSorted as $assignedOption)
                                                    @php
                                                        $addonPrice = $assignedOption->additional_price > 0
                                                            ? '(+₱' . number_format($assignedOption->additional_price, 2) . ')'
                                                            : '(Free)';
                                                    @endphp
                                                    <span class="pchy-addon-chip">{{ $assignedOption->name }} <span class="pchy-addon-price">{{ $addonPrice }}</span></span>
                                                @endforeach
                                            </span>
                                        </span>

                                    @endif

                                @endif
                            </td>

                            <td data-l="Options" class="pchy-col-action">
                                <span class="pchy-menu-item-count">{{ $item->options->count() }} option(s)</span>
                            </td>

                        </tr>

                    @endforeach

                @else

                    <tr class="pchy-branch-head">
                        <td colspan="3">
                            <div class="pchy-empty">
                                <i class="bi bi-grid-3x3-gap"></i>
                                No categories or menu items yet.
                            </div>
                        </td>
                    </tr>

                @endif

                </tbody>

            </table>
            </div>


            <div class="pchy-pager" id="menuPager" hidden>

                <span id="menuPagerInfo"></span>

                <div class="pchy-page-nav">

                    <button type="button"
                            class="pchy-page-btn"
                            id="menuPrev">
                        ‹
                    </button>

                    <span id="menuPages"></span>

                    <button type="button"
                            class="pchy-page-btn"
                            id="menuNext">
                        ›
                    </button>

                </div>

            </div>

        </div>

    </div>

    {{--
        ══════════════════════════════════════════════════════════════
        THE EDIT OPTION / ADD-ON MODAL
        ══════════════════════════════════════════════════════════════

        ONE SHELL FOR THE WHOLE PAGE, not one per option.

        Editing an option used to expand a second table row underneath
        it, holding a name/price form and the whole recipe editor. Two
        or three clicks and the table was taller than the screen, with
        the row you were working on pushed somewhere off it — the
        table stopped being a table exactly when you needed to read it.

        WHY ONE AND NOT ONE PER ROW. A per-option modal would have
        shipped N copies of this markup, and the expensive part is the
        inventory <select>: it lists every inventory row the viewer may
        use, so a per-option copy costs options x inventory items in
        <option> elements. A shop with 20 add-ons and 60 inventory rows
        was already paying 1,200 of them before this pass, because the
        inline editor had exactly that shape. One shell costs 60.

        WHAT MAKES IT THIS OPTION. openOptionModal(id) reads the
        clicked row's own data-* — its name, its raw price, its update
        URL, its ingredient-add URL and its recipe as JSON — and writes
        them in. Nothing is cached between opens and nothing is left
        behind on close: closeOptionModal() empties the recipe list and
        forgets the option id, so A -> B -> A cannot show A's rows while
        B is open, or B's while A is.

        WHAT DID NOT CHANGE. The name/price form still POSTs (PUT) to
        admin.menu-options.update and is still the only thing that
        writes a name or a price; the add and remove buttons still call
        admin.menu-options.ingredients.add / .delete and are still the
        only things that write a recipe link. Every one of those
        endpoints re-checks the actor server-side — the branch lock on
        inventory, the positive-price rule, the per-option ownership of
        an ingredient row — and none of them learned to trust this
        dialog. It is a place to stand, not a new authority.

        The action attribute is filled from the row's data-update-url,
        which Blade generated with route(). The script never assembles a
        URL out of an id, so the router stays the one thing that knows
        this page's URLs.
    --}}
    <div class="pchy-modal"
         id="optionModal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="optionModalTitle"
         aria-hidden="true">

        {{-- A sibling of the dialog, not a background on its wrapper:
             a click can then be told apart by what it landed on, with
             no coordinate maths. --}}
        <div class="pchy-modal-backdrop" data-modal-dismiss></div>

        <div class="pchy-modal-wrap">
            <div class="pchy-modal-dialog">

                <div class="pchy-modal-hd">
                    <p class="pchy-modal-t" id="optionModalTitle">
                        <span class="pchy-ico"><i class="bi bi-pencil-square"></i></span>
                        <span>
                            Edit Option / Add-on
                            {{-- Which one, under the fixed title. The
                                 title itself never changes, so a
                                 screen reader announcing the dialog
                                 always says the same thing, and the
                                 name below it says which row. --}}
                            <span class="pchy-modal-sub" id="optionModalName"></span>
                        </span>
                    </p>

                    <button type="button"
                            class="pchy-modal-x"
                            id="optionModalClose"
                            data-modal-dismiss
                            aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="pchy-modal-bd">

                    {{--
                        EDIT NAME/PRICE ONLY.

                        A real form POSTing (PUT) to
                        admin.menu-options.update, not a fetch()-driven
                        panel like the recipe editor below it — there is
                        nothing here that benefits from staying open
                        across several saves, so a normal
                        submit-and-redirect is the simpler,
                        harder-to-break choice.

                        It touches exactly the two columns
                        updateMenuOption() writes. menu_item_options is
                        never named in this markup, so there is nothing
                        here that could move an assignment, and the
                        row's own data-name/data-price (which the search
                        and price filters read) belong to the list, not
                        to this form.
                    --}}
                    <div class="pchy-edit" id="optionModalEdit">

                        <form method="POST"
                              id="optionModalForm"
                              class="pchy-name-price-form pchy-edit-form">

                            @csrf
                            @method('PUT')

                            {{-- "Option / Add-on Name", the same label the
                                 Add New Option form above uses. The page's
                                 heading calls these Options and every
                                 button calls them add-ons; one label
                                 saying one of those words left an owner to
                                 work out that the two are the same thing,
                                 and the two forms disagreeing about it
                                 made that worse. --}}
                            <div class="pchy-field" style="margin:0;">
                                <label class="pchy-label" for="optionModalNameInput">Option / Add-on Name *</label>
                                <input type="text"
                                       id="optionModalNameInput"
                                       name="name"
                                       class="pchy-in"
                                       autocomplete="off"
                                       required>
                            </div>

                            {{-- Copy and layout only. The rule that
                                 actually holds is updateMenuOption()'s
                                 'required|numeric|gt:0|max:99999999.99',
                                 which is untouched by this pass; these
                                 attributes are the browser's head start
                                 on it, kept identical to the Add form's. --}}
                            <div class="pchy-field" style="margin:0;">
                                <label class="pchy-label" for="optionModalPriceInput">Price (₱) *</label>
                                <input type="number"
                                       id="optionModalPriceInput"
                                       name="price"
                                       class="pchy-in"
                                       placeholder="0.00"
                                       step="0.01"
                                       min="0.01"
                                       max="99999999.99"
                                       required>
                            </div>

                            <button type="submit" class="pchy-btn">
                                <i class="bi bi-check-lg"></i>
                                Save
                            </button>

                        </form>

                    </div>

                    {{-- RECIPE INGREDIENTS (MenuOptionIngredient).

                         The rows are drawn from the open row's
                         data-ingredients by optIngRow(), which is the
                         same builder the add path uses on the JSON
                         addOptionIngredient() returns — one builder, so
                         a row restored here and a row just added cannot
                         come out looking different.

                         can_remove is decided on the server and carried
                         in that JSON, so a branch-locked supervisor is
                         offered the lock, not a dead button, on another
                         branch's link. Cosmetic: deleteOptionIngredient()
                         refuses it either way, which a test proves
                         separately. --}}
                    <div class="pchy-ing" id="optionModalRecipe">

                        <p class="pchy-ing-hd">
                            <i class="bi bi-basket"></i>
                            Recipe Ingredients
                            <span class="pchy-ing-note">deducted only when this add-on is selected</span>
                        </p>

                        <p class="pchy-ing-empty" id="optionModalIngEmpty">
                            No ingredients linked. This add-on will not deduct any stock.
                        </p>

                        <div class="pchy-ing-list" id="optionModalIngList" style="display:none;"></div>

                        <p class="pchy-ing-error" id="optionModalIngError" style="display:none;color:#C0392B;background:#fdecea;border-radius:8px;padding:.4rem .6rem;font-size:.72rem;margin:0 0 .5rem;"></p>

                        <form class="pchy-ing-form opt-ing-add-form" id="optionModalIngForm">

                            @csrf

                            {{--
                                THE INVENTORY PICKER, GROUPED BY BRANCH.

                                This was one flat alphabetical list with
                                "— <branch>" tacked onto the end of every
                                label, so picking the Main Branch copy of
                                an item meant reading to the end of each
                                line to tell the copies apart. The branch
                                is a heading now and the trailing text is
                                gone with it, because an <optgroup> label
                                already says what it was repeating.

                                SCOPE IS THE CONTROLLER'S, UNCHANGED.
                                $inventoryItems is what
                                showMenuOptions() decided the actor may
                                see — every active row for an admin, only
                                their own branch's for a branch-locked
                                supervisor. This groups that collection
                                and nothing else: there is no branch here
                                whose items the actor was not already
                                given, and an unauthorised row is absent
                                from the markup rather than hidden in it,
                                because it was never in $inventoryItems
                                to begin with. addOptionIngredient()
                                re-checks the branch on the way in
                                regardless.

                                inventory.branch_id is nullable (ON
                                DELETE SET NULL), so a row can belong to
                                no branch. Those get a group of their own
                                rather than being dropped — silently
                                withholding a row the actor is entitled
                                to pick would be a worse bug than an
                                awkward heading.

                                Ordered by branch id, matching the
                                $branches lookup the badges above use,
                                with the branchless group last. Grouped
                                in PHP from a collection that is already
                                loaded, so it costs no query.
                            --}}
                            @php
                                $inventoryByBranch = $inventoryItems
                                    ->groupBy(fn ($inv) => $inv->branch_id === null ? 'unassigned' : (int) $inv->branch_id)
                                    ->sortBy(fn ($rows, $key) => $key === 'unassigned' ? PHP_INT_MAX : (int) $key, SORT_REGULAR);
                            @endphp

                            <select name="inventory_id" class="pchy-in pchy-ing-select" id="optionModalIngSelect" required>
                                <option value="">-- Select inventory item --</option>

                                @foreach($inventoryByBranch as $invBranchKey => $invRows)
                                    @php
                                        $invGroupLabel = $invBranchKey === 'unassigned'
                                            ? 'Unassigned / No Branch'
                                            : ($branches[$invBranchKey]->name ?? ('Branch #' . $invBranchKey));
                                    @endphp
                                    <optgroup label="{{ $invGroupLabel }}">
                                        @foreach($invRows as $inv)
                                            <option value="{{ $inv->id }}" data-unit="{{ $inv->unit }}">
                                                {{ $inv->item_name }} ({{ $inv->unit }})
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach

                            </select>

                            <input type="number"
                                   name="quantity_used"
                                   class="pchy-in pchy-ing-qty-in"
                                   aria-label="Quantity used"
                                   step="0.001"
                                   min="0.001"
                                   required>

                            <button type="submit" class="pchy-btn pchy-ing-add">
                                <i class="bi bi-plus-lg"></i>
                                Add
                            </button>

                        </form>

                    </div>

                </div>

            </div>
        </div>

    </div>

</div>

@endsection


@push('scripts')

<script>
(function () {

    const state = {

        option: {
            page: 1,
            size: 25,
            search: '',
            price: ''
        },

        menu: {
            page: 1,
            size: 25,
            search: '',
            category: ''
        }

    };


    let selectedItemId = null;


    function getOptionRows() {

        return Array.from(
            document.querySelectorAll('.option-row')
        );

    }


    function getMenuRows() {

        return Array.from(
            document.querySelectorAll('.menu-filter-row')
        );

    }


    function buildPages(totalPages, currentPage) {

        const pages = [];

        for (let i = 1; i <= totalPages; i++) {

            if (
                i === 1 ||
                i === totalPages ||
                Math.abs(i - currentPage) <= 1
            ) {

                pages.push(i);

            } else if (
                pages[pages.length - 1] !== '…'
            ) {

                pages.push('…');

            }

        }

        return pages;

    }


    function renderPager(
        type,
        filteredLength,
        start,
        end,
        totalPages
    ) {

        const isOption = type === 'option';

        const pager = document.getElementById(
            isOption ? 'optionPager' : 'menuPager'
        );

        const info = document.getElementById(
            isOption ? 'optionPagerInfo' : 'menuPagerInfo'
        );

        const pagesEl = document.getElementById(
            isOption ? 'optionPages' : 'menuPages'
        );

        const prev = document.getElementById(
            isOption ? 'optionPrev' : 'menuPrev'
        );

        const next = document.getElementById(
            isOption ? 'optionNext' : 'menuNext'
        );

        const s = state[type];

        pager.hidden =
            filteredLength === 0 ||
            totalPages <= 1;

        info.textContent = filteredLength
            ? `Showing ${start + 1}–${end} of ${filteredLength}`
            : 'No matching results';

        prev.disabled = s.page === 1;
        next.disabled = s.page === totalPages;

        pagesEl.innerHTML = '';


        buildPages(totalPages, s.page).forEach(n => {

            if (n === '…') {

                const gap =
                    document.createElement('span');

                gap.className = 'pchy-page-gap';
                gap.textContent = '…';

                pagesEl.appendChild(gap);

                return;

            }


            const btn =
                document.createElement('button');

            btn.type = 'button';

            btn.className =
                'pchy-page-btn' +
                (n === s.page ? ' active' : '');

            btn.textContent = n;

            btn.onclick = () => {

                s.page = n;

                render(type);

            };

            pagesEl.appendChild(btn);

        });

    }


    function render(type) {

        const isOption =
            type === 'option';

        const rows =
            isOption
                ? getOptionRows()
                : getMenuRows();

        const s =
            state[type];


        const filtered =
            rows.filter(row => {

                if (isOption) {

                    const name =
                        row.dataset.name || '';

                    const price =
                        row.dataset.price || '';


                    if (
                        s.search &&
                        !name.includes(
                            s.search.toLowerCase()
                        )
                    ) {
                        return false;
                    }


                    if (
                        s.price &&
                        price !== s.price
                    ) {
                        return false;
                    }


                    return true;

                }


                const name =
                    row.dataset.name || '';

                const category =
                    row.dataset.category || '';


                if (
                    s.search &&
                    !name.includes(
                        s.search.toLowerCase()
                    )
                ) {
                    return false;
                }


                if (
                    s.category &&
                    category !==
                    s.category.toLowerCase()
                ) {
                    return false;
                }


                return true;

            });


        const pages =
            Math.max(
                1,
                Math.ceil(
                    filtered.length /
                    s.size
                )
            );


        s.page =
            Math.min(
                Math.max(1, s.page),
                pages
            );


        const start =
            (s.page - 1) *
            s.size;


        const end =
            Math.min(
                start + s.size,
                filtered.length
            );


        rows.forEach(row => {

            row.classList.add(
                'pchy-row-hidden'
            );

        });


        const visibleGroups =
            new Set();


        filtered
            .slice(start, end)
            .forEach(row => {

                row.classList.remove(
                    'pchy-row-hidden'
                );

                if (row.dataset.branchGroup) {
                    visibleGroups.add(
                        row.dataset.branchGroup
                    );
                }

            });


        /*
         * Branch section headers are not filterable rows — their own text is
         * just a branch name, which would fail almost any search or category
         * filter — so they are excluded from `rows` above and placed here
         * instead: on screen exactly while at least one of their own items
         * is. Without this a header would sit over an empty section after a
         * search, or an item would page onto screen with its header left
         * behind on the previous page. Same rule (and same reason) as
         * applyMenuRowVisibility() on menu-items.blade.php.
         */
        if (!isOption) {

            document
                .querySelectorAll(
                    '#menuItemsList .pchy-branch-head'
                )
                .forEach(head => {

                    head.classList.toggle(
                        'pchy-row-hidden',
                        !visibleGroups.has(
                            head.dataset.branchGroup
                        )
                    );

                });

        }


        renderPager(
            type,
            filtered.length,
            start,
            end,
            pages
        );

    }


    /* ══════════════════════════════════════════════════════════════
     * POPOVERS — one manager for both disclosures on this page
     * ══════════════════════════════════════════════════════════════
     *
     * "Used in N items" in the Options table and "N Add-ons" in the Menu
     * Items picker are the same control over different lists, so they share
     * one open/close/position path rather than growing two that drift.
     *
     * position:fixed, placed here rather than in CSS. The panels live inside
     * .pchy-tblwrap{overflow-x:auto}, and an overflow container clips
     * absolutely-positioned descendants — a list hanging below its row would
     * have been sliced off at the table's edge or grown the wrapper its own
     * scrollbars. A fixed element is laid out against the viewport instead,
     * so the wrapper cannot clip it; the cost is that "under the button" has
     * to be computed, which is what place() does.
     *
     * Only ever one open. Opening a second closes the first, so the page
     * cannot end up with a drift of panels floating over a table nobody can
     * read any more.
     */
    let openPop = null;

    function placePop(pop, btn) {

        const r = btn.getBoundingClientRect();
        const gap = 6;

        // Measured after it is displayed, never from CSS: max-width is a
        // min() against the viewport and max-height clamps on short screens,
        // so the only honest size is the one it actually took.
        const w = pop.offsetWidth;
        const h = pop.offsetHeight;

        // Below the button by default, above it when that would run off the
        // bottom — and back to below if there is no room either way, since a
        // popover half off the top is worse than one the page can scroll to.
        let top = r.bottom + gap;
        if (top + h > window.innerHeight - 8 && r.top - gap - h > 8) {
            top = r.top - gap - h;
        }
        top = Math.max(8, Math.min(top, window.innerHeight - h - 8));

        // Left-aligned with the button, pulled back inside the right edge.
        let left = r.left;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));

        pop.style.top = top + 'px';
        pop.style.left = left + 'px';
    }

    function closePop() {

        if (!openPop) return;

        openPop.pop.classList.remove('show');
        openPop.btn.classList.remove('open');
        openPop.btn.setAttribute('aria-expanded', 'false');

        openPop = null;
    }

    function togglePop(pop, btn) {

        if (!pop || !btn) return;

        const wasOpen = openPop && openPop.pop === pop;

        closePop();

        if (wasOpen) return;

        pop.classList.add('show');
        btn.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');

        openPop = { pop: pop, btn: btn };

        placePop(pop, btn);
    }

    // A click anywhere that is not inside the open panel or on the button
    // that opened it closes it. Capture phase, so a handler on the thing
    // clicked cannot swallow the event first and leave the panel stranded.
    document.addEventListener('click', function (e) {

        if (!openPop) return;

        if (openPop.pop.contains(e.target) || openPop.btn.contains(e.target)) {
            return;
        }

        closePop();

    }, true);

    /*
     * A fixed panel does not travel with the row it belongs to, so anything
     * that moves that row has to move the panel with it.
     *
     * THIS REPOSITIONS RATHER THAN CLOSING, and closing was the first
     * version. Scroll events are dispatched ASYNCHRONOUSLY, at a frame
     * boundary, not synchronously with whatever caused the scroll — so a
     * click that scrolls its own button into view first (the browser does
     * this for a control near the edge of the viewport, and so does any
     * scripted scrollIntoView) opened the popover and then closed it again
     * on the very next frame, from a scroll the user never made. It looked
     * exactly like a button that does nothing, and it was found by clicking
     * one in a real browser rather than by reading this.
     *
     * Repositioning has no such race: a late scroll event simply puts the
     * panel back under its button. The panel is only closed when its button
     * has left the viewport altogether, where there is nothing left to
     * anchor to.
     *
     * Capture phase, because scroll does not bubble and the table has its
     * own scrolling wrapper.
     */
    function repositionPop() {

        if (!openPop) return;

        const r = openPop.btn.getBoundingClientRect();

        const gone =
            r.bottom < 0 ||
            r.right < 0 ||
            r.top > window.innerHeight ||
            r.left > window.innerWidth;

        if (gone) {
            closePop();
            return;
        }

        placePop(openPop.pop, openPop.btn);
    }

    window.addEventListener('scroll', repositionPop, true);
    window.addEventListener('resize', repositionPop);

    window.toggleUsedIn =
        function (optionId) {

            togglePop(
                document.getElementById('usedin-list-' + optionId),
                document.getElementById('usedin-btn-' + optionId)
            );

        };

    /*
     * The Menu Items picker's own disclosure. The button is found from the
     * panel rather than by a second id, because repaintAssignedAddons()
     * rebuilds this pair after every save and one id to keep in step is
     * better than two.
     *
     * THE EVENT IS STOPPED HERE, and that is not a detail. This button is
     * inside a <tr onclick="selectMenuItem(this)">, so without it, reading
     * what is already on an item ALSO made that item the target of the next
     * "Save Add-ons" — a click that looks like a question silently answering
     * a different one, on the one control that decides which row a save
     * overwrites. Caught in a browser, not by reading: the popover appeared
     * to simply not open, because selecting a row expands the assignment
     * banner, and that layout shift fired the scroll handler that closes
     * popovers.
     */
    window.toggleAssignedAddons =
        function (btn, event) {

            if (event) {
                event.stopPropagation();
            }

            const pop = btn.parentElement
                ? btn.parentElement.querySelector('.pchy-addon-pop')
                : null;

            togglePop(pop, btn);

        };


    /* ══════════════════════════════════════════════════════════════
     * THE EDIT OPTION / ADD-ON MODAL
     * ══════════════════════════════════════════════════════════════
     *
     * One shell, filled from the clicked row. See the markup above it for
     * why there is one and not one per option.
     *
     * modalOptionId is the single piece of state: which row the dialog is
     * currently standing in for. Every write the dialog makes — the
     * ingredient count, the branch badges, the row's own cached recipe —
     * goes through it, so there is no second copy of "which option is this"
     * to fall out of step, and closing sets it back to null so a stale id
     * cannot outlive the dialog.
     */
    let modalOptionId = null;
    let modalReturnFocus = null;
    let modalLoadedName = '';
    let modalLoadedPrice = '';

    function modalEl(id) {
        return document.getElementById(id);
    }

    window.openOptionModal =
        function (optionId, focusSection) {

            const row = document.getElementById('opt-' + optionId);
            const modal = modalEl('optionModal');

            if (!row || !modal) return;

            // A popover left open would float above the backdrop, so the
            // dialog takes the screen on its own.
            closePop();

            modalOptionId = String(optionId);

            // Where focus came from, so closing can put it back rather than
            // dropping the caller at the top of the document.
            modalReturnFocus = document.activeElement;

            modalLoadedName = row.dataset.optionName || '';
            modalLoadedPrice = row.dataset.optionPrice || '';

            modalEl('optionModalName').textContent = modalLoadedName;

            const form = modalEl('optionModalForm');
            form.action = row.dataset.updateUrl || '';

            const nameInput = modalEl('optionModalNameInput');
            const priceInput = modalEl('optionModalPriceInput');

            nameInput.value = modalLoadedName;
            priceInput.value = modalLoadedPrice;

            const ingForm = modalEl('optionModalIngForm');
            ingForm.dataset.url = row.dataset.ingredientAddUrl || '';
            ingForm.dataset.option = modalOptionId;

            // Nothing carries over from the last option: the entry fields are
            // blanked, the error box is cleared, and the list is rebuilt from
            // scratch rather than added to.
            modalEl('optionModalIngSelect').value = '';
            ingForm.querySelector('input[name="quantity_used"]').value = '';
            optIngClearError();

            optIngRenderList(readRowIngredients(row));

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');

            // The page behind a modal must not scroll under it.
            document.body.style.overflow = 'hidden';

            // The pencil opens on the name, the basket on the recipe — the
            // two buttons still mean two different things even though they
            // open the same dialog.
            if (focusSection === 'recipe') {
                modalEl('optionModalIngSelect').focus();
            } else {
                nameInput.focus();
                nameInput.select();
            }

        };

    window.closeOptionModal =
        function () {

            const modal = modalEl('optionModal');

            if (!modal || !modal.classList.contains('show')) return;

            /*
             * The name/price form is a real submit, so closing without
             * saving throws the typing away. That was true of the inline
             * panel too, but a dialog with a backdrop is far easier to
             * dismiss by accident — a stray click outside it did nothing
             * before and closes it now — so the discard is asked for rather
             * than assumed. Only when something actually differs from what
             * was loaded: an untouched dialog closes on the first click.
             */
            const nameInput = modalEl('optionModalNameInput');
            const priceInput = modalEl('optionModalPriceInput');

            const dirty =
                nameInput.value !== modalLoadedName ||
                priceInput.value !== modalLoadedPrice;

            if (dirty && !confirm('Close without saving? Your changes to the name or price will be lost.')) {
                return;
            }

            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            // Emptied on the way out, not on the way in: nothing of the
            // option just closed is left on screen for the split second
            // before the next open fills it.
            modalEl('optionModalIngList').innerHTML = '';
            modalEl('optionModalIngList').style.display = 'none';
            modalEl('optionModalIngEmpty').style.display = '';
            optIngClearError();

            modalOptionId = null;
            modalLoadedName = '';
            modalLoadedPrice = '';

            if (modalReturnFocus && typeof modalReturnFocus.focus === 'function') {
                modalReturnFocus.focus();
            }
            modalReturnFocus = null;

        };

    // The X and the backdrop both carry data-modal-dismiss, so there is one
    // rule for "this closes the dialog" rather than a handler per element.
    document.addEventListener('click', function (e) {

        const dismiss = e.target.closest
            ? e.target.closest('[data-modal-dismiss]')
            : null;

        if (dismiss) {
            closeOptionModal();
        }

    });

    document.addEventListener('keydown', function (e) {

        if (e.key !== 'Escape') return;

        // A popover over the dialog closes first, so one Escape does not
        // take both away.
        if (openPop) {
            closePop();
            return;
        }

        closeOptionModal();

    });


    /* ══════════════════════════════════════════════════════════════
     * RECIPE INGREDIENTS, inside the modal
     * ══════════════════════════════════════════════════════════════
     *
     * Still add/remove over fetch(), so the dialog never closes under an
     * admin adding several ingredients in a row — it closes when they close
     * it. What changed is where the rows live: there is one list, in the
     * modal, belonging to whichever option is open, instead of one hidden
     * list per option in the table.
     *
     * The error box is one box now, so these no longer take an option id —
     * there is only ever one option open to report about.
     */
    function optIngError(message) {
        const box = document.getElementById('optionModalIngError');
        if (!box) return;
        box.textContent = message;
        box.style.display = 'block';
    }

    function optIngClearError() {
        const box = document.getElementById('optionModalIngError');
        if (box) box.style.display = 'none';
    }

    /*
     * An option's saved recipe, as the row carries it.
     *
     * The row is the only copy: it is what the modal is filled from on open
     * and what the add/remove paths write back to, so reopening an option
     * in the same page load shows what was actually done to it rather than
     * what the server rendered before it was touched.
     *
     * Wrapped in try/catch because this is parsed from an attribute. A row
     * with unreadable data degrades to "no ingredients" and an add still
     * works; it does not throw halfway through opening a dialog and leave a
     * half-filled one on screen.
     */
    function readRowIngredients(row) {
        try {
            const parsed = JSON.parse(row.dataset.ingredients || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function writeRowIngredients(row, rows) {
        row.dataset.ingredients = JSON.stringify(rows);
    }

    /*
     * ONE BUILDER for a saved row and for a row that has just been added.
     *
     * These were two shapes before — Blade rendered the saved ones, the
     * script built the new ones — which is two descriptions of one row that
     * can quietly stop matching. The keys here are the keys
     * addOptionIngredient() returns, and the Blade above puts the same keys
     * on the row, so both paths come through here.
     *
     * Names and units are admin-entered free text and come back from the
     * server, so every one of them is set with textContent and the delete
     * URL is set as a property. Nothing is concatenated into innerHTML —
     * the rule Phase 3b F11 set for the recipe editor on the menu items
     * page, applied to the same kind of editor here.
     *
     * can_remove is the SERVER's answer (see the Blade that builds this
     * payload). Undefined means "a row this actor just created", which they
     * are by definition allowed to remove. The lock is cosmetic either way:
     * deleteOptionIngredient() re-checks the branch on every call.
     */
    function optIngRow(ing) {

        const row = document.createElement('div');
        row.className = 'pchy-ing-row';
        row.dataset.ingredientId = ing.id;

        if (ing.branch_id !== null && ing.branch_id !== undefined) {
            row.dataset.branchId = ing.branch_id;
        }

        const name = document.createElement('span');
        name.className = 'pchy-ing-name';
        name.textContent = ing.name;
        row.appendChild(name);

        if (ing.branch_name) {
            const branch = document.createElement('span');
            branch.className = 'pchy-ing-branch';
            branch.textContent = ing.branch_name;
            row.appendChild(branch);
        }

        const qty = document.createElement('span');
        qty.className = 'pchy-ing-qty';
        qty.textContent = ing.quantity_used + (ing.unit ? ' ' + ing.unit : '');
        row.appendChild(qty);

        if (ing.can_remove === false) {

            const lock = document.createElement('span');
            lock.className = 'pchy-ing-lock';
            lock.title = "This link deducts from another branch's stock. Only that branch's supervisor or an admin can remove it.";
            lock.innerHTML = '<i class="bi bi-lock-fill"></i>';
            row.appendChild(lock);

        } else {

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'pchy-ing-del opt-ing-delete-btn';
            del.title = 'Remove ingredient';
            del.innerHTML = '<i class="bi bi-x-lg"></i>';
            del.dataset.url = ing.delete_url;
            row.appendChild(del);

        }

        return row;
    }

    // Rebuild the modal's list from scratch. Never appends to what is there,
    // so opening B after A cannot show A's rows underneath B's.
    function optIngRenderList(rows) {

        const list = document.getElementById('optionModalIngList');
        const empty = document.getElementById('optionModalIngEmpty');

        if (!list || !empty) return;

        list.innerHTML = '';

        rows.forEach(function (ing) {
            list.appendChild(optIngRow(ing));
        });

        list.style.display = rows.length ? '' : 'none';
        empty.style.display = rows.length ? 'none' : '';
    }

    // The option row's basket count, written by looking the row up by id.
    // This is the shape the remove path already used; the add path used to
    // walk up from its own form with closest('.pchy-option'), which stopped
    // being possible the moment that form moved into the modal and stopped
    // being inside any row at all.
    function optIngSetCount(optionId, n) {
        const row = document.getElementById('opt-' + optionId);
        const countEl = row ? row.querySelector('.pchy-ing-count') : null;
        if (countEl) countEl.textContent = String(Math.max(0, n));
    }

    // Re-derive one option's per-branch Mapped/Unmapped badges (and the amber
    // "add inventory first" hints) from the ingredient rows currently on
    // screen. Called after every add and remove, so the badges never wait for a
    // page reload. "Mapped" means what MenuOption::isMappedForBranch() means on
    // the server — at least one link whose inventory belongs to that branch —
    // and a row only exists here once the server has confirmed it (added) or
    // been told to drop it (removed), so the two cannot disagree. Recomputing
    // every badge from the rows, rather than flipping just the one that
    // changed, keeps this idempotent.
    function optSyncBranchBadges(optionId) {
        const status = document.getElementById('opt-branch-status-' + optionId);
        // The rows are in the modal now, and the only way a link is added or
        // removed is through the modal that is open, so "the rows currently
        // on screen" and "this option's rows" are still the same set. The
        // badges themselves stay in the option's row in the table, where
        // they are read without opening anything.
        const list = document.getElementById('optionModalIngList');
        if (!status || !list) return;

        const linked = {};
        list.querySelectorAll('.pchy-ing-row[data-branch-id]').forEach(function (r) {
            linked[r.dataset.branchId] = true;
        });

        /*
         * One pill, three states — the same table the Blade above builds a
         * rendered badge from, so an add/remove lands on exactly the markup
         * a reload would have produced.
         *
         * "empty" is decided by data-empty-inventory, NOT by re-reading the
         * ingredient rows: a branch can be mapped through an ARCHIVED
         * inventory row while having zero ACTIVE inventory, so removing the
         * last link there has to fall to "Add Inventory First" and not to
         * "Needs Inventory". Only the attribute still knows that, which is
         * why it is on every badge rather than only on the empty ones.
         */
        const BRANCH_STATES = {
            ok:    { label: 'Ready',               icon: 'bi-check-circle-fill',        title: 'titleOk' },
            off:   { label: 'Needs Inventory',     icon: 'bi-exclamation-triangle-fill', title: 'titleOff' },
            empty: { label: 'Add Inventory First', icon: 'bi-info-circle-fill',          title: 'titleEmpty' },
        };

        status.querySelectorAll('.pchy-branch-badge').forEach(function (badge) {
            const mapped = !!linked[badge.dataset.branchId];
            const isEmpty = badge.dataset.emptyInventory === '1';
            const state = mapped ? 'ok' : (isEmpty ? 'empty' : 'off');
            const spec = BRANCH_STATES[state];

            badge.classList.toggle('pchy-branch-ok', state === 'ok');
            badge.classList.toggle('pchy-branch-off', state === 'off');
            badge.classList.toggle('pchy-branch-empty', state === 'empty');

            badge.title = status.dataset[spec.title] || '';

            const icon = badge.querySelector('i');
            if (icon) icon.className = 'bi ' + spec.icon;

            const text = badge.querySelector('.pchy-branch-badge-text');
            if (text) text.textContent = badge.dataset.branchName + ' ' + spec.label;
        });
    }

    document.addEventListener('submit', function (e) {
        if (!e.target.classList || !e.target.classList.contains('opt-ing-add-form')) return;
        e.preventDefault();

        const form = e.target;

        // The form's data-option is written by openOptionModal() from the
        // row it opened, so it names the option this submit belongs to even
        // if something else on the page has since been clicked. Refused
        // outright when there is no open option rather than posted to a URL
        // left over from last time.
        const optionId = form.dataset.option;
        const row = optionId ? document.getElementById('opt-' + optionId) : null;

        if (!optionId || !row || !form.dataset.url) {
            optIngError('Could not tell which add-on this is for. Please close this window and try again.');
            return;
        }

        optIngClearError();

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        fetch(form.dataset.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
            },
            body: new FormData(form),
        })
            .then(res => res.json().then(data => ({ status: res.status, data })))
            .then(({ status, data }) => {
                if (submitBtn) submitBtn.disabled = false;

                if (status >= 400 || !data.success) {
                    const message = data.message
                        || (data.errors && Object.values(data.errors)[0][0])
                        || 'Could not add ingredient.';
                    optIngError(message);
                    return;
                }

                /*
                 * The row's cached recipe is the source of truth for what
                 * the list shows, so the new link goes in there first and
                 * the list is redrawn from it. Appending to the list and
                 * separately remembering the link would be two records of
                 * one fact, and reopening the option would have shown
                 * whichever of them had not been updated.
                 *
                 * A link this actor just created is one they may remove, so
                 * can_remove is not asked for in the response — optIngRow()
                 * treats an absent flag as permission, and the endpoint
                 * re-checks the branch on the delete regardless.
                 */
                const rows = readRowIngredients(row);
                rows.push(data.ingredient);
                writeRowIngredients(row, rows);

                optIngRenderList(rows);
                optSyncBranchBadges(optionId);
                optIngSetCount(optionId, rows.length);

                form.querySelector('.pchy-ing-select').value = '';
                form.querySelector('input[name="quantity_used"]').value = '';
            })
            .catch(() => {
                if (submitBtn) submitBtn.disabled = false;
                optIngError('Network error — could not add ingredient.');
            });
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest ? e.target.closest('.opt-ing-delete-btn') : null;
        if (!btn) return;
        if (!confirm('Remove this ingredient?')) return;

        // Which option, from the dialog's own state rather than by reading
        // an id back out of a panel's element id. There is one open option
        // at a time and modalOptionId is the one place that records it.
        const optionId = modalOptionId;
        const row = optionId ? document.getElementById('opt-' + optionId) : null;

        if (!optionId || !row) return;

        const ingredientRow = btn.closest('.pchy-ing-row');
        const ingredientId = ingredientRow ? ingredientRow.dataset.ingredientId : null;

        // The one token on the page's modal form, not one of N copies.
        const tokenInput = document.querySelector('#optionModalIngForm input[name="_token"]');

        const fd = new FormData();
        fd.append('_method', 'DELETE');

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': tokenInput ? tokenInput.value : '',
            },
            body: fd,
        })
            .then(res => res.json().then(data => ({ status: res.status, data })))
            .then(({ status, data }) => {

                /*
                 * A refusal is reported now instead of being swallowed.
                 * deleteOptionIngredient() answers a branch-locked
                 * supervisor who reaches another branch's link with a 422
                 * and a reason; the old handler returned silently on
                 * !data.success, so that refusal looked exactly like a
                 * click that had not registered.
                 */
                if (status >= 400 || !data.success) {
                    optIngError(data.message || 'Could not remove that ingredient.');
                    return;
                }

                // Dropped from the row's cached recipe first, then redrawn
                // from it — one record of what this option's links are.
                const rows = readRowIngredients(row)
                    .filter(function (ing) { return String(ing.id) !== String(ingredientId); });

                writeRowIngredients(row, rows);

                optIngRenderList(rows);
                optSyncBranchBadges(optionId);
                optIngSetCount(optionId, rows.length);
            })
            .catch(() => {
                optIngError('Network error — could not remove that ingredient.');
            });
    });


    window.selectMenuItem =
        function (el) {

            // The row IS the selectable element now. It used to be a
            // wrapper div around an inner .pchy-menu-item card, and both
            // carried .active; a <tr> has no such inner box, so the class
            // lives in one place and .pchy-menu-tbl tr.menu-item-row.active
            // styles its cells.
            document
                .querySelectorAll(
                    '.menu-item-row'
                )
                .forEach(row => {

                    row.classList.remove(
                        'active'
                    );

                });


            el.classList.add('active');


            selectedItemId =
                el.dataset.id;


            const assignedOptions =
                JSON.parse(
                    el.dataset.options || '[]'
                );


            const itemNameEl =
                el.querySelector(
                    '.pchy-menu-item-name'
                );


            /*
             * "Assigning Add-ons to: <name> (<branch>)".
             *
             * The name alone is ambiguous for exactly the reason the row
             * carries a branch chip: two live menu items are called "coke"
             * and two "roasted chicken", so this line could otherwise confirm
             * an assignment to either one.
             *
             * Two fixed spans now, each written with textContent — the older
             * shape set the name with textContent and then APPENDED a chip
             * element, which only worked because the append came second, and
             * would have silently dropped the branch the moment anything
             * touched the name afterwards. Both are ordinary text, so neither
             * can carry markup from a menu item's name.
             *
             * data-branch-name is read rather than the row's own chip so the
             * banner never inherits that chip's layout class. When a row
             * somehow has no branch name, the whole parenthetical is hidden
             * rather than rendered as an empty "()".
             */
            const selectedNameEl =
                document.getElementById(
                    'selectedItemName'
                );


            selectedNameEl.textContent =
                itemNameEl
                    ? itemNameEl.textContent.trim()
                    : 'Item';


            const selectedBranchName =
                el.dataset.branchName || '';


            const selectedBranchEl =
                document.getElementById(
                    'selectedItemBranch'
                );


            const branchWrap =
                document.querySelector(
                    '.pchy-assign-branch-wrap'
                );


            if (selectedBranchEl) {

                selectedBranchEl.textContent =
                    selectedBranchName;

            }


            if (branchWrap) {

                branchWrap.hidden =
                    selectedBranchName === '';

            }


            document.getElementById(
                'assignSection'
            ).classList.add('show');


            document
                .querySelectorAll(
                    '.option-check'
                )
                .forEach(cb => {

                    cb.checked =
                        assignedOptions.includes(
                            parseInt(
                                cb.dataset.id
                            )
                        );

                });


            refreshAssignCount();

        };


    /*
     * "N ticked", in the banner, beside the save button.
     *
     * Counts EVERY .option-check on the page, not the visible ones: the
     * options list is paged and filtered client-side while the ticks
     * survive both (see OptionAssignmentPaginationSafetyTest — a box ticked
     * on page 1 is still checked in the DOM, and still submitted, after
     * paging away), so counting only what is on screen would have
     * contradicted what the save actually sends.
     */
    window.refreshAssignCount =
        function () {

            const el =
                document.getElementById(
                    'assignCount'
                );


            if (!el) {
                return;
            }


            const n =
                document.querySelectorAll(
                    '.option-check:checked'
                ).length;


            el.textContent =
                n + ' ticked';

        };


    document.addEventListener(
        'change',
        function (e) {

            if (
                e.target
                && e.target.classList
                && e.target.classList.contains('option-check')
            ) {

                refreshAssignCount();

            }

        }
    );


    window.saveAssignment =
        function () {

            if (!selectedItemId) {
                return;
            }


            const checkedOptions = [];


            document
                .querySelectorAll(
                    '.option-check:checked'
                )
                .forEach(cb => {

                    checkedOptions.push(
                        cb.dataset.id
                    );

                });


            fetch(
                '{{ url('admin/menu-options/assign') }}/' +
                selectedItemId,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'X-CSRF-TOKEN':
                            '{{ csrf_token() }}'
                    },

                    body: JSON.stringify({
                        option_ids:
                            checkedOptions
                    })

                }
            )
            .then(res => res.json())
            .then(data => {

                if (!data.success) {

                    /*
                     * This used to be a bare `return` — the save failed and
                     * the admin was told nothing at all, so a rejected save
                     * looked identical to one that simply had not finished.
                     * Now it reports the server's reason the same way the
                     * success path reports success: on the button itself,
                     * flashed in the failure colour, then restored.
                     */
                    showAssignError(
                        data.message ||
                        'Could not save the options. Please refresh and try again.'
                    );

                    return;
                }


                const itemRow =
                    document.querySelector(
                        '.menu-item-row[data-id="' +
                        selectedItemId +
                        '"]'
                    );


                if (itemRow) {

                    const countEl =
                        itemRow.querySelector(
                            '.pchy-menu-item-count'
                        );


                    if (countEl) {

                        countEl.textContent =
                            checkedOptions.length +
                            ' option(s)';

                    }


                    repaintAssignedAddons(
                        itemRow,
                        checkedOptions
                    );


                    itemRow.dataset.options =
                        JSON.stringify(
                            checkedOptions.map(Number)
                        );

                }


                const btn =
                    document.querySelector(
                        '#assignSection .pchy-assign-save'
                    );


                btn.innerHTML =
                    '<i class="bi bi-check2"></i> Saved!';

                btn.style.background =
                    '#4CAF50';


                setTimeout(() => {

                    btn.innerHTML =
                        '<i class="bi bi-check-circle-fill"></i> Save Add-ons';

                    btn.style.background = '';

                }, 2000);

            })
            .catch(err => {

                console.error(err);

                // A network/parse failure left the admin with no feedback at
                // all either — same silent-failure problem as a rejected
                // save, so it gets the same visible treatment.
                showAssignError(
                    'Could not reach the server. Your options were not saved.'
                );

            });

        };


    /**
     * Redraw one menu-item row's "Assigned Add-ons" cell after a save.
     *
     * The names and prices are read back out of the OPTION ROWS on the left,
     * which are the same rows whose checkboxes produced this list — so the
     * cell ends up saying exactly what was ticked, and there is no second
     * copy of an option's name or price anywhere to drift out of step with
     * the first.
     *
     * Built with createElement + textContent throughout, never by
     * concatenating a name into innerHTML: a menu option's name is
     * admin-entered free text, and this is the one place on the page that
     * moves it from one element into another after load.
     *
     * Alphabetical, matching the server's own ->sortBy('name') for this
     * cell: the options table is ordered by name, so walking the ticked
     * boxes in DOM order already yields that order, and a saved row then
     * reads the same way it will after the next reload.
     *
     * The wording of both states — "Name (+P0.00)" / "Name (Free)" and the
     * grey "No add-ons assigned" — is the Blade's wording, because the price
     * text is lifted verbatim from the option row's own .pchy-price or
     * .pchy-free, which is where the Blade rendered it.
     */
    function repaintAssignedAddons(itemRow, checkedOptions) {

        const cell =
            itemRow.querySelector(
                'td[data-l="Assigned Add-ons"]'
            );


        if (!cell) {
            return;
        }


        // If the popover being thrown away here is the one that is open, it
        // has to be forgotten as well as removed: openPop would otherwise
        // hold a detached element that no click can ever be "outside" of in
        // a way that closes it, and the next disclosure would not open.
        if (openPop && cell.contains(openPop.pop)) {
            closePop();
        }

        cell.textContent = '';


        /*
         * One chip builder for both shapes below, so the single-add-on case
         * and the rows inside the popover cannot come out looking different
         * from each other or from what Blade rendered.
         */
        function addonChip(id) {

            const optionRow =
                document.getElementById('opt-' + id);


            if (!optionRow) {
                return null;
            }


            const nameEl =
                optionRow.querySelector('.pchy-option-name');

            const priceEl =
                optionRow.querySelector('.pchy-price')
                || optionRow.querySelector('.pchy-free');


            const chip =
                document.createElement('span');

            chip.className = 'pchy-addon-chip';

            chip.textContent =
                (nameEl ? nameEl.textContent.trim() : '') + ' ';


            const price =
                document.createElement('span');

            price.className = 'pchy-addon-price';

            price.textContent =
                '(' + (priceEl ? priceEl.textContent.trim() : '') + ')';


            chip.appendChild(price);

            return chip;
        }


        const chips =
            checkedOptions
                .map(addonChip)
                .filter(Boolean);


        /*
         * Redrawn into the SAME three shapes the Blade renders, because a
         * saved row has to read exactly the way it will after the next
         * reload: nothing, one chip, or a count button beside its popover.
         *
         * The count comes from the chips actually built, not from
         * checkedOptions.length: an id whose option row has gone from the
         * page produces no chip, and a button promising four add-ons over a
         * list of three would be the row disagreeing with itself.
         */
        if (!chips.length) {

            const empty =
                document.createElement('span');

            empty.className = 'pchy-noaddons';

            empty.textContent =
                'No add-ons assigned';

            cell.appendChild(empty);

            return;
        }


        if (chips.length === 1) {

            const list =
                document.createElement('span');

            list.className = 'pchy-addon-list';

            list.appendChild(chips[0]);

            cell.appendChild(list);

            return;
        }


        const wrap =
            document.createElement('span');

        wrap.className = 'pchy-addon-wrap';


        const btn =
            document.createElement('button');

        btn.type = 'button';
        btn.className = 'pchy-addon-more';
        btn.setAttribute('aria-expanded', 'false');
        btn.title = 'Add-ons assigned to this item';

        btn.textContent = chips.length + ' Add-ons ';

        const caret =
            document.createElement('i');

        caret.className = 'bi bi-chevron-down pchy-usedin-caret';

        btn.appendChild(caret);

        // Same stopPropagation as the rendered button's: a repainted cell
        // must not be the one place where reading an item's add-ons re-aims
        // the next save at it.
        btn.addEventListener('click', function (e) {
            toggleAssignedAddons(btn, e);
        });


        const pop =
            document.createElement('span');

        pop.className = 'pchy-pop pchy-addon-pop';
        pop.setAttribute('role', 'group');
        pop.setAttribute('aria-label', 'Add-ons assigned to this item');

        chips.forEach(function (chip) {
            pop.appendChild(chip);
        });


        wrap.appendChild(btn);
        wrap.appendChild(pop);

        cell.appendChild(wrap);

    }


    /**
     * Report a failed save on the Save Add-ons button itself.
     *
     * Mirrors the success path's own feedback (green "Saved!", restored after
     * a moment) so success and failure are reported in the same place and the
     * same way — the admin is already looking at that button, having just
     * clicked it. Held longer than the success flash because a failure asks
     * the admin to actually do something about it.
     */
    function showAssignError(message) {

        const btn =
            document.querySelector(
                '#assignSection .pchy-assign-save'
            );

        if (!btn) {
            return;
        }

        const original =
            '<i class="bi bi-check-circle-fill"></i> Save Add-ons';

        btn.innerHTML =
            '<i class="bi bi-exclamation-triangle"></i> ' +
            message;

        btn.style.background = '#C0392B';

        setTimeout(() => {

            btn.innerHTML = original;

            btn.style.background = '';

        }, 6000);

    }


    document
        .getElementById('optionSearch')
        ?.addEventListener(
            'input',
            e => {

                state.option.search =
                    e.target.value.trim();

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('optionPriceFilter')
        ?.addEventListener(
            'change',
            e => {

                state.option.price =
                    e.target.value;

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('optionPageSize')
        ?.addEventListener(
            'change',
            e => {

                state.option.size =
                    Number(e.target.value);

                state.option.page = 1;

                render('option');

            }
        );


    document
        .getElementById('menuSearch')
        ?.addEventListener(
            'input',
            e => {

                state.menu.search =
                    e.target.value.trim();

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('menuCategoryFilter')
        ?.addEventListener(
            'change',
            e => {

                state.menu.category =
                    e.target.value;

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('menuPageSize')
        ?.addEventListener(
            'change',
            e => {

                state.menu.size =
                    Number(e.target.value);

                state.menu.page = 1;

                render('menu');

            }
        );


    document
        .getElementById('optionPrev')
        ?.addEventListener(
            'click',
            () => {

                if (state.option.page > 1) {

                    state.option.page--;

                    render('option');

                }

            }
        );


    document
        .getElementById('optionNext')
        ?.addEventListener(
            'click',
            () => {

                state.option.page++;

                render('option');

            }
        );


    document
        .getElementById('menuPrev')
        ?.addEventListener(
            'click',
            () => {

                if (state.menu.page > 1) {

                    state.menu.page--;

                    render('menu');

                }

            }
        );


    document
        .getElementById('menuNext')
        ?.addEventListener(
            'click',
            () => {

                state.menu.page++;

                render('menu');

            }
        );


    render('option');
    render('menu');

})();
</script>

@endpush
