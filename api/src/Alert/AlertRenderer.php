<?php

declare(strict_types=1);

namespace App\Alert;

use App\Enum\AlertParameterFormat;
use App\Format\DecimalFormatter;
use App\Format\DistanceFormatter;
use App\Poi\PoiLabelResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a persisted alert into a readable one, in the reader's language.
 *
 * Producers persist `messageKey` plus raw parameters and no prose (ADR-069). Both the
 * language and the number formatting are chosen here, at read time, so one stored row
 * renders "2,4 km" to a French reader and "2.4 km" to an English one without anything
 * being recomputed.
 *
 * The three placeholders naming a stage are injected rather than stored, for the reason
 * ADR-068 refused to persist `dayNumber` at all: every structural edit renumbers it, so a
 * frozen copy drifts. `%from%`/`%to%` are the stage carrying the alert and the one after
 * it — a continuity gap sits on the earlier of the two stages it spans, so the pair is
 * always consecutive and derives from the owner alone.
 */
final readonly class AlertRenderer
{
    public function __construct(
        private TranslatorInterface $translator,
        private DistanceFormatter $distanceFormatter,
        private DecimalFormatter $decimalFormatter,
        private PoiLabelResolver $poiLabels,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $alerts
     *
     * @return list<array<string, mixed>>
     */
    public function render(array $alerts, int $dayNumber, string $locale): array
    {
        return array_map(
            fn (array $alert): array => $this->renderOne($alert, $dayNumber, $locale),
            $alerts,
        );
    }

    /**
     * Renders a flat published list, each alert naming the stage it addresses.
     *
     * The per-group Mercure payloads carry `dayNumber` on every entry — they are a flat
     * list across the whole trip, not a per-stage one — so the owning stage is read from
     * the alert rather than passed in.
     *
     * @param list<array<string, mixed>> $alerts
     *
     * @return list<array<string, mixed>>
     */
    public function renderFlat(array $alerts, string $locale): array
    {
        return array_map(
            function (array $alert) use ($locale): array {
                $dayNumber = $alert['dayNumber'] ?? 0;
                \assert(\is_int($dayNumber));

                return $this->renderOne($alert, $dayNumber, $locale);
            },
            $alerts,
        );
    }

    /**
     * @param array<string, mixed> $alert
     *
     * @return array<string, mixed>
     */
    private function renderOne(array $alert, int $dayNumber, string $locale): array
    {
        $raw = \is_array($alert['parameters'] ?? null) ? $alert['parameters'] : [];
        $formats = \is_array($alert['parameterFormats'] ?? null) ? $alert['parameterFormats'] : [];

        $arguments = [];
        foreach ($raw as $placeholder => $value) {
            $format = $formats[$placeholder] ?? null;
            $arguments[$placeholder] = \is_string($format)
                ? $this->applyFormat($value, AlertParameterFormat::from($format), $locale)
                : $value;
        }

        $arguments += [
            '%stage%' => $dayNumber,
            '%from%' => $dayNumber,
            '%to%' => $dayNumber + 1,
        ];

        $messageKey = $alert['messageKey'];
        \assert(\is_string($messageKey));
        $alert['message'] = $this->translator->trans($messageKey, $arguments, 'alerts', $locale);

        if (\is_array($alert['action'] ?? null)) {
            $labelKey = $alert['action']['labelKey'];
            \assert(\is_string($labelKey));
            $alert['action']['label'] = $this->translator->trans($labelKey, [], 'alerts', $locale);
        }

        return $alert;
    }

    private function applyFormat(mixed $value, AlertParameterFormat $format, string $locale): string
    {
        return match ($format) {
            AlertParameterFormat::DISTANCE => $this->distanceFormatter->format($this->toFloat($value), $locale),
            AlertParameterFormat::DISTANCE_KM => $this->distanceFormatter->formatKilometers($this->toFloat($value), $locale),
            AlertParameterFormat::DECIMAL => $this->decimalFormatter->format($this->toFloat($value), $locale),
            AlertParameterFormat::DECIMAL_ONE => $this->decimalFormatter->format($this->toFloat($value), $locale, 1, 1),
            AlertParameterFormat::SURFACE_LIST => $this->surfaceList($value, $locale),
            AlertParameterFormat::POI_LABEL => $this->poiLabels->label($this->toString($value), $locale),
            AlertParameterFormat::POI_DISPLAY_NAME => $this->poiLabels->displayName($this->toString($value), $locale),
            AlertParameterFormat::COUNTRY => $this->countryName($this->toString($value), $locale),
        };
    }

    /**
     * The leading dash makes ICU read the value as a region subtag rather than a language,
     * which is how {@see \App\Osm\AdminBoundaryRepository} already resolves a country it
     * has no localised OSM name for. An unknown code is echoed rather than dropped.
     */
    private function countryName(string $code, string $locale): string
    {
        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        return \is_string($name) && '' !== $name ? $name : $code;
    }

    private function surfaceList(mixed $value, string $locale): string
    {
        \assert(\is_array($value));

        return implode(', ', array_unique(array_map(
            fn (mixed $surface): string => $this->surfaceLabel($this->toString($surface), $locale),
            $value,
        )));
    }

    private function surfaceLabel(string $surface, string $locale): string
    {
        $key = 'surface.'.str_replace('=', '_', $surface);
        $label = $this->translator->trans($key, [], 'alerts', $locale);

        return $key === $label ? $this->translator->trans('surface.unknown', [], 'alerts', $locale) : $label;
    }

    private function toFloat(mixed $value): float
    {
        \assert(\is_int($value) || \is_float($value));

        return (float) $value;
    }

    private function toString(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }
}
