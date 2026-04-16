"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronDown, ArrowLeft } from "lucide-react";
import { useTranslations } from "next-intl";

interface FaqItem {
  question: string;
  answer: string;
}

interface FaqCategory {
  label: string;
  items: FaqItem[];
}

interface AccordionItemProps {
  question: string;
  answer: string;
  isOpen: boolean;
  onToggle: () => void;
}

function AccordionItem({
  question,
  answer,
  isOpen,
  onToggle,
}: AccordionItemProps) {
  return (
    <div className="border-b border-border last:border-0">
      <button
        type="button"
        className="flex w-full items-center justify-between gap-4 py-4 text-left text-sm font-medium transition-colors hover:text-foreground/80"
        aria-expanded={isOpen}
        onClick={onToggle}
      >
        <span>{question}</span>
        <ChevronDown
          className={`h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 ${
            isOpen ? "rotate-180" : ""
          }`}
          aria-hidden="true"
        />
      </button>
      <div
        className={`overflow-hidden transition-all duration-200 ${
          isOpen ? "max-h-96 pb-4" : "max-h-0"
        }`}
      >
        <p className="text-sm text-muted-foreground leading-relaxed">{answer}</p>
      </div>
    </div>
  );
}

export default function FaqPage() {
  const t = useTranslations("faq");
  const [openItems, setOpenItems] = useState<Record<string, boolean>>({});

  const categories: FaqCategory[] = [
    {
      label: t("categoryProject"),
      items: [
        { question: t("q1"), answer: t("a1") },
        { question: t("q2"), answer: t("a2") },
        { question: t("q3"), answer: t("a3") },
        { question: t("q4"), answer: t("a4") },
      ],
    },
    {
      label: t("categoryHowItWorks"),
      items: [
        { question: t("q5"), answer: t("a5") },
        { question: t("q6"), answer: t("a6") },
        { question: t("q7"), answer: t("a7") },
      ],
    },
    {
      label: t("categoryAccess"),
      items: [
        { question: t("q8"), answer: t("a8") },
        { question: t("q9"), answer: t("a9") },
      ],
    },
  ];

  function toggleItem(key: string) {
    setOpenItems((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  return (
    <main className="max-w-2xl mx-auto px-4 md:px-6 py-12">
      {/* Back link */}
      <Link
        href="/"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-8 transition-colors"
        data-testid="faq-back-link"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t("backToHome")}
      </Link>

      {/* Header */}
      <div className="mb-10">
        <h1 className="text-3xl font-bold tracking-tight mb-2">{t("title")}</h1>
        <p className="text-muted-foreground">{t("description")}</p>
      </div>

      {/* FAQ Categories */}
      <div className="space-y-10">
        {categories.map((category) => (
          <section key={category.label} aria-labelledby={`faq-category-${category.label}`}>
            <h2
              id={`faq-category-${category.label}`}
              className="text-lg font-semibold mb-4"
            >
              {category.label}
            </h2>
            <div className="rounded-lg border bg-card px-5">
              {category.items.map((item) => {
                const key = `${category.label}-${item.question}`;
                return (
                  <AccordionItem
                    key={key}
                    question={item.question}
                    answer={item.answer}
                    isOpen={!!openItems[key]}
                    onToggle={() => toggleItem(key)}
                  />
                );
              })}
            </div>
          </section>
        ))}
      </div>

      {/* Footer link back */}
      <div className="mt-12 pt-6 border-t text-center">
        <Link
          href="/"
          className="text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          {t("backToHome")}
        </Link>
      </div>
    </main>
  );
}
