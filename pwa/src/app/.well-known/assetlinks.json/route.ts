import { NextResponse } from "next/server";

/**
 * Digital Asset Links for Android App Links (ADR-053). The magic link lives on
 * the web domain (FRONTEND_URL); to have an App Link open the native app instead
 * of the browser, that domain must serve `/.well-known/assetlinks.json` verifying
 * the app package + signing certificate.
 *
 * Env-driven: `ANDROID_APP_PACKAGE` and `ANDROID_SHA256_CERT_FINGERPRINTS`
 * (comma-separated list of SHA-256 fingerprints). Read at runtime, so the route
 * must stay dynamic: a parameterless GET handler is otherwise prerendered at
 * build time (baking in the empty `[]`), and the env would never take effect
 * without a rebuild. Until the app ships, the env vars are unset and we serve an
 * empty `[]` — a valid, association-free statement list.
 */
export const dynamic = "force-dynamic";

export function GET() {
  const packageName = process.env.ANDROID_APP_PACKAGE;
  const fingerprints = (process.env.ANDROID_SHA256_CERT_FINGERPRINTS ?? "")
    .split(",")
    .map((f) => f.trim())
    .filter((f) => f.length > 0);

  if (!packageName || fingerprints.length === 0) {
    return NextResponse.json([]);
  }

  return NextResponse.json([
    {
      relation: ["delegate_permission/common.handle_all_urls"],
      target: {
        namespace: "android_app",
        package_name: packageName,
        sha256_cert_fingerprints: fingerprints,
      },
    },
  ]);
}
