import type { Metadata } from "next";
import EmailChangeVerifyPage from "./verify-page";

// The token is in the fragment (see verify-page); no Referer leaves this page.
export const metadata: Metadata = { referrer: "no-referrer" };

export default function Page() {
  return <EmailChangeVerifyPage />;
}
