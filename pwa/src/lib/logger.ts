/**
 * Structured logger for the PWA.
 *
 * Provides a uniform API ready to be wired to Sentry (or another remote sink)
 * in phase P1.1. Today:
 *
 *   - In development (`process.env.NODE_ENV === "development"`), entries are
 *     emitted to the browser console as a structured JSON payload so logs stay
 *     greppable while we iterate.
 *   - In production and test environments, all methods are no-ops. This keeps
 *     the surface area stable for Sentry wiring and prevents noisy console
 *     output from leaking to users.
 *
 * TODO P1.1: replace the production no-ops with `Sentry.captureException` for
 * `error`, and `Sentry.addBreadcrumb` (or `Sentry.captureMessage`) for the
 * other levels.
 */

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

function emit(level: LogLevel, message: string, context?: LogContext): void {
  if (!isDevelopment()) {
    // TODO P1.1: replace with Sentry.captureException / Sentry.addBreadcrumb.
    // NOTE: until P1.1 lands, production errors are silently dropped — this is
    // an intentional, time-bounded observability gap. If P1.1 slips, restore a
    // console.error fallback here so server-side stdout (visible via container
    // logs) still captures `error`-level entries.
    return;
  }

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
