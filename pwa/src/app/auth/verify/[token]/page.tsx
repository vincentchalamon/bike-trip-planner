import VerifyPage from "./verify-page";

export default async function Page({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;
  return <VerifyPage token={token} />;
}
