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

    Phone sizing: every line of text is text-sm (16px) or larger; the
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
<section id="streak" class="card-surface w-full max-w-3xl scroll-mt-24 p-3 sm:p-6" aria-labelledby="streak-title" data-testid="streak-calendar" data-branch="{{ $streakBranch?->id }}">
    <div class="mb-4 flex items-center gap-3">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-peach-soft text-lg text-peach-red">
            <i class="bi bi-calendar-check"></i>
        </span>
        <div class="min-w-0">
            <h2 id="streak-title" class="font-display text-lg font-bold text-peach-deep">Streak Calendar</h2>
            <p class="text-sm text-peach-deep/60">
                Days you completed an order at
                <span class="inline-flex items-center gap-1 font-bold text-peach-red" data-testid="streak-branch"><i class="bi bi-shop" aria-hidden="true"></i>{{ $calendarBranch }}</span>.
            </p>
        </div>
    </div>

    {{-- Current / best --}}
    <div class="mb-4 grid grid-cols-2 gap-2 sm:gap-3">
        <div class="rounded-2xl bg-peach-soft/60 p-3 text-center">
            <p class="text-sm font-semibold text-peach-deep/70">Current streak</p>
            <p class="font-display text-2xl font-black text-peach-red" data-testid="current-streak">
                {{ $current['length'] }} {{ $current['length'] === 1 ? 'day' : 'days' }}
            </p>
        </div>
        <div class="rounded-2xl bg-peach-soft/60 p-3 text-center">
            <p class="text-sm font-semibold text-peach-deep/70">Best streak</p>
            <p class="font-display text-2xl font-black text-peach-deep" data-testid="best-streak">
                {{ $streak['best'] }} {{ $streak['best'] === 1 ? 'day' : 'days' }}
            </p>
        </div>
    </div>

    @if($current['length'] > 0 && ! $current['alive_today'])
        <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
            <i class="bi bi-hourglass-split"></i> Complete an order at {{ $calendarBranch }} today to keep this streak going.
        </p>
    @endif

    {{-- The streak POINTS bonus is company-wide: its run counts completed
         orders at every branch, not only the one above. --}}
    @if($nextMilestone && $loyalty['qualifies'])
        <p class="mb-3 text-sm text-peach-deep/70" data-testid="streak-bonus-line">
            <i class="bi bi-trophy text-peach-red"></i>
            Streak bonus (orders at any branch count):
            {{ $nextMilestone - $bonusRun['length'] }} more {{ $nextMilestone - $bonusRun['length'] === 1 ? 'day' : 'days' }}
            in a row to the {{ $nextMilestone }}-day bonus (+{{ $milestones[$nextMilestone] }} points).
        </p>
    @endif

    {{-- Month header + navigation --}}
    <div class="mb-2 flex items-center justify-between gap-2">
        @if($streak['prev'])
            <a href="{{ route('customer.account', ['month' => $streak['prev']]) }}#streak"
               class="grid h-11 w-11 shrink-0 place-items-center rounded-full border border-peach-soft bg-white text-lg text-peach-red no-underline transition hover:bg-peach-soft"
               aria-label="Previous month" data-testid="streak-prev">
                <i class="bi bi-chevron-left"></i>
            </a>
        @else
            <span class="h-11 w-11 shrink-0" aria-hidden="true"></span>
        @endif

        <p class="min-w-0 text-center font-display text-base font-bold text-peach-deep" data-testid="streak-month">
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
        <div role="row" class="grid grid-cols-[repeat(7,minmax(0,1fr))] gap-1">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)
                <span role="columnheader" class="py-1 text-center text-sm font-bold text-peach-deep/60">
                    <abbr title="{{ $weekday }}" class="no-underline">{{ substr($weekday, 0, 1) }}</abbr>
                </span>
            @endforeach
        </div>
        @foreach($streak['weeks'] as $week)
            <div role="row" class="mt-1 grid grid-cols-[repeat(7,minmax(0,1fr))] gap-1">
                @foreach($week as $cell)
                    @if(! $cell['in_month'])
                        <span role="cell" aria-hidden="true" class="aspect-square"></span>
                    @else
                        @php
                            $label = \Carbon\Carbon::parse($cell['date'])->format('F j')
                                . ($cell['active'] ? ', order completed' : '')
                                . ($cell['today'] ? ', today' : '');
                        @endphp
                        <span role="cell" aria-label="{{ $label }}"
                              @if($cell['active']) data-streak-day="{{ $cell['date'] }}" @endif
                              class="relative grid aspect-square min-h-10 place-items-center rounded-full text-base font-bold
                                {{ $cell['active'] ? 'bg-peach-red text-white' : ($cell['future'] ? 'text-peach-deep/30' : 'text-peach-deep/80') }}
                                {{ $cell['today'] ? 'ring-2 ring-peach-deep ring-offset-1' : '' }}">
                            {{ $cell['day'] }}
                        </span>
                    @endif
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-peach-deep/70">
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-block h-4 w-4 rounded-full bg-peach-red" aria-hidden="true"></span> Completed order at {{ $calendarBranch }}
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-block h-4 w-4 rounded-full ring-2 ring-peach-deep" aria-hidden="true"></span> Today
        </span>
        <span>{{ $streak['active_in_month'] }} {{ $streak['active_in_month'] === 1 ? 'day' : 'days' }} this month</span>
    </div>

    <p class="mt-3 text-sm text-peach-deep/60">
        Only completed orders count. Pending or cancelled orders don't mark a day.
    </p>
</section>
