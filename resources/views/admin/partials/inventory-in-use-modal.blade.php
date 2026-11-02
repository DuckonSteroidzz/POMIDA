{{--
    The "You cannot delete this item" pop-up, shared by Inventory (Delete) and
    the Archive (Permanently Delete).

    Opened two ways, same words either way:
      - in the browser, before anything is sent: showInventoryInUse(name, names)
        from the usage map the page embeds;
      - after a refused submit: the controller flashes `inventory_in_use`
        ({name, message}) and this partial opens itself on load. The server is
        the real gate (App\Services\InventoryUsage); this is only its voice.

    Plain CSS and inline script, no Tailwind — the admin panel does not use it.
--}}
<style>
    .iu-modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(59,42,36,.5);
                align-items: center; justify-content: center; padding: 1rem; }
    .iu-modal.is-open { display: flex; }
    .iu-box { background: #fff; border-radius: 18px; padding: 1.4rem; width: 100%; max-width: 440px;
              box-shadow: 0 18px 50px rgba(59,42,36,.3); text-align: center; max-height: 90vh; overflow-y: auto; }
    .iu-ico { width: 52px; height: 52px; border-radius: 50%; background: #FBE7E4; color: #C0392B;
              display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: .6rem; }
    .iu-title { font-family: 'Fraunces', Georgia, serif; font-size: 1.1rem; font-weight: 700; color: #3B2A24; margin: 0 0 .5rem; }
    .iu-text { font-size: .86rem; color: #5B4740; line-height: 1.5; margin: 0 0 1.1rem; overflow-wrap: anywhere; text-align: left; }
    .iu-ok { background: #F4845F; color: #fff; border: none; border-radius: 10px; padding: .6rem 1.6rem;
             font-weight: 700; cursor: pointer; min-width: 120px; }
    .iu-ok:hover { background: #E8714B; }
</style>

<div id="inUseModal" class="iu-modal" role="alertdialog" aria-modal="true" aria-labelledby="inUseTitle" aria-describedby="inUseText">
    <div class="iu-box">
        <div class="iu-ico"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <h3 class="iu-title" id="inUseTitle">Cannot delete</h3>
        <p class="iu-text" id="inUseText"></p>
        <button type="button" class="iu-ok" onclick="closeInventoryInUse()">OK</button>
    </div>
</div>

<script>
    function inventoryInUseMessage(names) {
        return 'You cannot delete this item because it is currently being used by the following: '
            + names.join(', ')
            + '. Please remove it from these items first before attempting to delete it.';
    }

    function showInventoryInUse(itemName, names) {
        document.getElementById('inUseTitle').textContent = itemName ? 'Cannot delete ' + itemName : 'Cannot delete';
        document.getElementById('inUseText').textContent = inventoryInUseMessage(names);
        document.getElementById('inUseModal').classList.add('is-open');
    }

    function closeInventoryInUse() {
        document.getElementById('inUseModal').classList.remove('is-open');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeInventoryInUse();
    });

    @if(session('inventory_in_use'))
    document.addEventListener('DOMContentLoaded', function () {
        var flash = @json(session('inventory_in_use'));
        document.getElementById('inUseTitle').textContent = flash.name ? 'Cannot delete ' + flash.name : 'Cannot delete';
        document.getElementById('inUseText').textContent = flash.message;
        document.getElementById('inUseModal').classList.add('is-open');
    });
    @endif
</script>
