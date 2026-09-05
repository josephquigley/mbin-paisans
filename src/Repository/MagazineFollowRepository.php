<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagazineFollow>
 */
class MagazineFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagazineFollow::class);
    }

    /**
     * The magazine, if any, that follows the actor at this URL.
     *
     * Both actor tables are checked because Mbin stores a Group actor as a
     * Magazine and every other actor type as a User.
     */
    public function findMagazineFollowing(?string $actorUrl): ?Magazine
    {
        if (null === $actorUrl) {
            return null;
        }

        $result = $this->createQueryBuilder('mf')
            ->leftJoin('mf.followingUser', 'u')
            ->leftJoin('mf.followingMagazine', 'm')
            ->where('u.apProfileId = :url OR m.apProfileId = :url')
            ->setParameter('url', $actorUrl)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result?->magazine;
    }

    /**
     * @return MagazineFollow[]
     */
    public function findByMagazine(Magazine $magazine): array
    {
        return $this->findBy(['magazine' => $magazine], ['createdAt' => 'DESC']);
    }

    /**
     * Whether the magazine already follows this actor, regardless of status.
     */
    public function findOneByMagazineAndActor(Magazine $magazine, User|Magazine $actor): ?MagazineFollow
    {
        return $this->findOneBy(
            $actor instanceof User
                ? ['magazine' => $magazine, 'followingUser' => $actor]
                : ['magazine' => $magazine, 'followingMagazine' => $actor]
        );
    }
}
