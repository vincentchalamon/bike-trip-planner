import type { Metadata } from "next";
import VerifyPage from "./verify-page";

// The token is in the fragment, which no Referer carries anyway; this keeps even
// the page path out of the requests the page triggers.
export const metadata: Metadata = { referrer: "no-referrer" };

export default function Page() {
  return <VerifyPage />;
}
