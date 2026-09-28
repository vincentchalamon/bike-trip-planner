<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ApiResource\Stage;
use App\Enum\AlertGroup;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripStageStoreInterface;

/**
 * Pins, byte for byte, the alert arrays a producer hands to the database and to Mercure.
 *
 * Both copies are compared as JSON against `tests/fixtures/alert-payloads/<name>.json`, key
 * order and int/float distinction included, so a refactoring of how alerts are built shows
 * up as a fixture diff rather than passing unnoticed. Stage identities are random UUIDs, so
 * they are replaced by `stage:<dayNumber>` before the comparison.
 *
 * Regenerate after an intended change with `ALERT_PAYLOAD_SNAPSHOT_UPDATE=1`, then review
 * the fixture diff.
 */
trait AlertPayloadSnapshotTrait
{
    /**
     * @param list<Stage>                         $stages
     * @param array<string, array<string, mixed>> $persisted filled with [group => [stageId => alerts]]
     */
    private function recordingStageStore(array $stages, array &$persisted): TripStageStoreInterface
    {
        $store = $this->createStub(TripStageStoreInterface::class);
        $store->method('getStages')->willReturn($stages);
        $store->method('updateTripAlertsForGroup')->willReturnCallback(
            static function (string $tripId, AlertGroup $group, array $alertsByStageId) use (&$persisted): void {
                $persisted[$group->value] = $alertsByStageId;
            },
        );
        $store->method('updateStageAlertsForGroup')->willReturnCallback(
            static function (string $tripId, string $stageId, AlertGroup $group, array $alerts) use (&$persisted): void {
                $persisted[$group->value][$stageId] = $alerts;
            },
        );

        return $store;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $published
     */
    private function recordingPublisher(array &$published): TripUpdatePublisherInterface
    {
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            static function (string $tripId, MercureEventType $type, array $data = []) use (&$published): void {
                $published[] = ['type' => $type->value, 'data' => $data];
            },
        );

        return $publisher;
    }

    /**
     * @param list<Stage> $stages
     */
    private function assertAlertPayloadSnapshot(string $name, array $stages, mixed $persisted, mixed $published): void
    {
        $json = json_encode(
            ['persisted' => $persisted, 'published' => $published],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        )."\n";

        foreach ($stages as $stage) {
            $json = str_replace($stage->id, 'stage:'.$stage->dayNumber, $json);
        }

        $file = __DIR__.'/../fixtures/alert-payloads/'.$name.'.json';
        if ('1' === getenv('ALERT_PAYLOAD_SNAPSHOT_UPDATE')) {
            @mkdir(\dirname($file), 0o777, true);
            file_put_contents($file, $json);
        }

        self::assertFileExists($file);
        self::assertSame(file_get_contents($file), $json);
    }
}
