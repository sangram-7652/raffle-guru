import { afterEach, describe, expect, test, vi } from "vitest";
import { subscribeToNewsletter } from "@/lib/newsletter/client";
import { ApiValidationError } from "@/lib/api/http";

function jsonResponse(body: unknown, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as Response;
}

describe("newsletter client", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  test("subscribeToNewsletter posts the email and returns the success message", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ data: { message: "You're on the list." } }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const result = await subscribeToNewsletter({ email: "jane@example.com" });

    expect(result.data.message).toBe("You're on the list.");
    const postCall = fetchMock.mock.calls.find(([, init]) => init?.method === "POST");
    expect(postCall?.[0]).toContain("/v1/newsletter/subscribe");
    expect(JSON.parse(postCall?.[1]?.body as string)).toEqual({ email: "jane@example.com" });
  });

  test("a 422 response surfaces as an ApiValidationError with a field error", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse({ message: "Validation failed.", errors: { email: ["The email field is required."] } }, 422),
      ),
    );

    await expect(subscribeToNewsletter({ email: "" })).rejects.toBeInstanceOf(ApiValidationError);
  });
});
