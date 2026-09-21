{{--
    "Your date range was not usable, here is what we showed instead."

    Rendered by Analytics and Summary, both of which resolve their custom range
    through AdminController's ResolvesReportDateRange trait. $dateNotice is null
    on every ordinary request, so this partial draws nothing at all unless
    something was actually corrected.

    Why a notice and not an error page: all four report endpoints now FALL BACK
    to a safe default period rather than refusing outright, so the figures below
    are real figures for a real window — just not the window that was typed.
    Saying so is the whole point; silently swapping the period underneath
    someone is how a screenshot ends up in a meeting labelled as the wrong month.

    .no-print: this belongs to the act of filtering, not to the report. The
    printed Analytics sheet carries its own copy (analytics-print.blade.php),
    because a printed page leaves the browser that would otherwise show this one.
--}}
@if(!empty($dateNotice))
    <div class="date-range-notice no-print" role="status" data-testid="date-range-notice">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span>{{ $dateNotice }}</span>
    </div>
@endif
