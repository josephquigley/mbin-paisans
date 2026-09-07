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

    public function testNothingToNameIsNoNotice(): void
    {
        self::assertNull($this->runtime()->summarise([]));
    }

    public function testOneTargetIsNamedOnItsOwn(): void
    {
        self::assertSame('@someone@example.com', $this->runtime()->summarise(['@someone@example.com']));
    }

    public function testTwoTargetsAreJoinedWithOr(): void
    {
        // the shape the notice is written for: the actor, then the instance behind them
        self::assertSame(
            '@someone@example.com or example.com',
            $this->runtime()->summarise(['@someone@example.com', 'example.com']),
        );
    }

    public function testManyTargetsAreCutOffAndCounted(): void
    {
        $targets = ['@a@example.com', '@b@example.com', '@c@example.com', 'example.com'];

        self::assertSame(
            '@a@example.com or @b@example.com federation_not_delivered_more2',
            $this->runtime()->summarise($targets),
        );
    }

    public function testALongTargetIsTruncated(): void
    {
        $long = '@someone@a-very-long-hostname-indeed-that-keeps-going.example.com';

        $summary = $this->runtime()->summarise([$long]);

        self::assertStringEndsWith('…', $summary);
        self::assertLessThan(\strlen($long), \strlen($summary));
    }

    public function testTruncationDoesNotApplyToATargetThatFits(): void
    {
        self::assertStringNotContainsString('…', (string) $this->runtime()->summarise(['short.example.com']));
    }
}
