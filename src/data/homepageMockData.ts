/**
 * RaffleGuru — Homepage mock data
 * ------------------------------------------------------------------
 * This is a CLIENT APPROVAL CONCEPT. Everything here is static
 * placeholder content. There are NO API calls anywhere in this app.
 * Swap this file for real data sources when the platform is built.
 */

export type Competition = {
  id: string;
  title: string;
  blurb: string;
  image: string;
  badge: string;
  category: string;
  entryPrice: number; // GBP
  cashAlternative?: string;
  soldPct: number; // 0 - 100
  drawLabel: string; // e.g. "Sunday 9PM"
};

export type Category = {
  id: string;
  name: string;
  tagline: string;
  image: string;
  count: string;
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
  photo: string;
};

const U = (id: string, w = 1200) =>
  `https://images.unsplash.com/photo-${id}?auto=format&fit=crop&w=${w}&q=80`;

export const announcement = {
  text: "New competitions. Bigger prizes. More chances to win.",
  ctaLabel: "See what's live",
  ctaHref: "#ending-soon",
};

export const navLinks = [
  { label: "Competitions", href: "#featured" },
  { label: "Winners", href: "#winners" },
  { label: "How It Works", href: "#how-it-works" },
  { label: "About", href: "#why" },
  { label: "FAQ", href: "#faq" },
];

export type HeroBannerSlide = {
  id: string;
  eyebrow: string;
  title: string;
  subtitle: string;
  ctaLabel: string;
  ctaHref: string;
  image: string;
};

export const heroBannerSlides: HeroBannerSlide[] = [
  {
    id: "b-car",
    eyebrow: "This week's headline prize",
    title: "Win a 718 Cayman GTS 4.0",
    subtitle: "Or take £62,000 tax-free cash. Entries from £0.99 — draw live Sunday 9PM.",
    ctaLabel: "Enter the car draw",
    ctaHref: "#featured",
    image: U("1503376780353-7e6692767b70", 1600),
  },
  {
    id: "b-cash",
    eyebrow: "Midweek cash",
    title: "£25,000 straight to your bank",
    subtitle: "No strings, paid within 48 hours of the live draw. Entries from £0.79.",
    ctaLabel: "Enter the cash draw",
    ctaHref: "#featured",
    image: U("1554224155-6726b3ff858f", 1600),
  },
  {
    id: "b-watch",
    eyebrow: "Luxury line-up",
    title: "Rolex Submariner Date",
    subtitle: "Full set with box and papers, or £11,500 cash. Draw live Saturday 7PM.",
    ctaLabel: "See luxury competitions",
    ctaHref: "#featured",
    image: U("1523275335684-37898b6baf30", 1600),
  },
];

export const heroFeatured = {
  badge: "Featured Competition",
  title: "Win a Premium Performance Car",
  model: "718 Cayman GTS 4.0 — or £62,000 tax-free cash",
  entryLabel: "Entries from £0.99",
  soldPct: 46,
  drawLabel: "Draw live Sunday 9PM",
  image: U("1503376780353-7e6692767b70", 1400),
};

export const trustPoints = [
  { label: "Limited Entries", detail: "Every competition has a capped ticket count." },
  { label: "Secure Checkout", detail: "Encrypted payments, no card details stored." },
  { label: "Transparent Draws", detail: "Every winner drawn live on camera." },
  { label: "Real Winners", detail: "Thousands of prizes paid out and rising." },
];

export const endingSoon: Competition[] = [
  {
    id: "bmw-m4",
    title: "Win a BMW M4 Competition",
    blurb: "510bhp twin-turbo coupé in Isle of Man Green. Fully road legal, delivered to your door.",
    image: U("1555215695-3004980ad54e"),
    badge: "Ending Soon",
    category: "Cars & Bikes",
    entryPrice: 0.99,
    cashAlternative: "or £58,000 cash",
    soldPct: 32,
    drawLabel: "Sunday 9PM",
  },
  {
    id: "cash-25k",
    title: "£25,000 Tax-Free Cash",
    blurb: "No strings. Straight to your bank account within 48 hours of the live draw.",
    image: U("1554224155-6726b3ff858f"),
    badge: "Ending Soon",
    category: "Cash",
    entryPrice: 0.79,
    soldPct: 71,
    drawLabel: "Friday 8PM",
  },
  {
    id: "rolex-sub",
    title: "Rolex Submariner Date",
    blurb: "Oystersteel, 41mm, black Cerachrom bezel. Full set with box and papers.",
    image: U("1523275335684-37898b6baf30"),
    badge: "Ending Soon",
    category: "Luxury",
    entryPrice: 1.49,
    cashAlternative: "or £11,500 cash",
    soldPct: 58,
    drawLabel: "Saturday 7PM",
  },
];

export const categories: Category[] = [
  {
    id: "cars",
    name: "Cars & Bikes",
    tagline: "Performance, prestige, delivered.",
    image: U("1502877338535-766e1452684a"),
    count: "12 live",
  },
  {
    id: "cash",
    name: "Cash",
    tagline: "Tax-free, straight to your account.",
    image: U("1554224155-6726b3ff858f"),
    count: "8 live",
  },
  {
    id: "luxury",
    name: "Luxury",
    tagline: "Watches, jewellery and icons.",
    image: U("1523275335684-37898b6baf30"),
    count: "9 live",
  },
  {
    id: "tech",
    name: "Tech",
    tagline: "The latest kit, fully loaded.",
    image: U("1517336714731-489689fd1ca8"),
    count: "14 live",
  },
  {
    id: "lifestyle",
    name: "Lifestyle",
    tagline: "Getaways, experiences and more.",
    image: U("1441984904996-e0b6ba687e04"),
    count: "6 live",
  },
];

export const featuredCompetitions: Competition[] = [
  {
    id: "f-bmw-m4",
    title: "BMW M4 Competition",
    blurb: "510bhp coupé, road legal and ready to drive away.",
    image: U("1555215695-3004980ad54e"),
    badge: "The Big Prizes",
    category: "Cars & Bikes",
    entryPrice: 0.99,
    cashAlternative: "or £58,000 cash",
    soldPct: 41,
    drawLabel: "Sun 9PM",
  },
  {
    id: "f-cash-25k",
    title: "£25,000 Cash",
    blurb: "Life-changing money, paid within 48 hours.",
    image: U("1554224155-6726b3ff858f"),
    badge: "The Big Prizes",
    category: "Cash",
    entryPrice: 0.79,
    soldPct: 63,
    drawLabel: "Fri 8PM",
  },
  {
    id: "f-rolex",
    title: "Rolex Datejust 41",
    blurb: "Fluted bezel, jubilee bracelet, full set.",
    image: U("1524805444758-089113d48a6d"),
    badge: "The Big Prizes",
    category: "Luxury",
    entryPrice: 1.29,
    cashAlternative: "or £9,750 cash",
    soldPct: 55,
    drawLabel: "Sat 7PM",
  },
  {
    id: "f-tech",
    title: "Luxury Tech Bundle",
    blurb: "MacBook Pro, iPhone, iPad, AirPods Max & more.",
    image: U("1517336714731-489689fd1ca8"),
    badge: "The Big Prizes",
    category: "Tech",
    entryPrice: 0.49,
    soldPct: 78,
    drawLabel: "Thu 8PM",
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

export const winners: Winner[] = [
  { id: "w1", name: "James", location: "Leeds", prize: "Won a BMW M4", competition: "BMW M4 Competition", ticket: "#18429", photo: "https://i.pravatar.cc/240?img=12" },
  { id: "w2", name: "Priya", location: "Birmingham", prize: "Won £25,000 Cash", competition: "£25k Tax-Free Cash", ticket: "#04211", photo: "https://i.pravatar.cc/240?img=45" },
  { id: "w3", name: "Callum", location: "Glasgow", prize: "Won a Rolex Submariner", competition: "Rolex Submariner Date", ticket: "#27760", photo: "https://i.pravatar.cc/240?img=33" },
  { id: "w4", name: "Sophie", location: "Bristol", prize: "Won a Tech Bundle", competition: "Luxury Tech Bundle", ticket: "#11902", photo: "https://i.pravatar.cc/240?img=47" },
  { id: "w5", name: "Marcus", location: "London", prize: "Won £10,000 Cash", competition: "£10k Midweek Cash", ticket: "#65014", photo: "https://i.pravatar.cc/240?img=15" },
  { id: "w6", name: "Hannah", location: "Cardiff", prize: "Won a Ducati Panigale", competition: "Ducati Panigale V2", ticket: "#38845", photo: "https://i.pravatar.cc/240?img=44" },
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

export const footerLinks = {
  Explore: [
    { label: "Competitions", href: "#featured" },
    { label: "Winners", href: "#winners" },
    { label: "How It Works", href: "#how-it-works" },
    { label: "Categories", href: "#categories" },
  ],
  Support: [
    { label: "FAQ", href: "#faq" },
    { label: "Contact", href: "#" },
    { label: "Responsible Play", href: "#" },
  ],
  Legal: [
    { label: "Terms & Conditions", href: "#" },
    { label: "Privacy Policy", href: "#" },
    { label: "Cookie Policy", href: "#" },
  ],
};

export const stats = [
  { value: "£4.2M+", label: "Prizes paid out" },
  { value: "31,000+", label: "Winners so far" },
  { value: "4.9/5", label: "Rated by players" },
];
