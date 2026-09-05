<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Entity\Magazine;
use App\Repository\MagazineFollowRepository;
use App\Repository\MagazineRepository;
use App\Service\ActivityPub\MagazineFollowResolver;
use PHPUnit\Framework\TestCase;

class MagazineFollowResolverTest extends TestCase
{
    private MagazineFollowRepository $follows;
    private MagazineRepository $magazines;
    private MagazineFollowResolver $resolver;
    private Magazine $random;

    protected function setUp(): void
    {
        $this->follows = $this->createMock(MagazineFollowRepository::class);
        $this->magazines = $this->createMock(MagazineRepository::class);
        $this->resolver = new MagazineFollowResolver($this->follows, $this->magazines);
        $this->random = $this->createMock(Magazine::class);
    }

    public function testAnAddressedMagazineWinsAndTheFollowLookupIsNotConsulted(): void
    {
        $addressed = $this->createMock(Magazine::class);
        $this->follows->expects(self::never())->method('findMagazineFollowing');
        $this->magazines->expects(self::never())->method('findOneByName');

        self::assertSame($addressed, $this->resolver->resolve($addressed, 'https://blog.example/api/collections/quigs'));
    }

    public function testAFollowedActorRoutesToTheFollowingMagazine(): void
    {
        $following = $this->createMock(Magazine::class);
        $this->follows->method('findMagazineFollowing')
            ->with('https://blog.example/api/collections/quigs')
            ->willReturn($following);
        $this->magazines->expects(self::never())->method('findOneByName');

        self::assertSame($following, $this->resolver->resolve(null, 'https://blog.example/api/collections/quigs'));
    }

    public function testAnUnfollowedActorFallsBackToRandom(): void
    {
        $this->follows->method('findMagazineFollowing')->willReturn(null);
        $this->magazines->method('findOneByName')->with('random')->willReturn($this->random);

        self::assertSame($this->random, $this->resolver->resolve(null, 'https://elsewhere.example/users/nobody'));
    }

    public function testAMissingActorUrlFallsBackToRandom(): void
    {
        // Known limitation, spec section 2.6. A Group relays with Announce and
        // ChainActivityHandler passes only the inner object on, so the
        // announcer is invisible here and attributedTo names the original
        // author. Routing therefore cannot match the announcer's follow. This
        // test pins the gap: if it starts failing, announce handling changed
        // and the spec needs updating before this test does.
        $this->follows->method('findMagazineFollowing')->with(null)->willReturn(null);
        $this->magazines->method('findOneByName')->with('random')->willReturn($this->random);

        self::assertSame($this->random, $this->resolver->resolve(null, null));
    }
}
