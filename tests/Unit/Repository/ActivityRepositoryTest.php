<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Activity;
use App\Tests\WebTestCase;

class ActivityRepositoryTest extends WebTestCase
{
    public function testFindAllActivitiesByTypeObjectAndActorDoesNotCollideParameters(): void
    {
        $userA = $this->getUserByUsername('activityRepoUserA', addImage: false);
        $userB = $this->getUserByUsername('activityRepoUserB', addImage: false);

        $follow = new Activity('Follow');
        $follow->userActor = $userA;
        $follow->setObject($userB);

        $this->entityManager->persist($follow);
        $this->entityManager->flush();

        // A followed B: the object of the activity is B, the actor is A.
        // The object filter and the actor filter used to bind the same
        // Doctrine parameter name ("user"), so the second setParameter()
        // call silently overwrote the first and the query only ever matched
        // activities where the object and the actor were the same user.
        $results = $this->activityRepository->findAllActivitiesByTypeObjectAndActor('Follow', $userB, $userA);

        self::assertNotEmpty($results, 'Expected to find the Follow activity from userA to userB.');
        self::assertSame($follow->uuid, $results[0]->uuid);
    }
}
