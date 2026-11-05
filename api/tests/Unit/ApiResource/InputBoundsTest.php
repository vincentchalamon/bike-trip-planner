<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\StageManualAccommodationRequest;
use App\ApiResource\StageRequest;
use App\ApiResource\TripBatchRecomputeRequest;
use App\ApiResource\TripModification;
use App\ApiResource\TripRequest;
use App\Enum\TripModificationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Upper bounds on the write DTOs: each unbounded field was either a 500 at the database or a
 * loop sized by the caller.
 */
final class InputBoundsTest extends TestCase
{
    #[Test]
    public function aBatchOfAHundredModificationsIsAccepted(): void
    {
        self::assertCount(0, $this->validator()->validate(new TripBatchRecomputeRequest($this->modifications(100))));
    }

    #[Test]
    public function aBatchPastAHundredModificationsIsRefused(): void
    {
        self::assertCount(1, $this->validator()->validate(new TripBatchRecomputeRequest($this->modifications(101))));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function outOfRangePoints(): iterable
    {
        yield 'latitude above 90' => [90.5, 3.0];
        yield 'latitude below -90' => [-91.0, 3.0];
        yield 'longitude above 180' => [45.0, 180.5];
        yield 'longitude below -180' => [45.0, -181.0];
    }

    #[Test]
    #[DataProvider('outOfRangePoints')]
    public function aStagePointOutsideTheGlobeIsRefused(float $lat, float $lon): void
    {
        $request = new StageRequest();
        $request->startPoint = new Coordinate(45.0, 3.0);
        $request->endPoint = new Coordinate($lat, $lon);

        self::assertCount(1, $this->validator()->validate($request));
    }

    #[Test]
    public function aStageOnTheGlobeIsAccepted(): void
    {
        $request = new StageRequest();
        $request->startPoint = new Coordinate(-90.0, -180.0);
        $request->endPoint = new Coordinate(90.0, 180.0);
        $request->label = str_repeat('a', 255);

        self::assertCount(0, $this->validator()->validate($request));
    }

    #[Test]
    public function anOverlongStageLabelIsRefused(): void
    {
        $request = new StageRequest();
        $request->label = str_repeat('a', 256);

        self::assertCount(1, $this->validator()->validate($request));
    }

    #[Test]
    public function anOverlongManualAccommodationIsRefused(): void
    {
        $request = new StageManualAccommodationRequest();
        $request->name = str_repeat('a', 256);
        $request->address = str_repeat('a', 501);

        self::assertCount(2, $this->validator()->validate($request));
    }

    #[Test]
    public function aManualAccommodationAtTheBoundsIsAccepted(): void
    {
        $request = new StageManualAccommodationRequest();
        $request->name = str_repeat('a', 255);
        $request->address = str_repeat('a', 500);

        self::assertCount(0, $this->validator()->validate($request));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function manualAccommodationUrls(): iterable
    {
        yield 'https' => ['https://booking.example/abc', 0];
        yield 'scheme-less, read as https' => ['www.gite.fr', 0];
        yield 'blank, read as none' => ['  ', 0];
        yield 'javascript scheme' => ['javascript:alert(1)', 1];
        yield 'data scheme' => ['data:text/html,x', 1];
        yield 'userinfo' => ['https://user@gite.fr', 1];
        yield 'free text' => ['voir le site', 1];
        yield 'overlong' => ['https://gite.fr/'.str_repeat('a', 2040), 1];
    }

    #[Test]
    #[DataProvider('manualAccommodationUrls')]
    public function aManualAccommodationUrlMustBeHttp(string $url, int $expectedViolations): void
    {
        $request = new StageManualAccommodationRequest();
        $request->name = 'Gite';
        $request->address = '1 rue Test, Lyon';
        $request->url = $url;

        $violations = $this->validator()->validate($request);

        self::assertCount($expectedViolations, $violations);
        if (0 < $expectedViolations) {
            self::assertSame('url', $violations[0]?->getPropertyPath());
        }
    }

    /**
     * @return iterable<string, array{list<string>|null}>
     */
    public static function validationGroups(): iterable
    {
        yield 'update (Default)' => [null];
        yield 'creation' => [['Default', 'trip_request:create']];
    }

    /**
     * @param list<string>|null $groups
     */
    #[Test]
    #[DataProvider('validationGroups')]
    public function aTripLongerThanTheMaximumIsRefused(?array $groups): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/1';
        $request->startDate = new \DateTimeImmutable('2026-06-01');
        $request->endDate = new \DateTimeImmutable('9999-12-31');

        $violations = $this->validator()->validate($request, null, $groups);

        self::assertCount(1, $violations);
        self::assertSame('endDate', $violations[0]?->getPropertyPath());
    }

    #[Test]
    public function aTripOfExactlyTheMaximumIsAccepted(): void
    {
        $start = new \DateTimeImmutable('2026-01-01');
        $request = new TripRequest();
        $request->startDate = $start;
        $request->endDate = $start->modify(\sprintf('+%d days', TripRequest::MAX_TRIP_DAYS - 1));

        self::assertFalse($request->exceedsMaxDuration());
        self::assertCount(0, $this->validator()->validate($request));

        $request->endDate = $start->modify(\sprintf('+%d days', TripRequest::MAX_TRIP_DAYS));
        self::assertTrue($request->exceedsMaxDuration());
    }

    /**
     * @return list<TripModification>
     */
    private function modifications(int $count): array
    {
        return array_map(static fn (): TripModification => new TripModification(type: TripModificationType::PACING), range(1, $count));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
