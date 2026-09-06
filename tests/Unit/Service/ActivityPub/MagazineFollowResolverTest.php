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
    private const string DELIVERER = 'https://blog.example.com/api/collections/blog.example.com';
    private const string AUTHOR = 'https://blog.example.com/api/collections/quigs';

    private function magazine(string $name): Magazine
    {
        return new Magazine($name, ucfirst($name), null, null, null, false, false, null);
    }

    private function resolverFollowing(?string $followedUrl, ?Magazine $magazine, ?Magazine $random = null): MagazineFollowResolver
    {
        $follows = $this->createStub(MagazineFollowRepository::class);
        $follows->method('findMagazineFollowing')->willReturnCallback(
            fn (?string $url, ?string $activityType = null) => null !== $url && $url === $followedUrl ? $magazine : null
        );
        $magazines = $this->createStub(MagazineRepository::class);
        $magazines->method('findOneByName')->willReturn($random);

        return new MagazineFollowResolver($follows, $magazines);
    }

    public function testAnAddressedMagazineStillWins(): void
    {
        $addressed = $this->magazine('addressed');
        $resolver = $this->resolverFollowing(self::DELIVERER, $this->magazine('followed'));

        self::assertSame($addressed, $resolver->resolve($addressed, self::DELIVERER, self::AUTHOR, 'Announce'));
    }

    public function testItRoutesByTheDelivererNotTheAuthor(): void
    {
        // the observed bug: an instance actor announces a post written by a blog it
        // hosts, and only the instance actor is followed
        $magazine = $this->magazine('blog');
        $resolver = $this->resolverFollowing(self::DELIVERER, $magazine);

        self::assertSame($magazine, $resolver->resolve(null, self::DELIVERER, self::AUTHOR, 'Announce'));
    }

    public function testItFallsBackToTheAuthorWhenNobodyDelivered(): void
    {
        // an object we fetched ourselves has no delivering actor, so attributedTo is
        // the only claim available
        $magazine = $this->magazine('blog');
        $resolver = $this->resolverFollowing(self::AUTHOR, $magazine);

        self::assertSame($magazine, $resolver->resolve(null, null, self::AUTHOR, 'Create'));
    }

    public function testItFallsBackToTheAuthorWhenTheDelivererIsNotFollowed(): void
    {
        $magazine = $this->magazine('blog');
        $resolver = $this->resolverFollowing(self::AUTHOR, $magazine);

        self::assertSame($magazine, $resolver->resolve(null, self::DELIVERER, self::AUTHOR, 'Create'));
    }

    public function testItFallsThroughToRandomWhenNothingFollows(): void
    {
        $random = $this->magazine('random');
        $resolver = $this->resolverFollowing(null, null, $random);

        self::assertSame($random, $resolver->resolve(null, self::DELIVERER, self::AUTHOR, 'Create'));
    }

    public function testItReturnsNullWhenNothingFollowsAndThereIsNoRandom(): void
    {
        $resolver = $this->resolverFollowing(null, null, null);

        self::assertNull($resolver->resolve(null, self::DELIVERER, self::AUTHOR, 'Create'));
    }
}
