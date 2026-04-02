import dynamic from "next/dynamic";

const TripPage = dynamic(() => import("./trip-page"), {
  loading: () => null,
});

export function generateStaticParams() {
  return [{ id: "__placeholder" }];
}

export default function Page() {
  return <TripPage />;
}
