<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Entity\Magazine;
use App\Entity\User;
use App\Enum\MagazineFollowKind;
use PHPUnit\Framework\TestCase;

class MagazineFollowKindTest extends TestCase
{
    private function user(string $type): User
    {
        return new User('actor@example.com', 'actor', 'password', $type);
    }

    private function magazine(): Magazine
    {
        return new Magazine('grp', 'Group', null, null, null, false, false, null);
    }

    public function testCarries(): void
    {
        self::assertTrue(MagazineFollowKind::Create->carries('Create'));
        self::assertFalse(MagazineFollowKind::Create->carries('Announce'));
        self::assertTrue(MagazineFollowKind::Announce->carries('Announce'));
        self::assertFalse(MagazineFollowKind::Announce->carries('Create'));
        self::assertTrue(MagazineFollowKind::Both->carries('Create'));
        self::assertTrue(MagazineFollowKind::Both->carries('Announce'));
    }

    public function testAnUnknownActivityTypeIsCarriedByNothing(): void
    {
        foreach (MagazineFollowKind::cases() as $kind) {
            self::assertFalse($kind->carries('Like'), "$kind->value should not carry a Like");
        }
    }

    public function testDefaultForAnInstanceActorCarriesBoth(): void
    {
        // an instance actor may relay by announcing or deliver directly, and both on
        // means the follow works either way. Founder decision, 2026-09-06
        self::assertSame(MagazineFollowKind::Both, MagazineFollowKind::defaultFor($this->user('Application')));
        self::assertSame(MagazineFollowKind::Both, MagazineFollowKind::defaultFor($this->user('Service')));
    }

    public function testDefaultForAPersonIsCreateOnly(): void
    {
        // carrying their boosts would fill a magazine with third-party content
        self::assertSame(MagazineFollowKind::Create, MagazineFollowKind::defaultFor($this->user('Person')));
    }

    public function testDefaultForAGroupIsAnnounceOnly(): void
    {
        self::assertSame(MagazineFollowKind::Announce, MagazineFollowKind::defaultFor($this->magazine()));
    }
}
