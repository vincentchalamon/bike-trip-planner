import dynamic from "next/dynamic";

const EmailChangeVerifyPage = dynamic(() => import("./verify-page"), {
  loading: () => null,
});

export default function Page() {
  return <EmailChangeVerifyPage />;
}
