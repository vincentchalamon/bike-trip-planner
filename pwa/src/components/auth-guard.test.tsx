import { Profiler } from "react";
import { describe, it, expect, vi } from "vitest";
import { render, screen, act } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AuthGuard } from "./auth-guard";
import { useAuthStore } from "@/store/auth-store";

vi.mock("next/navigation", () => ({
  usePathname: () => "/trips",
  useRouter: () => ({ replace: vi.fn() }),
}));

describe("AuthGuard", () => {
  it("does not re-render when only the access token rotates", () => {
    useAuthStore.setState({ isAuthenticated: true, accessToken: "first" });
    const onRender = vi.fn();

    render(
      <Profiler id="guard" onRender={onRender}>
        <AuthGuard>
          <p>protected</p>
        </AuthGuard>
      </Profiler>,
    );
    expect(screen.getByText("protected")).toBeInTheDocument();
    const renders = onRender.mock.calls.length;

    act(() => useAuthStore.setState({ accessToken: "second" }));

    expect(onRender).toHaveBeenCalledTimes(renders);
  });
});
