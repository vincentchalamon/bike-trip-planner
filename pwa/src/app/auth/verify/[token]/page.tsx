import dynamic from "next/dynamic";

const VerifyPage = dynamic(() => import("./verify-page"), {
  loading: () => null,
});

// Required for static export (Capacitor mobile build).
// Placeholder value: Next.js 16 treats empty arrays as missing.
export function generateStaticParams() {
  return [{ token: "__placeholder" }];
}

export default function Page() {
  return <VerifyPage />;
}
