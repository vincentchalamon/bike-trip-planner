<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\State\TripCollectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the pure `computeStatus` logic via reflection so every branch of
 * the derivation table documented on the method is covered. The helper has no
 * constructor dependencies, so we bypass Pagination/EntityManager wiring.
 */
final class TripCollectionProviderTest extends TestCase
{
    /**
     * @param array<string, string>|null $statuses
     */
    #[Test]
    #[DataProvider('computeStatusProvider')]
    public function computeStatusMatchesDocumentedRules(
        ?array $statuses,
        int $stageCount,
        string $expected,
    ): void {
        $provider = new \ReflectionClass(TripCollectionProvider::class)
            ->newInstanceWithoutConstructor();

        $ref = new \ReflectionMethod($provider, 'computeStatus');
        $result = $ref->invoke($provider, $statuses, $stageCount);

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{0: array<string, string>|null, 1: int, 2: string}>
     */
    public static function computeStatusProvider(): iterable
    {
        yield 'null statuses with no stages → draft' => [null, 0, 'draft'];

        yield 'empty statuses with no stages → draft' => [[], 0, 'draft'];

        yield 'null statuses but stages persisted → analyzed (TTL-expired fallback)' => [null, 2, 'analyzed'];

        yield 'empty statuses but stages persisted → analyzed (TTL-expired fallback)' => [[], 5, 'analyzed'];

        yield 'any pending → analyzing' => [
            ['route' => 'pending', 'stages' => 'done'],
            3,
            'analyzing',
        ];

        yield 'any running → analyzing' => [
            ['route' => 'done', 'stages' => 'running'],
            3,
            'analyzing',
        ];

        yield 'at least one done with stages → analyzed' => [
            ['route' => 'done', 'stages' => 'failed'],
            3,
            'analyzed',
        ];

        yield 'all failed with no stages → draft' => [
            ['route' => 'failed', 'stages' => 'failed'],
            0,
            'draft',
        ];

        yield 'all failed but some stages persisted → analyzed' => [
            ['route' => 'failed', 'stages' => 'failed'],
            2,
            'analyzed',
        ];

        yield 'mixed done + failed → analyzed' => [
            ['route' => 'done', 'stages' => 'failed', 'weather' => 'done'],
            5,
            'analyzed',
        ];
    }
}
