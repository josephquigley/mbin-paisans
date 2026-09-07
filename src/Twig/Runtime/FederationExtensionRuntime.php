<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Service\OutboundFederationPolicy;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;

class FederationExtensionRuntime implements RuntimeExtensionInterface
{
    /**
     * How many hosts the notice names before it stops counting them out. A reply is
     * addressed to a handful of actors at most, so a longer list is noise: what the
     * member needs is that some of it goes nowhere, not a roll call.
     */
    public const int MAX_HOSTS = 2;

    /**
     * Where a single hostname is cut. Long enough for an ordinary domain and a
     * subdomain, short enough that two of them still fit on one line.
     */
    public const int MAX_HOST_LENGTH = 30;

    public function __construct(
        private readonly OutboundFederationPolicy $policy,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The read only hosts a reply written here would be addressed to, summarised, or null
     * when there are none.
     *
     * The subject is the thread's own post or entry and the parent is the comment being
     * replied to, if any. Both contribute their author, and both contribute the mentions
     * already carried in the thread, because a mention is delivered by the same code path
     * as a reply and is suppressed by the same rule.
     */
    public function readOnlyReplyNotice(
        Entry|Post|EntryComment|PostComment|null $subject,
        Entry|Post|EntryComment|PostComment|null $parent = null,
    ): ?string {
        $handles = [];
        foreach ([$parent, $subject] as $source) {
            if (null === $source) {
                continue;
            }

            $handles[] = $source->user->username;
            foreach ($source->mentions ?: [] as $mention) {
                $handles[] = $mention;
            }
        }

        return $this->summarise($this->policy->readOnlyHostsAmong($handles));
    }

    /**
     * Turn the hosts into the string the notice shows.
     *
     * Public because it is the display rule rather than an implementation detail: what
     * gets truncated, and at what point a list stops being useful, is the part worth
     * pinning down in a test.
     *
     * @param string[] $hosts
     */
    public function summarise(array $hosts): ?string
    {
        if (!$hosts) {
            return null;
        }

        $shown = array_map(
            fn (string $host) => mb_strlen($host) > self::MAX_HOST_LENGTH
                ? mb_substr($host, 0, self::MAX_HOST_LENGTH - 1).'…'
                : $host,
            \array_slice($hosts, 0, self::MAX_HOSTS)
        );

        $summary = implode(', ', $shown);

        $remaining = \count($hosts) - \count($shown);
        if ($remaining > 0) {
            $summary .= ' '.$this->translator->trans('federation_not_delivered_more', ['%count%' => $remaining]);
        }

        return $summary;
    }
}
