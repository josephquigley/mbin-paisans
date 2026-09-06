<?php

declare(strict_types=1);

namespace App\Provider;

use App\Service\Oidc\OidcMetadataResolver;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * A provider for any OpenID Connect issuer, configured rather than compiled in.
 *
 * Note what is absent: this class does not override getPkceMethod(). PKCE is
 * handled by App\Security\Oidc\OidcClient, which persists the code verifier
 * across the two requests of the flow. If this class generated a challenge as
 * well, the two would not agree and every token exchange would fail.
 */
class Oidc extends AbstractProvider
{
    use BearerAuthorizationTrait;

    private OidcMetadataResolver $metadataResolver;
    private string $usernameClaim;
    private bool $requestGroups;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        $this->metadataResolver = $options['metadata_resolver'];
        $usernameClaim = $options['username_claim'] ?? null;
        $this->usernameClaim = \is_string($usernameClaim) && '' !== $usernameClaim ? $usernameClaim : 'preferred_username';

        // The groups scope is only asked for when something actually reads a
        // group. Requesting a scope an instance has no use for would show the
        // person a consent screen listing access nobody needs. Either reader
        // is reason enough, and the member gate in particular refuses every
        // login when the claim it reads was never requested.
        $this->requestGroups = '' !== trim((string) ($options['admin_group'] ?? ''))
            || '' !== trim((string) ($options['member_group'] ?? ''));

        unset($options['metadata_resolver'], $options['username_claim'], $options['admin_group'], $options['member_group']);

        parent::__construct($options, $collaborators);
    }

    public function getBaseAuthorizationUrl(): string
    {
        return $this->metadataResolver->resolve()->authorizationEndpoint;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->metadataResolver->resolve()->tokenEndpoint;
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return $this->metadataResolver->resolve()->userinfoEndpoint;
    }

    /**
     * @return string[]
     */
    protected function getDefaultScopes(): array
    {
        $scopes = ['openid', 'profile', 'email'];

        if ($this->requestGroups) {
            $scopes[] = 'groups';
        }

        return $scopes;
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * @param array<string, mixed>|string $data
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (\is_array($data) && !empty($data['error'])) {
            $message = $data['error_description'] ?? $data['error'];

            throw new IdentityProviderException(htmlentities((string) $message, ENT_QUOTES, 'UTF-8'), $response->getStatusCode(), $response);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function createResourceOwner(array $response, AccessToken $token): OidcResourceOwner
    {
        return new OidcResourceOwner($response, $this->usernameClaim);
    }
}
