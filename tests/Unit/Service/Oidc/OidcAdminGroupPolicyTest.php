<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Provider\OidcResourceOwner;
use App\Service\Oidc\OidcAdminGroupPolicy;
use App\Service\Oidc\OidcGroupClaims;
use App\Service\Oidc\OidcMetadataResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

class OidcAdminGroupPolicyTest extends TestCase
{
    public function testDisabledWhenNoGroupIsConfigured(): void
    {
        $policy = $this->policy(null);

        self::assertFalse($policy->isEnabled());
        self::assertFalse($policy->shouldPromote(['groups' => ['mbin-admins']], $this->owner(['groups' => ['mbin-admins']])));
    }

    #[DataProvider('emptyConfigurations')]
    public function testWhitespaceAndEmptyStringsAreTreatedAsUnset(?string $configured): void
    {
        self::assertFalse(($this->policy($configured))->isEnabled());
    }

    /**
     * @return \Generator<array{?string}>
     */
    public static function emptyConfigurations(): \Generator
    {
        yield [null];
        yield [''];
        yield ['   '];
    }

    public function testPromotesOnTheIdTokenClaim(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertTrue($policy->shouldPromote(['groups' => ['members', 'mbin-admins']], $this->owner([])));
    }

    public function testDoesNotPromoteWhenTheGroupIsAbsent(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => ['members']], $this->owner([])));
    }

    public function testFallsBackToUserinfoWhenTheIdTokenCarriesNoGroupsClaim(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertTrue($policy->shouldPromote([], $this->owner(['groups' => ['mbin-admins']])));
    }

    /**
     * A present but empty claim is the provider answering the question. It
     * must not send us looking for a more agreeable answer in userinfo.
     */
    public function testAnEmptyIdTokenClaimIsNotOverriddenByUserinfo(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => []], $this->owner(['groups' => ['mbin-admins']])));
    }

    public function testAMalformedClaimDoesNotPromote(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => 'mbin-admins'], $this->owner([])));
        self::assertFalse($policy->shouldPromote(['groups' => [['mbin-admins']]], $this->owner([])));
    }

    public function testMatchingIsExactAndCaseSensitive(): void
    {
        $policy = $this->policy('mbin-admins');

        self::assertFalse($policy->shouldPromote(['groups' => ['Mbin-Admins']], $this->owner([])));
        self::assertFalse($policy->shouldPromote(['groups' => ['mbin-admins-readonly']], $this->owner([])));
    }

    /**
     * The userinfo response is not signed, so a group list read from it is
     * only as trustworthy as the transport. Over plain HTTP anything on the
     * path could add a group and mint an administrator.
     */
    public function testUserinfoGroupsAreIgnoredWhenTheEndpointIsPlainHttp(): void
    {
        $policy = $this->policy('mbin-admins', userinfo: 'http://idp:8080/userinfo');

        self::assertFalse($policy->shouldPromote([], $this->owner(['groups' => ['mbin-admins']])));
    }

    public function testUserinfoGroupsAreUsedWhenTheEndpointIsHttps(): void
    {
        $policy = $this->policy('mbin-admins', userinfo: 'https://idp.test/userinfo');

        self::assertTrue($policy->shouldPromote([], $this->owner(['groups' => ['mbin-admins']])));
    }

    public function testASignedGroupClaimIsTrustedRegardlessOfTheUserinfoTransport(): void
    {
        $policy = $this->policy('mbin-admins', userinfo: 'http://idp:8080/userinfo');

        self::assertTrue($policy->shouldPromote(['groups' => ['mbin-admins']], $this->owner([])));
    }

    public function testANullGroupClaimInTheTokenCountsAsAbsent(): void
    {
        self::assertTrue($this->policy('mbin-admins')->shouldPromote(['groups' => null], $this->owner(['groups' => ['mbin-admins']])));
    }

    private function policy(?string $group, string $userinfo = 'https://idp.test/userinfo'): OidcAdminGroupPolicy
    {
        $resolver = new OidcMetadataResolver(
            new MockHttpClient([]),
            new ArrayAdapter(),
            'https://idp.test',
            'https://idp.test/authorize',
            'https://idp.test/token',
            $userinfo,
            'https://idp.test/jwks',
        );

        return new OidcAdminGroupPolicy($group, new OidcGroupClaims($resolver));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function owner(array $claims): OidcResourceOwner
    {
        return new OidcResourceOwner($claims + ['sub' => 'user-1'], 'preferred_username');
    }
}
