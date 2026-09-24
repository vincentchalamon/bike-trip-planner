<?php

declare(strict_types=1);

namespace App\ApiResource\OAuth;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\OAuth\ConsentDecisionProcessor;
use App\State\OAuth\ConsentProvider;

/**
 * What the consent screen is allowed to say, and where the answer goes.
 *
 * A pending authorization, read back by the PWA page the browser was sent to. Everything
 * here is resolved server-side: the page never reads the authorization request's query
 * string, so it cannot display a scope the server did not actually resolve, nor a client
 * name the server did not actually fetch.
 */
#[ApiResource(
    shortName: 'OAuthConsent',
    operations: [
        new Get(
            uriTemplate: '/oauth/pending-authorizations/{handle}',
            openapi: new Operation(summary: 'Read the pending authorization a browser was sent here to decide.'),
            provider: ConsentProvider::class,
        ),
        new Post(
            uriTemplate: '/oauth/pending-authorizations/{handle}/approve',
            status: 204,
            openapi: new Operation(summary: 'Grant the pending authorization, then follow continueUrl.'),
            input: false,
            output: false,
            read: false,
            processor: ConsentDecisionProcessor::class,
            // The decision itself, rather than a path suffix the processor would have to
            // parse back out of the operation.
            extraProperties: ['consent_granted' => true],
        ),
        new Post(
            uriTemplate: '/oauth/pending-authorizations/{handle}/deny',
            status: 204,
            openapi: new Operation(summary: 'Refuse the pending authorization, then follow continueUrl.'),
            input: false,
            output: false,
            read: false,
            processor: ConsentDecisionProcessor::class,
            extraProperties: ['consent_granted' => false],
        ),
    ],
)]
final readonly class Consent
{
    /**
     * @param list<string> $scopes What the agent is asking for, as the authorization server
     *                             resolved it — not as the request asked
     */
    public function __construct(
        // Declared, or API Platform looks for an `id` uri variable, finds none, and hands
        // the provider an empty handle — which reads as "no pending authorization".
        #[ApiProperty(identifier: true)]
        public string $handle,
        #[ApiProperty(description: "The client's self-declared name. Third-party text: display it, never interpolate it into a sentence.")]
        public string $clientName,
        public array $scopes,
        #[ApiProperty(description: 'Host of the address the agent will be sent back to. The specification requires showing it.')]
        public ?string $redirectHost,
        #[ApiProperty(description: 'True when the client only ever returns to a loopback address, which nothing can prove belongs to it.')]
        public bool $redirectsToLoopback,
        #[ApiProperty(description: 'Where to send the browser once decided. Relative to this origin.')]
        public string $continueUrl,
    ) {
    }
}
