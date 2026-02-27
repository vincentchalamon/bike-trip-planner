"use client";

import { useState, useRef } from "react";

interface UseEditableOptions {
  value: string;
  onChange: (value: string) => void;
}

interface UseEditableReturn {
  isEditing: boolean;
  editValue: string;
  startEditing: () => void;
  stopEditing: () => void;
  cancel: () => void;
  setEditValue: (value: string) => void;
  inputRef: React.RefObject<HTMLInputElement | null>;
  handleKeyDown: (e: React.KeyboardEvent) => void;
}

export function useEditable({
  value,
  onChange,
}: UseEditableOptions): UseEditableReturn {
  const [isEditing, setIsEditing] = useState(false);
  const [editValue, setEditValue] = useState(value);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const prevValueRef = useRef(value);

  // Sync editValue with external value when not editing (replaces useEffect)
  if (!isEditing && prevValueRef.current !== value) {
    prevValueRef.current = value;
    setEditValue(value);
  }

  function startEditing() {
    setEditValue(value);
    setIsEditing(true);
    requestAnimationFrame(() => {
      inputRef.current?.focus();
      inputRef.current?.select();
    });
  }

  function stopEditing() {
    setIsEditing(false);
    if (editValue.trim() && editValue !== value) {
      onChange(editValue.trim());
    } else {
      setEditValue(value);
    }
  }

  function cancel() {
    setIsEditing(false);
    setEditValue(value);
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      e.preventDefault();
      stopEditing();
    } else if (e.key === "Escape") {
      e.preventDefault();
      cancel();
    }
  }

  return {
    isEditing,
    editValue,
    startEditing,
    stopEditing,
    cancel,
    setEditValue,
    inputRef,
    handleKeyDown,
  };
}
