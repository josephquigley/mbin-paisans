<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Oidc;

use App\Provider\OidcResourceOwner;
use App\Service\Oidc\OidcGroupClaims;
use App\Service\Oidc\OidcMemberGroupPolicy;
use App\Service\Oidc\OidcMetadataResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The member gate reads the same claim as the admin policy and reaches the
 * opposite conclusion from silence: where an unanswerable question means "no
 * promotion" there, it means "no entry" here.
 */
class OidcMemberGroupPolicyTest extends TestCase
{
    public function testEveryoneIsAdmittedWhenNoGroupIsConfigured(): void
    {
        $policy = $this->policy(null);

        self::assertFalse($policy->isEnabled());
        self::assertTrue($policy->permits([], $this->owner([])));
        self::assertTrue($policy->permits(['groups' => []], $this->owner([])));
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

    public function testAdmitsOnTheIdTokenClaim(): void
    {
        self::assertTrue($this->policy('members')->permits(['groups' => ['members', 'mbin-admins']], $this->owner([])));
    }

    public function testRefusesWhenTheGroupIsAbsentFromTheClaim(): void
    {
        self::assertFalse($this->policy('members')->permits(['groups' => ['mbin-admins']], $this->owner([])));
    }

    public function testFallsBackToUserinfoWhenTheIdTokenCarriesNoGroupsClaim(): void
    {
        self::assertTrue($this->policy('members')->permits([], $this->owner(['groups' => ['members']])));
    }

    public function testANullGroupClaimInTheTokenCountsAsAbsent(): void
    {
        self::assertTrue($this->policy('members')->permits(['groups' => null], $this->owner(['groups' => ['members']])));
    }

    /**
     * A present but empty claim is the provider answering the question. It
     * must not send us looking for a more agreeable answer in userinfo.
     */
    public function testAnEmptyIdTokenClaimIsNotOverriddenByUserinfo(): void
    {
        self::assertFalse($this->policy('members')->permits(['groups' => []], $this->owner(['groups' => ['members']])));
    }

    public function testAMalformedClaimRefuses(): void
    {
        $policy = $this->policy('members');

        self::assertFalse($policy->permits(['groups' => 'members'], $this->owner([])));
        self::assertFalse($policy->permits(['groups' => [['members']]], $this->owner([])));
    }

    public function testMatchingIsExactAndCaseSensitive(): void
    {
        $policy = $this->policy('members');

        self::assertFalse($policy->permits(['groups' => ['Members']], $this->owner([])));
        self::assertFalse($policy->permits(['groups' => ['members-readonly']], $this->owner([])));
    }

    /**
     * The one place the two policies genuinely differ. An unsigned userinfo
     * response cannot appoint an administrator, and it cannot admit a member
     * either. Here the consequence of not being able to read the claim is a
     * refusal rather than a shrug.
     */
    public function testRefusesWhenTheClaimIsOnlyInAnUntrustedUserinfoResponse(): void
    {
        $policy = $this->policy('members', userinfo: 'http://idp:8080/userinfo');

        self::assertFalse($policy->permits([], $this->owner(['groups' => ['members']])));
    }

    public function testUserinfoGroupsAreUsedWhenTheEndpointIsHttps(): void
    {
        $policy = $this->policy('members', userinfo: 'https://idp.test/userinfo');

        self::assertTrue($policy->permits([], $this->owner(['groups' => ['members']])));
    }

    public function testASignedGroupClaimIsTrustedRegardlessOfTheUserinfoTransport(): void
    {
        $policy = $this->policy('members', userinfo: 'http://idp:8080/userinfo');

        self::assertTrue($policy->permits(['groups' => ['members']], $this->owner([])));
    }

    /**
     * The failure this gate exists to survive being wrong about: a provider
     * that stops emitting the claim entirely must refuse, not admit. Every
     * other test here would still pass if the gate failed open.
     */
    public function testRefusesWhenNobodyAnswersTheQuestionAtAll(): void
    {
        $policy = $this->policy('members', userinfo: 'http://idp:8080/userinfo');

        self::assertFalse($policy->permits([], $this->owner([])));
    }

    private function policy(?string $group, string $userinfo = 'https://idp.test/userinfo'): OidcMemberGroupPolicy
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

        return new OidcMemberGroupPolicy($group, new OidcGroupClaims($resolver));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function owner(array $claims): OidcResourceOwner
    {
        return new OidcResourceOwner($claims + ['sub' => 'user-1'], 'preferred_username');
    }
}
