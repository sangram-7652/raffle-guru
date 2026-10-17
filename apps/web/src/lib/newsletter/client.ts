/**
 * Newsletter signup API client — the only module that calls
 * /api/v1/newsletter/subscribe. Public/unauthenticated, but still goes
 * through apiRequest's Sanctum CSRF handshake: every non-GET /api/* route
 * is covered by statefulApi(), not just the authenticated ones.
 */
import { apiRequest } from "@/lib/api/http";

export function subscribeToNewsletter(input: { email: string }): Promise<{ data: { message: string } }> {
  return apiRequest<{ data: { message: string } }>("/v1/newsletter/subscribe", {
    method: "POST",
    body: input,
  });
}
