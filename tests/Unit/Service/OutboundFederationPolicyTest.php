<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Instance;
use App\Repository\InstanceRepository;
use App\Service\OutboundFederationPolicy;
use App\Service\SettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutboundFederationPolicyTest extends TestCase
{
    private const string INBOX = 'https://readonly.example.com/inbox';

    private function policy(bool $useAllowList, bool $instanceIsReadOnly): OutboundFederationPolicy
    {
        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('getUseAllowList')->willReturn($useAllowList);
        $settingsManager->method('isLocalUrl')->willReturnCallback(
            fn (string $url) => str_starts_with($url, 'https://local.example.com/')
        );

        $instance = new Instance('readonly.example.com');
        $instance->isExplicitlyAllowed = true;
        $instance->isReadOnly = $instanceIsReadOnly;

        $instanceRepository = $this->createStub(InstanceRepository::class);
        $instanceRepository->method('findOneBy')->willReturn($instance);

        return new OutboundFederationPolicy($settingsManager, $instanceRepository, $this->createStub(LoggerInterface::class));
    }

    public function testFollowIsDelivered(): void
    {
        self::assertTrue($this->policy(true, true)->mayDeliver(self::INBOX, ['type' => 'Follow']));
    }

    public function testUndoOfAFollowIsDelivered(): void
    {
        $payload = ['type' => 'Undo', 'object' => ['type' => 'Follow']];
        self::assertTrue($this->policy(true, true)->mayDeliver(self::INBOX, $payload));
    }

    public function testAcceptAndRejectOfAFollowAreDelivered(): void
    {
        $policy = $this->policy(true, true);
        self::assertTrue($policy->mayDeliver(self::INBOX, ['type' => 'Accept', 'object' => ['type' => 'Follow']]));
        self::assertTrue($policy->mayDeliver(self::INBOX, ['type' => 'Reject', 'object' => ['type' => 'Follow']]));
    }

    public function testUndoOfSomethingElseIsSuppressed(): void
    {
        $policy = $this->policy(true, true);
        self::assertFalse($policy->mayDeliver(self::INBOX, ['type' => 'Undo', 'object' => ['type' => 'Like']]));
        self::assertFalse($policy->mayDeliver(self::INBOX, ['type' => 'Undo', 'object' => ['type' => 'Announce']]));
    }

    public function testUpdateOfOurOwnActorIsDelivered(): void
    {
        $policy = $this->policy(true, true);
        $person = ['type' => 'Update', 'object' => ['type' => 'Person', 'id' => 'https://local.example.com/u/alice']];
        $group = ['type' => 'Update', 'object' => ['type' => 'Group', 'id' => 'https://local.example.com/m/news']];
        self::assertTrue($policy->mayDeliver(self::INBOX, $person));
        self::assertTrue($policy->mayDeliver(self::INBOX, $group));
    }

    public function testUpdateOfARemoteActorIsSuppressed(): void
    {
        $payload = ['type' => 'Update', 'object' => ['type' => 'Person', 'id' => 'https://elsewhere.example.org/u/bob']];
        self::assertFalse($this->policy(true, true)->mayDeliver(self::INBOX, $payload));
    }

    public function testUpdateOfLocalContentIsSuppressed(): void
    {
        $policy = $this->policy(true, true);
        $page = ['type' => 'Update', 'object' => ['type' => 'Page', 'id' => 'https://local.example.com/e/1']];
        $note = ['type' => 'Update', 'object' => ['type' => 'Note', 'id' => 'https://local.example.com/p/1']];
        self::assertFalse($policy->mayDeliver(self::INBOX, $page));
        self::assertFalse($policy->mayDeliver(self::INBOX, $note));
    }

    public function testAnUpdateWhoseObjectIsAStringIsSuppressed(): void
    {
        $payload = ['type' => 'Update', 'object' => 'https://local.example.com/u/alice'];
        self::assertFalse($this->policy(true, true)->mayDeliver(self::INBOX, $payload));
    }

    public function testContentActivitiesAreSuppressed(): void
    {
        $policy = $this->policy(true, true);
        foreach (['Create', 'Delete', 'Announce', 'Like', 'Dislike', 'Flag', 'Block', 'Add', 'Remove', 'Lock'] as $type) {
            self::assertFalse(
                $policy->mayDeliver(self::INBOX, ['type' => $type, 'object' => ['type' => 'Note', 'id' => 'https://local.example.com/p/1']]),
                "a $type should not be delivered to a read only instance"
            );
        }
    }

    public function testAPayloadWithoutATypeIsSuppressed(): void
    {
        self::assertFalse($this->policy(true, true)->mayDeliver(self::INBOX, []));
    }

    public function testAnInstanceThatIsNotReadOnlyReceivesEverything(): void
    {
        $policy = $this->policy(true, false);
        self::assertTrue($policy->mayDeliver(self::INBOX, ['type' => 'Create']));
        self::assertTrue($policy->mayDeliver(self::INBOX, ['type' => 'Announce']));
    }

    public function testTheFlagIsIgnoredWhenTheAllowListIsOff(): void
    {
        $policy = $this->policy(false, true);
        self::assertTrue($policy->mayDeliver(self::INBOX, ['type' => 'Create']));
        self::assertFalse($policy->isReadOnlyInstance(self::INBOX));
    }

    public function testAnUnknownInstanceIsNotReadOnly(): void
    {
        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('getUseAllowList')->willReturn(true);
        $instanceRepository = $this->createStub(InstanceRepository::class);
        $instanceRepository->method('findOneBy')->willReturn(null);
        $policy = new OutboundFederationPolicy($settingsManager, $instanceRepository, $this->createStub(LoggerInterface::class));

        self::assertFalse($policy->isReadOnlyInstance(self::INBOX));
    }

    public function testAMalformedUrlFailsClosed(): void
    {
        // a URL we cannot attribute to an instance must not be cleared for delivery
        self::assertTrue($this->policy(true, false)->isReadOnlyInstance('not a url'));
        self::assertFalse($this->policy(true, false)->mayDeliver('not a url', ['type' => 'Create']));
    }

    public function testTheWwwPrefixIsNormalisedTheSameWayABanIs(): void
    {
        $instance = new Instance('readonly.example.com');
        $instance->isExplicitlyAllowed = true;
        $instance->isReadOnly = true;

        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('getUseAllowList')->willReturn(true);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['domain' => 'readonly.example.com'])
            ->willReturn($instance);

        $policy = new OutboundFederationPolicy($settingsManager, $instanceRepository, $this->createStub(LoggerInterface::class));

        self::assertTrue($policy->isReadOnlyInstance('https://www.readonly.example.com/inbox'));
    }
}
