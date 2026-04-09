import type { Page } from "@playwright/test";

let currentRecettePage: Page | undefined;

export function setCurrentRecettePage(page: Page): void {
  currentRecettePage = page;
}

export function clearCurrentRecettePage(): void {
  currentRecettePage = undefined;
}

export function getCurrentRecettePage(): Page {
  if (!currentRecettePage) {
    throw new Error("Current recette page is not initialized");
  }

  return currentRecettePage;
}
