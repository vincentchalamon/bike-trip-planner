import OAuthConsentPage from "./consent-page";

export default async function Page({
  params,
}: {
  params: Promise<{ handle: string }>;
}) {
  const { handle } = await params;
  return <OAuthConsentPage handle={handle} />;
}
