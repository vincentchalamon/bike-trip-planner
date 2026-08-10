import dynamic from "next/dynamic";

const EmailChangeVerifyPage = dynamic(() => import("./verify-page"), {
  loading: () => null,
});

// Only prerender a placeholder for the static export (Capacitor mobile build);
// on web, return an empty set so the route stays dynamic and next-intl can read
// the `locale` cookie instead of being frozen to the `fr` fallback.
export function generateStaticParams() {
  return process.env.NEXT_PUBLIC_IS_MOBILE_BUILD === "1"
    ? [{ token: "__placeholder" }]
    : [];
}

export default function Page() {
  return <EmailChangeVerifyPage />;
}
