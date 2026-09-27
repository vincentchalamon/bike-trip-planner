<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Notes that an application actually did something, so a user can see it on their account page.
 *
 * Its own decorator rather than a few lines inside {@see McpCallBudget}: the budget's whole
 * docblock is about what a call is allowed to cost, and a class that also writes an unrelated
 * column is one nobody can reason about later. It sits INSIDE the budget, so only a call that
 * was allowed and paid for counts as use.
 *
 * Three things decide the shape, and all three are about not harming the call:
 *
 *  - **it runs after the inner handler.** At this depth the SDK owns the HTTP response, so a
 *    failure here cannot be reported; and a write before the call could poison the transaction
 *    the tool opens. Whatever happens, `$this->inner->handle()` has already returned;
 *  - **it never lets an exception out.** The call succeeded; a missing timestamp is a wrong
 *    line on a screen, not a failed edit;
 *  - **it does not read the database to decide whether to write.** A handshake-era batch
 *    carries up to a hundred messages, and a SELECT per message would trade one write for a
 *    hundred reads. A cache key per grant, five minutes, decides instead — which is why the
 *    pool has to be one that survives between requests, including under test.
 *
 * What counts as use is a tool call or a resource read. An agent that connects, lists the tools
 * and leaves reads as never used — the screen answers "what has this application done with the
 * access", not "has it ever held a connection".
 *
 * @implements RequestHandlerInterface<CallToolResult|ReadResourceResult>
 */
final readonly class McpGrantUsage implements RequestHandlerInterface
{
    private const int THROTTLE_SECONDS = 300;

    /**
     * @param RequestHandlerInterface<CallToolResult|ReadResourceResult> $inner
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private TokenStorageInterface $tokenStorage,
        private Connection $connection,
        #[Autowire(service: 'cache.oauth_grant_usage')]
        private CacheInterface $throttle,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $result = $this->inner->handle($request, $session);

        if ($request instanceof CallToolRequest || $request instanceof ReadResourceRequest) {
            $this->note();
        }

        return $result;
    }

    private function note(): void
    {
        try {
            $token = $this->tokenStorage->getToken();
            $user = $token?->getUser();

            if (!$user instanceof User || !$token instanceof OAuth2Token) {
                return;
            }

            $userId = $user->getId()->toRfc4122();
            $client = $token->getOAuthClientId();

            $this->throttle->get(
                'usage_'.hash('xxh128', $userId.'|'.$client),
                function (ItemInterface $item) use ($userId, $client): bool {
                    $item->expiresAfter(self::THROTTLE_SECONDS);

                    $this->connection->executeStatement(
                        'UPDATE oauth_grant SET last_used_at = :now WHERE user_id = :user AND client_identifier = :client AND revoked_at IS NULL',
                        [
                            'now' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                            'user' => $userId,
                            'client' => $client,
                        ],
                    );

                    return true;
                },
            );
        } catch (\Throwable $throwable) {
            $this->logger->warning('Could not record the last use of an OAuth grant.', ['exception' => $throwable]);
        }
    }
}
