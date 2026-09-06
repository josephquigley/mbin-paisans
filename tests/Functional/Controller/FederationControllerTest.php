<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\WebTestCase;

class FederationControllerTest extends WebTestCase
{
    public function testTheFederationPageMarksReadOnlyInstancesForAnonymousVisitors(): void
    {
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $settings->KBIN_FEDERATION_PAGE_ENABLED = true;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance('readonly.example.com');
        $this->instanceManager->allowInstanceFederation($instance);
        $this->instanceManager->markInstanceReadOnly($instance);

        $crawler = $this->client->request('GET', '/federation');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('readonly.example.com', $crawler->html());
        // the badge must not sit behind the admin guard: members read this page
        self::assertStringContainsString('Read only', $crawler->html());
    }
}
