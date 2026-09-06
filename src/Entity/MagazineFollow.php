<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Traits\CreatedAtTrait;
use App\Repository\MagazineFollowRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity(repositoryClass: MagazineFollowRepository::class)]
#[Table]
#[UniqueConstraint(name: 'magazine_follow_user_idx', columns: ['magazine_id', 'following_user_id'])]
#[UniqueConstraint(name: 'magazine_follow_magazine_idx', columns: ['magazine_id', 'following_magazine_id'])]
class MagazineFollow
{
    use CreatedAtTrait {
        CreatedAtTrait::__construct as createdAtTraitConstruct;
    }

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_REJECTED = 'rejected';

    #[Column(type: 'string', nullable: false, options: ['default' => self::STATUS_PENDING])]
    public string $status = self::STATUS_PENDING;

    #[ManyToOne(targetEntity: Magazine::class)]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Magazine $magazine;

    // Exactly one of the two below is set. Mbin stores a Group actor as a
    // Magazine and every other actor type as a User, so "an actor" cannot be a
    // single foreign key.
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?User $followingUser = null;

    #[ManyToOne(targetEntity: Magazine::class)]
    #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Magazine $followingMagazine = null;

    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private int $id;

    public function __construct(Magazine $magazine, User|Magazine $following)
    {
        $this->createdAtTraitConstruct();

        $this->magazine = $magazine;
        if ($following instanceof User) {
            $this->followingUser = $following;
        } else {
            $this->followingMagazine = $following;
        }
    }

    public function getFollowingActor(): User|Magazine
    {
        return $this->followingUser ?? $this->followingMagazine;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
