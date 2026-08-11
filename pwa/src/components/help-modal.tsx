"use client";

import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { HelpCircle, Keyboard, MessageCircleQuestion } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { FaqAccordion, type FaqCategory } from "@/components/faq-accordion";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/store/ui-store";

/**
 * Unified help modal (#384) with two tabs:
 * - "Raccourcis clavier" — keyboard shortcuts (supersedes the previous
 *   standalone keyboard-help modal).
 * - "FAQ" — the same questions/answers as the `/faq` page, rendered inline.
 *
 * Opened/closed via the `isHelpModalOpen` flag in the UI store, toggled by the
 * `?` key (see `useKeyboardShortcuts`) or the top-bar help button.
 */
const modKey =
  typeof navigator !== "undefined" &&
  /Mac|iPhone|iPad/.test(navigator.userAgent)
    ? "⌘"
    : "Ctrl";

type HelpTab = "shortcuts" | "faq";

export function HelpModal() {
  const t = useTranslations("help");
  const tShortcuts = useTranslations("keyboardHelp");
  const tFaq = useTranslations("faq");
  const isOpen = useUiStore((s) => s.isHelpModalOpen);
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);
  const [activeTab, setActiveTab] = useState<HelpTab>("shortcuts");
  const tabRefs = useRef<Record<HelpTab, HTMLButtonElement | null>>({
    shortcuts: null,
    faq: null,
  });

  function handleTabKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") {
      return;
    }
    e.preventDefault();
    const next: HelpTab = activeTab === "shortcuts" ? "faq" : "shortcuts";
    setActiveTab(next);
    tabRefs.current[next]?.focus();
  }

  const faqCategories: FaqCategory[] = [
    {
      id: "project",
      label: tFaq("categoryProject"),
      items: [
        { question: tFaq("q1"), answer: tFaq("a1") },
        { question: tFaq("q2"), answer: tFaq("a2") },
        { question: tFaq("q3"), answer: tFaq("a3") },
        { question: tFaq("q4"), answer: tFaq("a4") },
      ],
    },
    {
      id: "how-it-works",
      label: tFaq("categoryHowItWorks"),
      items: [
        { question: tFaq("q5"), answer: tFaq("a5") },
        { question: tFaq("q6"), answer: tFaq("a6") },
        { question: tFaq("q7"), answer: tFaq("a7") },
      ],
    },
    {
      id: "access",
      label: tFaq("categoryAccess"),
      items: [
        { question: tFaq("q8"), answer: tFaq("a8") },
        { question: tFaq("q9"), answer: tFaq("a9") },
      ],
    },
  ];

  return (
    <Dialog open={isOpen} onOpenChange={setHelpModalOpen}>
      <DialogContent
        className="sm:max-w-lg max-h-[85vh] overflow-y-auto"
        data-testid="help-modal"
      >
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <HelpCircle className="h-5 w-5" />
            {t("title")}
          </DialogTitle>
          <DialogDescription>{t("description")}</DialogDescription>
        </DialogHeader>

        {/* Tabs */}
        <div
          role="tablist"
          aria-label={t("title")}
          className="flex gap-1 rounded-lg bg-muted p-1"
          onKeyDown={handleTabKeyDown}
        >
          <TabButton
            id="shortcuts"
            label={t("tabShortcuts")}
            icon={Keyboard}
            isActive={activeTab === "shortcuts"}
            onClick={() => setActiveTab("shortcuts")}
            ref={(el) => {
              tabRefs.current.shortcuts = el;
            }}
          />
          <TabButton
            id="faq"
            label={t("tabFaq")}
            icon={MessageCircleQuestion}
            isActive={activeTab === "faq"}
            onClick={() => setActiveTab("faq")}
            ref={(el) => {
              tabRefs.current.faq = el;
            }}
          />
        </div>

        {/* Shortcuts tab */}
        {activeTab === "shortcuts" && (
          <div
            role="tabpanel"
            id="help-tabpanel-shortcuts"
            aria-labelledby="help-tab-shortcuts"
            className="space-y-4"
            data-testid="help-tab-shortcuts-panel"
          >
            <section aria-labelledby="help-nav-heading">
              <h3
                id="help-nav-heading"
                className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
              >
                {tShortcuts("sectionNavigation")}
              </h3>
              <ShortcutTable
                shortcuts={[
                  { keys: ["J"], label: tShortcuts("nextStage") },
                  { keys: ["K"], label: tShortcuts("previousStage") },
                ]}
              />
            </section>

            <section aria-labelledby="help-history-heading">
              <h3
                id="help-history-heading"
                className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
              >
                {tShortcuts("sectionHistory")}
              </h3>
              <ShortcutTable
                shortcuts={[
                  { keys: [modKey, "Z"], label: tShortcuts("undo") },
                  { keys: [modKey, "Y"], label: tShortcuts("redo") },
                ]}
              />
            </section>

            <section aria-labelledby="help-ui-heading">
              <h3
                id="help-ui-heading"
                className="text-sm font-semibold text-muted-foreground uppercase tracking-wide mb-2"
              >
                {tShortcuts("sectionInterface")}
              </h3>
              <ShortcutTable
                shortcuts={[
                  { keys: ["?"], label: tShortcuts("toggleHelp") },
                  { keys: ["Esc"], label: tShortcuts("closePanels") },
                  { keys: ["T"], label: tShortcuts("toggleTheme") },
                  { keys: ["M"], label: tShortcuts("toggleMap") },
                ]}
              />
            </section>
          </div>
        )}

        {/* FAQ tab */}
        {activeTab === "faq" && (
          <div
            role="tabpanel"
            id="help-tabpanel-faq"
            aria-labelledby="help-tab-faq"
            data-testid="help-tab-faq-panel"
          >
            <FaqAccordion categories={faqCategories} />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

interface TabButtonProps {
  id: HelpTab;
  label: string;
  icon: typeof Keyboard;
  isActive: boolean;
  onClick: () => void;
  ref?: React.Ref<HTMLButtonElement>;
}

function TabButton({
  id,
  label,
  icon: Icon,
  isActive,
  onClick,
  ref,
}: TabButtonProps) {
  return (
    <button
      ref={ref}
      type="button"
      role="tab"
      id={`help-tab-${id}`}
      aria-selected={isActive}
      aria-controls={`help-tabpanel-${id}`}
      tabIndex={isActive ? 0 : -1}
      onClick={onClick}
      data-testid={`help-tab-${id}`}
      className={cn(
        "flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors cursor-pointer",
        isActive
          ? "bg-background text-foreground shadow-sm"
          : "text-muted-foreground hover:text-foreground",
      )}
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
      {label}
    </button>
  );
}

interface Shortcut {
  keys: string[];
  label: string;
}

function ShortcutTable({ shortcuts }: { shortcuts: Shortcut[] }) {
  return (
    <div className="space-y-1.5">
      {shortcuts.map((shortcut) => (
        <div
          key={shortcut.keys.join("+")}
          className="flex items-center justify-between gap-4"
        >
          <span className="text-sm">{shortcut.label}</span>
          <div className="flex items-center gap-1 shrink-0">
            {shortcut.keys.map((key, index) => (
              <span key={key} className="flex items-center gap-1">
                {index > 0 && (
                  <span className="text-xs text-muted-foreground">+</span>
                )}
                <kbd className="inline-flex items-center justify-center rounded border border-border bg-muted px-1.5 py-0.5 text-xs font-mono font-medium shadow-sm min-w-[1.5rem]">
                  {key}
                </kbd>
              </span>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}
