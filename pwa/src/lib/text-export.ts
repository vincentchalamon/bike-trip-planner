// The formatted trip text builder now lives framework-free in @btp/core
// (ADR-055), shared with mobile. Re-exported here so existing
// `@/lib/text-export` imports keep working unchanged.
export { buildTripText, type TextExportParams } from "@btp/core";
