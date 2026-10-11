/**
 * RaffleGuru — Homepage mock data
 * ------------------------------------------------------------------
 * This is a CLIENT APPROVAL CONCEPT. Everything here is static
 * placeholder content. There are NO API calls anywhere in this app.
 * Swap this file for real data sources when the platform is built.
 */

export type Category = {
  id: string;
  name: string;
  tagline: string;
  image: string;
  /** Not shown: there is no per-category total in the public API. */
  count?: string;
};

export type Step = {
  number: string;
  title: string;
  copy: string;
};

export type Feature = {
  title: string;
  copy: string;
  icon: "shield" | "ticket" | "gift" | "trophy";
};

export type Winner = {
  id: string;
  name: string;
  location: string;
  prize: string;
  competition: string;
  ticket: string;
  /**
   * No authentic, authorized winner photos exist for this prototype's
   * sample data — deliberately omitted rather than filled with a stock
   * "random person" photo standing in for someone who didn't win
   * anything (see WinnerCard, which falls back to an initials badge
   * when this is absent). Only set this to a real URL once there is an
   * actual photo this specific winner consented to publish.
   */
  photo?: string;
};

const U = (id: string, w = 1200) =>
  `https://images.unsplash.com/photo-${id}?auto=format&fit=crop&w=${w}&q=80`;

export type HeroHighlight = {
  eyebrow: string;
  headline: string;
  altPrizeLine: string;
  ticketPrice: string;
  ctaLabel: string;
  ctaHref: string;
  supportingLine: string;
  image: string;
  imageAlt: string;
};

/**
 * Single-prize homepage hero. Replaces the previous 3-slide carousel,
 * whose lead slide was a stock "AI woman presenter" photo in front of
 * an unrelated pile of cars/tech (public/hero/raffle-guru-hero-1.jpg,
 * removed) — the thing this redesign was asked to get rid of. The
 * other two carousel slides (cash, watch) are also dropped rather than
 * kept as secondary slides: the brief asks for one focused prize, not
 * competing promotional messages.
 *
 * The headline figures below (£62,000 cash alternative, 99p ticket
 * price, Sunday 9PM draw, "limited entries") are carried over as-is
 * from what this file already had on the old "b-car" slide — not
 * newly invented for this change. They are still NOT backed by a live
 * competition record: `/competitions` is real-API-backed (see
 * @/data/competitions.ts), and no Porsche 718 Cayman GTS competition
 * exists in that data. Treat these as prototype concept copy, not
 * live facts, until a matching competition is actually published
 * through the real API — flagged here rather than silently presented
 * as verified.
 *
 * Image: a genuine Unsplash photo of a Porsche 718 Cayman (model
 * family confirmed via the photo's own page). No "GTS" badge is
 * visible on the car, so the specific GTS 4.0 trim isn't verifiable
 * from the image alone — no free, confirmed GTS-badged photo could be
 * found. Using the closest genuine match (same model family, correct
 * body) rather than substituting an unrelated car (a 911, Panamera,
 * etc. would be a materially different model).
 */
export const heroHighlight: HeroHighlight = {
  eyebrow: "This week's headline prize",
  headline: "Win this Porsche 718 Cayman GTS 4.0",
  altPrizeLine: "Or take £62,000 tax-free cash",
  ticketPrice: "Tickets just 99p",
  ctaLabel: "Enter now — 99p",
  ctaHref: "/competitions",
  supportingLine: "Live draw Sunday 9PM  •  Limited entries  •  Cash alternative",
  image: U("1592853625601-bb9d23da12fc", 1600),
  imageAlt: "Porsche 718 Cayman, this week's competition prize car",
};

export const trustPoints = [
  { label: "Limited Entries", detail: "Every competition has a capped ticket count." },
  { label: "Secure Checkout", detail: "Encrypted payments, no card details stored." },
  { label: "Transparent Draws", detail: "Every winner drawn live on camera." },
  { label: "Real Winners", detail: "Thousands of prizes paid out and rising." },
];

export const categories: Category[] = [
  {
    id: "cars",
    name: "Cars & Bikes",
    tagline: "Performance, prestige, delivered.",
    image: U("1502877338535-766e1452684a"),
  },
  {
    id: "cash",
    name: "Cash",
    tagline: "Tax-free, straight to your account.",
    // Genuine Bank of England notes — was the same US tax-paperwork
    // photo as the "b-cash" hero slide. A DIFFERENT (brighter) GBP
    // photo than that slide uses, deliberately: CategoryCard always
    // overlays a strong dark gradient
    // (`bg-gradient-to-t from-ink via-ink/50 to-ink/5`, see that
    // component) across the full tile for text legibility, which
    // swallowed the hero slide's black-background note photo entirely
    // at this smaller size — confirmed by hand against the real
    // rendered page, not assumed. This one keeps full contrast under
    // that overlay.
    image: U("1607166602071-847b7d110781"),
  },
  {
    id: "luxury",
    name: "Luxury",
    tagline: "Watches, jewellery and icons.",
    // Steel watch on a bright background — was the same generic white
    // plastic smartwatch as the "b-watch" hero slide. A DIFFERENT
    // (brighter) watch photo than that slide uses, for the same
    // dark-gradient-visibility reason as the "cash" tile above: the
    // hero slide's moody black-and-white macro shot (which looks great
    // in the hero, see that entry) disappeared entirely under
    // CategoryCard's overlay. A small "SEKONDA" label is visible on
    // the dial on close inspection — a real but unrelated, non-luxury
    // watch brand, not a Rolex product photo, so this still never
    // implies an official Rolex partnership.
    image: U("1606387318469-bada9b642157"),
  },
  {
    id: "tech",
    name: "Tech",
    tagline: "The latest kit, fully loaded.",
    image: U("1517336714731-489689fd1ca8"),
  },
  {
    id: "lifestyle",
    name: "Lifestyle",
    tagline: "Getaways, experiences and more.",
    image: U("1441984904996-e0b6ba687e04"),
  },
];

export const steps: Step[] = [
  { number: "01", title: "Choose", copy: "Pick your favourite competition from the live line-up." },
  { number: "02", title: "Enter", copy: "Choose your number of entries and check out securely." },
  { number: "03", title: "Watch", copy: "Follow the live draw and see the winner revealed." },
  { number: "04", title: "Win", copy: "Your next big moment could be yours — prize delivered fast." },
];

export const whyChooseUs: Feature[] = [
  { title: "Fair & Transparent", copy: "Clear competition information and every draw streamed live on camera.", icon: "shield" },
  { title: "Limited Entries", copy: "Know your odds. Every competition has a published entry cap.", icon: "ticket" },
  { title: "Exciting Prizes", copy: "Cars, cash, luxury watches, tech and once-in-a-lifetime experiences.", icon: "gift" },
  { title: "Real Winners", copy: "Genuine winner stories, real names, real tickets, every single week.", icon: "trophy" },
];

/**
 * Exactly 3 — the homepage shows a deliberately short, high-trust
 * sample, not every mock winner going (WinnerSection also enforces
 * this with its own `.slice(0, 3)`, so this array could technically
 * hold more without breaking that guarantee — kept at exactly 3 anyway
 * so there's only one place that determines what actually renders).
 * The full, longer sample list lives separately at
 * `@/data/winners` for the dedicated `/winners` page.
 */
export const winners: Winner[] = [
  { id: "w1", name: "James", location: "Leeds", prize: "Won a BMW M4", competition: "BMW M4 Competition", ticket: "#18429", photo: "/images/winners/james-rolex.jpeg" },
  { id: "w2", name: "Priya", location: "Birmingham", prize: "Won £25,000 Cash", competition: "£25k Tax-Free Cash", ticket: "#04211", photo: "/images/winners/priya-iphone-unboxing.png" },
  { id: "w3", name: "Callum", location: "Glasgow", prize: "Won a Rolex Submariner", competition: "Rolex Submariner Date", ticket: "#27760", photo: "/images/winners/callum-rolex.png" },
];

export type Review = {
  id: string;
  name: string;
  location: string;
  rating: number; // 1 - 5
  quote: string;
  prize: string;
  photo: string;
};

export const reviews: Review[] = [
  {
    id: "r1",
    name: "Danielle O.",
    location: "Manchester",
    rating: 5,
    quote:
      "Entered on a Friday, won £10,000 on the Sunday draw and the money was in my account by Tuesday. Watched it happen live — no doubt it's genuine.",
    prize: "Won £10,000 Cash",
    photo: "https://i.pravatar.cc/240?img=32",
  },
  {
    id: "r2",
    name: "Tom R.",
    location: "Newcastle",
    rating: 5,
    quote:
      "I've tried a few competition sites and RaffleGuru is the only one that feels properly transparent. Entry caps are clear and the live draws are actually live.",
    prize: "Won a Tech Bundle",
    photo: "https://i.pravatar.cc/240?img=14",
  },
  {
    id: "r3",
    name: "Aisha K.",
    location: "London",
    rating: 5,
    quote:
      "The car turned up on a trailer 11 days after the draw, fully valeted with all the paperwork. Customer support kept me updated the whole way through.",
    prize: "Won a BMW M4",
    photo: "https://i.pravatar.cc/240?img=41",
  },
  {
    id: "r4",
    name: "Greg M.",
    location: "Cardiff",
    rating: 4,
    quote:
      "Took the cash alternative on my watch win and it was sorted with one email. Site's quick, checkout took seconds. Would happily enter again.",
    prize: "Won a Rolex (cash alt)",
    photo: "https://i.pravatar.cc/240?img=51",
  },
  {
    id: "r5",
    name: "Chloe B.",
    location: "Edinburgh",
    rating: 5,
    quote:
      "Been playing for about six months. Small entry prices, real winners posted every week and you always know when the draw is. Feels fair.",
    prize: "Won £2,500 Cash",
    photo: "https://i.pravatar.cc/240?img=26",
  },
];

export const reviewSummary = {
  rating: "4.9",
  count: "3,100+",
  source: "verified player reviews",
};


