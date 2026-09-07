<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MagazineFollowTest extends TestCase
{
    /**
     * A real User, not a mock: MagazineFollow reads the followed actor's type to pick
     * the kind the follow carries, and a mock leaves that typed property uninitialized.
     */
    private function user(string $type = 'Person'): User
    {
        return new User('actor@example.com', 'actor', 'secret', $type);
    }

    public function testFollowingAUserStoresItOnTheUserSide(): void
    {
        $magazine = $this->createMock(Magazine::class);
        $user = $this->user();

        $follow = new MagazineFollow($magazine, $user);

        self::assertSame($user, $follow->followingUser);
        self::assertNull($follow->followingMagazine);
        self::assertSame($user, $follow->getFollowingActor());
    }

    public function testFollowingAMagazineStoresItOnTheMagazineSide(): void
    {
        $magazine = $this->createMock(Magazine::class);
        $remote = $this->createMock(Magazine::class);

        $follow = new MagazineFollow($magazine, $remote);

        self::assertSame($remote, $follow->followingMagazine);
        self::assertNull($follow->followingUser);
        self::assertSame($remote, $follow->getFollowingActor());
    }

    public function testANewFollowStartsPending(): void
    {
        $follow = new MagazineFollow($this->createMock(Magazine::class), $this->user());

        self::assertSame(MagazineFollow::STATUS_PENDING, $follow->status);
    }
}
