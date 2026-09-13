import "@testing-library/jest-dom/vitest";
import { afterEach } from "vitest";
import { cleanup } from "@testing-library/react";

afterEach(() => {
  cleanup();
});

// jsdom doesn't implement IntersectionObserver — several components
// (Reveal, StickyEnterBar) use it for scroll-driven behavior that isn't
// meaningful in a test DOM anyway.
class IntersectionObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
// @ts-expect-error — test-environment stub, not a spec-complete implementation
globalThis.IntersectionObserver = IntersectionObserverStub;
