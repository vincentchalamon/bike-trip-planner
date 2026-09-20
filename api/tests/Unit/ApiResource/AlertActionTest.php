<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\Tests\Unit\AlertMessageTestTrait;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertActionTest extends TestCase
{
    use AlertMessageTestTrait;

    #[Test]
    public function alertActionHasCorrectProperties(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::AUTO_FIX,
            labelKey: 'Split stage',
            payload: ['splitAt' => 45.0],
        );

        $this->assertSame(AlertActionKind::AUTO_FIX, $action->kind);
        $this->assertSame('Split stage', $action->labelKey);
        $this->assertSame(['splitAt' => 45.0], $action->payload);
    }

    #[Test]
    public function alertActionDefaultsToEmptyPayload(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::DISMISS,
            labelKey: 'Dismiss',
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
            labelKey: 'alert.steep_gradient.action',
            payload: ['lat' => 44.6, 'lon' => 4.5],
        );

        $alert = new Alert(
            code: AlertCode::STEEP_GRADIENT,
            type: AlertType::WARNING,
            messageKey: 'alert.steep_gradient.warning',
            lat: 44.6,
            lon: 4.5,
            action: $action,
        );

        $this->assertSame(AlertType::WARNING, $alert->type);
        $this->assertSame('alert.steep_gradient.warning', $alert->messageKey);
        $this->assertNotNull($alert->action);
        $this->assertSame(AlertActionKind::NAVIGATE, $alert->action->kind);
        $this->assertSame('alert.steep_gradient.action', $alert->action->labelKey);
        $this->assertSame(['lat' => 44.6, 'lon' => 4.5], $alert->action->payload);
    }

    #[Test]
    public function alertWithoutAction(): void
    {
        $alert = new Alert(
            code: AlertCode::SURFACE_ROUGH,
            type: AlertType::WARNING,
            messageKey: 'alert.surface.warning',
        );

        $this->assertNull($alert->action);
    }

    #[Test]
    public function alertActionWithComplexPayload(): void
    {
        $action = new AlertAction(
            kind: AlertActionKind::AUTO_FIX,
            labelKey: 'Apply fix',
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
