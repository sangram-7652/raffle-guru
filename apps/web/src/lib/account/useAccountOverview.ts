"use client";

import { useEffect, useState } from "react";
import { getCompetition } from "@/lib/competitions/client";
import type { ApiCompetition } from "@/lib/competitions/types";
import { listAllEntries } from "@/lib/entries/client";
import type { ApiEntry } from "@/lib/entries/types";
import { listOrders } from "@/lib/orders/client";
import type { ApiOrder } from "@/lib/orders/types";
import { listWinners } from "@/lib/winners/client";
import { buildAccountSummary, type AccountSummary } from "./summary";

export type AccountOverview = {
  summary: AccountSummary;
  /** null when the wins endpoint failed — rendered as "—", never as 0. */
  winCount: number | null;
  /** null when the orders endpoint failed. */
  recentOrders: ApiOrder[] | null;
};

export type AccountOverviewState =
  | { kind: "loading" }
  | { kind: "error" }
  | { kind: "ready"; data: AccountOverview };

/**
 * Loads everything the account overview shows. Entries are essential (an
 * error there fails the whole view); wins, orders and per-competition
 * details are secondary and degrade individually.
 */
export function useAccountOverview(): AccountOverviewState {
  const [state, setState] = useState<AccountOverviewState>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setState({ kind: "loading" });
      try {
        const entries = await listAllEntries();
        const slugs = [...new Set(entries.map((e: ApiEntry) => e.competition.slug))];

        const [competitionResults, winsResult, ordersResult] = await Promise.all([
          Promise.allSettled(slugs.map((slug) => getCompetition(slug))),
          listWinners(1).then((res) => res.meta.total).catch(() => null),
          listOrders(1).then((res) => res.data).catch(() => null),
        ]);

        const competitions = new Map<string, ApiCompetition>();
        competitionResults.forEach((result, i) => {
          if (result.status === "fulfilled" && result.value) {
            competitions.set(slugs[i], result.value);
          }
        });

        if (cancelled) return;
        setState({
          kind: "ready",
          data: {
            summary: buildAccountSummary(entries, competitions, Date.now()),
            winCount: winsResult,
            recentOrders: ordersResult,
          },
        });
      } catch {
        if (!cancelled) setState({ kind: "error" });
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  return state;
}
