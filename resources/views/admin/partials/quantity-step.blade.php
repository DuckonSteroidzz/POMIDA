{{--
    Count units take whole numbers, measured units up to 3 decimals. The list
    of count units is App\Models\Inventory::COUNT_UNITS, handed to the browser
    here so the page never keeps a second copy. The server enforces the same
    rule (Inventory::quantityError()); this only lets the input's own step
    help: step 1 for a count, 0.001 for everything else.

    Use: pomidaQuantityStep('pc') -> '1';  pomidaApplyQuantityStep(input, unit)
--}}
<script>
    window.POMIDA_COUNT_UNITS = @json(\App\Models\Inventory::COUNT_UNITS);

    window.pomidaIsCountUnit = function (unit) {
        return window.POMIDA_COUNT_UNITS.indexOf(String(unit || '').trim().toLowerCase()) !== -1;
    };

    window.pomidaQuantityStep = function (unit) {
        return window.pomidaIsCountUnit(unit) ? '1' : '0.001';
    };

    // Sets step (and the matching minimum for a "must be positive" input) on
    // one or more inputs; a count minimum is 1, a measured minimum 0.001.
    window.pomidaApplyQuantityStep = function (inputs, unit, positiveOnly) {
        [].concat(inputs).forEach(function (input) {
            if (!input) { return; }
            var count = window.pomidaIsCountUnit(unit);
            input.step = count ? '1' : '0.001';
            if (positiveOnly) { input.min = count ? '1' : '0.001'; }
        });
    };
</script>
