<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * One definition of "what counts as an acceptable password", for every place
 * in the app where one is set or changed.
 *
 * Added by the manual security review of 2026-08-31. Before it, the rules had
 * drifted apart — the reset flow and customer registration accepted `min:6`
 * with no complexity at all, so `123456` and `simon123` were both accepted and
 * could immediately be used to sign in. Staff and admin accounts were reachable
 * that way, which made it an account-takeover risk rather than a style problem.
 *
 * Keeping the rule here (instead of repeating a literal in five controllers)
 * means the policy can only ever be changed in one place, and no future flow
 * can quietly ship a weaker one.
 *
 * The policy: at least 8 characters, with upper case, lower case, a number and
 * a symbol. Laravel's own Password rule is used rather than a hand-written
 * regex so the failure messages name the specific missing requirement
 * ("must contain at least one symbol") instead of a useless "invalid password".
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * The rule object itself.
     */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * Validation rules for a password the user MUST supply.
     *
     * @param bool $confirmed whether a matching *_confirmation field is required
     * @return array<int, mixed>
     */
    public static function required(bool $confirmed = true): array
    {
        $rules = ['required', 'string', self::rule()];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    /**
     * Validation rules for an OPTIONAL password field — one that is only
     * validated when the user actually typed something, as on the customer's
     * account-settings form where leaving it blank means "keep my password".
     *
     * @return array<int, mixed>
     */
    public static function optional(bool $confirmed = false): array
    {
        $rules = ['nullable', 'string', self::rule()];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    /**
     * A single human-readable summary, for forms that want to state the rule
     * up front rather than only after a failure.
     */
    public static function describe(): string
    {
        return 'Password must be at least ' . self::MIN_LENGTH
            . ' characters and include an uppercase letter, a lowercase letter, '
            . 'a number and a special character.';
    }
}
