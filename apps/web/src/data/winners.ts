/**
 * RaffleGuru — Winners mock data (PROTOTYPE ONLY).
 * Sample names and results for UI demonstration. Not real people.
 */

export type Winner = {
  id: string;
  name: string;
  location: string;
  prize: string;
  prizeValue: number;
  competition: string;
  ticket: string;
  drawDate: string; // ISO
  photo: string;
  quote?: string;
};

export const winners: Winner[] = [
  {
    id: "w1",
    name: "James",
    location: "",
    prize: "Won a BMW M4",
    prizeValue: 84000,
    competition: "BMW M4 Competition",
    ticket: "#18429",
    drawDate: "2026-08-24T20:00:00Z",
    photo: "/images/winners/dream-car-giveaway-winner.png",
  },
  {
    id: "w2",
    name: "Adele",
    location: "",
    prize: "Won Apple Giveaway",
    prizeValue: 1199,
    competition: "Tech bundle giveaway",
    ticket: "#04211",
    drawDate: "2026-08-22T19:00:00Z",
    photo: "/images/winners/priya-iphone-unboxing.png",
  },
  {
    id: "w3",
    name: "Callum",
    location: "",
    prize: "Won a Rolex Submariner",
    prizeValue: 13500,
    competition: "Rolex Submariner Date",
    ticket: "#27760",
    drawDate: "2026-08-17T18:00:00Z",
    photo: "/images/winners/callum-rolex.png",
  },
];

export const winnerStats = [
  { value: "£4.2M+", label: "Total prizes paid" },
  { value: "31,000+", label: "Winners so far" },
  { value: "48 hrs", label: "Average cash payout" },
  { value: "600+", label: "Live draws completed" },
];
