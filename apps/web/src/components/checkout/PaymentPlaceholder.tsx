import Icon from "../ui/Icon";

/**
 * Non-functional payment section. Fields are disabled and no payment
 * provider is connected — this only shows where card entry would live.
 */
export default function PaymentPlaceholder() {
  return (
    <fieldset
      disabled
      className="rounded-2xl border border-dashed border-line bg-mist p-5"
    >
      <legend className="flex items-center gap-2 px-1 font-display text-sm font-bold tracking-tight text-heading">
        <Icon name="lock" className="h-4 w-4 text-pink-600" />
        Payment
      </legend>

      <div className="mt-3 grid gap-3">
        <div className="rounded-md border border-line bg-card px-3.5 py-3 text-sm text-muted/70">
          Card number
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="rounded-md border border-line bg-card px-3.5 py-3 text-sm text-muted/70">
            MM / YY
          </div>
          <div className="rounded-md border border-line bg-card px-3.5 py-3 text-sm text-muted/70">
            CVC
          </div>
        </div>
      </div>

      <p className="mt-3 flex items-start gap-2 text-xs text-muted">
        <Icon name="shield" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        No payment provider is connected in this prototype. These fields are
        disabled and nothing is charged.
      </p>
    </fieldset>
  );
}
