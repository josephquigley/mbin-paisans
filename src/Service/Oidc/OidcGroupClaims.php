<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Provider\OidcResourceOwner;

/**
 * Reads the group list a login arrived with, from the most trustworthy source
 * that carries one.
 *
 * The claim is read from the id_token first, which has been verified, and only
 * then from the userinfo response. The userinfo response is not signed, so it
 * is only as trustworthy as the transport it arrived over: it is consulted
 * only when the userinfo endpoint is HTTPS. An admin who overrides the
 * endpoint with a plain http:// address on a container network keeps working
 * logins, but that response cannot decide who is an administrator or who is a
 * member.
 *
 * This class deliberately does not decide what an unanswerable question means.
 * It returns null, and each policy applies its own default to that: the admin
 * policy declines to promote, the member gate refuses the login. Putting the
 * two opposite defaults behind one shared reader is the whole reason it is
 * separate from either of them.
 */
class OidcGroupClaims
{
    public function __construct(
        private readonly OidcMetadataResolver $metadataResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $idTokenClaims
     *
     * @return string[]|null the group names the provider vouched for, or null
     *                       when no trustworthy source answered at all
     */
    public function resolve(array $idTokenClaims, OidcResourceOwner $resourceOwner): ?array
    {
        $groups = self::groupsIn($idTokenClaims);

        if (null !== $groups) {
            return $groups;
        }

        if (!$this->userinfoIsTrusted()) {
            return null;
        }

        return $resourceOwner->getGroups();
    }

    public function userinfoIsTrusted(): bool
    {
        try {
            $endpoint = $this->metadataResolver->resolve()->userinfoEndpoint;
        } catch (\Throwable) {
            return false;
        }

        return str_starts_with(strtolower($endpoint), 'https://');
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return string[]|null null when the claim is absent or null, which is
     *                       different from present and empty: absent means
     *                       look further
     */
    private static function groupsIn(array $claims): ?array
    {
        $groups = $claims['groups'] ?? null;

        if (null === $groups) {
            return null;
        }

        if (!\is_array($groups)) {
            return [];
        }

        return array_values(array_filter($groups, 'is_string'));
    }
}
