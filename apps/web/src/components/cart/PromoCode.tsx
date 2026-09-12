"use client";

import { useState } from "react";
import { useCart } from "./CartContext";
import Button from "../ui/Button";
import Icon from "../ui/Icon";

export default function PromoCode() {
  const { promo, applyPromo, removePromo } = useCart();
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);

  if (promo) {
    return (
      <div className="flex items-center justify-between rounded-xl border border-success/30 bg-success/10 px-3.5 py-2.5 text-sm">
        <span className="flex items-center gap-2 font-medium text-success-600">
          <Icon name="check" className="h-4 w-4" />
          {promo.code} — {promo.label}
        </span>
        <button
          type="button"
          onClick={removePromo}
          className="text-xs font-semibold text-muted underline underline-offset-2 hover:text-heading"
        >
          Remove
        </button>
      </div>
    );
  }

  return (
    <div>
      <label htmlFor="promo" className="t-label text-heading">
        Promo code
      </label>
      <div className="mt-1.5 flex gap-2">
        <input
          id="promo"
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              if (applyPromo(value)) setValue("");
              else setError("That code isn't valid. Try RAFFLE10.");
            }
          }}
          placeholder="e.g. RAFFLE10"
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? "promo-err" : undefined}
          className={`h-11 flex-1 rounded-md border bg-card px-3.5 text-sm uppercase text-heading placeholder:normal-case placeholder:text-muted/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pink/40 ${
            error
              ? "border-error focus-visible:ring-error/30"
              : "border-line focus-visible:border-pink-600"
          }`}
        />
        <Button
          type="button"
          variant="secondary"
          disabled={!value.trim()}
          onClick={() => {
            if (applyPromo(value)) setValue("");
            else setError("That code isn't valid. Try RAFFLE10.");
          }}
        >
          Apply
        </Button>
      </div>
      {error && (
        <p
          id="promo-err"
          className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-error-600"
        >
          <Icon name="close" className="h-3.5 w-3.5" />
          {error}
        </p>
      )}
    </div>
  );
}
