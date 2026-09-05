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

    public function testANullActorUrlFallsBackToRandom(): void
    {
        // Pins null-safety of the fallback chain: resolve() must tolerate a
        // null actor URL end to end and still land on 'random'.
        //
        // This is not a test of the Group/Announce gap recorded in spec
        // section 2.6 of specs/08-magazine-follows.md. In that scenario the
        // actor URL is not null: a relayed Announce still carries the
        // original author's id in attributedTo, and the gap is that no
        // magazine follows that author, which is the case already covered by
        // testAnUnfollowedActorFallsBackToRandom above. A resolver test
        // cannot detect the Group/Announce gap at all, since the announcing
        // actor is lost one level up in ChainActivityHandler, outside this
        // class's call graph. A real guard for that gap belongs in a test
        // over ChainActivityHandler and is out of scope here.
        $this->follows->method('findMagazineFollowing')->with(null)->willReturn(null);
        $this->magazines->method('findOneByName')->with('random')->willReturn($this->random);

        self::assertSame($this->random, $this->resolver->resolve(null, null));
    }
}
