<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Contracts\VisibilityInterface;
use App\Entity\MagazineFollow;
use App\Entity\User;
use App\Enum\MagazineFollowKind;
use App\Service\ActivityPub\Note;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The instance-actor case, observed live on 2026-09-06: blog.beta announced two posts
 * from its instance actor, each post attributed to the individual blog that wrote it,
 * and both were dropped because routing asked who wrote them rather than who delivered
 * them.
 */
#[Group(name: 'NonThreadSafe')]
class DeliveryScopedRoutingTest extends WebTestCase
{
    private const string INSTANCE_ACTOR = 'https://blog.example.com/api/collections/blog.example.com';
    private const string BLOG_ACTOR = 'https://blog.example.com/api/collections/quigs';

    private function announcedPost(): array
    {
        // no audience, to or cc naming a magazine: WriteFreely addresses followers
        return [
            'id' => 'https://blog.example.com/api/posts/ax4nbuxcvt',
            'type' => 'Article',
            'attributedTo' => self::BLOG_ACTOR,
        ];
    }

    private function remoteActor(string $username, string $profileId, string $type): User
    {
        $user = new User($username.'@example.com', $username, 'secret', $type);
        $user->apProfileId = $profileId;
        // ap_id is uniquely indexed, so each remote actor needs its own
        $user->apId = $username.'@blog.example.com';
        $user->apInboxUrl = $profileId.'/inbox';
        $user->apPublicUrl = $profileId;
        $user->apFollowersUrl = $profileId.'/followers';
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function testAPostAnnouncedByAFollowedInstanceActorIsRoutedToThatMagazine(): void
    {
        $magazine = $this->getMagazineByName('blog');
        $instanceActor = $this->remoteActor('instanceactor', self::INSTANCE_ACTOR, 'Application');
        // nothing follows the blog that wrote the post, which is the whole point
        $this->remoteActor('quigs', self::BLOG_ACTOR, 'Person');

        $follow = new MagazineFollow($magazine, $instanceActor);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $resolved = $this->activityPubManager->findOrCreateMagazineByToCCAndAudience(
            $this->announcedPost(),
            self::INSTANCE_ACTOR,
            'Announce'
        );

        self::assertNotNull($resolved, 'the announced post should have resolved to a magazine');
        self::assertSame($magazine->getId(), $resolved->getId());
    }

    public function testAnUntitledPostAnnouncedByAFollowedInstanceActorBecomesAMicroblogPost(): void
    {
        // A WriteFreely post with no title arrives as a Note, which Note::createPost()
        // handles. That is a different method from Note::create(), so the delivering
        // actor has to be threaded into it as well.
        $magazine = $this->getMagazineByName('blog');
        $instanceActor = $this->remoteActor('instanceactor', self::INSTANCE_ACTOR, 'Application');
        $this->remoteActor('quigs', self::BLOG_ACTOR, 'Person');

        $follow = new MagazineFollow($magazine, $instanceActor);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $note = [
            'id' => 'https://blog.example.com/api/posts/sbmvvvjz9j',
            'type' => 'Note',
            'attributedTo' => self::BLOG_ACTOR,
            'content' => 'a single line with no title',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [],
            'published' => '2026-09-06T23:10:00Z',
        ];

        $post = self::getContainer()->get(Note::class)->create($note, deliveredBy: self::INSTANCE_ACTOR, activityType: 'Announce');

        self::assertSame($magazine->getId(), $post->magazine->getId());
    }

    public function testAFollowersOnlyPostStaysPrivateEvenWhenAMagazineFollowsTheSender(): void
    {
        // Founder decision, 2026-09-06: an unlisted blog's posts are followers-only on
        // the wire, and a magazine follow must not widen that audience. Routing decides
        // WHICH magazine an object belongs to. It says nothing about WHO may see it, and
        // this test exists so that stays true.
        $magazine = $this->getMagazineByName('blog');
        $blogActor = $this->remoteActor('unlisted', self::BLOG_ACTOR, 'Person');

        $follow = new MagazineFollow($magazine, $blogActor);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $followersOnly = [
            'id' => 'https://blog.example.com/api/posts/unlisted1',
            'type' => 'Note',
            'attributedTo' => self::BLOG_ACTOR,
            'content' => 'a post from an unlisted blog',
            // exactly what WriteFreely puts on the wire for an unlisted collection:
            // the followers collection in `to`, and the Public collection in neither field
            'to' => [self::BLOG_ACTOR.'/followers'],
            'cc' => [],
            'published' => '2026-09-06T23:20:00Z',
        ];

        $post = self::getContainer()->get(Note::class)->create($followersOnly, deliveredBy: self::BLOG_ACTOR, activityType: 'Create');

        self::assertSame($magazine->getId(), $post->magazine->getId(), 'it should still be routed to the magazine');
        self::assertSame(VisibilityInterface::VISIBILITY_PRIVATE, $post->visibility, 'a followers-only post must stay private');
    }

    public function testAFollowOnAnApplicationActorDefaultsToCarryingAnnounces(): void
    {
        $magazine = $this->getMagazineByName('blog');
        $instanceActor = $this->remoteActor('instanceactor', self::INSTANCE_ACTOR, 'Application');

        $follow = new MagazineFollow($magazine, $instanceActor);

        self::assertSame(MagazineFollowKind::Both, $follow->kind);
    }

    public function testAFollowThatCarriesOnlyCreatesDoesNotTakeAnAnnounce(): void
    {
        $magazine = $this->getMagazineByName('blog');
        $instanceActor = $this->remoteActor('instanceactor', self::INSTANCE_ACTOR, 'Application');
        $this->remoteActor('quigs', self::BLOG_ACTOR, 'Person');

        $follow = new MagazineFollow($magazine, $instanceActor);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $follow->kind = MagazineFollowKind::Create;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $resolved = $this->activityPubManager->findOrCreateMagazineByToCCAndAudience(
            $this->announcedPost(),
            self::INSTANCE_ACTOR,
            'Announce'
        );

        // no random magazine exists in the test fixtures, so an unrouted object resolves
        // to nothing, which is the normal unrouted path rather than a special case
        self::assertNull($resolved);
    }

    public function testTheSameFollowStillTakesACreate(): void
    {
        $magazine = $this->getMagazineByName('blog');
        $instanceActor = $this->remoteActor('instanceactor', self::INSTANCE_ACTOR, 'Application');
        $this->remoteActor('quigs', self::BLOG_ACTOR, 'Person');

        $follow = new MagazineFollow($magazine, $instanceActor);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $follow->kind = MagazineFollowKind::Create;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        $resolved = $this->activityPubManager->findOrCreateMagazineByToCCAndAudience(
            $this->announcedPost(),
            self::INSTANCE_ACTOR,
            'Create'
        );

        self::assertNotNull($resolved);
        self::assertSame($magazine->getId(), $resolved->getId());
    }
}
