{{--
    The recipe-quantity helpers shared by the Menu Items recipe editor (menu
    item, sizes) and the Add-ons recipe dialog (QA Nov 2).

    A recipe line is how much ONE order uses. QA typed STOCK amounts into it
    (Egg 30 pc, Rice 60 kg), so the menu showed Low / Out of Stock while the
    shelf looked full. Three helpers, all display-only:

      pomidaRecipeQuantityConfirm(name, unit, qty, stock)
          Before a line is saved: true when it looks sensible, or when the
          owner confirms the warning (more than the stock, or unusually large
          for its unit). The table and sentences are
          App\Support\RecipeQuantityLimits', handed over here, not copied.
      pomidaCanMake(stock, qty)
          Whole orders the stock covers: floor(stock / qty), the same rule as
          MenuItem::servingsCoveredBy(). Null when it cannot be worked out.
      pomidaRefreshCanMake(tbody)
          Rewrites the "Can make" cells of a recipe table from the rows'
          data-stock / data-qty and tags the HIGH line with the fewest orders
          "Runs out first" (a LOW line never does).

    Nothing here changes stock logic, the low-stock threshold, recipes or
    stock: the owner can confirm any warning and keep the line.
--}}
<style>
    .recipe-limiter { background: #fff8e1; }
    .recipe-limit-tag { display: block; width: fit-content; margin-top: 0.15rem; padding: 0.1rem 0.4rem; border-radius: 6px; background: #fde68a; color: #6B4E00; font-size: 0.66rem; font-weight: 700; white-space: nowrap; }
    .recipe-canmake { white-space: nowrap; font-weight: 600; }
    /* "Runs out first" is longer than the old tag: let it wrap on a phone rather than widen the column. */
    @media (max-width: 600px) { .recipe-limit-tag { white-space: normal; max-width: 4.6rem; } }
</style>
<script>
    window.POMIDA_RECIPE_LIMITS = @json(\App\Support\RecipeQuantityLimits::limits());
    window.POMIDA_RECIPE_TEXT = @json(\App\Support\RecipeQuantityLimits::templates());

    window.pomidaFormatQty = function (n) {
        return Number(n).toLocaleString('en-US', { maximumFractionDigits: 3 });
    };

    // The PHP twin is RecipeQuantityLimits::warningFor(); same order, same words.
    window.pomidaRecipeQuantityWarning = function (name, unit, qty, stock) {
        qty = parseFloat(qty);
        stock = parseFloat(stock);
        if (isNaN(qty)) return null;
        unit = String(unit || '').trim();
        var limit = window.POMIDA_RECIPE_LIMITS[unit.toLowerCase()];
        var T = window.POMIDA_RECIPE_TEXT;
        var fill = function (t) {
            return t.split('{qty}').join(window.pomidaFormatQty(qty))
                .split('{unit}').join(unit)
                .split('{name}').join(name)
                .split('{stock}').join(isNaN(stock) ? '0' : window.pomidaFormatQty(stock))
                .split('{limit}').join(limit === undefined ? '' : window.pomidaFormatQty(limit));
        };
        if (!isNaN(stock) && qty > stock) return fill(T.overStock + ' ' + T.advice);
        if (limit !== undefined && qty > limit) return fill(T.large + ' ' + T.advice);
        return null;
    };

    window.pomidaRecipeQuantityConfirm = function (name, unit, qty, stock) {
        var message = window.pomidaRecipeQuantityWarning(name, unit, qty, stock);
        if (!message) return true;
        return window.confirm(message + '\n\nPress OK to keep it as typed, or Cancel to change the amount.');
    };

    window.pomidaCanMake = function (stock, qty) {
        stock = parseFloat(stock);
        qty = parseFloat(qty);
        if (isNaN(stock) || isNaN(qty) || qty <= 0) return null;
        return stock <= 0 ? 0 : Math.floor(stock / qty);
    };

    window.pomidaRefreshCanMake = function (tbody) {
        if (!tbody) return;
        var rows = [].slice.call(tbody.querySelectorAll('tr'));
        var best = null;
        var bestRow = null;
        var counts = rows.map(function (row) {
            var qty = row.dataset.qty;
            var hidden = row.querySelector('input[name$="[quantity_used]"]');
            if (hidden) qty = hidden.value;
            var n = window.pomidaCanMake(row.dataset.stock, qty);
            var pill = row.querySelector('.recipe-priority-pill');
            var isLow = pill && pill.dataset.priority === 'low';
            if (n !== null && !isLow && (best === null || n < best)) { best = n; bestRow = row; }
            return n;
        });
        rows.forEach(function (row, i) {
            var cell = row.querySelector('.recipe-canmake');
            if (!cell) return;
            var n = counts[i];
            cell.textContent = '';
            var span = document.createElement('span');
            span.className = 'recipe-canmake-n';
            span.textContent = n === null ? '—' : n + (n === 1 ? ' order' : ' orders');
            cell.appendChild(span);
            var limits = row === bestRow;
            row.classList.toggle('recipe-limiter', limits);
            if (limits) {
                var tag = document.createElement('span');
                tag.className = 'recipe-limit-tag';
                tag.textContent = 'Runs out first';
                tag.title = window.POMIDA_RECIPE_TEXT.canMakeHint;
                tag.setAttribute('aria-label', 'Runs out first. ' + window.POMIDA_RECIPE_TEXT.canMakeHint);
                cell.appendChild(document.createTextNode(' '));
                cell.appendChild(tag);
            }
        });
    };
</script>
