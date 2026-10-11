import type { ReactNode } from "react";
import RequireAuth from "@/components/auth/RequireAuth";
import AccountShell from "@/components/account/AccountShell";

/** Shared frame for every /account section: auth guard, then the nav +
 * content shell. Each section still guards itself as well, so pages stay
 * safe when rendered on their own. */
export default function AccountLayout({ children }: { children: ReactNode }) {
  return (
    <RequireAuth>
      <AccountShell>{children}</AccountShell>
    </RequireAuth>
  );
}
