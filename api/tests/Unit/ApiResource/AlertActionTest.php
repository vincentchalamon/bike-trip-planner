<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertActionTest extends TestCase
{
    #[Test]
    public function alertActionHasCorrectProperties(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::AUTO_FIX,
            label: 'Split stage',
            payload: ['splitAt' => 45.0],
        );

        $this->assertSame(AlertActionKind::AUTO_FIX, $action->kind);
        $this->assertSame('Split stage', $action->label);
        $this->assertSame(['splitAt' => 45.0], $action->payload);
    }

    #[Test]
    public function alertActionDefaultsToEmptyPayload(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::DISMISS,
            label: 'Dismiss',
        );

        $this->assertSame([], $action->payload);
    }

    #[Test]
    public function alertActionKindEnumValues(): void
    {
        $this->assertSame('auto_fix', AlertActionKind::AUTO_FIX->value);
        $this->assertSame('detour', AlertActionKind::DETOUR->value);
        $this->assertSame('navigate', AlertActionKind::NAVIGATE->value);
        $this->assertSame('dismiss', AlertActionKind::DISMISS->value);
    }

    #[Test]
    public function alertActionKindFromString(): void
    {
        $this->assertSame(AlertActionKind::AUTO_FIX, AlertActionKind::from('auto_fix'));
        $this->assertSame(AlertActionKind::DETOUR, AlertActionKind::from('detour'));
        $this->assertSame(AlertActionKind::NAVIGATE, AlertActionKind::from('navigate'));
        $this->assertSame(AlertActionKind::DISMISS, AlertActionKind::from('dismiss'));
    }

    #[Test]
    public function alertWithAction(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::NAVIGATE,
            label: 'Zoom to location',
            payload: ['lat' => 44.6, 'lon' => 4.5],
        );

        $alert = new Alert(
            type: AlertType::WARNING,
            message: 'Steep gradient detected',
            lat: 44.6,
            lon: 4.5,
            action: $action,
        );

        $this->assertSame(AlertType::WARNING, $alert->type);
        $this->assertSame('Steep gradient detected', $alert->message);
        $this->assertNotNull($alert->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alert->action->kind);
        $this->assertSame('Zoom to location', $alert->action->label);
        $this->assertSame(['lat' => 44.6, 'lon' => 4.5], $alert->action->payload);
    }

    #[Test]
    public function alertWithoutAction(): void
    {
        $alert = new Alert(
            type: AlertType::WARNING,
            message: 'Route non goudronnée sur 3km',
        );

        $this->assertNull($alert->action);
    }

    #[Test]
    public function alertActionWithComplexPayload(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::AUTO_FIX,
            label: 'Apply fix',
            payload: [
                'stageIndex' => 0,
                'adjustments' => ['splitAt' => 45.0, 'newTarget' => 60.0],
                'reason' => 'ebike_range',
            ],
        );

        $this->assertSame(AlertActionKind::AUTO_FIX, $action->kind);
        $this->assertArrayHasKey('stageIndex', $action->payload);
        $this->assertArrayHasKey('adjustments', $action->payload);
        $this->assertArrayHasKey('reason', $action->payload);
    }
}
