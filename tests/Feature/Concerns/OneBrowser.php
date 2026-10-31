<?php

namespace Tests\Feature\Concerns;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Sends each request as a browser really would: only the session cookie it
 * holds, nothing left over in the test process. Without this a "signed out"
 * guard, or data the test client keeps in its one Store between requests, makes
 * assertions pass vacuously. See SingleSessionPerAccountTest::on().
 *
 * More than one $browser name gives separate cookie jars. Tabs of one browser
 * share a jar, so they share a name.
 */
trait OneBrowser
{
    /** @var array<string, string> browser name => its current session id */
    private array $jar = [];

    private function browser(callable $request, string $browser = 'tester'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $id = $this->jar[$browser] ??= Str::random(40);

        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($id);

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();
        $this->withCredentials();
        $this->withCookie((string) config('session.cookie'), $id);

        $response = $request();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie') && (string) $cookie->getValue() !== '') {
                $this->jar[$browser] = CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false));
            }
        }

        return $response;
    }

    /** What the session holds right after the last request. */
    private function sessionData(): array
    {
        return $this->app['session']->driver()->all();
    }

    private function hasLogin(string $guard): bool
    {
        return array_key_exists($this->app['auth']->guard($guard)->getName(), $this->sessionData());
    }
}
