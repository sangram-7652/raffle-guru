"use client";

import { useEffect } from "react";
import { useRouter, usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/AuthContext";
import { routes } from "@/lib/routes";

/**
 * Client-side guard: redirects guests to /login?redirect=<here>. Auth state
 * comes from AuthContext, which is itself derived from the server session
 * (GET /api/v1/auth/user) — not a locally-stored flag.
 */
export default function RequireAuth({ children }: { children: React.ReactNode }) {
  const { status } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (status === "guest") {
      router.replace(`${routes.login}?redirect=${encodeURIComponent(pathname)}`);
    }
  }, [status, router, pathname]);

  if (status === "loading" || status === "guest") {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <p className="text-sm text-muted">Loading…</p>
      </div>
    );
  }

  return <>{children}</>;
}
