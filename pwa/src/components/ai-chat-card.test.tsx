import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  cleanup,
} from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import {
  AiChatCard,
  AI_CHAT_LAUNCH_EVENT,
  MAX_USER_TURNS,
  type AiChatLaunchEventDetail,
} from "./ai-chat-card";
import type { AiChatResult } from "@/lib/api/client";

// Echo translation keys (params appended) so assertions can target keys.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string, params?: Record<string, string>) =>
    params ? `${key}:${JSON.stringify(params)}` : key,
}));

vi.mock("next/link", () => ({
  default: ({
    href,
    children,
    ...rest
  }: {
    href: string;
    children: React.ReactNode;
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const sendAiChat = vi.fn<(...args: unknown[]) => Promise<AiChatResult>>();
vi.mock("@/lib/api/client", () => ({
  sendAiChat: (...args: unknown[]) => sendAiChat(...args),
}));

function ok(
  reply: string,
  collected: Record<string, unknown> = {},
  readyToGenerate = false,
): AiChatResult {
  return {
    status: "ok",
    data: { reply, readyToGenerate, collected },
  } as AiChatResult;
}

async function sendUserTurn(text: string) {
  const textarea = screen.getByTestId("ai-chat-textarea");
  fireEvent.change(textarea, { target: { value: text } });
  fireEvent.keyDown(textarea, { key: "Enter" });
}

describe("AiChatCard (ADR-045)", () => {
  beforeEach(() => {
    sendAiChat.mockReset();
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it("renders the greeting and an empty recap before any turn", () => {
    render(<AiChatCard />);
    expect(screen.getByText("greeting")).toBeInTheDocument();
    expect(screen.getByText("recapEmpty")).toBeInTheDocument();
    // Launch is disabled until a geocodable start is collected.
    expect(screen.getByTestId("ai-chat-launch")).toBeDisabled();
  });

  it("sends the whole transcript on each turn and appends the assistant reply", async () => {
    sendAiChat.mockResolvedValueOnce(ok("First reply", { start: "Lille" }));
    sendAiChat.mockResolvedValueOnce(ok("Second reply", { start: "Lille" }));
    render(<AiChatCard />);

    await sendUserTurn("Boucle au départ de Lille");
    await screen.findByText("First reply");
    await sendUserTurn("Sur 3 jours");
    await screen.findByText("Second reply");

    // The second call carries the full conversation (2 user + 1 assistant).
    const secondCallMessages = sendAiChat.mock.calls[1]?.[0] as Array<{
      role: string;
      content: string;
    }>;
    expect(secondCallMessages).toEqual([
      { role: "user", content: "Boucle au départ de Lille" },
      { role: "assistant", content: "First reply" },
      { role: "user", content: "Sur 3 jours" },
    ]);

    // Both user bubbles + both assistant replies (greeting is not in `messages`).
    expect(
      screen.getAllByTestId("ai-chat-message").filter((el) => {
        return el.getAttribute("data-role") === "user";
      }),
    ).toHaveLength(2);
  });

  it("keeps the launch button disabled without a geocodable start", async () => {
    sendAiChat.mockResolvedValueOnce(
      ok("Where do you start?", { durationDays: 3 }, true),
    );
    render(<AiChatCard />);

    await sendUserTurn("3 jours quelque part");
    await screen.findByText("Where do you start?");

    // readyToGenerate is true but `start` is missing → hard gate keeps it off.
    expect(screen.getByTestId("ai-chat-launch")).toBeDisabled();
    expect(screen.getByText("launchNeedsStart")).toBeInTheDocument();
  });

  it("enables the launch button once a start is collected", async () => {
    sendAiChat.mockResolvedValueOnce(ok("Got Lille", { start: "Lille" }));
    render(<AiChatCard />);

    await sendUserTurn("Départ Lille");
    await screen.findByText("Got Lille");

    expect(screen.getByTestId("ai-chat-launch")).toBeEnabled();
    // Recap shows the collected start.
    expect(screen.getByTestId("ai-chat-recap-start")).toHaveTextContent(
      "Lille",
    );
  });

  it("marks the launch button as recommended when readyToGenerate is true", async () => {
    sendAiChat.mockResolvedValueOnce(
      ok("All set", { start: "Lille", durationDays: 3 }, true),
    );
    render(<AiChatCard />);

    await sendUserTurn("Boucle Lille 3 jours");
    await screen.findByText("All set");

    const launch = screen.getByTestId("ai-chat-launch");
    expect(launch).toBeEnabled();
    expect(launch).toHaveAttribute("data-recommended", "true");
    expect(screen.getByTestId("ai-chat-launch-hint")).toBeInTheDocument();
  });

  it("does not recommend launch when readyToGenerate is false even with a start", async () => {
    sendAiChat.mockResolvedValueOnce(ok("Need more", { start: "Lille" }, false));
    render(<AiChatCard />);

    await sendUserTurn("Départ Lille");
    await screen.findByText("Need more");

    const launch = screen.getByTestId("ai-chat-launch");
    expect(launch).toBeEnabled();
    expect(launch).not.toHaveAttribute("data-recommended");
    expect(screen.queryByTestId("ai-chat-launch-hint")).not.toBeInTheDocument();
  });

  it("consolidates the brief (collected + user turns) and fires the launch callback + event", async () => {
    sendAiChat.mockResolvedValueOnce(
      ok("ready", { start: "Lille", durationDays: 3 }, true),
    );
    const onLaunchGeneration = vi.fn();
    const eventDetails: AiChatLaunchEventDetail[] = [];
    document.addEventListener(AI_CHAT_LAUNCH_EVENT, (e) =>
      eventDetails.push((e as CustomEvent<AiChatLaunchEventDetail>).detail),
    );

    render(<AiChatCard onLaunchGeneration={onLaunchGeneration} />);
    await sendUserTurn("Boucle au départ de Lille sur 3 jours");
    await screen.findByText("ready");

    fireEvent.click(screen.getByTestId("ai-chat-launch"));

    expect(onLaunchGeneration).toHaveBeenCalledTimes(1);
    const brief = onLaunchGeneration.mock.calls[0]?.[0] as string;
    // Structured params first…
    expect(brief).toContain("start: Lille");
    expect(brief).toContain("durationDays: 3");
    // …then the rider's own turn as fallback.
    expect(brief).toContain("Boucle au départ de Lille sur 3 jours");
    expect(eventDetails[0]?.brief).toBe(brief);
  });

  it("surfaces the configure CTA on a 422 ai_not_configured", async () => {
    sendAiChat.mockResolvedValueOnce({
      status: "not_configured",
    } as AiChatResult);
    render(<AiChatCard />);

    await sendUserTurn("Boucle Lille");
    await waitFor(() =>
      expect(screen.getByTestId("ai-chat-not-configured")).toBeInTheDocument(),
    );
    expect(screen.getByTestId("ai-chat-configure-cta")).toHaveAttribute(
      "href",
      "/account/settings#ai",
    );
  });

  it("maps 429 / 503 / generic failures to localized error bubbles", async () => {
    sendAiChat.mockResolvedValueOnce({ status: "rate_limited" });
    sendAiChat.mockResolvedValueOnce({ status: "unavailable" });
    sendAiChat.mockResolvedValueOnce({ status: "error" });
    render(<AiChatCard />);

    await sendUserTurn("a");
    await screen.findByText("errorRateLimit");
    await sendUserTurn("b");
    await screen.findByText("errorUnavailable");
    await sendUserTurn("c");
    await screen.findByText("errorGeneric");

    // Error bubbles render in the assistant lane flagged as errors.
    expect(
      screen.getAllByTestId("ai-chat-message").filter((el) => {
        return el.getAttribute("data-error") === "true";
      }),
    ).toHaveLength(3);
  });

  it("stops sending past the user-turn cap and shows the cap hint", async () => {
    sendAiChat.mockResolvedValue(ok("ok", { start: "Lille" }));
    render(<AiChatCard />);

    for (let i = 0; i < MAX_USER_TURNS; i++) {
      await sendUserTurn(`turn ${i}`);
      // Wait for each reply so the next send sees the updated turn count.
      await waitFor(() =>
        expect(sendAiChat).toHaveBeenCalledTimes(i + 1),
      );
    }

    // The (MAX+1)-th attempt must not hit the API and must surface the hint.
    await sendUserTurn("one too many");
    expect(screen.getByTestId("ai-chat-cap-hint")).toBeInTheDocument();
    expect(sendAiChat).toHaveBeenCalledTimes(MAX_USER_TURNS);
  });

  it("disables every interactive control when disabled", () => {
    render(<AiChatCard disabled />);
    expect(screen.getByTestId("ai-chat-textarea")).toBeDisabled();
    expect(screen.getByTestId("ai-chat-send")).toBeDisabled();
    expect(screen.getByTestId("ai-chat-launch")).toBeDisabled();
  });

  // Locks the recap/brief keys to the ones the backend `brief-chat` prompt
  // actually emits (elevationTolerance / dates / resupply, not elevation /
  // startDate / supply); a divergence would silently drop them from both.
  it("surfaces the elevationTolerance / dates / resupply fields in the brief", async () => {
    sendAiChat.mockResolvedValueOnce(
      ok(
        "Noté",
        {
          start: "Lille",
          elevationTolerance: "medium",
          dates: "juillet",
          resupply: "autonomie",
        },
        true,
      ),
    );
    const onLaunchGeneration = vi.fn();
    render(<AiChatCard onLaunchGeneration={onLaunchGeneration} />);

    await sendUserTurn("Boucle Lille en juillet, peu de dénivelé, autonome");
    await screen.findByText("Noté");

    fireEvent.click(screen.getByTestId("ai-chat-launch"));
    const brief = onLaunchGeneration.mock.calls[0]?.[0] as string;
    expect(brief).toContain("elevationTolerance: medium");
    expect(brief).toContain("dates: juillet");
    expect(brief).toContain("resupply: autonomie");
  });
});
