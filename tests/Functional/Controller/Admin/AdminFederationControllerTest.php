<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Tests\WebTestCase;

class AdminFederationControllerTest extends WebTestCase
{
    public function testAdminCanClearBannedInstances(): void
    {
        $instance = $this->instanceRepository->getOrCreateInstance('www.example.com');
        $this->instanceManager->banInstance($instance);

        $this->client->loginUser($this->getUserByUsername('admin', isAdmin: true));

        $crawler = $this->client->request('GET', '/admin/federation');

        $this->client->submit($crawler->filter('#content tr td button[type=submit]')->form());

        $this->assertSame(
            [],
            $this->settingsManager->getBannedInstances(),
        );
    }

    public function testMarkInstanceReadOnlyRequiresTheAllowList(): void
    {
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = false;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance('readonly.example.com');

        $this->expectException(\LogicException::class);
        $this->instanceManager->markInstanceReadOnly($instance);
    }

    public function testMarkInstanceReadOnlyRequiresAnAllowedInstance(): void
    {
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance('readonly.example.com');

        $this->expectException(\LogicException::class);
        $this->instanceManager->markInstanceReadOnly($instance);
    }

    public function testMarkInstanceReadOnlyAndBack(): void
    {
        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance('readonly.example.com');
        $this->instanceManager->allowInstanceFederation($instance);

        $this->instanceManager->markInstanceReadOnly($instance);
        self::assertTrue($instance->isReadOnly);
        // read-only never removes the allow: inbound has to keep working
        self::assertTrue($instance->isExplicitlyAllowed);

        $this->instanceManager->markInstanceReadWrite($instance);
        self::assertFalse($instance->isReadOnly);
    }
}
