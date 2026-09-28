<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * The event and accommodation importers, built from the environment the way both `provision`
 * and `events:refresh` expect. A source whose credentials are not set is null: absent, never a
 * failure (ADR-041), and each command words its own warning.
 */
final class EnvImporters
{
    public static function dataTourisme(): ?DataTourismeImporter
    {
        $fluxId = getenv('DATATOURISME_FLUX_ID') ?: '';
        $appKey = getenv('DATATOURISME_APP_KEY') ?: '';
        if ('' === $fluxId || '' === $appKey) {
            return null;
        }

        return new DataTourismeImporter(
            \sprintf('https://diffuseur.datatourisme.fr/webservice/%s/%s', $fluxId, $appKey),
        );
    }

    /**
     * Gated on the dataset (the "flux"): the Opendatasoft public export needs no key, but a
     * private portal can supply one through OPENAGENDA_API_KEY.
     */
    public static function openAgenda(): ?OpenAgendaImporter
    {
        $dataset = getenv('OPENAGENDA_DATASET') ?: '';
        if ('' === $dataset) {
            return null;
        }

        $url = \sprintf('https://public.opendatasoft.com/api/explore/v2.1/catalog/datasets/%s/exports/jsonl', rawurlencode($dataset));
        $apiKey = getenv('OPENAGENDA_API_KEY') ?: '';
        if ('' !== $apiKey) {
            $url .= '?apikey='.rawurlencode($apiKey);
        }

        return new OpenAgendaImporter($url);
    }
}
