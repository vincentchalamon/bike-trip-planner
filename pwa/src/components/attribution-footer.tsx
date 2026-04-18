"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";

export function AttributionFooter() {
  const t = useTranslations("attribution");
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        onClick={() => setOpen(true)}
        className="text-xs text-muted-foreground hover:text-foreground transition-colors underline underline-offset-2"
        data-testid="attribution-footer-link"
      >
        {t("link")}
      </button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent
          className="max-w-md"
          data-testid="attribution-modal"
        >
          <DialogHeader>
            <DialogTitle>{t("title")}</DialogTitle>
            <DialogDescription>{t("description")}</DialogDescription>
          </DialogHeader>

          <ul className="space-y-3 text-sm" data-testid="attribution-list">
            <li>
              <p className="font-medium">OpenStreetMap</p>
              <p className="text-muted-foreground">
                {t("osmCredit")}{" "}
                <a
                  href="https://opendatacommons.org/licenses/odbl/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline hover:text-foreground"
                  data-testid="attribution-osm-link"
                >
                  ODbL
                </a>
              </p>
            </li>
            <li>
              <p className="font-medium">DataTourisme</p>
              <p className="text-muted-foreground">
                {t("datatourismeCredit")}{" "}
                <a
                  href="https://www.etalab.gouv.fr/licence-ouverte-open-licence"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline hover:text-foreground"
                  data-testid="attribution-datatourisme-link"
                >
                  {t("licenceOuverte")}
                </a>
              </p>
            </li>
            <li>
              <p className="font-medium">Wikidata</p>
              <p className="text-muted-foreground">
                {t("wikidataCredit")}{" "}
                <a
                  href="https://creativecommons.org/publicdomain/zero/1.0/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline hover:text-foreground"
                  data-testid="attribution-wikidata-link"
                >
                  CC0
                </a>
              </p>
            </li>
            <li>
              <p className="font-medium">data.gouv.fr</p>
              <p className="text-muted-foreground">
                {t("datagouvCredit")}{" "}
                <a
                  href="https://www.etalab.gouv.fr/licence-ouverte-open-licence"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline hover:text-foreground"
                  data-testid="attribution-datagouv-link"
                >
                  {t("licenceOuverte")}
                </a>
              </p>
            </li>
          </ul>
        </DialogContent>
      </Dialog>
    </>
  );
}
