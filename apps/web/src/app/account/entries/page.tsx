"use client";

import { useEffect, useState } from "react";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import EmptyState from "@/components/ui/EmptyState";
import RequireAuth from "@/components/auth/RequireAuth";
import AccountPageHeading from "@/components/account/AccountPageHeading";
import { listEntries } from "@/lib/entries/client";
import type { ApiEntry } from "@/lib/entries/types";
import { toDisplayStatus } from "@/lib/competitions/adapter";
import { competitionAvailability } from "@/lib/competitionAvailability";
import { dateMedium } from "@/lib/format";
import { routes } from "@/lib/routes";

type State =
  | { kind: "loading" }
  | { kind: "error" }
  | { kind: "ready"; entries: ApiEntry[] };

function EntriesList() {
  const [state, setState] = useState<State>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setState({ kind: "loading" });
      try {
        const { data } = await listEntries(1);
        if (!cancelled) setState({ kind: "ready", entries: data });
      } catch {
        if (!cancelled) setState({ kind: "error" });
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  if (state.kind === "loading") {
    return (
      <div className="space-y-3" aria-hidden="true">
        <div className="h-24 animate-pulse rounded-card bg-mist" />
        <div className="h-24 animate-pulse rounded-card bg-mist" />
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <EmptyState
        icon="ticket"
        title="Couldn't load your entries"
        description="Something went wrong reaching the server. Please try again."
        action={
          <Button variant="secondary" onClick={() => window.location.reload()}>
            Retry
          </Button>
        }
      />
    );
  }

  if (state.entries.length === 0) {
    return (
      <EmptyState
        icon="ticket"
        title="No entries yet"
        description="Once you've bought and paid for an entry, your ticket numbers will show up here."
        action={<Button href={routes.competitions}>Browse competitions</Button>}
      />
    );
  }

  return (
    <ul className="divide-y divide-line overflow-hidden rounded-card border border-line bg-card card-shadow">
      {state.entries.map((entry) => {
        const displayStatus = toDisplayStatus(entry.competition.status);
        const availability = competitionAvailability(displayStatus);

        return (
          <li key={entry.id} className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-display text-base font-extrabold tracking-tight text-heading">
                  {entry.competition.title}
                </p>
                {!availability.purchasable && (
                  <span className="rounded-full border border-line bg-panel px-2.5 py-0.5 t-caption font-semibold text-muted">
                    {availability.label}
                  </span>
                )}
              </div>
              <p className="mt-1 text-xs text-muted">
                Order {entry.order_reference}
                {entry.allocated_at && <> · Allocated {dateMedium(entry.allocated_at)}</>}
              </p>
            </div>

            <div className="flex items-center gap-2 rounded-lg border border-gold/30 bg-mist px-3 py-2 text-sm sm:shrink-0">
              <Icon name="ticket" className="h-4 w-4 shrink-0 text-gold-700" />
              <span className="font-semibold tabular-nums text-heading">
                {entry.ticket_numbers.map((n) => `#${n}`).join(", ")}
              </span>
            </div>
          </li>
        );
      })}
    </ul>
  );
}

export default function AccountEntriesPage() {
  return (
    <RequireAuth>
      <div className="space-y-8">
        <AccountPageHeading
          eyebrow="Account"
          title="My entries"
          lede="Every competition you've entered, with the ticket numbers allocated to you."
        />
        <EntriesList />
      </div>
    </RequireAuth>
  );
}
