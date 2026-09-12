"use client";

import { useSyncExternalStore } from "react";

const noop = () => () => {};

/**
 * `false` during SSR and the first client render, `true` afterwards — via
 * useSyncExternalStore so there is no setState-in-effect and no hydration
 * mismatch. Use to gate reads of browser-only storage.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(
    noop,
    () => true,
    () => false,
  );
}
