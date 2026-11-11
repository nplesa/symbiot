<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionCookieSecurityTest extends TestCase
{
    /** @return array{0: mixed, 1: mixed} previous APP_URL / SESSION_SECURE_COOKIE server values */
    private function sessionConfigWith(?string $appUrl, ?string $secureCookie): array
    {
        $previous = [$_SERVER['APP_URL'] ?? null, $_SERVER['SESSION_SECURE_COOKIE'] ?? null];

        foreach (['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $secureCookie] as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        return $previous;
    }

    /** @param array{0: mixed, 1: mixed} $previous */
    private function restore(array $previous): void
    {
        $this->sessionConfigWith($previous[0], $previous[1]);
    }

    public function test_secure_flag_defaults_from_the_app_url_scheme(): void
    {
        $previous = $this->sessionConfigWith('https://symbiot.npsoft.ro', null);
        try {
            $this->assertTrue((require config_path('session.php'))['secure']);

            $this->sessionConfigWith('http://localhost', null);
            $this->assertFalse((require config_path('session.php'))['secure']);

            // An explicit value still wins.
            $this->sessionConfigWith('https://symbiot.npsoft.ro', 'false');
            $this->assertFalse((require config_path('session.php'))['secure']);
        } finally {
            $this->restore($previous);
        }
    }

    public function test_session_cookie_is_sent_with_the_secure_flag_on_https_sites(): void
    {
        config(['session.secure' => true, 'session.driver' => 'array']);

        $response = $this->get('/login');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'The session cookie should be set.');
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }
}
