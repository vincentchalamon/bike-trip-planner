import VerifyPage from "./verify-page";

type SearchParams = Record<string, string | string[] | undefined>;

// A repeated key keeps its first value, as `URLSearchParams.get()` did.
const first = (value: string | string[] | undefined) =>
  Array.isArray(value) ? value[0] : value;

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<SearchParams>;
}) {
  const { email, expires, signature } = await searchParams;
  return (
    <VerifyPage
      email={first(email)}
      expires={first(expires)}
      signature={first(signature)}
    />
  );
}
