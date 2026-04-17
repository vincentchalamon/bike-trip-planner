import dynamic from "next/dynamic";

const VerifyPage = dynamic(() => import("./verify-page"), {
  loading: () => null,
});

export default function Page() {
  return <VerifyPage />;
}
