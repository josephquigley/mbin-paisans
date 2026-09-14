<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ImageManager;
use App\Service\SettingsManager;
use App\Twig\Runtime\FormattingExtensionRuntime;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ImageDownloadAcceptHeaderTest extends TestCase
{
    /**
     * The downloaded file is validated with getimagesize(), which reads none of these.
     * A content negotiating CDN answers with whatever the Accept header offers, so
     * advertising one of them loses the image: the response is discarded as corrupted.
     */
    public function testDownloadDoesNotAdvertiseFormatsItCannotValidate(): void
    {
        $accept = $this->captureAcceptHeader();

        self::assertStringNotContainsString('image/jxl', $accept);
        self::assertStringNotContainsString('image/heic', $accept);
        self::assertStringNotContainsString('image/heif', $accept);
        self::assertStringNotContainsString('image/webp', $accept);
        self::assertStringNotContainsString('image/avif', $accept);
    }

    public function testDownloadStillAdvertisesFormatsItCanValidate(): void
    {
        $accept = $this->captureAcceptHeader();

        self::assertStringContainsString('image/jpeg', $accept);
        self::assertStringContainsString('image/png', $accept);
        self::assertStringContainsString('image/gif', $accept);
    }

    private function captureAcceptHeader(): string
    {
        $accept = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$accept): MockResponse {
            foreach ($options['headers'] as $header) {
                if (str_starts_with(mb_strtolower($header), 'accept:')) {
                    $accept = mb_substr($header, \strlen('accept:'));
                }
            }

            return new MockResponse('not a real image');
        });

        $manager = new ImageManager(
            'https://example.com/media',
            $this->createStub(FilesystemOperator::class),
            $httpClient,
            $this->createStub(MimeTypesInterface::class),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(SettingsManager::class),
            $this->createStub(FormattingExtensionRuntime::class),
            0.8,
            $this->createStub(CacheManager::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $manager->download('https://example.com/cdn/some-content-negotiated-image');

        self::assertNotNull($accept, 'the download request sent no Accept header');

        return $accept;
    }
}
