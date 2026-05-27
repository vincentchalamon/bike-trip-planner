/**
 * Structured logger for the PWA.
 *
 * Provides a uniform API used across the app to surface errors and useful
 * diagnostics:
 *
 *   - In development (`process.env.NODE_ENV === "development"`), entries are
 *     emitted to the browser console as a structured JSON payload so logs
 *     stay greppable while we iterate.
 *   - In production, entries are forwarded to Sentry / GlitchTip:
 *       * `logger.error`  → `Sentry.captureException` (Error context) or
 *         `Sentry.captureMessage(level: "error")`.
 *       * `logger.warn`   → `Sentry.captureMessage(level: "warning")`.
 *       * `logger.info`   → `Sentry.addBreadcrumb(level: "info")`.
 *       * `logger.debug`  → `Sentry.addBreadcrumb(level: "debug")`.
 *     Sentry SDK calls become no-ops if `NEXT_PUBLIC_SENTRY_DSN` is empty,
 *     so we never hard-depend on the DSN being configured.
 *   - In the test environment, all methods are no-ops.
 */
import * as Sentry from "@sentry/nextjs";

export type LogLevel = "error" | "warn" | "info" | "debug";

export type LogContext = Record<string, unknown>;

interface LogEntry {
  level: LogLevel;
  message: string;
  context?: LogContext;
  ts: string;
}

function isDevelopment(): boolean {
  return process.env.NODE_ENV === "development";
}

function isTest(): boolean {
  return process.env.NODE_ENV === "test";
}

function serializeError(value: Error): Record<string, unknown> {
  return {
    name: value.name,
    message: value.message,
    stack: value.stack,
    ...(value.cause !== undefined
      ? {
          cause:
            value.cause instanceof Error
              ? serializeError(value.cause)
              : value.cause,
        }
      : {}),
  };
}

function normalizeContext(context: LogContext): LogContext {
  const out: LogContext = {};
  for (const [key, value] of Object.entries(context)) {
    out[key] = value instanceof Error ? serializeError(value) : value;
  }
  return out;
}

function pickError(context?: LogContext): Error | undefined {
  if (!context) return undefined;
  for (const value of Object.values(context)) {
    if (value instanceof Error) return value;
  }
  return undefined;
}

function emitDev(level: LogLevel, message: string, context?: LogContext): void {
  const entry: LogEntry = {
    level,
    message,
    ts: new Date().toISOString(),
    ...(context !== undefined ? { context: normalizeContext(context) } : {}),
  };

  // JSON.stringify can throw on BigInt, circular references, or a user-defined
  // toJSON() that throws. A logger must never propagate errors to its caller.
  let payload: string;
  try {
    payload = JSON.stringify(entry);
  } catch {
    payload = JSON.stringify({
      level,
      message,
      ts: entry.ts,
      context: "[unserializable]",
    });
  }

  // logger.ts is the single sanctioned console caller (see module docblock).
  switch (level) {
    case "error":
      console.error(payload);
      return;
    case "warn":
      console.warn(payload);
      return;
    case "info":
      console.info(payload);
      return;
    case "debug":
      console.debug(payload);
      return;
  }
}

function emitProd(
  level: LogLevel,
  message: string,
  context?: LogContext,
): void {
  // Sentry calls are no-ops when the DSN is missing, so this is safe to call
  // unconditionally in production.
  switch (level) {
    case "error": {
      const err = pickError(context);
      if (err) {
        Sentry.captureException(err, {
          extra: context ? normalizeContext(context) : undefined,
          tags: { logger_message: message },
        });
      } else {
        Sentry.captureMessage(message, {
          level: "error",
          extra: context ? normalizeContext(context) : undefined,
        });
      }
      return;
    }
    case "warn":
      Sentry.captureMessage(message, {
        level: "warning",
        extra: context ? normalizeContext(context) : undefined,
      });
      return;
    case "info":
      Sentry.addBreadcrumb({
        level: "info",
        category: "logger",
        message,
        data: context ? normalizeContext(context) : undefined,
      });
      return;
    case "debug":
      Sentry.addBreadcrumb({
        level: "debug",
        category: "logger",
        message,
        data: context ? normalizeContext(context) : undefined,
      });
      return;
  }
}

function emit(level: LogLevel, message: string, context?: LogContext): void {
  if (isTest()) {
    return;
  }
  if (isDevelopment()) {
    emitDev(level, message, context);
    return;
  }
  emitProd(level, message, context);
}

export const logger = {
  error(message: string, context?: LogContext): void {
    emit("error", message, context);
  },
  warn(message: string, context?: LogContext): void {
    emit("warn", message, context);
  },
  info(message: string, context?: LogContext): void {
    emit("info", message, context);
  },
  debug(message: string, context?: LogContext): void {
    emit("debug", message, context);
  },
} as const;
