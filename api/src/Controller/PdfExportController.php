<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\Stage;
use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class PdfExportController
{
    public function __construct(
        private GotenbergPdfInterface $gotenbergPdf,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/export-pdf', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $body = $request->getContent();

        /** @var array{stages?: list<mixed>, title?: string} $tripData */
        $tripData = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        /** @var list<Stage> $stages */
        $stages = $this->serializer->deserialize(
            json_encode($tripData['stages'] ?? [], \JSON_THROW_ON_ERROR),
            Stage::class.'[]',
            'json',
        );

        $title = $tripData['title'] ?? 'Roadbook';

        return $this->gotenbergPdf
            ->html()
            ->content('pdf/roadbook.html.twig', [
                'stages' => $stages,
                'title' => $title,
            ])
            ->generate()
            ->stream();
    }
}
