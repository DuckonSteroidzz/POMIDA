{{--
    Click/tap feedback sound — customer-facing pages only.

    Not the shared partials/typography-stability.blade.php: that one is also
    included on admin/staff pages, and this sound is customer-only, so it
    lives in its own partial and is included alongside it on every full
    customer document instead.

    No audio asset ships with the project (none existed before this), so the
    "click" is synthesised with the Web Audio API — a ~70ms sine blip that
    decays to silence — rather than adding a binary file to fetch on every
    page. CLICK_SOUND_VOLUME is the single place to mute (0) or adjust it.
--}}
<script>
(function () {
    var CLICK_SOUND_VOLUME = 0.05; // 0 = muted; single control point for volume.

    var audioCtx = null;

    function playClickSound() {
        if (CLICK_SOUND_VOLUME <= 0) return;

        try {
            if (!audioCtx) {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                audioCtx = new Ctx();
            }

            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }

            var now = audioCtx.currentTime;
            var oscillator = audioCtx.createOscillator();
            var gain = audioCtx.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(920, now);

            gain.gain.setValueAtTime(CLICK_SOUND_VOLUME, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.07);

            oscillator.connect(gain);
            gain.connect(audioCtx.destination);

            oscillator.start(now);
            oscillator.stop(now + 0.08);
        } catch (e) {}
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest(
            'button, a, [role="button"], input[type="submit"], input[type="button"]'
        );
        if (target) playClickSound();
    }, true);
})();
</script>
