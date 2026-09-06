<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Magazine\Panel;

use App\Controller\Magazine\Panel\MagazineFollowController;
use PHPUnit\Framework\TestCase;

/**
 * The moderator panel accepts an actor in several shapes, and every one of them
 * has to end up as something findActorOrCreate can resolve.
 *
 * These are unit tests over the normalisation alone, reached by reflection: the
 * controller's constructor pulls in the whole federation stack, and standing
 * that up would test Symfony rather than the mapping under test.
 */
class MagazineFollowInputTest extends TestCase
{
    private function normalize(string $input): array
    {
        $method = new \ReflectionMethod(MagazineFollowController::class, 'normalizeActorInput');

        return $method->invoke(
            (new \ReflectionClass(MagazineFollowController::class))->newInstanceWithoutConstructor(),
            $input
        );
    }

    /**
     * A bare host with no path is how an instance-wide actor is addressed, and
     * how someone naturally types "follow this whole site".
     *
     * This is the regression test for a real failure. The bare host previously
     * fell through to the absolute-URL case and became "https://host", so mbin
     * fetched the site's HTML home page, found no actor, and the moderator was
     * told the host might be unreachable while it had in fact answered 200.
     */
    public function testABareHostBecomesTheInstanceActorHandle(): void
    {
        [$normalized, $derivedFromUrl] = $this->normalize('blog.beta.paisans.community');

        self::assertSame('@blog.beta.paisans.community@blog.beta.paisans.community', $normalized);
        self::assertTrue($derivedFromUrl, 'a derived handle should be reported as derived, so a failure can be explained in terms of what was looked up');
    }

    /**
     * The same host with a scheme and a trailing slash is the address bar copy
     * of the same thing, and must resolve identically.
     */
    public function testABareHostWithSchemeAndTrailingSlashBehavesTheSame(): void
    {
        [$normalized] = $this->normalize('https://blog.beta.paisans.community/');

        self::assertSame('@blog.beta.paisans.community@blog.beta.paisans.community', $normalized);
    }

    /**
     * One path segment is a blog or user profile, not the instance. This is the
     * case that already worked, pinned so the new branch above cannot swallow
     * it.
     */
    public function testASinglePathSegmentIsStillAProfileHandle(): void
    {
        [$normalized] = $this->normalize('https://blog.beta.paisans.community/quigs/');

        self::assertSame('@quigs@blog.beta.paisans.community', $normalized);
    }

    /**
     * An actor id has more than one path segment and must be passed through
     * untouched, because it is already the thing to resolve.
     */
    public function testAnActorIdIsPassedThrough(): void
    {
        [$normalized, $derivedFromUrl] = $this->normalize('https://blog.beta.paisans.community/api/collections/quigs');

        self::assertSame('https://blog.beta.paisans.community/api/collections/quigs', $normalized);
        self::assertFalse($derivedFromUrl);
    }

    /**
     * A handle typed either way stays a handle.
     */
    public function testAHandleIsLeftAloneApartFromItsLeadingAt(): void
    {
        self::assertSame('@quigs@example.com', $this->normalize('@quigs@example.com')[0]);
        self::assertSame('@quigs@example.com', $this->normalize('quigs@example.com')[0]);
    }
}
