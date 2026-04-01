"use client";

import { createContext, useContext } from "react";

interface ShareContextValue {
  shortCode: string;
  title: string;
}

const ShareContext = createContext<ShareContextValue | null>(null);

export const ShareProvider = ShareContext.Provider;

export function useShareContext(): ShareContextValue | null {
  return useContext(ShareContext);
}
