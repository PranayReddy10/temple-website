<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Behind Cloudflare the origin receives plain HTTP carrying
 * X-Forwarded-Proto: https. Whether Laravel believes that header decides
 * whether the admin panel generates https:// or http:// links.
 */
class TrustedProxyTest extends TestCase
{
    protected ?string $proxies = null;

    protected function setUp(): void
    {
        // Symfony stores trusted proxies in a static, which survives between
        // tests in the same process. Without this reset, whichever variant ran
        // first would decide the answer for the other.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        // bootstrap/app.php reads env() while the application is being built,
        // so the value has to exist before the app is created in parent::setUp().
        if ($this->proxies === null) {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $this->proxies;
            $_ENV['TRUSTED_PROXIES'] = $this->proxies;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    protected function forwardedRequest(): Request
    {
        return Request::create('http://temple.example/admin', 'GET', [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->assertNull(env('TRUSTED_PROXIES'));

        $request = $this->forwardedRequest();

        // Default posture: an attacker reaching the origin directly must not be
        // able to spoof scheme or client IP.
        $this->assertFalse($request->isSecure());
        $this->assertSame('10.0.0.1', $request->ip());
    }

    public function test_the_app_boots_without_the_variable_set(): void
    {
        $this->get('/up')->assertOk();
    }
}
