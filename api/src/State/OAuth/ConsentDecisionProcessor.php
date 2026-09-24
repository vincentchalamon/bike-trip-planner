<?php

declare(strict_types=1);

namespace App\State\OAuth;

use Symfony\Component\Security\Core\User\UserInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\OAuth\ConsentRecord;
use App\Security\OAuth\ConsentStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records the decision, for both answers.
 *
 * This POST is what makes the flow safe against a forged authorization. The returning leg
 * is a top-level GET carrying a SameSite=Lax cookie, which any page can cause a browser to
 * make; on its own it would be an approval anybody could trigger. It is not, because it
 * only completes when a decision is already on file — and recording one requires the
 * Bearer, which lives in the tab's memory and which no third-party page can produce. The
 * API is stateless and CORS grants no credentials, so there is no ambient authority here to
 * ride on.
 *
 * Approving and refusing share this processor deliberately: they differ by one boolean, and
 * splitting them into two classes would be the one place where the refusal path could rot
 * unnoticed.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ConsentDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private ConsentStore $consents,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $handle = \is_string($uriVariables['handle'] ?? null) ? $uriVariables['handle'] : '';

        $record = $this->consents->get($handle);
        $user = $this->security->getUser();

        if (!$record instanceof ConsentRecord || !$user instanceof UserInterface || $record->userId !== $user->getUserIdentifier()) {
            throw new NotFoundHttpException('No pending authorization.');
        }

        $record->approved = true === ($operation->getExtraProperties()['consent_granted'] ?? null);
        $this->consents->put($handle, $record);

        return null;
    }
}
