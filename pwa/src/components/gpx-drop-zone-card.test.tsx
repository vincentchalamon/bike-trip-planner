import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { GpxDropZoneCard } from "./gpx-drop-zone-card";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string, params?: Record<string, string>) =>
    params ? `${key}:${JSON.stringify(params)}` : key,
}));

/**
 * Helper: locate the inner role="button" drop zone div within a render.
 * The outer wrapper exposes the `data-testid` from `dropZoneTestId`; the
 * actual interactive zone is the first descendant with role="button".
 */
function getDropZone(): HTMLElement {
  return screen.getByRole("button", { name: "ariaLabel" });
}

function getHiddenFileInput(): HTMLInputElement {
  return screen.getByTestId("gpx-drop-zone-input") as HTMLInputElement;
}

function makeGpxFile(name = "trip.gpx"): File {
  return new File(["<gpx/>"], name, { type: "application/gpx+xml" });
}

describe("GpxDropZoneCard", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it("renders the idle state by default with the upload icon and idle copy", () => {
    render(<GpxDropZoneCard onFileSelected={() => {}} />);
    const wrapper = screen.getByTestId("gpx-drop-zone-card");
    expect(wrapper).toHaveAttribute("data-status", "idle");
    expect(screen.getByText("idle")).toBeInTheDocument();
    expect(screen.getByTestId("gpx-drop-zone-browse")).toBeInTheDocument();
  });

  it("switches to the hovering state on dragover and clears it on dragleave", () => {
    render(<GpxDropZoneCard onFileSelected={() => {}} />);
    const wrapper = screen.getByTestId("gpx-drop-zone-card");
    const zone = getDropZone();

    fireEvent.dragOver(zone);
    expect(wrapper).toHaveAttribute("data-status", "hovering");
    expect(wrapper).toHaveAttribute("data-drag-over", "true");

    fireEvent.dragLeave(zone);
    expect(wrapper).toHaveAttribute("data-status", "idle");
    expect(wrapper).not.toHaveAttribute("data-drag-over");
  });

  it("calls onFileSelected with the dropped file", () => {
    const onFileSelected = vi.fn();
    render(<GpxDropZoneCard onFileSelected={onFileSelected} />);
    const file = makeGpxFile();
    const zone = getDropZone();

    fireEvent.drop(zone, { dataTransfer: { files: [file] } });
    expect(onFileSelected).toHaveBeenCalledTimes(1);
    expect(onFileSelected).toHaveBeenCalledWith(file);
  });

  it("calls onFileSelected when the hidden file input changes", () => {
    const onFileSelected = vi.fn();
    render(<GpxDropZoneCard onFileSelected={onFileSelected} />);
    const file = makeGpxFile("from-input.gpx");
    const input = getHiddenFileInput();

    fireEvent.change(input, { target: { files: [file] } });
    expect(onFileSelected).toHaveBeenCalledWith(file);
  });

  it("renders the uploading state non-interactively with file name and progress", () => {
    render(
      <GpxDropZoneCard
        onFileSelected={() => {}}
        state={{ status: "uploading", fileName: "trip.gpx", progress: 42 }}
      />,
    );
    const wrapper = screen.getByTestId("gpx-drop-zone-card");
    expect(wrapper).toHaveAttribute("data-status", "uploading");

    const zone = getDropZone();
    expect(zone).toHaveAttribute("tabIndex", "-1");
    expect(zone).toHaveAttribute("aria-busy", "true");

    expect(
      screen.getByTestId("gpx-drop-zone-uploading-name"),
    ).toHaveTextContent("trip.gpx");
    const progressBar = screen.getByRole("progressbar");
    expect(progressBar).toHaveAttribute("aria-valuenow", "42");
  });

  it("ignores drops while uploading", () => {
    const onFileSelected = vi.fn();
    render(
      <GpxDropZoneCard
        onFileSelected={onFileSelected}
        state={{ status: "uploading", fileName: "busy.gpx" }}
      />,
    );
    const zone = getDropZone();
    fireEvent.drop(zone, { dataTransfer: { files: [makeGpxFile()] } });
    expect(onFileSelected).not.toHaveBeenCalled();
  });

  it("renders the error state with a retry button that triggers the file picker", () => {
    const onFileSelected = vi.fn();
    render(
      <GpxDropZoneCard
        onFileSelected={onFileSelected}
        state={{ status: "error", message: "Format invalide" }}
      />,
    );
    const wrapper = screen.getByTestId("gpx-drop-zone-card");
    expect(wrapper).toHaveAttribute("data-status", "error");

    expect(screen.getByTestId("gpx-drop-zone-error")).toHaveTextContent(
      "Format invalide",
    );

    const retry = screen.getByTestId("gpx-drop-zone-retry");
    const input = getHiddenFileInput();
    const clickSpy = vi.spyOn(input, "click");

    fireEvent.click(retry);
    expect(clickSpy).toHaveBeenCalledTimes(1);
  });

  it("triggers the hidden file input on Enter and Space when focused on the drop zone", () => {
    render(<GpxDropZoneCard onFileSelected={() => {}} />);
    const zone = getDropZone();
    const input = getHiddenFileInput();
    const clickSpy = vi.spyOn(input, "click");

    fireEvent.keyDown(zone, { key: "Enter" });
    expect(clickSpy).toHaveBeenCalledTimes(1);

    fireEvent.keyDown(zone, { key: " " });
    expect(clickSpy).toHaveBeenCalledTimes(2);
  });

  it("forwards the ref to the inner drop zone element", () => {
    const ref = { current: null as HTMLDivElement | null };
    render(<GpxDropZoneCard ref={ref} onFileSelected={() => {}} />);
    expect(ref.current).not.toBeNull();
    expect(ref.current).toHaveAttribute("role", "button");
  });
});
