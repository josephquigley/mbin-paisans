<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Outbox;

use App\Message\ActivityPub\Outbox\DeliverMessage;
use App\Service\DeliverManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group(name: 'ActivityPub')]
#[Group(name: 'NonThreadSafe')]
class ReadOnlyDeliveryTest extends WebTestCase
{
    private const string READ_ONLY_DOMAIN = 'readonly.example.com';
    private const string READ_ONLY_INBOX = 'https://readonly.example.com/inbox';

    private DeliverManager $deliverManager;
    private string $localActorId;

    public function setUp(): void
    {
        parent::setUp();

        $settings = $this->settingsManager->getDto();
        $settings->MBIN_USE_FEDERATION_ALLOW_LIST = true;
        $this->settingsManager->save($settings);

        $instance = $this->instanceRepository->getOrCreateInstance(self::READ_ONLY_DOMAIN);
        $this->instanceManager->allowInstanceFederation($instance);
        $this->instanceManager->markInstanceReadOnly($instance);

        $user = $this->getUserByUsername('alice');
        $this->localActorId = $this->personFactory->getActivityPubId($user);
        $this->testingApHttpClient->actorObjects[$this->localActorId] = $this->personFactory->create($user);

        $this->deliverManager = self::getContainer()->get(DeliverManager::class);
    }

    public function testACreateIsNotDeliveredToAReadOnlyInstance(): void
    {
        $this->deliverManager->deliver([self::READ_ONLY_INBOX], [
            'type' => 'Create',
            'actor' => $this->localActorId,
            'object' => ['type' => 'Note', 'id' => $this->localActorId.'/note/1', 'attributedTo' => $this->localActorId],
        ]);

        self::assertSame([], $this->testingApHttpClient->getPostedObjects());
    }

    public function testAFollowIsDeliveredToAReadOnlyInstance(): void
    {
        $this->deliverManager->deliver([self::READ_ONLY_INBOX], [
            'type' => 'Follow',
            'actor' => $this->localActorId,
            'object' => 'https://readonly.example.com/u/bob',
        ]);

        $posted = $this->testingApHttpClient->getPostedObjects();
        self::assertCount(1, $posted);
        self::assertSame('Follow', $posted[0]['payload']['type']);
        self::assertSame(self::READ_ONLY_INBOX, $posted[0]['inboxUrl']);
    }

    public function testADirectlyDispatchedDeleteIsNotDelivered(): void
    {
        // DeleteUserHandler dispatches its own DeliverMessage, bypassing DeliverManager
        $this->bus->dispatch(new DeliverMessage(self::READ_ONLY_INBOX, [
            'type' => 'Delete',
            'actor' => $this->localActorId,
            'object' => ['type' => 'Person', 'id' => $this->localActorId],
        ]));

        self::assertSame([], $this->testingApHttpClient->getPostedObjects());
    }

    public function testADirectlyDispatchedActorUpdateIsDelivered(): void
    {
        // UserRotatePrivateKeys dispatches this one and it has to get through: suppress it
        // and the remote instance keeps a stale key, which silently breaks every later
        // Follow and Undo we sign
        $this->bus->dispatch(new DeliverMessage(self::READ_ONLY_INBOX, [
            'type' => 'Update',
            'actor' => $this->localActorId,
            'object' => ['type' => 'Person', 'id' => $this->localActorId],
        ]));

        $posted = $this->testingApHttpClient->getPostedObjects();
        self::assertCount(1, $posted);
        self::assertSame('Update', $posted[0]['payload']['type']);
    }
}
