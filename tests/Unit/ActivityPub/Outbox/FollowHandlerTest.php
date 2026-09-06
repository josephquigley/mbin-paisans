<?php

declare(strict_types=1);

namespace App\Tests\Unit\ActivityPub\Outbox;

use App\Message\ActivityPub\Outbox\FollowMessage;
use App\MessageHandler\ActivityPub\Outbox\FollowHandler;
use App\Tests\ActivityPubTestCase;

class FollowHandlerTest extends ActivityPubTestCase
{
    public function testRefollowMagazineAfterUnfollowCreatesNewActivity(): void
    {
        $followHandler = $this->getContainer()->get(FollowHandler::class);

        $followHandler->doWork(new FollowMessage($this->user->getId(), $this->magazine->getId(), magazine: true));
        $followHandler->doWork(new FollowMessage($this->user->getId(), $this->magazine->getId(), unfollow: true, magazine: true));
        $followHandler->doWork(new FollowMessage($this->user->getId(), $this->magazine->getId(), magazine: true));

        $followActivities = $this->activityRepository->findAllActivitiesByTypeObjectAndActor('Follow', $this->magazine, $this->user);

        self::assertCount(2, $followActivities, 'expected the original Follow and a fresh one after the refollow');
        self::assertNotSame(
            $followActivities[0]->uuid->toString(),
            $followActivities[1]->uuid->toString(),
            'refollowing after an unfollow must not resend the id of the already-undone Follow'
        );
    }
}
