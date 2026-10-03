"use client";

import { useState } from "react";
import { resendVerification } from "@/lib/auth/client";

/** Shared resend-verification action for the overview banner and the
 * account details page. Errors are swallowed here, same as before the
 * redesign — the button simply returns to its idle state. */
export function useResendVerification() {
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);

  async function resend() {
    setSending(true);
    try {
      await resendVerification();
      setSent(true);
    } catch {
      // Left idle so the customer can try again.
    } finally {
      setSending(false);
    }
  }

  return { sending, sent, resend };
}
