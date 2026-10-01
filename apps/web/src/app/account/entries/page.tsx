"use client";

import { useEffect, useState } from "react";
import { Container, PageHeader } from "@/components/layout";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import EmptyState from "@/components/ui/EmptyState";
import RequireAuth from "@/components/auth/RequireAuth";
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
      <div className="space-y-3">
        <div className="h-24 rounded-card border border-line bg-mist" />
        <div className="h-24 rounded-card border border-line bg-mist" />
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
    <ul className="divide-y divide-line rounded-card border border-line bg-card">
      {state.entries.map((entry) => {
        const displayStatus = toDisplayStatus(entry.competition.status);
        const availability = competitionAvailability(displayStatus);

        return (
        <li key={entry.id} className="flex flex-wrap items-center justify-between gap-3 p-5">
          <div className="min-w-0">
            <p className="font-display text-sm font-extrabold tracking-tight text-heading">
              {entry.competition.title}
              {!availability.purchasable && (
                <span className="ml-2 rounded-full bg-mist px-2 py-0.5 text-[11px] font-medium text-muted">
                  {availability.label}
                </span>
              )}
            </p>
            <p className="mt-1 text-xs text-muted">
              Order {entry.order_reference}
              {entry.allocated_at && <> · Allocated {dateMedium(entry.allocated_at)}</>}
            </p>
          </div>
          <div className="flex items-center gap-1.5 text-sm">
            <Icon name="ticket" className="h-4 w-4 shrink-0 text-gold" />
            <span className="font-medium text-heading">
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
      <PageHeader
        eyebrow="Account"
        title="My entries"
        breadcrumbs={[
          { label: "Account", href: routes.account },
          { label: "My entries" },
        ]}
      />
      <Container className="pb-16">
        <EntriesList />
      </Container>
    </RequireAuth>
  );
}
