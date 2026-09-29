import type { Metadata } from "next";
import VerifyPage from "./verify-page";

// The signed payload is in the fragment (see verify-page); no Referer leaves this page.
export const metadata: Metadata = { referrer: "no-referrer" };

export default function Page() {
  return <VerifyPage />;
}
