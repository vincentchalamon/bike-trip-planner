import type { CapacitorConfig } from "@capacitor/cli";

const config: CapacitorConfig = {
  appId: "com.biketripplanner.app",
  appName: "Bike Trip Planner",
  webDir: "out",
  server: {
    androidScheme: "https",
  },
  android: {
    backgroundColor: "#0a0a0a",
    allowMixedContent: false,
  },
};

export default config;
