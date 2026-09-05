<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Repository\SiteRepository;
use App\Repository\UserPushSubscriptionRepository;
use App\Service\Notification\UserPushSubscriptionManager;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserPushSubscriptionManagerTest extends TestCase
{
    public function testVapidSubjectIsAMailtoUriWhenAContactEmailIsConfigured(): void
    {
        $manager = $this->managerWithSettings(domain: 'example.org', contactEmail: 'admin@example.org');

        self::assertSame('mailto:admin@example.org', $manager->getVapidSubject());
    }

    public function testVapidSubjectFallsBackToAnHttpsUriWhenNoContactEmailIsConfigured(): void
    {
        $manager = $this->managerWithSettings(domain: 'example.org', contactEmail: '');

        self::assertSame('https://example.org', $manager->getVapidSubject());
    }

    /**
     * RFC 8292 section 2.1 requires the "sub" claim of a VAPID token to be a
     * "mailto:" or "https:" URI. Apple's push service rejects anything else
     * with 403 BadJwtToken, so a bare domain must never be returned.
     */
    public function testVapidSubjectIsNeverABareDomain(): void
    {
        foreach ([null, '', '   '] as $contactEmail) {
            $manager = $this->managerWithSettings(domain: 'example.org', contactEmail: $contactEmail);

            self::assertStringStartsWith('https://', $manager->getVapidSubject());
        }
    }

    private function managerWithSettings(string $domain, ?string $contactEmail): UserPushSubscriptionManager
    {
        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('get')->willReturnMap([
            ['KBIN_DOMAIN', $domain],
            ['KBIN_CONTACT_EMAIL', $contactEmail],
        ]);

        return new UserPushSubscriptionManager(
            $settingsManager,
            $this->createStub(SiteRepository::class),
            $this->createStub(UserPushSubscriptionRepository::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }
}
