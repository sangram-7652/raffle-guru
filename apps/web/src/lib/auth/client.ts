/**
 * Auth API client — the only place that talks to Laravel's auth endpoints.
 * Cookie-based (Sanctum SPA), not a bearer token: the browser holds the
 * session/XSRF cookies itself (HttpOnly for the session, so this code
 * never even sees it), we just need to read the non-HttpOnly XSRF-TOKEN
 * cookie back into a header on state-changing requests.
 */
import { apiBaseUrl, apiOrigin } from "@/lib/config";

export type User = {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
};

export class ValidationError extends Error {
  errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]>) {
    super(message);
    this.name = "ValidationError";
    this.errors = errors;
  }

  /** First error message for a field, if any — convenient for form UI. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export class AuthError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "AuthError";
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

async function request<T>(
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
    throw new ValidationError(data?.message ?? "Validation failed.", data?.errors ?? {});
  }

  if (!response.ok) {
    throw new AuthError(data?.message ?? "Something went wrong.", response.status);
  }

  return data as T;
}

export function getCurrentUser(): Promise<{ data: User }> {
  return request<{ data: User }>("/v1/auth/user");
}

export function register(input: {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<{ data: User }> {
  return request<{ data: User }>("/v1/auth/register", { method: "POST", body: input });
}

export function login(input: { email: string; password: string }): Promise<{ data: User }> {
  return request<{ data: User }>("/v1/auth/login", { method: "POST", body: input });
}

export function logout(): Promise<void> {
  return request<void>("/v1/auth/logout", { method: "POST" });
}

export function forgotPassword(input: { email: string }): Promise<{ message: string }> {
  return request<{ message: string }>("/v1/auth/forgot-password", { method: "POST", body: input });
}

export function resetPassword(input: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> {
  return request<{ message: string }>("/v1/auth/reset-password", { method: "POST", body: input });
}

export function resendVerification(): Promise<{ message: string }> {
  return request<{ message: string }>("/v1/auth/email/verification-notification", {
    method: "POST",
  });
}
