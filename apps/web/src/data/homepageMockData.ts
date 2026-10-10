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

export type HeroBannerSlide = {
  id: string;
  eyebrow: string;
  title: string;
  subtitle: string;
  ctaLabel: string;
  ctaHref: string;
  image: string;
  /** Optional object-position override (Tailwind object-* classes) for slides whose source image needs a non-centered crop. */
  imagePositionClassName?: string;
};

export const heroBannerSlides: HeroBannerSlide[] = [
  {
    id: "b-car",
    eyebrow: "This week's headline prize",
    title: "Win a 718 Cayman GTS 4.0",
    subtitle: "Or take £62,000 tax-free cash. Entries from £0.99 — draw live Sunday 9PM.",
    ctaLabel: "Enter the car draw",
    ctaHref: "/competitions",
    image: "/hero/raffle-guru-hero-1.jpg",
    imagePositionClassName: "object-[75%_50%] sm:object-[50%_80%]",
  },
  {
    id: "b-cash",
    eyebrow: "Midweek cash",
    title: "£25,000 straight to your bank",
    subtitle: "No strings, paid within 48 hours of the live draw. Entries from £0.79.",
    ctaLabel: "Enter the cash draw",
    ctaHref: "/competitions",
    // Genuine Bank of England £5 notes — was a US tax-paperwork photo
    // (IRS forms, American eagle) on a UK cash-prize slide.
    image: U("1700047614820-0f32080db6f8", 1600),
  },
  {
    id: "b-watch",
    eyebrow: "Luxury line-up",
    title: "Rolex Submariner Date",
    subtitle: "Full set with box and papers, or £11,500 cash. Draw live Saturday 7PM.",
    ctaLabel: "See luxury competitions",
    ctaHref: "/competitions",
    // Steel dive-watch macro (bezel/crown/bracelet), dial deliberately
    // out of focus — was a generic white plastic smartwatch render.
    // No brand name is legible anywhere in frame: a stand-in "luxury
    // watch" shot, not a real Rolex product photo, so it never implies
    // an official Rolex partnership.
    image: U("1544689797-0347c6b7224c", 1600),
  },
];

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
  { id: "w1", name: "James", location: "Leeds", prize: "Won a BMW M4", competition: "BMW M4 Competition", ticket: "#18429" },
  { id: "w2", name: "Priya", location: "Birmingham", prize: "Won £25,000 Cash", competition: "£25k Tax-Free Cash", ticket: "#04211" },
  { id: "w3", name: "Callum", location: "Glasgow", prize: "Won a Rolex Submariner", competition: "Rolex Submariner Date", ticket: "#27760" },
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


