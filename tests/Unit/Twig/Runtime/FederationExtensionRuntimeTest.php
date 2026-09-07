<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Runtime;

use App\Service\OutboundFederationPolicy;
use App\Twig\Runtime\FederationExtensionRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class FederationExtensionRuntimeTest extends TestCase
{
    private function runtime(): FederationExtensionRuntime
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            // the catalogue is not loaded here, so stand in for it: the key plus whatever count it was given
            fn (string $id, array $parameters = []) => $id.($parameters['%count%'] ?? '')
        );

        return new FederationExtensionRuntime($this->createStub(OutboundFederationPolicy::class), $translator);
    }

    public function testNoHostsIsNoNotice(): void
    {
        self::assertNull($this->runtime()->summarise([]));
    }

    public function testOneHostIsNamedInFull(): void
    {
        self::assertSame('example.com', $this->runtime()->summarise(['example.com']));
    }

    public function testTwoHostsAreBothNamed(): void
    {
        self::assertSame('a.example.com, b.example.com', $this->runtime()->summarise(['a.example.com', 'b.example.com']));
    }

    public function testManyHostsAreCutOffAndCounted(): void
    {
        $hosts = ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com'];

        self::assertSame('a.example.com, b.example.com federation_not_delivered_more2', $this->runtime()->summarise($hosts));
    }

    public function testALongHostIsTruncated(): void
    {
        $long = 'a-very-long-hostname-indeed-that-keeps-going.example.com';

        $summary = $this->runtime()->summarise([$long]);

        self::assertStringEndsWith('…', $summary);
        self::assertLessThan(\strlen($long), \strlen($summary));
    }

    public function testTruncationDoesNotApplyToAHostThatFits(): void
    {
        self::assertStringNotContainsString('…', (string) $this->runtime()->summarise(['short.example.com']));
    }
}
