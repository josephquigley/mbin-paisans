<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\InstanceRepository;
use Psr\Log\LoggerInterface;

/**
 * Decides what may be delivered to an instance the admin has marked read only.
 *
 * A read only instance is one we federate with inbound (it is on the allow list) but
 * which must not receive anything we produce. Only the follow handshake and updates of
 * our own actors are allowed out, so that following it keeps working and a key rotation
 * still reaches it.
 *
 * This does not hide a reply from an instance that can reach it some other way: an
 * activity addressed to a remote actor is still delivered to every other allowed
 * instance, any of which may relay it onward.
 */
readonly class OutboundFederationPolicy
{
    /**
     * The types an Update may carry to a read only instance. Everything else is content.
     */
    public const array ACTOR_TYPES = ['Person', 'Application', 'Group', 'Service'];

    public function __construct(
        private SettingsManager $settingsManager,
        private InstanceRepository $instanceRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the built ActivityPub JSON that would be delivered
     */
    public function mayDeliver(string $inboxUrl, array $payload): bool
    {
        if (!$this->isReadOnlyInstance($inboxUrl)) {
            return true;
        }

        $type = $payload['type'] ?? null;

        $allowed = match ($type) {
            'Follow' => true,
            'Undo', 'Accept', 'Reject' => 'Follow' === ($payload['object']['type'] ?? null),
            'Update' => $this->isOwnActorUpdate($payload),
            default => false,
        };

        if (!$allowed) {
            $this->logger->debug('not delivering a {type} to {url}, the instance is marked read only', [
                'type' => \is_string($type) ? $type : 'activity without a type',
                'url' => $inboxUrl,
            ]);
        }

        return $allowed;
    }

    /**
     * Whether the instance hosting this URL is allowed to federate but marked read only.
     *
     * A URL whose host cannot be parsed is treated as read only rather than throwing,
     * because the caller is deciding whether to disclose something. Note that
     * SettingsManager::isBannedInstance() throws on the same input: it answers a different
     * question, and a filter that exists for privacy has to fail closed.
     */
    public function isReadOnlyInstance(string $url): bool
    {
        if (!$this->settingsManager->getUseAllowList()) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host)) {
            $this->logger->error('OutboundFederationPolicy: unable to parse a host from {url}, treating it as read only', ['url' => $url]);

            return true;
        }

        // the same normalisation SettingsManager::isBannedInstance() applies, so that marking
        // example.org covers www.example.org exactly as banning it would
        $domain = str_replace('www.', '', $host);
        $instance = $this->instanceRepository->findOneBy(['domain' => $domain]);

        return null !== $instance && $instance->isReadOnly;
    }

    /**
     * An Update may go out only when its object is one of our own actors.
     *
     * Both halves are required. The type alone would let through an actor typed object
     * belonging to somebody else, and the locality alone would let through any local
     * object, which is every post the community writes.
     *
     * @param array<string, mixed> $payload
     */
    private function isOwnActorUpdate(array $payload): bool
    {
        $object = $payload['object'] ?? null;
        if (!\is_array($object)) {
            return false;
        }

        $type = $object['type'] ?? null;
        $id = $object['id'] ?? null;

        return \is_string($type)
            && \in_array($type, self::ACTOR_TYPES, true)
            && \is_string($id)
            && $this->settingsManager->isLocalUrl($id);
    }
}
