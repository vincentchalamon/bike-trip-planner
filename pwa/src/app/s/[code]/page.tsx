import dynamic from "next/dynamic";

const SharedTripPage = dynamic(() => import("./shared-trip-page"), {
  loading: () => null,
});

export function generateStaticParams() {
  return [{ code: "__placeholder" }];
}

export default function Page() {
  return <SharedTripPage />;
}
