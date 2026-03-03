"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { Loader2 } from "lucide-react";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Command,
  CommandInput,
  CommandList,
  CommandItem,
  CommandEmpty,
} from "@/components/ui/command";
import { searchPlaces, type GeocodeResult } from "@/lib/geocode/client";

interface LocationComboboxProps {
  value: string;
  onSelect: (result: GeocodeResult) => void;
  onCancel: () => void;
  placeholder?: string;
  "aria-label": string;
}

export function LocationCombobox({
  value,
  onSelect,
  onCancel,
  placeholder = "Search a place...",
  "aria-label": ariaLabel,
}: LocationComboboxProps) {
  const [open, setOpen] = useState(true);
  const [query, setQuery] = useState(value);
  const [results, setResults] = useState<GeocodeResult[]>([]);
  const [loading, setLoading] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const search = useCallback((q: string) => {
    if (debounceRef.current) clearTimeout(debounceRef.current);

    if (q.length < 2) {
      setResults([]);
      return;
    }

    debounceRef.current = setTimeout(async () => {
      setLoading(true);
      const data = await searchPlaces(q);
      setResults(data);
      setLoading(false);
    }, 300);
  }, []);

  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, []);

  const handleSelect = useCallback(
    (result: GeocodeResult) => {
      setOpen(false);
      onSelect(result);
    },
    [onSelect],
  );

  return (
    <Popover
      open={open}
      onOpenChange={(isOpen) => {
        setOpen(isOpen);
        if (!isOpen) onCancel();
      }}
    >
      <PopoverTrigger asChild>
        <span className="w-full" />
      </PopoverTrigger>
      <PopoverContent className="w-[300px] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput
            placeholder={placeholder}
            value={query}
            onValueChange={(val: string) => {
              setQuery(val);
              search(val);
            }}
            aria-label={ariaLabel}
          />
          <CommandList>
            {loading && (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            )}
            <CommandEmpty>{loading ? "" : "No results found"}</CommandEmpty>
            {results.map((result) => (
              <CommandItem
                key={`${result.lat}-${result.lon}`}
                onSelect={() => handleSelect(result)}
                className="cursor-pointer"
              >
                <div className="flex flex-col">
                  <span className="font-medium">{result.name}</span>
                  <span className="text-xs text-muted-foreground">
                    {result.displayName}
                  </span>
                </div>
              </CommandItem>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
