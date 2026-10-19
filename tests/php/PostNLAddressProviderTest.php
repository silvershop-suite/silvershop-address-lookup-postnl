<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\PostNL\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\PostNL\PostNLAddressProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Drives the real {@link PostNLAddressProvider} against canned ACI responses with a mocked Guzzle handler (no live
 * calls): the `apikey` request header, the NL structured `/search` request + response -> ResolvedAddress mapping,
 * the international free-text `/search` request/response, capability flags, `isConfigured()` false when the env is
 * unset (inert), the null consent category, and soft-fail on a fault.
 *
 * The ACI `/search` endpoint returns a TOP-LEVEL JSON array of address objects (no envelope), so every canned body
 * here is a JSON array.
 */
class PostNLAddressProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TEST_KEY = 'test-postnl-key-123';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    // Spec test address: "2521CA 3" -> Waldorpstraat 3, 2521CA 'S-GRAVENHAGE.
    private const NL_BODY = '[{"streetName":"Waldorpstraat","houseNumber":3,"houseNumberAddition":"",'
        . '"postalCode":"2521CA","cityName":"s-Gravenhage","region":"Zuid-Holland","countryIso":"nl",'
        . '"addressLine":"Waldorpstraat 3"}]';

    // Spec test address (GB): "11 anneter" style -> 137 Trinity Road, London, SW17 7HJ.
    private const GB_BODY = '[{"streetName":"Trinity Road","houseNumber":137,"houseNumberAddition":"",'
        . '"postalCode":"SW17 7HJ","cityName":"London","region":"Wandsworth","countryIso":"gb",'
        . '"addressLine":"137 Trinity Road, LONDON, SW17 7HJ"},'
        . '{"streetName":"Trinity Road","houseNumber":137,"houseNumberAddition":"","floor":"Flat 1",'
        . '"postalCode":"SW17 7HJ","cityName":"London","region":"Wandsworth","countryIso":"gb",'
        . '"addressLine":"Flat 1, 137 Trinity Road, LONDON, SW17 7HJ"}]';

    /**
     * @var string|false
     */
    private $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = Environment::getEnv('SS_POSTNL_API_KEY');
        Environment::setEnv('SS_POSTNL_API_KEY', self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_POSTNL_API_KEY', $this->originalKey === false ? '' : $this->originalKey);
        parent::tearDown();
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, mixed> $transactions
     */
    private function provider(array $responses, array &$transactions = []): PostNLAddressProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new PostNLAddressProvider(['handler' => $stack]);
    }

    public function testApikeyHeaderIsSent(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $provider->lookup(new AddressQuery('NL', '2521CA', '3'));

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame(self::TEST_KEY, $request->getHeaderLine('apikey'), 'PostNL auth is an apikey header');
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringNotContainsString(
            'apikey',
            $request->getUri()->getQuery(),
            'apikey must never ride in the query string',
        );
    }

    public function testNlStructuredLookupMapsResultAndRequestShape(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '2521 CA', '3'));

        $this->assertNotNull($address);
        $this->assertSame('Waldorpstraat', $address->street);
        $this->assertSame('3', $address->houseNumber);
        $this->assertSame('', $address->suffix);
        $this->assertSame('s-Gravenhage', $address->city);
        $this->assertSame('2521CA', $address->postalCode);
        $this->assertSame('Zuid-Holland', $address->state, 'ACI region -> state');
        $this->assertSame('NL', $address->countryCode, 'lowercase countryIso upper-cased');
        $this->assertNull($address->lat, 'ACI returns no coordinates');
        $this->assertNull($address->lng);
        $this->assertTrue($address->exact);
        $this->assertSame('postnl', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('api.postnl.nl', $request->getUri()->getHost());
        $this->assertSame('/v1/address/autocomplete/search', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('NL', $q['countryIso'] ?? null);
        $this->assertSame('2521CA', $q['postalCode'] ?? null, 'Postcode whitespace stripped + upper-cased');
        $this->assertSame('3', $q['houseNumber'] ?? null);
        $this->assertArrayNotHasKey('q', $q, 'NL uses the structured params, not free-text q');
        $this->assertArrayHasKey('uuid', $q, 'A session uuid is always sent');
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, (string) $q['uuid'], 'uuid is RFC 4122 v4');
    }

    public function testNlLookupPrefersAdditionMatch(): void
    {
        $body = '[{"streetName":"Waldorpstraat","houseNumber":11,"houseNumberAddition":"",'
            . '"postalCode":"2521CA","cityName":"s-Gravenhage","countryIso":"nl"},'
            . '{"streetName":"Waldorpstraat","houseNumber":11,"houseNumberAddition":"D",'
            . '"postalCode":"2521CA","cityName":"s-Gravenhage","countryIso":"nl"}]';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '2521CA', '11', 'D'));

        $this->assertNotNull($address);
        $this->assertSame('D', $address->suffix, 'Picks the row matching the typed house-number addition');

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('D', $q['houseNumberAddition'] ?? null);
    }

    public function testInternationalSuggestMapsCandidatesAndRequestShape(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::GB_BODY)], $transactions);

        $results = $provider->suggest('137 trinity', 'GB');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(ResolvedAddress::class, $results[0]);
        $this->assertSame('Trinity Road', $results[0]->street);
        $this->assertSame('137', $results[0]->houseNumber);
        $this->assertSame('London', $results[0]->city);
        $this->assertSame('Wandsworth', $results[0]->state);
        $this->assertSame('GB', $results[0]->countryCode);
        $this->assertSame('postnl', $results[0]->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/v1/address/autocomplete/search', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('GB', $q['countryIso'] ?? null);
        $this->assertSame('137 trinity', $q['q'] ?? null);
        $this->assertArrayHasKey('uuid', $q);
        $this->assertArrayNotHasKey('postalCode', $q, 'suggest sends no dedicated postcode filter');
    }

    public function testInternationalLookupComposesFreeTextAndPostcodeFilter(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::GB_BODY)], $transactions);

        // No explicit term: the number rides in q and the postcode in the dedicated filter.
        $address = $provider->lookup(new AddressQuery('GB', 'SW17 7HJ', '137'));

        $this->assertNotNull($address);
        $this->assertSame('Trinity Road', $address->street);
        $this->assertSame('London', $address->city);
        $this->assertSame('GB', $address->countryCode);

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('GB', $q['countryIso'] ?? null);
        $this->assertSame('137', $q['q'] ?? null, 'House number composed into the free-text q');
        $this->assertSame('SW17 7HJ', $q['postalCode'] ?? null, 'Postcode rides as the dedicated filter');
    }

    public function testSuggestResultLimitIsAppliedClientSide(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = '{"streetName":"Rue Test","houseNumber":' . $i . ',"cityName":"Paris","countryIso":"fr"}';
        }
        $body = '[' . implode(',', $rows) . ']';
        $provider = $this->provider([new Response(200, [], $body)]);
        PostNLAddressProvider::config()->set('result_limit', 2);

        $results = $provider->suggest('rue test', 'FR');

        $this->assertCount(2, $results, 'ACI has no limit param, so suggest trims to result_limit client-side');
    }

    public function testSuggestRejectsEmptyTermWithoutRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '[]')], $transactions);

        $this->assertSame([], $provider->suggest('   ', 'NL'));
        $this->assertCount(0, $transactions, 'An empty term makes no HTTP call');
    }

    public function testSuggestUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '[]')], $transactions);

        $this->assertSame([], $provider->suggest('anything', 'ZZZ'));
        $this->assertCount(0, $transactions);
    }

    public function testValidateResolvesToCanonicalOnHit(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $result = $provider->validate(new ResolvedAddress(
            street: 'Waldorpstraat',
            houseNumber: '3',
            postalCode: '2521CA',
            city: 's-Gravenhage',
            countryCode: 'NL',
        ));

        $this->assertTrue($result->valid);
        $this->assertSame(1.0, $result->score);
        $this->assertNotNull($result->canonical, 'A hit returns the resolved address as the canonical form');
        $this->assertSame('Waldorpstraat', $result->canonical->street);

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('NL', $q['countryIso'] ?? null);
        $this->assertSame('2521CA', $q['postalCode'] ?? null);
        $this->assertSame('3', $q['houseNumber'] ?? null);
    }

    public function testValidateSoftPassesOnEmptyResult(): void
    {
        $provider = $this->provider([new Response(200, [], '[]')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '0000XX', houseNumber: '1', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'An empty ACI result is ambiguous -> soft pass so checkout stays usable');
        $this->assertSame(0.0, $result->score);
        $this->assertNull($result->canonical);
    }

    public function testValidateSoftPassesOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'upstream error')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '2521CA', houseNumber: '3', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'A fault is a soft pass');
        $this->assertSame(0.0, $result->score);
    }

    public function testValidateUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $result = $provider->validate(new ResolvedAddress(countryCode: 'ZZZ'));

        $this->assertTrue($result->valid);
        $this->assertCount(0, $transactions);
    }

    public function testSupportsCountryAndLookupMode(): void
    {
        $provider = $this->provider([new Response(200, [], '[]')]);

        $this->assertTrue($provider->supportsCountry('NL'));
        $this->assertTrue($provider->supportsCountry('BE'));
        $this->assertTrue($provider->supportsCountry('US'));
        $this->assertTrue($provider->supportsCountry('gb'), 'Case-insensitive');
        $this->assertFalse($provider->supportsCountry('ZZZ'), 'Not a 2-letter code');
        $this->assertFalse($provider->supportsCountry('USA'));
        $this->assertFalse($provider->supportsCountry('N1'));
        $this->assertFalse($provider->supportsCountry(''));

        $this->assertSame(AddressLookupProvider::MODE_POSTCODE_NUMBER, $provider->lookupMode('NL'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('BE'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('GB'));
        $this->assertSame(AddressLookupProvider::MODE_NONE, $provider->lookupMode('ZZZ'));

        $this->assertNull($provider->consentCategory(), 'Functional EU carrier, no consent gate');
    }

    public function testUnsupportedCountryLookupMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('ZZZ', '2521CA', '3')));
        $this->assertCount(0, $transactions);
    }

    public function testNlLookupSoftMissOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'boom')]);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '2521CA', '3')));
    }

    public function testIsConfiguredReflectsKeyPresence(): void
    {
        $provider = $this->provider([new Response(200, [], '[]')]);
        $this->assertTrue($provider->isConfigured());

        Environment::setEnv('SS_POSTNL_API_KEY', '');
        $this->assertFalse($provider->isConfigured(), 'No key -> inert');
    }

    public function testNoApikeyHeaderWhenKeyUnset(): void
    {
        Environment::setEnv('SS_POSTNL_API_KEY', '');
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        // Directly invoked (the registry would skip an unconfigured provider) — no apikey header is attached.
        $provider->lookup(new AddressQuery('NL', '2521CA', '3'));

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('', $request->getHeaderLine('apikey'));
    }
}
