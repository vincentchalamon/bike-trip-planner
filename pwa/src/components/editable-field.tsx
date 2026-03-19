"use client";

import { Pencil } from "lucide-react";
import { Input } from "@/components/ui/input";
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

  if (isEditing) {
    return (
      <Input
        ref={inputRef}
        value={editValue}
        onChange={(e) => setEditValue(e.target.value)}
        onBlur={stopEditing}
        onKeyDown={handleKeyDown}
        className={cn(
          "bg-transparent border-none shadow-none focus-visible:ring-0 p-0 h-auto",
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
        "group inline-flex items-center gap-2 cursor-pointer",
        className,
      )}
      aria-label={ariaLabel}
      data-testid={testId}
    >
      <span className={cn(!value && "text-muted-foreground/60")}>
        {value || placeholder}
      </span>
      <Pencil className="h-3.5 w-3.5 text-muted-icon shrink-0" />
    </span>
  );
}
