<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\WebTestCase;

class PushPromptTest extends WebTestCase
{
    public function testPromptIsShownToALoggedInUser(): void
    {
        $this->client->loginUser($this->getUserByUsername('JohnDoe'));

        $this->client->request('GET', '/');

        $this->assertSelectorExists('[data-controller="push-prompt"]');
    }

    public function testPromptIsNotShownToAnAnonymousVisitor(): void
    {
        $this->client->request('GET', '/');

        $this->assertSelectorNotExists('[data-controller="push-prompt"]');
    }

    public function testDecliningThePromptHidesItForGood(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);

        $this->client->request('POST', '/ajax/decline_push_prompt');

        self::assertResponseIsSuccessful();

        $this->entityManager->refresh($user);
        self::assertTrue($user->pushPromptDeclined);

        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('[data-controller="push-prompt"]');
    }

    public function testDecliningRequiresAuthentication(): void
    {
        $this->client->request('POST', '/ajax/decline_push_prompt');

        self::assertFalse($this->client->getResponse()->isSuccessful());
    }
}
