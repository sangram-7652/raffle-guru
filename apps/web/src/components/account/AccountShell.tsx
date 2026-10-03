"use client";

import { useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import Container from "@/components/layout/Container";
import { useAuth } from "@/lib/auth/AuthContext";
import { routes } from "@/lib/routes";
import AccountNav from "./AccountNav";

/**
 * Two-column account frame: navigation beside the section content on
 * desktop, navigation tiles above it on tablet and mobile. Owns sign-out
 * so the nav stays presentational.
 */
export default function AccountShell({ children }: { children: ReactNode }) {
  const { logout } = useAuth();
  const router = useRouter();
  const [signingOut, setSigningOut] = useState(false);

  async function onSignOut() {
    setSigningOut(true);
    try {
      await logout();
      router.push(routes.home);
    } finally {
      setSigningOut(false);
    }
  }

  return (
    <Container className="pb-20 pt-8 sm:pt-12">
      <div className="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-10">
        <AccountNav onSignOut={onSignOut} signingOut={signingOut} />
        <div className="mt-8 min-w-0 lg:mt-0">{children}</div>
      </div>
    </Container>
  );
}
