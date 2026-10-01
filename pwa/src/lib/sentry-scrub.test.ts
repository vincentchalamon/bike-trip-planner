import { describe, expect, it } from "vitest";
import type { ErrorEvent, Event } from "@sentry/nextjs";
import {
  scrubBreadcrumb,
  scrubEvent,
  scrubText,
  scrubUrl,
} from "./sentry-scrub";

describe("scrubText", () => {
  it.each([
    ["mail to rider@example.com", "mail to [email]"],
    ["/auth/verify#0123abcd", "/auth/verify#[redacted]"],
    [
      "/account/email-change/verify#0123abcd",
      "/account/email-change/verify#[redacted]",
    ],
    [
      "/access-requests/verify#id=0199&expires=1&signature=ab",
      "/access-requests/verify#[redacted]",
    ],
    ["/s/Ab3-_x9Z/stages/2", "/s/[redacted]/stages/2"],
    [
      "/geocode/reverse?lat=45.1&lon=5.7",
      "/geocode/reverse?lat=[redacted]&lon=[redacted]",
    ],
    ["/x?email=rider%40example.com", "/x?email=[redacted]"],
  ])("redacts %s", (text, expected) => {
    expect(scrubText(text)).toBe(expected);
  });

  it("leaves ordinary paths alone", () => {
    const path = "/trips/0199a1b2-0000-7000-8000-000000000001/stages";
    expect(scrubText(path)).toBe(path);
  });
});

describe("scrubUrl", () => {
  it("drops the query and the fragment", () => {
    expect(scrubUrl("https://h/auth/verify#0123abcd")).toBe(
      "https://h/auth/verify",
    );
    expect(scrubUrl("https://h/geocode/reverse?lat=1&lon=2")).toBe(
      "https://h/geocode/reverse",
    );
  });
});

describe("scrubEvent", () => {
  it("scrubs the request, exceptions, message and breadcrumbs of an error", () => {
    const event: ErrorEvent = {
      type: undefined,
      message: "failed for rider@example.com",
      request: {
        url: "https://h/s/Ab3-_x9Z?email=rider%40example.com",
        method: "GET",
        query_string: "email=rider%40example.com",
        data: { token: "0123abcd" },
        cookies: { session: "s" },
        headers: { Referer: "https://h/s/Ab3-_x9Z" },
      },
      exception: { values: [{ value: "Key (email)=(rider@example.com)" }] },
      breadcrumbs: [
        {
          category: "navigation",
          data: { from: "/", to: "/auth/verify#0123abcd" },
        },
      ],
    };

    const scrubbed = scrubEvent(event);

    expect(scrubbed.request).toEqual({
      method: "GET",
      url: "https://h/s/[redacted]",
      headers: { Referer: "https://h/s/[redacted]" },
    });
    expect(scrubbed.message).toBe("failed for [email]");
    expect(scrubbed.exception?.values?.[0]?.value).toBe(
      "Key (email)=([email])",
    );
    expect(scrubbed.breadcrumbs?.[0]?.data).toEqual({
      from: "/",
      to: "/auth/verify#[redacted]",
    });
  });

  it("drops the query of outgoing request spans", () => {
    const event: Event = {
      type: "transaction",
      transaction: "/s/Ab3-_x9Z",
      spans: [
        {
          span_id: "a",
          trace_id: "b",
          start_timestamp: 0,
          op: "http.client",
          description: "GET /geocode/reverse?lat=45.1&lon=5.7",
          data: {
            "http.query": "?lat=45.1&lon=5.7",
            url: "/geocode/reverse?lat=45.1&lon=5.7",
          },
        },
      ],
    };

    const span = scrubEvent(event).spans?.[0];

    expect(scrubEvent(event).transaction).toBe("/s/[redacted]");
    expect(span?.description).toBe("GET /geocode/reverse");
    expect(span?.data).toEqual({
      url: "/geocode/reverse?lat=[redacted]&lon=[redacted]",
    });
  });
});

describe("scrubEvent on a non-http span", () => {
  it("leaves an SQL description intact, its addresses aside", () => {
    const event: Event = {
      type: "transaction",
      spans: [
        {
          span_id: "a",
          trace_id: "b",
          start_timestamp: 0,
          op: "db.query",
          description: "SELECT * FROM users WHERE email = ? AND id = ?",
          data: {},
        },
      ],
    };

    expect(scrubEvent(event).spans?.[0]?.description).toBe(
      "SELECT * FROM users WHERE email = ? AND id = ?",
    );
  });
});

describe("scrubBreadcrumb", () => {
  it("scrubs a fetch breadcrumb", () => {
    expect(
      scrubBreadcrumb({
        category: "fetch",
        data: { url: "/api/geocode/reverse?lat=1&lon=2", status_code: 200 },
      }),
    ).toEqual({
      category: "fetch",
      message: undefined,
      data: {
        url: "/api/geocode/reverse?lat=[redacted]&lon=[redacted]",
        status_code: 200,
      },
    });
  });
});
