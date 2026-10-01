<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Absolute links into the web app, built on FRONTEND_URL: the public origin a mail, a share
 * link or a redirect must point at, which is not necessarily the one serving this request.
 */
final readonly class FrontendUrl
{
    private string $origin;

    public function __construct(
        #[Autowire(env: 'FRONTEND_URL')]
        string $frontendUrl,
    ) {
        $this->origin = rtrim($frontendUrl, '/');
    }

    /**
     * @param string $path starting with `/`
     */
    public function to(string $path): string
    {
        return $this->origin.$path;
    }
}
