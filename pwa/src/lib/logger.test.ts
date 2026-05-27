import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
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

    it("serializes Error.cause chains", () => {
      const root = new Error("root");
      const outer = new Error("outer", { cause: root });
      logger.error("chained", { error: outer });

      const parsed = JSON.parse(errorSpy.mock.calls[0]?.[0] as string);
      expect(parsed.context.error.message).toBe("outer");
      expect(parsed.context.error.cause.name).toBe("Error");
      expect(parsed.context.error.cause.message).toBe("root");
      expect(typeof parsed.context.error.cause.stack).toBe("string");
    });

    it("serializes non-Error Error.cause values verbatim", () => {
      const outer = new Error("outer", { cause: "string-reason" });
      logger.error("chained", { error: outer });

      const parsed = JSON.parse(errorSpy.mock.calls[0]?.[0] as string);
      expect(parsed.context.error.cause).toBe("string-reason");
    });
  });

  describe("in production", () => {
    beforeEach(() => {
      setNodeEnv("production");
    });

    it("is a no-op for every level", () => {
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
    });
  });
});
