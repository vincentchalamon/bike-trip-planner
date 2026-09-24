import dynamic from "next/dynamic";

const OAuthConsentPage = dynamic(() => import("./consent-page"), {
  loading: () => null,
});

export default function Page() {
  return <OAuthConsentPage />;
}
