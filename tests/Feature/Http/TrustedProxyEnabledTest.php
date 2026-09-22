<?php

namespace Tests\Feature\Http;

class TrustedProxyEnabledTest extends TrustedProxyTest
{
    protected ?string $proxies = '*';

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->markTestSkipped('Covered by the parent class with proxies disabled.');
    }

    public function test_https_is_detected_through_a_trusted_proxy(): void
    {
        $this->assertSame('*', env('TRUSTED_PROXIES'));

        $request = $this->forwardedRequest();
        $this->app->handleRequest($request);

        // Without this the admin panel emits http:// links on an https domain,
        // which browsers then block as mixed content.
        $this->assertTrue($request->isSecure());
        $this->assertSame('203.0.113.7', $request->ip());
    }
}
