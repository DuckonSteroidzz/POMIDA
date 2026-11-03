{{--
    Which branch's streak and stamp card is showing — Account page and Vouchers
    & Rewards. One button per branch (FreeDesserts::cardBranchOptions), the one
    showing marked with aria-pressed. It works with no JavaScript and posts to
    customer.rewards-branch, which only saves the choice on the account: it is a
    window onto another branch's card, so it never touches the branch the
    customer is ordering from or their cart.

    NOT inside #account-form: a form inside a form is dropped by the HTML parser
    and these buttons would submit the account form instead.

    $cardBranch (?Branch), $cardBranches, $returnTo ('account' | 'vouchers'),
    $pickerClass (the card it sits on), $pickerLabel.
--}}
<form method="POST" action="{{ route('customer.rewards-branch') }}" class="{{ $pickerClass }} flex flex-wrap items-center gap-x-3 gap-y-2" data-testid="card-branch-picker">
    @csrf
    <input type="hidden" name="return_to" value="{{ $returnTo }}">
    <p id="card-branch-label" class="min-w-0 text-sm font-semibold text-peach-deep">{{ $pickerLabel }}</p>
    <div class="flex min-w-0 flex-wrap gap-2" role="group" aria-labelledby="card-branch-label">
        @foreach($cardBranches as $option)
            @php $isShown = $cardBranch && (int) $cardBranch->id === (int) $option->id; @endphp
            <button type="submit" name="branch_id" value="{{ $option->id }}" aria-pressed="{{ $isShown ? 'true' : 'false' }}"
                class="inline-flex min-h-11 min-w-0 items-center gap-1.5 rounded-full border px-3.5 py-2 text-left text-sm font-semibold transition [overflow-wrap:anywhere] {{ $isShown ? 'border-peach-red bg-peach-red text-white' : 'border-peach-soft bg-white text-peach-deep hover:bg-peach-soft' }}">
                <i class="bi bi-shop" aria-hidden="true"></i> {{ $option->name }}
            </button>
        @endforeach
    </div>
</form>
