"use client";

import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import {
  buildWeatherSeries,
  hourToX,
  tempToY,
  tempPath,
  rainBars,
  pointAtFraction,
  weatherCodeToConditionKey,
  type HourlyWeatherData,
  type PlotGeometry,
} from "@btp/core";

const GEO: PlotGeometry = {
  width: 800,
  height: 220,
  padLeft: 32,
  padRight: 12,
  padTop: 16,
  padBottom: 44,
};

interface StageWeatherProfileProps {
  hourly: HourlyWeatherData[];
}

/**
 * Detailed hourly weather graph (temperature + feels-like curves, rain bars,
 * wind arrows) over an accessible `<table>` that is the canonical data: the SVG
 * is decorative (`aria-hidden`), the table carries keyboard + screen-reader +
 * touch access. Mirrors the ElevationProfile SVG convention.
 */
export function StageWeatherProfile({ hourly }: StageWeatherProfileProps) {
  const t = useTranslations("weather");
  const svgRef = useRef<SVGSVGElement>(null);
  const [hoverHour, setHoverHour] = useState<number | null>(null);

  const series = buildWeatherSeries(hourly);
  if (!series) return null;

  const bars = rainBars(series, GEO);
  const hovered =
    hoverHour !== null
      ? (series.points.find((p) => p.hour === hoverHour) ?? null)
      : null;

  const onMove = (e: React.MouseEvent<SVGSVGElement>) => {
    const rect = svgRef.current?.getBoundingClientRect();
    if (!rect) return;
    const fraction =
      (((e.clientX - rect.left) / rect.width) * GEO.width - GEO.padLeft) /
      (GEO.width - GEO.padLeft - GEO.padRight);
    const p = pointAtFraction(series, fraction);
    setHoverHour(p?.hour ?? null);
  };

  return (
    <div>
      <div className="relative">
        <svg
          ref={svgRef}
          viewBox={`0 0 ${GEO.width} ${GEO.height}`}
          preserveAspectRatio="none"
          className="h-40 w-full"
          aria-hidden="true"
          onMouseMove={onMove}
          onMouseLeave={() => setHoverHour(null)}
        >
          {/* Rain bars (secondary layer) */}
          {bars.map((b) => (
            <rect
              key={`rain-${b.hour}`}
              x={b.x}
              y={b.y}
              width={b.width}
              height={b.height}
              className="fill-blue-400/60"
            />
          ))}

          {/* Feels-like curve (dashed) then real temperature curve */}
          <path
            d={tempPath(series, GEO, "apparentTemp")}
            fill="none"
            strokeWidth={2}
            strokeDasharray="4 3"
            className="stroke-sky-500"
          />
          <path
            d={tempPath(series, GEO, "temp")}
            fill="none"
            strokeWidth={2.5}
            className="stroke-orange-500"
          />

          {/* Wind arrows + hour ticks along the bottom axis */}
          {series.points.map((p) => {
            const x = hourToX(series, p.hour, GEO);
            return (
              <g key={`axis-${p.hour}`}>
                <g
                  transform={`translate(${x}, ${GEO.height - 26}) rotate(${(p.windDirectionDeg + 180) % 360})`}
                  className="stroke-foreground/70"
                >
                  <line x1={0} y1={-5} x2={0} y2={5} strokeWidth={1.5} />
                  <line x1={0} y1={5} x2={-3} y2={1} strokeWidth={1.5} />
                  <line x1={0} y1={5} x2={3} y2={1} strokeWidth={1.5} />
                </g>
                <text
                  x={x}
                  y={GEO.height - 6}
                  textAnchor="middle"
                  className="fill-muted-foreground text-[10px]"
                >
                  {p.hour}h
                </text>
              </g>
            );
          })}

          {/* Crosshair */}
          {hovered && (
            <line
              x1={hourToX(series, hovered.hour, GEO)}
              y1={GEO.padTop}
              x2={hourToX(series, hovered.hour, GEO)}
              y2={GEO.height - GEO.padBottom}
              className="stroke-foreground/40"
              strokeWidth={1}
            />
          )}
          {hovered && (
            <circle
              cx={hourToX(series, hovered.hour, GEO)}
              cy={tempToY(series, hovered.temp, GEO)}
              r={3}
              className="fill-orange-500"
            />
          )}
        </svg>

        {hovered && (
          <div className="pointer-events-none absolute right-0 top-0 rounded-md bg-card/95 px-2 py-1 text-[11px] shadow">
            <span className="font-medium">{hovered.hour}h</span> ·{" "}
            {Math.round(hovered.temp)}° ({t("feelsLike")}{" "}
            {Math.round(hovered.apparentTemp)}°) · {hovered.precipitationMm}mm ·{" "}
            {Math.round(hovered.windSpeed)}km/h
          </div>
        )}
      </div>

      {/* Legend (text + shape, never colour-only) */}
      <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-muted-foreground">
        <span className="text-orange-500">━ {t("temperature")}</span>
        <span className="text-sky-500">┄ {t("feelsLike")}</span>
        <span className="text-blue-400">▮ {t("rainMm")}</span>
        <span>↑ {t("wind")}</span>
      </p>

      {/* Canonical data: accessible hourly table. */}
      <div className="mt-2 overflow-x-auto">
        <table className="w-full border-collapse text-left text-xs">
          <caption className="sr-only">{t("hourlyTableCaption")}</caption>
          <thead>
            <tr className="text-muted-foreground">
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("colHour")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("colCondition")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("colTemp")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("feelsLike")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("rainMm")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("wind")}
              </th>
              <th scope="col" className="py-1 pr-2 font-medium">
                {t("gusts")}
              </th>
            </tr>
          </thead>
          <tbody className="tabular-nums">
            {series.points.map((p) => (
              <tr key={`row-${p.hour}`} className="border-t border-border/50">
                <th scope="row" className="py-1 pr-2 font-normal">
                  {p.hour}h
                </th>
                <td className="py-1 pr-2">
                  {t(`condition.${weatherCodeToConditionKey(p.weatherCode)}`)}
                </td>
                <td className="py-1 pr-2">{Math.round(p.temp)}°</td>
                <td className="py-1 pr-2">{Math.round(p.apparentTemp)}°</td>
                <td className="py-1 pr-2">{p.precipitationMm} mm</td>
                <td className="py-1 pr-2">
                  {Math.round(p.windSpeed)}
                  {p.relativeWindDirection !== "unknown"
                    ? ` (${t(`relativeWind_${p.relativeWindDirection}` as "relativeWind_headwind" | "relativeWind_tailwind" | "relativeWind_crosswind")})`
                    : ""}
                </td>
                <td className="py-1 pr-2">{Math.round(p.windGusts)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
