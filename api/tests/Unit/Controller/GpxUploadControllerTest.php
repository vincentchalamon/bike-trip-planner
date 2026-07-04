<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\ApiResource\Model\Coordinate;
use App\Controller\GpxUploadController;
use App\Entity\User;
use App\Service\GpxUploadServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class GpxUploadControllerTest extends TestCase
{
    #[Test]
    public function uploadIsRateLimited(): void
    {
        // SEC-006: a valid upload that passes parsing must be throttled per user,
        // just before the expensive createTrip, once the limiter is exhausted.
        $user = new User('gpx@example.com');

        $limiter = new RateLimiterFactory(
            ['id' => 'gpx_upload', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $limiter->create($user->getId()->toRfc4122())->consume();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $gpxService = $this->createStub(GpxUploadServiceInterface::class);
        $gpxService->method('parseGpx')->willReturn([new Coordinate(50.0, 3.0)]);
        $gpxService->method('extractTitle')->willReturn('Trip');

        $tmp = tempnam(sys_get_temp_dir(), 'gpx');
        self::assertIsString($tmp);
        file_put_contents($tmp, '<gpx/>');

        try {
            $file = $this->createStub(UploadedFile::class);
            $file->method('isValid')->willReturn(true);
            $file->method('getSize')->willReturn(100);
            $file->method('getClientOriginalExtension')->willReturn('gpx');
            $file->method('getMimeType')->willReturn('application/gpx+xml');
            $file->method('getPathname')->willReturn($tmp);

            $controller = new GpxUploadController($gpxService, $security, $limiter);
            $request = new Request([], [], [], [], ['gpxFile' => $file]);

            $this->expectException(TooManyRequestsHttpException::class);
            $controller($request);
        } finally {
            @unlink($tmp);
        }
    }
}
