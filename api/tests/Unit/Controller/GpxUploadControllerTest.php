<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\Controller\GpxUploadController;
use App\Entity\User;
use App\RouteParser\RouteTooLargeException;
use App\Service\GpxUploadServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

final class GpxUploadControllerTest extends TestCase
{
    private User $user;

    private ?string $tmp = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new User('gpx@example.com');
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->tmp) {
            @unlink($this->tmp);
        }
    }

    #[Test]
    public function uploadIsRateLimited(): void
    {
        // SEC-006: a valid upload must be throttled per user once the limiter is exhausted.
        $limiter = $this->limiter(1);
        $limiter->create($this->user->getId()->toRfc4122())->consume();

        $gpxService = $this->createMock(GpxUploadServiceInterface::class);
        // The parse is the expensive part: a throttled client must not get one.
        $gpxService->expects($this->never())->method('parseGpx');
        $gpxService->expects($this->never())->method('createTrip');

        // Answered, not thrown: every other 429 in the API is problem+json, and an
        // exception out of this plain Symfony route would not have been.
        $response = $this->upload($gpxService, $limiter);

        $body = json_decode((string) $response->getContent(), true);
        \assert(\is_array($body));

        self::assertSame(429, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame(429, $body['status']);
    }

    #[Test]
    public function aFailedUploadDoesNotEchoTheServerLimits(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gpx');
        self::assertIsString($tmp);
        $this->tmp = $tmp;
        $file = new UploadedFile($tmp, 'route.gpx', null, \UPLOAD_ERR_INI_SIZE, true);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);
        $controller = new GpxUploadController($this->createStub(GpxUploadServiceInterface::class), $security, $this->limiter(1), new NativeClock(), new NullLogger());

        $response = $controller(new Request([], [], [], [], ['gpxFile' => $file]));
        $body = json_decode((string) $response->getContent(), true);
        \assert(\is_array($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('File upload failed.', $body['detail']);
    }

    #[Test]
    public function aFailedParseStillConsumesTheBudget(): void
    {
        $limiter = $this->limiter(1);

        $gpxService = $this->createStub(GpxUploadServiceInterface::class);
        $gpxService->method('parseGpx')->willThrowException(new \RuntimeException('broken'));

        self::assertSame(422, $this->upload($gpxService, $limiter)->getStatusCode());
        self::assertFalse($limiter->create($this->user->getId()->toRfc4122())->consume()->isAccepted());
    }

    #[Test]
    public function aRouteOverThePointCapIsRejectedWith422(): void
    {
        $gpxService = $this->createMock(GpxUploadServiceInterface::class);
        $gpxService->method('parseGpx')->willThrowException(RouteTooLargeException::tooManyPoints());
        $gpxService->expects($this->never())->method('createTrip');

        $response = $this->upload($gpxService, $this->limiter(10));

        $body = json_decode((string) $response->getContent(), true);
        \assert(\is_array($body));

        self::assertSame(422, $response->getStatusCode());
        self::assertIsString($body['detail']);
        self::assertStringContainsString((string) RouteTooLargeException::MAX_POINTS, $body['detail']);
    }

    #[Test]
    public function aTripLongerThanTheMaximumIsRejectedWith422(): void
    {
        $gpxService = $this->createMock(GpxUploadServiceInterface::class);
        $gpxService->expects($this->never())->method('parseGpx');
        $gpxService->expects($this->never())->method('createTrip');

        $response = $this->upload($gpxService, $this->limiter(10), ['startDate' => '2026-06-01', 'endDate' => '9999-12-31']);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function aTripOfExactlyTheMaximumIsAccepted(): void
    {
        $captured = null;
        $gpxService = $this->acceptingService($captured);

        $end = new \DateTimeImmutable('2026-06-01')->modify(\sprintf('+%d days', TripRequest::MAX_TRIP_DAYS - 1));
        $response = $this->upload($gpxService, $this->limiter(10), ['startDate' => '2026-06-01', 'endDate' => $end->format('Y-m-d')]);

        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function aNestedAccommodationTypeIsIgnoredNotATypeError(): void
    {
        $captured = null;
        $gpxService = $this->acceptingService($captured);

        $response = $this->upload($gpxService, $this->limiter(10), ['enabledAccommodationTypes' => [['hotel'], 'camp_site']]);

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(TripRequest::class, $captured);
        self::assertSame(['camp_site'], $captured->enabledAccommodationTypes);
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'gpx_upload', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function acceptingService(?TripRequest &$captured): GpxUploadServiceInterface
    {
        $gpxService = $this->createStub(GpxUploadServiceInterface::class);
        $gpxService->method('parseGpx')->willReturn([new Coordinate(50.0, 3.0)]);
        $gpxService->method('extractTitle')->willReturn('Trip');
        $gpxService->method('createTrip')->willReturnCallback(static function (array $points, ?string $title, TripRequest $request) use (&$captured): array {
            $captured = $request;

            return [
                'tripId' => 'trip-1',
                'computationStatus' => [],
                'totalDistance' => 1.0,
                'totalElevation' => 0,
                'totalElevationLoss' => 0,
                'status' => 'draft',
                'isLocked' => false,
                'stages' => [],
            ];
        });

        return $gpxService;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function upload(GpxUploadServiceInterface $gpxService, RateLimiterFactory $limiter, array $parameters = []): JsonResponse
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);

        $tmp = tempnam(sys_get_temp_dir(), 'gpx');
        self::assertIsString($tmp);
        $this->tmp = $tmp;
        file_put_contents($tmp, '<gpx/>');

        $file = $this->createStub(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(100);
        $file->method('getClientOriginalExtension')->willReturn('gpx');
        $file->method('getMimeType')->willReturn('application/gpx+xml');
        $file->method('getPathname')->willReturn($tmp);

        $controller = new GpxUploadController($gpxService, $security, $limiter, new NativeClock(), new NullLogger());

        return $controller(new Request([], $parameters, [], [], ['gpxFile' => $file]));
    }
}
