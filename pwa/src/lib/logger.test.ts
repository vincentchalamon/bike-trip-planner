import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const captureExceptionMock = vi.fn();
const captureMessageMock = vi.fn();
const addBreadcrumbMock = vi.fn();

vi.mock("@sentry/nextjs", () => ({
  captureException: (...args: unknown[]) => captureExceptionMock(...args),
  captureMessage: (...args: unknown[]) => captureMessageMock(...args),
  addBreadcrumb: (...args: unknown[]) => addBreadcrumbMock(...args),
}));

import { logger } from "./logger";

function setNodeEnv(value: string): void {
  // `process.env.NODE_ENV` is declared as a read-only literal type in
  // @types/node, so we go through `vi.stubEnv` which handles the cast and the
  // restoration through `vi.unstubAllEnvs`.
  vi.stubEnv("NODE_ENV", value);
}

describe("logger", () => {
  let errorSpy: ReturnType<typeof vi.spyOn>;
  let warnSpy: ReturnType<typeof vi.spyOn>;
  let infoSpy: ReturnType<typeof vi.spyOn>;
  let debugSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    errorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
    warnSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
    infoSpy = vi.spyOn(console, "info").mockImplementation(() => {});
    debugSpy = vi.spyOn(console, "debug").mockImplementation(() => {});

    captureExceptionMock.mockReset();
    captureMessageMock.mockReset();
    addBreadcrumbMock.mockReset();
  });

  afterEach(() => {
    errorSpy.mockRestore();
    warnSpy.mockRestore();
    infoSpy.mockRestore();
    debugSpy.mockRestore();
    vi.unstubAllEnvs();
  });

  describe("in development", () => {
    beforeEach(() => {
      setNodeEnv("development");
    });

    it("emits structured JSON for error()", () => {
      logger.error("boom", { trace: "abc" });

      expect(errorSpy).toHaveBeenCalledTimes(1);
      const arg = errorSpy.mock.calls[0]?.[0] as string;
      const parsed = JSON.parse(arg);
      expect(parsed.level).toBe("error");
      expect(parsed.message).toBe("boom");
      expect(parsed.context).toEqual({ trace: "abc" });
      expect(typeof parsed.ts).toBe("string");
    });

    it("emits structured JSON for warn(), info() and debug()", () => {
      logger.warn("warned");
      logger.info("informed");
      logger.debug("debugged");

      expect(warnSpy).toHaveBeenCalledTimes(1);
      expect(infoSpy).toHaveBeenCalledTimes(1);
      expect(debugSpy).toHaveBeenCalledTimes(1);
    });

    it("omits context from the payload when not provided", () => {
      logger.error("no-ctx");
      const parsed = JSON.parse(errorSpy.mock.calls[0]?.[0] as string);
      expect("context" in parsed).toBe(false);
    });

    it("serializes Error instances passed via context", () => {
      const cause = new Error("kaboom");
      logger.error("with-error", { cause });

      const parsed = JSON.parse(errorSpy.mock.calls[0]?.[0] as string);
      expect(parsed.context.cause.name).toBe("Error");
      expect(parsed.context.cause.message).toBe("kaboom");
      expect(typeof parsed.context.cause.stack).toBe("string");
    });

    it("does not call Sentry in development", () => {
      logger.error("boom", { cause: new Error("x") });
      logger.warn("warned");
      logger.info("informed");
      logger.debug("debugged");

      expect(captureExceptionMock).not.toHaveBeenCalled();
      expect(captureMessageMock).not.toHaveBeenCalled();
      expect(addBreadcrumbMock).not.toHaveBeenCalled();
    });
  });

  describe("in production", () => {
    beforeEach(() => {
      setNodeEnv("production");
    });

    it("forwards error() with an Error context to Sentry.captureException", () => {
      const cause = new Error("kaboom");
      logger.error("with-error", { cause, tripId: "trip-1" });

      expect(captureExceptionMock).toHaveBeenCalledTimes(1);
      const [err, opts] = captureExceptionMock.mock.calls[0] as [
        Error,
        { extra?: Record<string, unknown>; tags?: Record<string, string> },
      ];
      expect(err).toBe(cause);
      expect(opts.tags?.logger_message).toBe("with-error");
      expect(opts.extra?.tripId).toBe("trip-1");
      expect(captureMessageMock).not.toHaveBeenCalled();
    });

    it("forwards error() without an Error to Sentry.captureMessage(level=error)", () => {
      logger.error("plain-error", { code: 42 });

      expect(captureExceptionMock).not.toHaveBeenCalled();
      expect(captureMessageMock).toHaveBeenCalledTimes(1);
      const [message, opts] = captureMessageMock.mock.calls[0] as [
        string,
        { level: string; extra?: Record<string, unknown> },
      ];
      expect(message).toBe("plain-error");
      expect(opts.level).toBe("error");
      expect(opts.extra?.code).toBe(42);
    });

    it("forwards warn() to Sentry.captureMessage(level=warning)", () => {
      logger.warn("careful", { reason: "x" });

      expect(captureMessageMock).toHaveBeenCalledTimes(1);
      const [message, opts] = captureMessageMock.mock.calls[0] as [
        string,
        { level: string; extra?: Record<string, unknown> },
      ];
      expect(message).toBe("careful");
      expect(opts.level).toBe("warning");
      expect(opts.extra?.reason).toBe("x");
    });

    it("records info() and debug() as Sentry breadcrumbs", () => {
      logger.info("informed", { step: "a" });
      logger.debug("debugged", { step: "b" });

      expect(addBreadcrumbMock).toHaveBeenCalledTimes(2);
      type BreadcrumbArg = {
        level: string;
        category: string;
        message: string;
        data?: Record<string, unknown>;
      };
      const info = addBreadcrumbMock.mock.calls[0]?.[0] as BreadcrumbArg;
      const dbg = addBreadcrumbMock.mock.calls[1]?.[0] as BreadcrumbArg;

      expect(info.level).toBe("info");
      expect(info.category).toBe("logger");
      expect(info.message).toBe("informed");
      expect(info.data?.step).toBe("a");

      expect(dbg.level).toBe("debug");
      expect(dbg.message).toBe("debugged");
      expect(dbg.data?.step).toBe("b");
    });

    it("does not touch the console in production", () => {
      logger.error("nope", { a: 1 });
      logger.warn("nope");
      logger.info("nope");
      logger.debug("nope");

      expect(errorSpy).not.toHaveBeenCalled();
      expect(warnSpy).not.toHaveBeenCalled();
      expect(infoSpy).not.toHaveBeenCalled();
      expect(debugSpy).not.toHaveBeenCalled();
    });
  });

  describe("in test environment", () => {
    beforeEach(() => {
      setNodeEnv("test");
    });

    it("is a no-op for every level", () => {
      logger.error("nope");
      logger.warn("nope");
      logger.info("nope");
      logger.debug("nope");

      expect(errorSpy).not.toHaveBeenCalled();
      expect(warnSpy).not.toHaveBeenCalled();
      expect(infoSpy).not.toHaveBeenCalled();
      expect(debugSpy).not.toHaveBeenCalled();
      expect(captureExceptionMock).not.toHaveBeenCalled();
      expect(captureMessageMock).not.toHaveBeenCalled();
      expect(addBreadcrumbMock).not.toHaveBeenCalled();
    });
  });
});
