import Icon from "@/components/ui/Icon";

type VerificationBannerProps = {
  sending: boolean;
  sent: boolean;
  onResend: () => void;
};

/** Shown while the account's email is unverified. Resend is the existing
 * verification action; the caller owns the request state. */
export default function VerificationBanner({ sending, sent, onResend }: VerificationBannerProps) {
  return (
    <div
      role="status"
      className="flex flex-col gap-3 rounded-card border border-warning/25 bg-warning/10 p-4 text-sm sm:flex-row sm:items-center sm:justify-between sm:p-5"
    >
      <div className="flex items-start gap-3">
        <Icon name="lock" className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
        <div>
          <p className="font-semibold text-heading">Verify your email address</p>
          <p className="mt-1 text-muted">
            {sent
              ? "A new verification link is on its way — check your inbox."
              : "Check your inbox for the verification link, or send it again."}
          </p>
        </div>
      </div>
      {!sent && (
        <button
          type="button"
          onClick={onResend}
          disabled={sending}
          className="shrink-0 self-start rounded-full border border-gold/40 bg-card px-4 py-2 text-sm font-semibold text-heading transition-colors hover:border-gold hover:text-gold-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold-700/60 disabled:opacity-50 sm:self-auto"
        >
          {sending ? "Sending…" : "Resend verification email"}
        </button>
      )}
    </div>
  );
}
