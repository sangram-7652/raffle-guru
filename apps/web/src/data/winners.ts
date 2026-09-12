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

const AV = (n: number) => `https://i.pravatar.cc/320?img=${n}`;

export const winners: Winner[] = [
  {
    id: "w1",
    name: "James P.",
    location: "Leeds",
    prize: "BMW M4 Competition",
    prizeValue: 84000,
    competition: "BMW M4 Competition — August",
    ticket: "#18429",
    drawDate: "2026-08-24T20:00:00Z",
    photo: AV(12),
    quote: "Still can't believe it. The car was on my drive within two weeks.",
  },
  {
    id: "w2",
    name: "Priya S.",
    location: "Birmingham",
    prize: "£25,000 Tax-Free Cash",
    prizeValue: 25000,
    competition: "£25k Tax-Free Cash",
    ticket: "#04211",
    drawDate: "2026-08-22T19:00:00Z",
    photo: AV(45),
    quote: "Money was in my account on the Monday. Paid off the car and booked a holiday.",
  },
  {
    id: "w3",
    name: "Callum M.",
    location: "Glasgow",
    prize: "Rolex Submariner Date",
    prizeValue: 13500,
    competition: "Rolex Submariner Date",
    ticket: "#27760",
    drawDate: "2026-08-17T18:00:00Z",
    photo: AV(33),
  },
  {
    id: "w4",
    name: "Sophie T.",
    location: "Bristol",
    prize: "Luxury Tech Bundle",
    prizeValue: 6500,
    competition: "Luxury Tech Bundle",
    ticket: "#11902",
    drawDate: "2026-08-14T19:00:00Z",
    photo: AV(47),
  },
  {
    id: "w5",
    name: "Marcus D.",
    location: "London",
    prize: "£10,000 Cash",
    prizeValue: 10000,
    competition: "£10k Midweek Cash",
    ticket: "#65014",
    drawDate: "2026-08-13T19:00:00Z",
    photo: AV(15),
    quote: "Entered on my lunch break for 59p. Won ten grand that evening.",
  },
  {
    id: "w6",
    name: "Hannah W.",
    location: "Cardiff",
    prize: "Ducati Panigale V2",
    prizeValue: 17000,
    competition: "Ducati Panigale V2",
    ticket: "#38845",
    drawDate: "2026-08-11T19:00:00Z",
    photo: AV(44),
  },
  {
    id: "w7",
    name: "Oliver H.",
    location: "Manchester",
    prize: "£15,000 Cash",
    prizeValue: 15000,
    competition: "£15k Weekend Cash",
    ticket: "#52130",
    drawDate: "2026-08-10T20:00:00Z",
    photo: AV(52),
  },
  {
    id: "w8",
    name: "Amara O.",
    location: "Nottingham",
    prize: "Maldives Escape for Two",
    prizeValue: 12000,
    competition: "Maldives Escape for Two",
    ticket: "#09984",
    drawDate: "2026-08-03T19:00:00Z",
    photo: AV(26),
    quote: "We fly out in October. Absolute dream — thank you RaffleGuru.",
  },
  {
    id: "w9",
    name: "Ben R.",
    location: "Sheffield",
    prize: "Range Rover Sport",
    prizeValue: 92000,
    competition: "Range Rover Sport — July",
    ticket: "#71220",
    drawDate: "2026-07-27T20:00:00Z",
    photo: AV(59),
  },
  {
    id: "w10",
    name: "Grace L.",
    location: "Edinburgh",
    prize: "£5,000 Cash",
    prizeValue: 5000,
    competition: "£5k Flash Draw",
    ticket: "#33417",
    drawDate: "2026-07-24T19:00:00Z",
    photo: AV(20),
  },
  {
    id: "w11",
    name: "Daniel K.",
    location: "Belfast",
    prize: "Rolex Datejust 41",
    prizeValue: 11500,
    competition: "Rolex Datejust 41",
    ticket: "#48001",
    drawDate: "2026-07-19T18:00:00Z",
    photo: AV(68),
  },
  {
    id: "w12",
    name: "Ella F.",
    location: "Liverpool",
    prize: "Ultimate Gaming Setup",
    prizeValue: 4500,
    competition: "Ultimate Gaming Setup",
    ticket: "#15563",
    drawDate: "2026-07-14T19:00:00Z",
    photo: AV(24),
  },
];

export const winnerStats = [
  { value: "£4.2M+", label: "Total prizes paid" },
  { value: "31,000+", label: "Winners so far" },
  { value: "48 hrs", label: "Average cash payout" },
  { value: "600+", label: "Live draws completed" },
];
