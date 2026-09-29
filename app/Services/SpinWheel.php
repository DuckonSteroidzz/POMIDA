<?php

namespace App\Services;

/**
 * Where the Spin & Win wheel lands. Decided here, on the server, and nowhere
 * else (hardening pass F3, 2026-09-27).
 *
 * Before F3 the browser picked a random landing angle, worked out which
 * segment that was, and POSTed the segment's points to
 * AuthController::addPoints(). The server only checked the number against an
 * allowlist, so a client could post the top prize every time and win it
 * every time. Now addPoints() asks this class for the segment, credits that,
 * and returns it. The page animates to it and has no say in it.
 *
 * THE ODDS ARE UNCHANGED
 * ----------------------
 * The page drew every segment the same size and landed at a uniform random
 * angle, so each segment had a 1-in-N chance and a prize's odds were how
 * many segments carried it. A uniform pick over the segment indexes is that
 * same distribution. The table itself is still
 * AuthController::WHEEL_SEGMENTS. This class only chooses from it.
 *
 * random_int() is the CSPRNG, so the result cannot be predicted from earlier
 * results the way mt_rand()'s could.
 *
 * It is a container-resolved class rather than an inline random_int() so
 * a test can bind a fixed outcome and assert what the server does with it.
 */
class SpinWheel
{
    /**
     * @param  array<int, array{type:string, points:int, label:string}>  $segments
     * @return int  an index into $segments
     */
    public function land(array $segments): int
    {
        return random_int(0, count($segments) - 1);
    }
}
