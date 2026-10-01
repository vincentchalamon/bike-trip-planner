<?php

declare(strict_types=1);

namespace App\Controller;

use App\Health\HealthCheck;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Psr\Clock\ClockInterface;
use App\RateLimiter\RetryAfter;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Liveness and readiness probes for orchestration (Coolify, Uptime Kuma, smoke tests).
 *
 * - GET /api/healthz: lightweight liveness, no dependencies, always 200.
 * - GET /api/health:  readiness with parallel checks over critical dependencies.
 *                     200 when every required dep is green, 503 otherwise.
 *
 * Each dependency is one {@see HealthCheck}, which says whether it is required and why.
 */
final readonly class HealthController
{
    /**
     * @param iterable<HealthCheck> $checks
     */
    public function __construct(
        #[AutowireIterator(HealthCheck::TAG)]
        private iterable $checks,
        #[Target('health_liveness')]
        private RateLimiterFactoryInterface $healthLivenessLimiter,
        #[Target('health_readiness')]
        private RateLimiterFactoryInterface $healthReadinessLimiter,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/healthz', name: 'app_healthz', methods: ['GET'])]
    public function liveness(Request $request): JsonResponse
    {
        // Liveness must remain dependency-free: rate limiting is best-effort and
        // must never turn a healthy instance into a 5xx if Redis (or the cache
        // pool backing the limiter) happens to be unreachable.
        $this->enforceRateLimit($this->healthLivenessLimiter, $request, bestEffort: true);

        return new JsonResponse([
            'status' => 'ok',
        ]);
    }

    #[Route('/api/health', name: 'app_health', methods: ['GET'])]
    public function readiness(Request $request): JsonResponse
    {
        // Readiness reports degradation via the response body; do not let the
        // rate limiter backend take down the probe itself.
        $this->enforceRateLimit($this->healthReadinessLimiter, $request, bestEffort: true);

        // Every check is started before any is completed, so the HTTP probes are in flight
        // while the database and Redis checks run.
        $pending = [];
        foreach ($this->checks as $check) {
            $pending[$check->name()] = [$check, $check->start()];
        }

        $deps = [];
        $status = 'ok';
        foreach ($pending as $name => [$check, $complete]) {
            $deps[$name] = $complete();

            if ($check->isRequired() && 'ok' !== ($deps[$name]['status'] ?? 'down')) {
                $status = 'degraded';
            }
        }

        $httpStatus = 'ok' === $status ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $status,
            'deps' => $deps,
        ], $httpStatus);
    }

    private function enforceRateLimit(RateLimiterFactoryInterface $factory, Request $request, bool $bestEffort = false): void
    {
        try {
            $limiter = $factory->create($request->getClientIp() ?? 'anonymous');

            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyRequestsHttpException(RetryAfter::seconds($limit, $this->clock));
            }
        } catch (TooManyRequestsHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The backing cache (e.g. Redis) is unavailable. Health probes must
            // not fail on infrastructure issues unrelated to the probe itself —
            // skip rate limiting rather than emit a 5xx.
            if (!$bestEffort) {
                throw $e;
            }
        }
    }
}
