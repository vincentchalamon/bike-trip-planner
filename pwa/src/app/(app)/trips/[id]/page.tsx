import dynamic from "next/dynamic";

const TripPage = dynamic(() => import("./trip-page"), {
  loading: () => null,
});

export default function Page() {
  return <TripPage />;
}
