{{--
    Streak Calendar — Account page, registered customers only.

    Data comes from App\Services\StreakCalendar::forAccountPage() via
    AuthController::showAccount(). PER BRANCH: it shows $streakBranch, the
    branch the customer is ordering from, and a day is marked only when it has
    at least one COMPLETED order AT THAT BRANCH (shop time zone, config
    app.shop_timezone); placed, pending and cancelled orders never mark a day.
    The current/best runs and "days this month" count that branch too. The one
    exception is the points-bonus line: the streak bonus is company-wide, so it
    reads $streak['bonus_current'] (every branch) and says so.
    Month navigation is plain links (?month=YYYY-MM), clamped server-side, so
    it works with no JavaScript.

    Layout (Oct 2026 redesign): a cell of the Overview grid on the Account page,
    7 of 12 columns from 761px up and full width below it; the grid and the
    stacked bonus-points card + stamp card beside it are in account-settings.blade.php.

    Phone sizing: body text is text-sm (16px) or larger, the weekday row and
    legend are text-xs (14px, this app's floor); the
    previous/next links are 44px squares; the 7-column grid uses minmax(0,1fr)
    so it can never push the page sideways at 360px (a bare 1fr track can,
    because it will not shrink below its content).
--}}
@php
    $milestones = $loyalty['milestones'];
    $current = $streak['current'];
    $bonusRun = $streak['bonus_current'] ?? $current;
    $nextMilestone = collect($milestones)->keys()->first(fn ($days) => $days > $bonusRun['length']);
    $calendarBranch = $streakBranch?->name ?? 'this branch';
@endphp
<section id="streak" class="acct-card min-w-0 scroll-mt-24 p-4 sm:p-[22px] col-span-12 min-[761px]:col-span-7" aria-labelledby="streak-title" data-testid="streak-calendar" data-branch="{{ $streakBranch?->id }}">
    <div class="mb-4 flex items-start gap-3">
        <span class="acct-icon text-lg">
            <i class="bi bi-calendar-check"></i>
        </span>
        <div class="min-w-0">
            <h2 id="streak-title" class="font-display text-lg font-bold leading-snug text-peach-deep">Streak Calendar</h2>
            <p class="text-sm text-peach-deep/60">
                Days you completed an order at
                <span class="inline-flex items-center gap-1 rounded-full border border-peach-soft bg-peach-soft/50 px-2 py-0.5 text-xs font-semibold text-peach-deep" data-testid="streak-branch"><i class="bi bi-shop" aria-hidden="true"></i>{{ $calendarBranch }}</span>.
            </p>
        </div>
    </div>

    {{-- Current / best --}}
    <div class="mb-3 grid grid-cols-2 gap-2.5">
        <div class="rounded-[14px] bg-peach-soft/60 p-3 text-center">
            <p class="text-sm text-peach-deep/70">Current streak</p>
            <p class="font-display text-2xl font-bold text-peach-red" data-testid="current-streak">
                {{ $current['length'] }} {{ $current['length'] === 1 ? 'day' : 'days' }}
            </p>
        </div>
        <div class="rounded-[14px] bg-peach-soft/60 p-3 text-center">
            <p class="text-sm text-peach-deep/70">Best streak</p>
            <p class="font-display text-2xl font-bold text-peach-red" data-testid="best-streak">
                {{ $streak['best'] }} {{ $streak['best'] === 1 ? 'day' : 'days' }}
            </p>
        </div>
    </div>

    @if($current['length'] > 0 && ! $current['alive_today'])
        <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-medium text-amber-800">
            <i class="bi bi-hourglass-split"></i> Order today to keep your streak.
        </p>
    @endif

    {{-- The streak POINTS bonus is company-wide: its run counts completed
         orders at every branch, not only the one above. --}}
    @if($nextMilestone && $loyalty['qualifies'])
        <p class="mb-3 flex items-start gap-1.5 rounded-xl bg-peach-cream px-3 py-2.5 text-sm text-peach-deep/70" data-testid="streak-bonus-line">
            <i class="bi bi-trophy mt-0.5 shrink-0 text-peach"></i>
            <span>
            Streak bonus (any branch):
            {{ $nextMilestone - $bonusRun['length'] }} more {{ $nextMilestone - $bonusRun['length'] === 1 ? 'day' : 'days' }}
            in a row to the {{ $nextMilestone }}-day bonus (+{{ $milestones[$nextMilestone] }} points).
            </span>
        </p>
    @endif

    {{-- Month header + navigation --}}
    <div class="mb-2 mt-3.5 flex items-center justify-between gap-2">
        @if($streak['prev'])
            <a href="{{ route('customer.account', ['month' => $streak['prev']]) }}#streak"
               class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-peach-soft bg-white text-lg text-peach-red no-underline transition hover:bg-peach-soft"
               aria-label="Previous month" data-testid="streak-prev">
                <i class="bi bi-chevron-left"></i>
            </a>
        @else
            <span class="h-11 w-11 shrink-0" aria-hidden="true"></span>
        @endif

        <p class="min-w-0 text-center font-display text-base font-bold text-peach-deep" aria-live="polite" data-testid="streak-month">
            {{ $streak['month']->format('F Y') }}
        </p>

        @if($streak['next'])
            <a href="{{ route('customer.account', ['month' => $streak['next']]) }}#streak"
               class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-peach-soft bg-white text-lg text-peach-red no-underline transition hover:bg-peach-soft"
               aria-label="Next month" data-testid="streak-next">
                <i class="bi bi-chevron-right"></i>
            </a>
        @else
            <span class="h-11 w-11 shrink-0" aria-hidden="true"></span>
        @endif
    </div>

    {{-- The grid --}}
    <div role="table" aria-label="Completed orders in {{ $streak['month']->format('F Y') }}" class="w-full">
        <div role="row" class="grid grid-cols-[repeat(7,minmax(0,1fr))] gap-1 text-center">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                <span role="columnheader" class="py-1 text-center text-xs font-semibold text-peach-deep/60">
                    <abbr title="{{ $weekday }}" class="no-underline">{{ substr($weekday, 0, 1) }}</abbr>
                </span>
            @endforeach
        </div>
        @foreach($streak['weeks'] as $week)
            <div role="row" class="mt-1 grid grid-cols-[repeat(7,minmax(0,1fr))] gap-1">
                @foreach($week as $cell)
                    @if(! $cell['in_month'])
                        <span role="cell" aria-hidden="true" class="aspect-square max-h-12"></span>
                    @else
                        @php
                            $label = \Carbon\Carbon::parse($cell['date'])->format('F j')
                                . ($cell['active'] ? ', order completed' : '')
                                . ($cell['today'] ? ', today' : '');
                        @endphp
                        <span role="cell" aria-label="{{ $label }}"
                              @if($cell['active']) data-streak-day="{{ $cell['date'] }}" @endif
                              class="relative mx-auto grid aspect-square max-h-12 w-full place-items-center rounded-xl text-sm
                                {{ $cell['active'] ? 'bg-peach-red font-bold text-white' : ($cell['future'] ? 'text-peach-deep/30' : 'text-peach-deep/80') }}
                                {{ $cell['today'] ? 'font-bold ring-2 ring-inset ring-peach' : '' }}">
                            {{ $cell['day'] }}
                        </span>
                    @endif
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-3.5 gap-y-1 text-xs text-peach-deep/70">
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-block h-2.5 w-2.5 rounded-full bg-peach-red" aria-hidden="true"></span> Order completed
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-block h-2.5 w-2.5 rounded-full border-2 border-peach" aria-hidden="true"></span> Today
        </span>
        <span>{{ $streak['active_in_month'] }} {{ $streak['active_in_month'] === 1 ? 'day' : 'days' }} this month</span>
    </div>

</section>
