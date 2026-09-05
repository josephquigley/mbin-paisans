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

    public function resolve(?Magazine $addressed, ?string $actorUrl): ?Magazine
    {
        // Software that models communities names one in audience/to/cc. That
        // answer always wins: the publisher was explicit.
        if (null !== $addressed) {
            return $addressed;
        }

        // Nothing was addressed. Does a magazine carry this actor? This is what
        // lets a magazine follow an actor that names no magazine, which is most
        // of the fediverse.
        $following = $this->magazineFollowRepository->findMagazineFollowing($actorUrl);
        if (null !== $following) {
            return $following;
        }

        return $this->magazineRepository->findOneByName('random');
    }
}
