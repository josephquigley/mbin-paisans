<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Entity\Magazine;
use App\Repository\MagazineFollowRepository;
use App\Repository\MagazineRepository;

/**
 * Decides which magazine an incoming post belongs to once addressing has been
 * resolved.
 *
 * Extracted from ActivityPubManager so the decision can be tested without
 * standing up that class's 24 collaborators.
 */
readonly class MagazineFollowResolver
{
    public function __construct(
        private MagazineFollowRepository $magazineFollowRepository,
        private MagazineRepository $magazineRepository,
    ) {
    }

    public function resolve(?Magazine $addressed, ?string $deliveredBy, ?string $authorUrl, ?string $activityType = null): ?Magazine
    {
        // Software that models communities names one in audience/to/cc. That
        // answer always wins: the publisher was explicit.
        if (null !== $addressed) {
            return $addressed;
        }

        // Who delivered this is the question that matters. An actor that delivered and
        // signed an activity has asserted something about it, where attributedTo only
        // states who composed it. Asking about the author is what lost every post an
        // instance actor announced on behalf of the blogs it hosts.
        $following = $this->magazineFollowRepository->findMagazineFollowing($deliveredBy, $activityType);
        if (null !== $following) {
            return $following;
        }

        // Nothing delivered it, or nothing follows the deliverer. An object we fetched
        // ourselves has no delivering actor at all, and for that one the author is the
        // only claim there is.
        $following = $this->magazineFollowRepository->findMagazineFollowing($authorUrl, $activityType);
        if (null !== $following) {
            return $following;
        }

        return $this->magazineRepository->findOneByName('random');
    }
}
