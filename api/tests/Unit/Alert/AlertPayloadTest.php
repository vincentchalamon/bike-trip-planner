<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alert;

use App\Alert\AlertPayload;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertPayloadTest extends TestCase
{
    #[Test]
    public function buildsTheCommonShapeInAFixedOrder(): void
    {
        $payload = AlertPayload::of(new Alert(
            code: AlertCode::WATER_POINT_GAP,
            type: AlertType::NUDGE,
            messageKey: 'alert.water.nudge',
            parameters: ['%threshold%' => 30000],
            parameterFormats: ['%threshold%' => 'distance'],
            lat: 48.1,
            lon: 2.1,
            action: new AlertAction(AlertActionKind::NAVIGATE, 'alert.water.action', ['lat' => 48.1, 'lon' => 2.1]),
        ));

        self::assertSame([
            'code' => 'water_point_gap',
            'type' => 'nudge',
            'messageKey' => 'alert.water.nudge',
            'parameters' => ['%threshold%' => 30000],
            'parameterFormats' => ['%threshold%' => 'distance'],
            'lat' => 48.1,
            'lon' => 2.1,
            'action' => ['kind' => 'navigate', 'labelKey' => 'alert.water.action', 'payload' => ['lat' => 48.1, 'lon' => 2.1]],
        ], $payload);
    }

    /**
     * @return iterable<string, array{AlertActionKind, bool}>
     */
    public static function actionKinds(): iterable
    {
        yield 'navigate is wired' => [AlertActionKind::NAVIGATE, true];
        yield 'dismiss is wired' => [AlertActionKind::DISMISS, true];
        yield 'auto_fix is not' => [AlertActionKind::AUTO_FIX, false];
        yield 'detour is not' => [AlertActionKind::DETOUR, false];
    }

    /**
     * Issue #397: a kind the frontend does not wire would render a dead button, so it is not
     * delivered. Before the builder existed only the terrain analyzers went through that
     * filter; a handler building its array by hand would have shipped any kind.
     */
    #[Test]
    #[DataProvider('actionKinds')]
    public function deliversOnlyTheActionKindsTheFrontendWires(AlertActionKind $kind, bool $delivered): void
    {
        $payload = AlertPayload::of(new Alert(
            code: AlertCode::FERRY_CROSSING,
            type: AlertType::WARNING,
            messageKey: 'alert.ferry.warning',
            action: new AlertAction($kind, 'alert.ferry.action'),
        ));

        self::assertSame($delivered, \array_key_exists('action', $payload));
    }

    #[Test]
    public function forStageNamesTheStageAndKeepsExtrasFromOverridingCommonFields(): void
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 3,
            distance: 50.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.1, 2.1),
        );

        $payload = AlertPayload::forStage($stage, new Alert(
            code: AlertCode::CULTURAL_POI_SUGGESTION,
            type: AlertType::NUDGE,
            messageKey: 'alert.cultural_poi.suggestion',
            lat: 48.05,
            lon: 2.05,
        ), ['poiName' => 'Château', 'lat' => 0.0]);

        self::assertSame($stage->id, $payload['stageId']);
        self::assertSame(3, $payload['dayNumber']);
        self::assertSame(48.05, $payload['lat']);
        self::assertSame('Château', $payload['poiName']);
        self::assertSame(['stageId', 'dayNumber', 'code', 'type', 'messageKey', 'parameters', 'parameterFormats', 'lat', 'lon', 'poiName'], array_keys($payload));
    }
}
