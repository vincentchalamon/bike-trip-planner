<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ConfirmationChallenge;
use App\Entity\User;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Answers a destructive tool's first call with what it would do, and carries it out on the
 * second.
 *
 * A tool declaring {@see self::EXTRA_PROPERTY} and called without a `confirmationToken` writes
 * nothing: it returns an impact summary and a token. Called again with that token and the same
 * arguments, it goes through.
 *
 * ⚠ The priority — declared in `config/services.php`, alongside the two other write guards — is
 * positive, which makes this the INNERMOST decorator and therefore the LAST to run.
 * `DecoratorServicePass` iterates from the highest priority down and the one handled last keeps
 * the alias, so a low priority runs first: counter-intuitive, and this repository has already
 * got it wrong once (lot D put the lock behind the precondition; lot E fixed it).
 * `TripLockProcessor` sits at -10 and `PreconditionProcessor` at 0, so both have had their say
 * before a token is ever minted. Reversed, an agent would be sent off to confirm a call that a
 * started trip or a stale version was going to refuse anyway — and would come back with a
 * token, only to be told no.
 *
 * ⚠ What this proves is narrow, and calling it "confirmation" without saying so would be
 * theatre: the same agent makes both calls, so it is not a human's agreement. It guarantees
 * that an impact summary was produced and put in a transcript a person can read back, and that
 * the arguments did not move between the two calls. It defends against a mis-parameterised
 * mutation, not against a hostile agent.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final readonly class McpConfirmationProcessor implements ProcessorInterface
{
    /**
     * Carries the sentence describing what the tool is about to do, rather than a flag: the
     * sentence has to exist anyway, and one key cannot fall out of step with the other.
     */
    public const string EXTRA_PROPERTY = 'mcp_confirmation';

    /**
     * @param ProcessorInterface<mixed, mixed> $decorated
     */
    public function __construct(
        private ProcessorInterface $decorated,
        private ConfirmationStore $tokens,
        private TripImpactSummary $impact,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $action = $operation->getExtraProperties()[self::EXTRA_PROPERTY] ?? null;

        // Not on HTTP: there the caller is a person clicking a button in an interface that
        // already asked them, and a second round trip would be a regression for every web and
        // mobile client. The property is only ever declared on tools, but the transport check
        // states the intent rather than relying on that.
        if (!\is_string($action) || '' === $action || !McpArguments::isToolCall($context)) {
            return $this->decorated->process($data, $operation, $uriVariables, $context);
        }

        $arguments = McpArguments::from($context);
        $token = $arguments->control('confirmationToken');
        $binding = $this->binding($operation, $uriVariables, $arguments);

        if (null === $token || '' === $token) {
            return new ConfirmationChallenge(
                confirmationToken: $this->tokens->issue($binding),
                action: $action,
                impact: $this->impact->for($this->tripId($uriVariables)),
            );
        }

        if (!\is_string($token) || !$this->tokens->consume($token, $binding)) {
            throw new BadRequestHttpException('This "confirmationToken" is not valid for this call: it was minted for different arguments, has already been used, or has expired. Call the tool again without a token to get a fresh impact summary.');
        }

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    /**
     * What the token is bound to, and every part of it earns its place.
     *
     * The user and the OAuth client, so a token cannot be carried across either. The tool, so
     * confirming a share does not confirm a deletion. The URI variables, so confirming the
     * deletion of one trip does not delete another. And the canonical arguments, which is the
     * whole point — the summary shown to the human described these arguments, so a token spent
     * on different ones would confirm something nobody was shown.
     *
     * @param array<string, mixed> $uriVariables
     */
    private function binding(Operation $operation, array $uriVariables, McpArguments $arguments): string
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        \assert($user instanceof User);

        ksort($uriVariables);

        return hash('sha256', json_encode([
            $user->getId()->toRfc4122(),
            $token instanceof OAuth2Token ? $token->getOAuthClientId() : '',
            $operation->getName() ?? $operation::class,
            $uriVariables,
            $arguments->canonical(),
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $uriVariables */
    private function tripId(array $uriVariables): string
    {
        $tripId = $uriVariables['tripId'] ?? $uriVariables['id'] ?? null;

        if (!\is_string($tripId)) {
            // A declaration error rather than a caller's: every tool that confirms acts on a
            // trip, and one that did not could not say what it was about to destroy.
            throw new \LogicException(\sprintf('A tool declaring "%s" must address a trip through a "tripId" or "id" uri variable.', self::EXTRA_PROPERTY));
        }

        return $tripId;
    }
}
