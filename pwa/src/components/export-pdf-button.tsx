"use client";

import { Download, Loader2 } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";

export function ExportPdfButton() {
  const [loading, setLoading] = useState(false);
  const trip = useTripStore((s) => s.trip);
  const stages = useTripStore((s) => s.stages);
  const isProcessing = useUiStore((s) => s.isProcessing);

  const disabled = !trip || isProcessing || stages.length === 0;

  const handleExport = async () => {
    if (!trip) return;

    setLoading(true);
    try {
      const res = await fetch("/api/export-pdf", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ stages, title: trip.title }),
      });

      if (!res.ok) {
        throw new Error("PDF generation failed");
      }

      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `Roadbook_${trip.title.replace(/\s+/g, "_")}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch {
      toast.error("PDF generation failed. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex justify-center mt-12">
      <Button
        onClick={handleExport}
        disabled={disabled || loading}
        className="bg-brand hover:bg-brand-hover text-white rounded-full px-12 py-6 text-lg font-medium w-full md:w-auto md:py-6"
        data-testid="export-pdf-button"
      >
        {loading ? (
          <>
            <Loader2 className="h-5 w-5 animate-spin mr-2" />
            Generating...
          </>
        ) : isProcessing ? (
          "Computing..."
        ) : (
          <>
            <Download className="h-5 w-5 mr-2" />
            Export as PDF
          </>
        )}
      </Button>
    </div>
  );
}
