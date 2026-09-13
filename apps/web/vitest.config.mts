import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  resolve: {
    tsconfigPaths: true,
  },
  plugins: [react()],
  test: {
    environment: "jsdom",
    setupFiles: ["./vitest.setup.ts"],
    // The default 5s can be tight for tests that fill out a full multi-
    // field form via userEvent (realistic per-keystroke timing) when the
    // whole suite runs under CPU contention across many parallel jsdom
    // environments — individual tests are correct and fast in isolation.
    testTimeout: 15000,
  },
});
