"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import QuantityStepper from "../ui/QuantityStepper";
import ProgressBar from "../ui/ProgressBar";
import Countdown from "../ui/Countdown";
import CompetitionQuestion from "./CompetitionQuestion";
import { useCart } from "../cart/CartContext";
import { useAuth } from "@/lib/auth/AuthContext";
import { ApiValidationError } from "@/lib/api/http";
import { competitionAvailability } from "@/lib/competitionAvailability";
import { gbp, drawDateLong } from "@/lib/format";
import { routes } from "@/lib/routes";
import type { Competition } from "@/data/competitions";

const QUICK_PICKS = [1, 5, 10, 25];
const MAX = 100; // matches CartItem::MAX_QUANTITY server-side (see docs/ORDERS_API.md)

export default function EntrySelector({
  competition,
  domId = "enter",
}: {
  competition: Competition;
  domId?: string;
}) {
  const { addItem } = useCart();
  const { status: authStatus } = useAuth();
  const router = useRouter();
  const [qty, setQty] = useState(1);
  const [answered, setAnswered] = useState<boolean | null>(null);
  const [justAdded, setJustAdded] = useState(false);
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const availability = competitionAvailability(competition.status);
  const soldOut = !availability.purchasable || competition.soldPct >= 100;
  const total = competition.entryPrice * qty;

  async function add(goToCheckout: boolean) {
    if (authStatus === "guest") {
      router.push(`${routes.login}?redirect=${encodeURIComponent(routes.competition(competition.slug))}`);
      return;
    }

    setError(null);
    setAdding(true);
    try {
      await addItem(Number(competition.id), qty);
      if (goToCheckout) {
        router.push(routes.cart);
      } else {
        setJustAdded(true);
        window.setTimeout(() => setJustAdded(false), 2500);
      }
    } catch (err) {
      setError(
        err instanceof ApiValidationError
          ? (err.fieldError("competition_id") ?? err.fieldError("quantity") ?? err.message)
          : "Couldn't add this to your basket. Please try again.",
      );
    } finally {
      setAdding(false);
    }
  }

  return (
    <div
      id={domId}
      className="scroll-mt-24 rounded-card border border-line bg-card p-5 card-shadow-lg sm:p-6"
    >
      <div className="flex items-baseline justify-between gap-3">
        <div>
          <span className="font-display text-3xl font-extrabold text-heading">
            {gbp(competition.entryPrice)}
          </span>
          <span className="ml-1.5 text-sm text-muted">per entry</span>
        </div>
        {competition.cashAlternative && (
          <span className="text-xs font-medium text-gold">
            {competition.cashAlternative}
          </span>
        )}
      </div>

      <ProgressBar value={competition.soldPct} className="mt-5" />
      <p className="mt-1.5 text-xs text-muted">
        {competition.totalEntries.toLocaleString("en-GB")} tickets total
      </p>

      <div className="mt-4 flex items-center gap-2 rounded-xl border border-line bg-panel px-3.5 py-3">
        <Icon name="clock" className="h-4 w-4 shrink-0 text-gold" />
        <div className="min-w-0">
          <p className="text-xs text-muted">Draws {competition.drawLabel}</p>
          <Countdown
            target={competition.drawDate}
            className="text-sm font-semibold"
          />
        </div>
      </div>

      {soldOut ? (
        <div className="mt-5 rounded-xl border border-line bg-panel p-4 text-center text-sm text-muted">
          <p className="font-display font-bold text-heading">{availability.label}</p>
          <p className="mt-1">
            {availability.description || "This competition is no longer taking entries."}{" "}
            {competition.status !== "drawn" ? `The draw takes place ${competition.drawLabel}.` : null}
          </p>
          {competition.status === "drawn" && competition.winningTicketNumber !== undefined && (
            <p className="mt-2 font-display font-bold text-heading">
              Winning ticket: #{competition.winningTicketNumber}
            </p>
          )}
        </div>
      ) : (
        <>
          <div className="mt-6">
            <p className="t-label mb-2 text-heading">Number of entries</p>
            <div className="flex flex-wrap items-center gap-3">
              <QuantityStepper
                value={qty}
                onChange={setQty}
                min={1}
                max={MAX}
                size="lg"
                label="number of entries"
              />
              <div className="flex gap-1.5">
                {QUICK_PICKS.map((n) => (
                  <button
                    key={n}
                    type="button"
                    onClick={() => setQty(n)}
                    className={`h-8 rounded-full px-3 text-xs font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 ${
                      qty === n
                        ? "brand-gradient text-ink"
                        : "border border-line text-muted hover:text-heading"
                    }`}
                  >
                    {n}
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="mt-5">
            <CompetitionQuestion
              question={competition.question}
              onAnsweredChange={setAnswered}
            />
          </div>

          <div className="mt-5 flex items-center justify-between border-t border-line pt-4">
            <span className="text-sm text-muted">
              {qty} {qty === 1 ? "entry" : "entries"}
            </span>
            <span className="font-display text-xl font-extrabold text-heading">
              {gbp(total)}
            </span>
          </div>

          {answered === false && (
            <p className="mt-2 flex items-center gap-1.5 text-xs text-warning">
              <Icon name="close" className="h-3.5 w-3.5" />
              Choose the correct answer above to be entered.
            </p>
          )}

          {error && (
            <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-error-600">
              <Icon name="close" className="h-3.5 w-3.5" />
              {error}
            </p>
          )}

          <div className="mt-4 space-y-2">
            <Button
              block
              size="lg"
              onClick={() => add(true)}
              loading={adding}
              disabled={adding}
            >
              {justAdded ? "Added — go to basket" : "Enter now"}
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
            <Button
              block
              variant="secondary"
              onClick={() => add(false)}
              aria-live="polite"
              loading={adding}
              disabled={adding}
            >
              {justAdded ? (
                <>
                  <Icon name="check" className="h-4 w-4" />
                  Added to basket
                </>
              ) : (
                "Add to basket"
              )}
            </Button>
          </div>

          <p className="mt-3 flex items-center justify-center gap-1.5 text-xs text-muted">
            <Icon name="lock" className="h-3.5 w-3.5" />
            No payment is taken yet
          </p>
        </>
      )}

      <p className="mt-4 border-t border-line pt-3 text-[11px] leading-relaxed text-muted">
        Draw: {drawDateLong(competition.drawDate)}. 18+, UK residents only. See{" "}
        <a
          href={routes.competitionRules}
          className="underline underline-offset-2"
        >
          Competition Rules
        </a>
        .
      </p>
    </div>
  );
}
