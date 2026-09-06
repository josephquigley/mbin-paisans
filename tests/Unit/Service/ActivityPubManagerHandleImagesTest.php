<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Image;
use App\Factory\ActivityPub\PersonFactory;
use App\Factory\MagazineFactory;
use App\Factory\UserFactory;
use App\Repository\ApActivityRepository;
use App\Repository\EntryRepository;
use App\Repository\ImageRepository;
use App\Repository\InstanceRepository;
use App\Repository\MagazineRepository;
use App\Repository\UserRepository;
use App\Service\ActivityPub\ApHttpClientInterface;
use App\Service\ActivityPub\MagazineFollowResolver;
use App\Service\ActivityPub\Webfinger\WebFingerFactory;
use App\Service\ActivityPubManager;
use App\Service\EntryManager;
use App\Service\ImageManagerInterface;
use App\Service\MagazineManager;
use App\Service\MentionManager;
use App\Service\RemoteInstanceManager;
use App\Service\SettingsManager;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * handleImages() must not fatal when the image cannot be stored.
 *
 * ImageRepository::findOrCreateFromPath() is declared ": ?Image" and returns null
 * on any storage failure (an unwritable media directory is the case seen in
 * production), logging an error of its own. The caller then assigned a property
 * on the result before checking it for null, which raises an \Error. \Error is
 * not an \Exception, so the surrounding catch did not stop it and it escaped
 * into the message handler.
 */
class ActivityPubManagerHandleImagesTest extends TestCase
{
    private function manager(ImageManagerInterface $imageManager, ImageRepository $imageRepository): ActivityPubManager
    {
        return new ActivityPubManager(
            $this->createMock(ApActivityRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(UserManager::class),
            $this->createMock(UserFactory::class),
            $this->createMock(MagazineManager::class),
            $this->createMock(MagazineFactory::class),
            $this->createMock(MagazineRepository::class),
            $this->createMock(ApHttpClientInterface::class),
            $imageRepository,
            $imageManager,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(PersonFactory::class),
            $this->createMock(SettingsManager::class),
            $this->createMock(WebFingerFactory::class),
            $this->createMock(MentionManager::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(RateLimiterFactoryInterface::class),
            $this->createMock(EntryRepository::class),
            $this->createMock(EntryManager::class),
            $this->createMock(RemoteInstanceManager::class),
            $this->createMock(InstanceRepository::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(MagazineFollowResolver::class),
        );
    }

    private function attachment(): array
    {
        return [[
            'type' => 'Image',
            'mediaType' => 'image/png',
            'url' => 'https://remote.example/fileserver/attachment.png',
            'name' => 'a test square',
        ]];
    }

    /**
     * The regression: a storable download whose repository call returns null.
     *
     * Before the fix this raised
     * Error: Attempt to assign property "sourceUrl" on null.
     */
    public function testReturnsNullWhenTheImageCannotBeStored(): void
    {
        $imageManager = $this->createMock(ImageManagerInterface::class);
        $imageManager->method('download')->willReturn('/tmp/downloaded.png');

        $imageRepository = $this->createMock(ImageRepository::class);
        $imageRepository->method('findOrCreateFromPath')->willReturn(null);

        self::assertNull($this->manager($imageManager, $imageRepository)->handleImages($this->attachment()));
    }

    /**
     * The happy path is unchanged: sourceUrl and altText are still both set.
     */
    public function testStoresSourceUrlAndAltTextWhenTheImageIsCreated(): void
    {
        $image = new Image('attachment.png', '07/85/attachment.png', hash('sha256', 'test'), null, null, null);

        $imageManager = $this->createMock(ImageManagerInterface::class);
        $imageManager->method('download')->willReturn('/tmp/downloaded.png');

        $imageRepository = $this->createMock(ImageRepository::class);
        $imageRepository->method('findOrCreateFromPath')->willReturn($image);

        $result = $this->manager($imageManager, $imageRepository)->handleImages($this->attachment());

        self::assertSame($image, $result);
        self::assertSame('https://remote.example/fileserver/attachment.png', $result->sourceUrl);
        self::assertSame('a test square', $result->altText);
    }

    /**
     * A download that yields nothing is a separate path and already returned null.
     * Asserted so the null-guard above is not mistaken for covering this one.
     */
    public function testReturnsNullWhenTheDownloadFails(): void
    {
        $imageManager = $this->createMock(ImageManagerInterface::class);
        $imageManager->method('download')->willReturn(null);

        $imageRepository = $this->createMock(ImageRepository::class);
        $imageRepository->expects($this->never())->method('findOrCreateFromPath');

        self::assertNull($this->manager($imageManager, $imageRepository)->handleImages($this->attachment()));
    }
}
