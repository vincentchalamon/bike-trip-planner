"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";

export interface FaqItem {
  question: string;
  answer: string;
}

export interface FaqCategory {
  id: string;
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
        <p className="text-sm text-muted-foreground leading-relaxed">
          {answer}
        </p>
      </div>
    </div>
  );
}

interface FaqAccordionProps {
  categories: FaqCategory[];
}

export function FaqAccordion({ categories }: FaqAccordionProps) {
  const [openItems, setOpenItems] = useState<Record<string, boolean>>({});

  function toggleItem(key: string) {
    setOpenItems((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  return (
    <div className="space-y-10">
      {categories.map((category) => (
        <section
          key={category.id}
          aria-labelledby={`faq-category-${category.id}`}
        >
          <h2
            id={`faq-category-${category.id}`}
            className="text-lg font-semibold mb-4"
          >
            {category.label}
          </h2>
          <div className="rounded-lg border bg-card px-5">
            {category.items.map((item, index) => {
              const key = `${category.id}-${index}`;
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
  );
}
