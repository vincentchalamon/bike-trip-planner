import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.API_BACKEND_URL ?? "https://php"}/:path*`,
      },
    ];
  },
};

export default nextConfig;
