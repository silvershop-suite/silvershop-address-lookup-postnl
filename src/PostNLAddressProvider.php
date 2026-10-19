<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\PostNL;

use GuzzleHttp\Client;
use SilverShop\AddressLookup\Provider\AbstractHttpProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;

/**
 * PostNL Adrescheck provider — the carrier-aligned satellite for
 * {@see \SilverShop\AddressLookup\Provider\AddressLookupProvider}. Backed by PostNL's unified **Adrescheck
 * Autocomplete (ACI) v1** API on host `https://api.postnl.nl`, a single `/v1/address/autocomplete/search` endpoint
 * that serves BOTH the structured NL postcode + house-number lookup (what PostNL markets as "Adrescheck Nederland")
 * AND free-text international autocomplete for the rest of the world. Authenticated with an **`apikey` request
 * header** (per the ACI docs; this provider never puts the key in the query string, so it stays out of URLs and
 * logs), reading the key from `SS_POSTNL_API_KEY` (configurable via {@see self::$api_key_env}).
 *
 * Capabilities, exactly as the ACI spec honours them:
 *  - **NL** → {@see self::MODE_POSTCODE_NUMBER}. `GET /search?countryIso=NL&postalCode=&houseNumber=(&houseNumberAddition=)`
 *    resolves a full structured address (street + city + region) from a postcode + house number — the spec's
 *    §2.3 "addresscheck-Nederland" behaviour, exposed through the same autocomplete endpoint.
 *  - **Every other country** → {@see self::MODE_AUTOCOMPLETE}. `GET /search?countryIso=&q=(&postalCode=)` takes a
 *    free-text term and returns candidate addresses. ACI advertises coverage for "all countries of the world"; the
 *    actual per-account entitlement is enforced server-side (HTTP 403 for an unsupported entry-point country), which
 *    degrades to a soft miss, so any well-formed ISO 3166-1 alpha-2 code is accepted.
 *
 * **No autocomplete→retrieve two-step:** `/search` returns full addresses directly (streetName, cityName, postalCode,
 * region, …), so there is nothing to retrieve afterwards. The only optional first step the ACI offers is
 * `/token` for a session `uuid`; because that uuid may be self-generated (it is valid one day per country and only
 * dedupes multi-call sessions for billing), this provider mints an RFC 4122 v4 uuid per request and skips the extra
 * round-trip.
 *
 * **Validation:** ACI exposes no boolean verify endpoint, so {@see self::validate()} is a best-effort lookup-match —
 * it resolves the address and, on a hit, returns it as the canonical form; a miss or any fault soft-passes as
 * {@see AddressValidationResult::unknown()} so a flaky API never blocks checkout.
 *
 * **Consent:** functional / no gate — PostNL is an EU carrier used for the shop's own logistics, so
 * {@see self::consentCategory()} stays null (same posture as the bundled providers and the MyParcel satellite),
 * unlike the consent-gated global Loqate satellite.
 *
 * Rebuilds the base's hardened Guzzle client in {@see self::httpClient()} (SSL verify ON, short timeout, graceful
 * empty on any fault) so the `apikey` header is always applied, and still merges the `http_options` / constructor
 * options last so the MockHandler test seam works.
 *
 * @see https://developer.postnl.nl/ Adrescheck Autocomplete (International) v1
 */
class PostNLAddressProvider extends AbstractHttpProvider
{
    /**
     * ACI base URI (host + the autocomplete path prefix). Overridable in config to pin a sandbox / mirror host.
     */
    private static string $endpoint = 'https://api.postnl.nl/v1/address/autocomplete';

    /**
     * Environment variable holding the PostNL API key. No key → the provider is inert ({@see self::isConfigured()}
     * false, the registry skips it, the form stays manual), so the module is safe to install before credentials.
     */
    private static string $api_key_env = 'SS_POSTNL_API_KEY';

    /**
     * Max candidates returned from {@see self::suggest()}. ACI `/search` has no limit parameter, so the cap is applied
     * client-side after mapping.
     */
    private static int $result_limit = 10;

    /**
     * Countries resolved from a STRUCTURED postcode + house number (frontend {@see self::MODE_POSTCODE_NUMBER}). Only
     * NL — the ACI `postalCode` + `houseNumber` + `houseNumberAddition` params are the Dutch addresscheck flow; every
     * other country resolves through free-text `q`.
     *
     * @var array<int, string>
     */
    private const STRUCTURED_COUNTRIES = ['NL'];

    /**
     * A private copy of the constructor's Guzzle options (the MockHandler test seam), because the base keeps its own
     * copy private and {@see self::httpClient()} is overridden here to add the `apikey` header.
     *
     * @var array<string, mixed>
     */
    private array $postnlHttpOptions;

    private ?Client $postnlClient = null;

    /**
     * @param array<string, mixed> $httpOptions extra Guzzle config merged last (the MockHandler test seam)
     */
    public function __construct(array $httpOptions = [])
    {
        parent::__construct($httpOptions);
        $this->postnlHttpOptions = $httpOptions;
    }

    public function getKey(): string
    {
        return 'postnl';
    }

    public function getName(): string
    {
        return 'PostNL Adrescheck';
    }

    /**
     * Configured once the API key is present in the environment. No key → inert, so the module is safe to install
     * before credentials are supplied.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Global coverage. ACI advertises "all countries of the world"; it exposes no country-enumeration endpoint and
     * enforces per-account entitlement server-side (HTTP 403 → soft miss), so any well-formed ISO 3166-1 alpha-2 code
     * is accepted. The registry only ever routes a country here when postnl is its `by_country` entry or the `default`.
     */
    public function supportsCountry(string $countryCode): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2}$/', trim($countryCode));
    }

    public function lookupMode(string $countryCode): string
    {
        $code = $this->normaliseCountry($countryCode);
        if (in_array($code, self::STRUCTURED_COUNTRIES, true)) {
            return self::MODE_POSTCODE_NUMBER;
        }

        return $this->supportsCountry($code) ? self::MODE_AUTOCOMPLETE : self::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = $this->normaliseCountry($query->countryCode);
        if (!$this->supportsCountry($code)) {
            return null;
        }

        // NL: the structured postcode + house-number flow (ACI §2.3 "addresscheck-Nederland") → a full address.
        if ($code === 'NL' && $query->hasPostcodeNumber()) {
            return $this->structuredNl($query);
        }

        // Everyone else is free-text: an explicit term wins; otherwise compose a street/number term and let the
        // postcode ride as the dedicated `postalCode` filter.
        $term = trim($query->term);
        $postal = trim($query->postalCode);
        if ($term === '') {
            $term = trim((string) preg_replace('/\s+/', ' ', trim($query->houseNumber . ' ' . $query->suffix)));
        }
        if ($term === '' && $postal === '') {
            return null;
        }

        return $this->freeText($code, $term, $postal, 1)[0] ?? null;
    }

    public function suggest(string $term, string $countryCode): array
    {
        $code = $this->normaliseCountry($countryCode);
        $term = trim($term);
        if (!$this->supportsCountry($code) || $term === '') {
            return [];
        }

        return $this->freeText($code, mb_substr($term, 0, 200), '', (int) static::config()->get('result_limit'));
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        $code = $this->normaliseCountry($address->countryCode);
        if (!$this->supportsCountry($code)) {
            return AddressValidationResult::unknown();
        }

        // ACI has no boolean verify endpoint, so validation is a best-effort lookup-match: resolve the address and,
        // on a hit, hand back the resolved record as the canonical form. A miss or any fault soft-passes (never
        // blocks checkout), because an empty `/search` result is ambiguous between "not found" and a transport fault.
        $resolved = $this->lookup($this->queryFromAddress($address, $code));
        if ($resolved instanceof ResolvedAddress) {
            return new AddressValidationResult(true, 1.0, $resolved);
        }

        return AddressValidationResult::unknown();
    }

    /**
     * Structured NL lookup: `countryIso=NL` + postcode + house number (+ optional addition). ACI returns every
     * sub-address under a house number when no addition is given; this picks the addition match when one was typed,
     * else the first row.
     */
    private function structuredNl(AddressQuery $query): ?ResolvedAddress
    {
        $params = [
            'countryIso' => 'NL',
            'postalCode' => strtoupper((string) preg_replace('/\s+/', '', $query->postalCode)),
            'houseNumber' => $query->houseNumber,
            'uuid' => $this->newUuid(),
        ];
        if ($query->suffix !== '') {
            $params['houseNumberAddition'] = $query->suffix;
        }

        $rows = $this->rows($this->getJson($this->url('search'), $params));
        if ($rows === []) {
            return null;
        }

        $chosen = $rows[0];
        if ($query->suffix !== '') {
            foreach ($rows as $row) {
                if (strcasecmp((string) ($row['houseNumberAddition'] ?? ''), $query->suffix) === 0) {
                    $chosen = $row;
                    break;
                }
            }
        }

        return $this->mapAddress($chosen, 'NL', (string) ($chosen['streetName'] ?? '') !== '');
    }

    /**
     * Free-text search (`q`), the path for every non-NL country (and the dedicated `postalCode` filter when one is
     * known). `q` may be empty when a postcode is supplied, per the ACI spec. Results are capped to `$limit`
     * client-side (ACI has no limit parameter).
     *
     * @return array<int, ResolvedAddress>
     */
    private function freeText(string $code, string $term, string $postalCode, int $limit): array
    {
        $params = [
            'countryIso' => $code,
            'q' => $term,
            'uuid' => $this->newUuid(),
        ];
        if ($postalCode !== '') {
            $params['postalCode'] = $postalCode;
        }

        $out = [];
        foreach ($this->rows($this->getJson($this->url('search'), $params)) as $row) {
            $exact = (string) ($row['streetName'] ?? '') !== '' && $this->hasHouseNumber($row);
            $out[] = $this->mapAddress($row, $code, $exact);
        }

        if ($limit > 0 && count($out) > $limit) {
            $out = array_slice($out, 0, $limit);
        }

        return $out;
    }

    /**
     * Normalise an {@see AddressQuery} out of an already-entered address for {@see self::validate()}: NL goes through
     * the structured postcode + number path when both are present, everything else through a composed free-text term.
     */
    private function queryFromAddress(ResolvedAddress $address, string $code): AddressQuery
    {
        if ($code === 'NL' && $address->postalCode !== '' && $address->houseNumber !== '') {
            return new AddressQuery('NL', $address->postalCode, $address->houseNumber, $address->suffix);
        }

        $parts = array_filter([
            $address->street,
            trim($address->houseNumber . ' ' . $address->suffix),
            $address->postalCode,
            $address->city,
        ], static fn (string $v): bool => trim($v) !== '');
        $term = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));

        return new AddressQuery($code, '', '', '', $term);
    }

    /**
     * The address rows of an ACI `/search` response. The endpoint returns a TOP-LEVEL JSON array of address objects
     * (not wrapped in an envelope); a non-2xx, an error object or a non-JSON body all decode to [] (a soft miss).
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Map one ACI address row onto the normalised {@see ResolvedAddress}. ACI returns `houseNumber` as a JSON number,
     * `countryIso` lowercased, `region` as the province/county (→ state) and no coordinates (→ lat/lng null).
     *
     * @param array<string, mixed> $row
     */
    private function mapAddress(array $row, string $fallbackCode, bool $exact): ResolvedAddress
    {
        $houseNumber = $this->hasHouseNumber($row) ? trim((string) ($row['houseNumber'] ?? '')) : '';

        $country = strtoupper((string) ($row['countryIso'] ?? ''));
        if ($country === '') {
            $country = $fallbackCode;
        }

        return new ResolvedAddress(
            street: (string) ($row['streetName'] ?? ''),
            houseNumber: $houseNumber,
            suffix: (string) ($row['houseNumberAddition'] ?? ''),
            postalCode: (string) ($row['postalCode'] ?? ''),
            city: (string) ($row['cityName'] ?? ''),
            municipality: '',
            state: (string) ($row['region'] ?? ''),
            countryCode: $country,
            lat: null,
            lng: null,
            exact: $exact,
            providerRef: $this->getKey(),
        );
    }

    /**
     * Whether a row carries a usable house number (ACI sends `houseNumber` as a JSON number; absent / empty / 0 means
     * an address without a street number, e.g. a company or building-only row).
     *
     * @param array<string, mixed> $row
     */
    private function hasHouseNumber(array $row): bool
    {
        $hn = trim((string) ($row['houseNumber'] ?? ''));

        return $hn !== '' && $hn !== '0';
    }

    /**
     * Build a full request URL from the configured base and a path segment (e.g. 'search', 'token').
     */
    private function url(string $path): string
    {
        return rtrim((string) static::config()->get('endpoint'), '/') . '/' . ltrim($path, '/');
    }

    private function apiKey(): string
    {
        $env = (string) static::config()->get('api_key_env');

        return $env === '' ? '' : (string) Environment::getEnv($env);
    }

    private function normaliseCountry(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * A self-generated RFC 4122 version-4 UUID for the ACI `uuid` session parameter (mandatory; may be self-minted —
     * valid one day per country, dedupes multi-call sessions for billing). Avoids the optional `/token` round-trip.
     */
    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        /** @var array<int, string> $chunks */
        $chunks = str_split(bin2hex($bytes), 4);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }

    /**
     * The hardened Guzzle client, rebuilt here (rather than inherited) so the PostNL `apikey` header is always
     * applied. Keeps SSL verify ON and the short timeout, and still merges the config / constructor options last so
     * the MockHandler test seam works.
     */
    protected function httpClient(): Client
    {
        if ($this->postnlClient instanceof Client) {
            return $this->postnlClient;
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'silvershop-address-lookup-postnl/1.0 (+https://github.com/silvershop)',
        ];
        $key = $this->apiKey();
        if ($key !== '') {
            // PostNL ACI authenticates with an `apikey` request header (never a query param — keeps the key out of
            // request URLs and server logs).
            $headers['apikey'] = $key;
        }

        $this->postnlClient = new Client(array_merge(
            [
                'timeout' => (int) static::config()->get('timeout'),
                'connect_timeout' => (int) static::config()->get('timeout'),
                'verify' => true,
                'http_errors' => false,
                'headers' => $headers,
            ],
            (array) static::config()->get('http_options'),
            $this->postnlHttpOptions,
        ));

        return $this->postnlClient;
    }
}
