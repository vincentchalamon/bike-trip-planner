"use client";

import { useEffect, useCallback } from "react";
import { Pencil } from "lucide-react";
import { useEditable } from "@/hooks/use-editable";
import { cn } from "@/lib/utils";

interface EditableFieldProps {
  value: string;
  onChange: (value: string) => void;
  className?: string;
  placeholder?: string;
  "aria-label": string;
  "data-testid"?: string;
}

export function EditableField({
  value,
  onChange,
  className,
  placeholder = "Click to edit",
  "aria-label": ariaLabel,
  "data-testid": testId,
}: EditableFieldProps) {
  const {
    isEditing,
    editValue,
    startEditing,
    stopEditing,
    setEditValue,
    inputRef,
    handleKeyDown,
  } = useEditable({ value, onChange });

  // Auto-resize textarea to fit content
  const autoResize = useCallback(() => {
    const el = inputRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  }, [inputRef]);

  useEffect(() => {
    if (isEditing) autoResize();
  }, [isEditing, editValue, autoResize]);

  if (isEditing) {
    return (
      <textarea
        ref={inputRef as React.RefObject<HTMLTextAreaElement>}
        value={editValue}
        onChange={(e) => {
          setEditValue(e.target.value);
          autoResize();
        }}
        onBlur={stopEditing}
        onKeyDown={handleKeyDown}
        rows={1}
        className={cn(
          "bg-transparent border-none shadow-none focus-visible:ring-0 p-0 h-auto w-full resize-none overflow-hidden",
          className,
        )}
        placeholder={placeholder}
        aria-label={ariaLabel}
        data-testid={testId}
      />
    );
  }

  return (
    <span
      role="button"
      tabIndex={0}
      onClick={startEditing}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          startEditing();
        }
      }}
      className={cn(
        "group inline-flex items-start gap-2 cursor-pointer",
        className,
      )}
      aria-label={ariaLabel}
      data-testid={testId}
    >
      <span className={cn("break-words", !value && "text-muted-foreground/60")}>
        {value || placeholder}
      </span>
      <Pencil className="h-3.5 w-3.5 text-muted-icon shrink-0 mt-1.5" />
    </span>
  );
}
