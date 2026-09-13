/**
 * Shared CSRF-aware authenticated request helper for the cart and orders
 * clients (P5) — both need the identical Sanctum CSRF-cookie handshake
 * @/lib/auth/client (P3) already established. Extracted here rather than
 * duplicated a third time; auth/client.ts itself is left untouched since
 * it's already tested and working — no reason to risk it for a refactor.
 */
import { apiBaseUrl, apiOrigin } from "@/lib/config";

export class ApiValidationError extends Error {
  errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]>) {
    super(message);
    this.name = "ApiValidationError";
    this.errors = errors;
  }

  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
}

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.split("=").slice(1).join("=")) : null;
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${apiOrigin}/sanctum/csrf-cookie`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

export async function apiRequest<T>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const { method = "GET", body } = options;

  if (method !== "GET") {
    await ensureCsrfCookie();
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    method,
    credentials: "include",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(method !== "GET" ? { "X-XSRF-TOKEN": readCookie("XSRF-TOKEN") ?? "" } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const data = await response.json().catch(() => null);

  if (response.status === 422) {
    throw new ApiValidationError(data?.message ?? "Validation failed.", data?.errors ?? {});
  }

  if (!response.ok) {
    throw new ApiError(data?.message ?? "Something went wrong.", response.status);
  }

  return data as T;
}
