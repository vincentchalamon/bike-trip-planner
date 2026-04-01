import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const isMobile = process.env.BUILD_TARGET === "mobile";

const nextConfig: NextConfig = {
  output: isMobile ? "export" : "standalone",
  env: {
    NEXT_PUBLIC_IS_MOBILE_BUILD: isMobile ? "1" : "",
  },
  ...(isMobile
    ? {}
    : {
        async rewrites() {
          return [
            {
              source: "/api/:path*",
              destination: `${process.env.API_BACKEND_URL ?? "http://php"}/:path*`,
            },
          ];
        },
      }),
};

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");
export default withNextIntl(nextConfig);
