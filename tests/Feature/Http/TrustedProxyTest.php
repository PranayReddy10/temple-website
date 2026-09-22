<?php

namespace Tests\Feature\Http;

use App\Support\TrustedProxies;
use Tests\TestCase;

/**
 * Behind Cloudflare the origin receives plain HTTP carrying
 * X-Forwarded-Proto: https. Whether Laravel believes that header decides
 * whether the admin panel generates https:// or http:// links.
 */
class TrustedProxyTest extends TestCase
{
    public function test_an_unset_value_trusts_nothing(): void
    {
        // The safe default, and what .env.example ships.
        $this->assertNull(TrustedProxies::from(null));
        $this->assertNull(TrustedProxies::from(''));
        $this->assertNull(TrustedProxies::from('   '));
    }

    public function test_it_can_be_switched_off_explicitly(): void
    {
        $this->assertNull(TrustedProxies::from('false'));
        $this->assertNull(TrustedProxies::from('0'));
    }

    public function test_a_star_trusts_any_proxy(): void
    {
        // Appropriate behind Cloudflare, where the origin address is not
        // publicly advertised.
        $this->assertSame('*', TrustedProxies::from('*'));
    }

    public function test_a_list_of_addresses_is_split_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2'],
            TrustedProxies::from(' 10.0.0.1 , 10.0.0.2 '),
        );
    }

    public function test_a_list_of_only_separators_trusts_nothing(): void
    {
        // ",,," must not become a list of empty strings, which Symfony would
        // treat as proxies and effectively trust the request as-is.
        $this->assertNull(TrustedProxies::from(',,,'));
    }

    public function test_the_application_boots_with_the_default_configuration(): void
    {
        $this->get('/up')->assertOk();
    }
}
