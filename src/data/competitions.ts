/**
 * RaffleGuru — Competition mock data (PROTOTYPE ONLY)
 * ------------------------------------------------------------------
 * Static placeholder content. No API calls, no real ticket allocation,
 * no draw engine. Draw dates are fixed sample values.
 *
 * `Competition` is a structural superset of the homepage card shape
 * (`@/data/homepageMockData` `Competition`) so the Phase 1 CompetitionCard
 * and CompetitionGrid consume it unchanged.
 */

export type CompetitionStatus = "live" | "ending-soon" | "sold-out" | "drawn";

export type CompetitionQuestion = {
  prompt: string;
  options: string[];
  /** index of the correct option — UI only, not validated anywhere real */
  answerIndex: number;
};

export type Competition = {
  // ---- fields shared with the homepage card ----
  id: string;
  slug: string;
  title: string;
  blurb: string;
  image: string;
  badge: string;
  category: string;
  entryPrice: number;
  cashAlternative?: string;
  soldPct: number;
  drawLabel: string;
  // ---- listing / detail fields ----
  prize: string;
  prizeValue: number;
  cashAlternativeValue?: number;
  images: string[];
  totalEntries: number;
  drawDate: string; // ISO 8601
  status: CompetitionStatus;
  featured: boolean;
  description: string[];
  prizeDetails: string[];
  rules: string[];
  question: CompetitionQuestion;
  faqs: { q: string; a: string }[];
};

const IMG = (id: string, w = 1400) =>
  `https://images.unsplash.com/photo-${id}?auto=format&fit=crop&w=${w}&q=80`;

const standardRules = [
  "One entry equals one ticket. Every competition has a published maximum number of tickets.",
  "The draw takes place on the date shown once all tickets are sold or the closing time is reached, whichever is first.",
  "Winners are selected at random using a verified random number generator and announced on our live stream.",
  "You must be 18 or over and a UK resident to enter. Proof of ID and address may be requested before a prize is released.",
  "Where offered, the cash alternative is chosen by the winner within 14 days of the draw.",
  "Full terms are set out in the Competition Rules. This prototype uses sample copy only.",
];

const standardFaqs = [
  {
    q: "How is the winner chosen?",
    a: "Every sold ticket is assigned a number. On the draw date a verified random number generator selects the winning number, live on camera.",
  },
  {
    q: "What happens if not all tickets sell?",
    a: "The draw still goes ahead on the published date. The prize is guaranteed regardless of how many tickets are sold.",
  },
  {
    q: "Can I take cash instead of the prize?",
    a: "Where a cash alternative is shown, the winner can choose it instead of the physical prize.",
  },
  {
    q: "How quickly is the prize delivered?",
    a: "Cash is transferred within 48 hours of the draw. Cars and larger prizes are arranged directly with the winner, usually within two weeks.",
  },
];

function make(
  partial: Omit<Competition, "images" | "rules" | "faqs" | "badge"> &
    Partial<Pick<Competition, "images" | "rules" | "faqs" | "badge">>,
): Competition {
  return {
    badge:
      partial.status === "ending-soon"
        ? "Ending Soon"
        : partial.status === "sold-out"
          ? "Sold Out"
          : partial.featured
            ? "Featured"
            : "Live",
    images: [partial.image],
    rules: standardRules,
    faqs: standardFaqs,
    ...partial,
  };
}

export const competitions: Competition[] = [
  make({
    id: "c-bmw-m4",
    slug: "bmw-m4",
    title: "BMW M4 Competition",
    blurb: "510bhp twin-turbo coupé in Isle of Man Green, road legal and delivered to your door.",
    category: "Cars & Bikes",
    prize: "BMW M4 Competition xDrive",
    prizeValue: 84000,
    cashAlternative: "or £58,000 cash",
    cashAlternativeValue: 58000,
    entryPrice: 0.99,
    totalEntries: 95000,
    soldPct: 41,
    drawDate: "2026-09-13T20:00:00Z",
    drawLabel: "Sun 9PM",
    status: "live",
    featured: true,
    image: IMG("1555215695-3004980ad54e"),
    images: [
      IMG("1555215695-3004980ad54e"),
      IMG("1552519507-da3b142c6e3d"),
      IMG("1553440569-bcc63803a83d"),
      IMG("1502877338535-766e1452684a"),
    ],
    description: [
      "Win a brand-new BMW M4 Competition finished in Isle of Man Green with a full black Merino leather interior.",
      "510bhp from the twin-turbocharged 3.0-litre straight six, 0–62mph in 3.5 seconds and an 8-speed M Steptronic gearbox. Supplied brand new, first registered in the winner's name, road legal and delivered anywhere in mainland UK.",
      "Prefer the money? Take the £58,000 tax-free cash alternative instead.",
    ],
    prizeDetails: [
      "Brand new BMW M4 Competition xDrive",
      "Isle of Man Green metallic paint, Black Merino leather",
      "Carbon exterior package and M Driver's package",
      "12 months road tax and first service included",
      "Nationwide delivery on a covered transporter",
    ],
    question: {
      prompt: "How many cylinders does the BMW M4 Competition engine have?",
      options: ["Four", "Six", "Eight", "Twelve"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-cash-25k",
    slug: "cash-25k",
    title: "£25,000 Tax-Free Cash",
    blurb: "No strings. Straight to your bank account within 48 hours of the live draw.",
    category: "Cash",
    prize: "£25,000 tax-free cash",
    prizeValue: 25000,
    entryPrice: 0.79,
    totalEntries: 60000,
    soldPct: 71,
    drawDate: "2026-09-11T19:00:00Z",
    drawLabel: "Fri 8PM",
    status: "ending-soon",
    featured: true,
    image: IMG("1554224155-6726b3ff858f"),
    images: [IMG("1554224155-6726b3ff858f"), IMG("1600585154340-be6161a56a0c")],
    description: [
      "A clean £25,000 paid straight into your account — no catch, no tax, spend it however you like.",
      "The transfer is made by bank transfer within 48 hours of the live draw. That's a deposit, a car, a wedding or a very good year sorted.",
    ],
    prizeDetails: [
      "£25,000 paid by UK bank transfer",
      "No tax to pay on competition prizes",
      "Funds sent within 48 hours of the draw",
      "No cash alternative needed — it's already cash",
    ],
    question: {
      prompt: "Competition prizes in the UK are paid to the winner…",
      options: ["Minus 40% tax", "Tax-free", "In instalments", "As vouchers"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-rolex-datejust",
    slug: "rolex-datejust",
    title: "Rolex Datejust 41",
    blurb: "Fluted bezel, jubilee bracelet, full set with box and papers.",
    category: "Luxury",
    prize: "Rolex Datejust 41 (2024)",
    prizeValue: 11500,
    cashAlternative: "or £9,750 cash",
    cashAlternativeValue: 9750,
    entryPrice: 1.29,
    totalEntries: 12000,
    soldPct: 55,
    drawDate: "2026-09-12T18:00:00Z",
    drawLabel: "Sat 7PM",
    status: "live",
    featured: true,
    image: IMG("1524805444758-089113d48a6d"),
    images: [IMG("1524805444758-089113d48a6d"), IMG("1547996160-81dfa63595aa")],
    description: [
      "A 2024 Rolex Datejust 41 in Oystersteel with a fluted white gold bezel and jubilee bracelet.",
      "Supplied as a full set — watch, box, papers and card — and fully authenticated. Or take £9,750 in cash.",
    ],
    prizeDetails: [
      "Rolex Datejust 41, 2024, unworn",
      "Oystersteel case, fluted 18ct white gold bezel",
      "Jubilee bracelet, silver dial",
      "Full set: box, papers, warranty card",
    ],
    question: {
      prompt: "What material is the Datejust 41 case made from?",
      options: ["Titanium", "Oystersteel", "Ceramic", "Platinum only"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-tech-bundle",
    slug: "tech-bundle",
    title: "Luxury Tech Bundle",
    blurb: "MacBook Pro, iPhone, iPad, AirPods Max and more — the full setup.",
    category: "Tech",
    prize: "Apple tech bundle",
    prizeValue: 6500,
    entryPrice: 0.49,
    totalEntries: 20000,
    soldPct: 78,
    drawDate: "2026-09-10T19:00:00Z",
    drawLabel: "Thu 8PM",
    status: "ending-soon",
    featured: true,
    image: IMG("1517336714731-489689fd1ca8"),
    images: [IMG("1517336714731-489689fd1ca8"), IMG("1541807084-5c52b6b3adef")],
    description: [
      "Everything on your wishlist in one go: 16-inch MacBook Pro, iPhone 16 Pro, iPad Pro, Apple Watch Ultra and AirPods Max.",
      "All brand new, sealed and delivered together. Take the full £6,500 cash alternative if you'd rather.",
    ],
    prizeDetails: [
      "16-inch MacBook Pro (M-series)",
      "iPhone 16 Pro, 256GB",
      "iPad Pro 13-inch with Magic Keyboard",
      "Apple Watch Ultra and AirPods Max",
    ],
    question: {
      prompt: "Which company makes the MacBook Pro?",
      options: ["Microsoft", "Apple", "Google", "Samsung"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-rolex-submariner",
    slug: "rolex-submariner",
    title: "Rolex Submariner Date",
    blurb: "Oystersteel, 41mm, black Cerachrom bezel. Full set with box and papers.",
    category: "Luxury",
    prize: "Rolex Submariner Date",
    prizeValue: 13500,
    cashAlternative: "or £11,500 cash",
    cashAlternativeValue: 11500,
    entryPrice: 1.49,
    totalEntries: 11000,
    soldPct: 58,
    drawDate: "2026-09-12T18:00:00Z",
    drawLabel: "Sat 7PM",
    status: "live",
    featured: false,
    image: IMG("1523275335684-37898b6baf30"),
    images: [IMG("1523275335684-37898b6baf30"), IMG("1587836374828-4dbafa94cf0e")],
    description: [
      "The watch that defined the dive watch — a Rolex Submariner Date in Oystersteel with the black Cerachrom bezel.",
      "Full set, unworn, fully authenticated. £11,500 cash alternative available.",
    ],
    prizeDetails: [
      "Rolex Submariner Date 126610LN",
      "41mm Oystersteel case, black dial",
      "Unidirectional Cerachrom bezel",
      "Full set with box and papers",
    ],
    question: {
      prompt: "The Rolex Submariner was originally designed for…",
      options: ["Pilots", "Divers", "Racing drivers", "Astronauts"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-range-rover",
    slug: "range-rover-sport",
    title: "Range Rover Sport",
    blurb: "The latest Range Rover Sport in Santorini Black, delivered nationwide.",
    category: "Cars & Bikes",
    prize: "Range Rover Sport Dynamic SE",
    prizeValue: 92000,
    cashAlternative: "or £70,000 cash",
    cashAlternativeValue: 70000,
    entryPrice: 1.19,
    totalEntries: 110000,
    soldPct: 24,
    drawDate: "2026-09-20T20:00:00Z",
    drawLabel: "Sun 9PM",
    status: "live",
    featured: false,
    image: IMG("1606664515524-ed2f786a0bd6"),
    images: [IMG("1606664515524-ed2f786a0bd6"), IMG("1614026480209-c06f2f8f6c7f")],
    description: [
      "A brand-new Range Rover Sport Dynamic SE in Santorini Black with a 23-inch alloy upgrade and full leather interior.",
      "Registered in the winner's name and delivered anywhere in mainland UK, or take £70,000 in cash.",
    ],
    prizeDetails: [
      "Brand new Range Rover Sport Dynamic SE",
      "Santorini Black, 23-inch alloys",
      "Meridian sound system, panoramic roof",
      "12 months road tax, nationwide delivery",
    ],
    question: {
      prompt: "Range Rover is a marque of which company?",
      options: ["BMW", "Jaguar Land Rover", "Stellantis", "Volkswagen"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-ducati",
    slug: "ducati-panigale-v2",
    title: "Ducati Panigale V2",
    blurb: "955cc Superquadro twin in Ducati Red, road legal and ready to ride.",
    category: "Cars & Bikes",
    prize: "Ducati Panigale V2",
    prizeValue: 17000,
    cashAlternative: "or £13,000 cash",
    cashAlternativeValue: 13000,
    entryPrice: 0.89,
    totalEntries: 25000,
    soldPct: 47,
    drawDate: "2026-09-14T19:00:00Z",
    drawLabel: "Mon 8PM",
    status: "live",
    featured: false,
    image: IMG("1568772585407-9361f9bf3a87"),
    images: [IMG("1568772585407-9361f9bf3a87"), IMG("1558981806-ec527fa84c39")],
    description: [
      "A brand-new Ducati Panigale V2 in Ducati Red — 155hp, full electronics package and that unmistakable exhaust note.",
      "Supplied road legal and delivered to your door, or take £13,000 cash.",
    ],
    prizeDetails: [
      "Brand new Ducati Panigale V2",
      "Ducati Red, 955cc Superquadro L-twin",
      "Riding modes, cornering ABS, quick-shifter",
      "12 months road tax, nationwide delivery",
    ],
    question: {
      prompt: "Ducati is a motorcycle manufacturer from which country?",
      options: ["Japan", "Germany", "Italy", "Spain"],
      answerIndex: 2,
    },
  }),
  make({
    id: "c-cash-10k",
    slug: "cash-10k-midweek",
    title: "£10,000 Midweek Cash",
    blurb: "A quick midweek draw for a clean £10,000, paid within 48 hours.",
    category: "Cash",
    prize: "£10,000 tax-free cash",
    prizeValue: 10000,
    entryPrice: 0.59,
    totalEntries: 30000,
    soldPct: 88,
    drawDate: "2026-09-09T19:00:00Z",
    drawLabel: "Wed 8PM",
    status: "ending-soon",
    featured: false,
    image: IMG("1580519542036-c47de6196ba5"),
    images: [IMG("1580519542036-c47de6196ba5")],
    description: [
      "A fast midweek cash draw — £10,000 straight to your account within 48 hours of the draw.",
      "Small entry price, capped tickets, published draw time.",
    ],
    prizeDetails: [
      "£10,000 paid by UK bank transfer",
      "Tax-free",
      "Funds within 48 hours of the draw",
    ],
    question: {
      prompt: "How is the cash prize paid?",
      options: ["Cheque", "UK bank transfer", "Cryptocurrency", "Cash in person"],
      answerIndex: 1,
    },
  }),
  make({
    id: "c-holiday",
    slug: "maldives-escape",
    title: "Maldives Escape for Two",
    blurb: "Seven nights overwater in the Maldives, flights and transfers included.",
    category: "Lifestyle",
    prize: "7-night Maldives holiday for two",
    prizeValue: 12000,
    cashAlternative: "or £9,000 cash",
    cashAlternativeValue: 9000,
    entryPrice: 0.69,
    totalEntries: 18000,
    soldPct: 12,
    drawDate: "2026-09-28T19:00:00Z",
    drawLabel: "Sun 8PM",
    status: "live",
    featured: false,
    image: IMG("1573843981267-be1999ff37cd"),
    images: [IMG("1573843981267-be1999ff37cd"), IMG("1439066615861-d1af74d74000")],
    description: [
      "Seven nights in an overwater villa at a five-star Maldives resort, half board, for two people.",
      "Return flights from any major UK airport and speedboat transfers included. Travel within 12 months. Or take £9,000 cash.",
    ],
    prizeDetails: [
      "7 nights, overwater villa, half board",
      "Return flights for two from the UK",
      "Speedboat airport transfers",
      "£1,000 spending money included",
    ],
    question: {
      prompt: "The Maldives is an island nation in which ocean?",
      options: ["Atlantic", "Pacific", "Indian", "Arctic"],
      answerIndex: 2,
    },
  }),
  make({
    id: "c-ps5",
    slug: "ultimate-gaming-setup",
    title: "Ultimate Gaming Setup",
    blurb: "PS5 Pro, 4K OLED, racing rig and a year of everything — sold out.",
    category: "Tech",
    prize: "Complete gaming battlestation",
    prizeValue: 4500,
    entryPrice: 0.39,
    totalEntries: 15000,
    soldPct: 100,
    drawDate: "2026-09-07T19:00:00Z",
    drawLabel: "Mon 8PM",
    status: "sold-out",
    featured: false,
    image: IMG("1550745165-9bc0b252726f"),
    images: [IMG("1550745165-9bc0b252726f")],
    description: [
      "The full battlestation: PS5 Pro, 48-inch 4K OLED, a sim racing rig, mechanical keyboard, headset and a year of game pass.",
      "This competition has sold out — the draw goes ahead on the published date.",
    ],
    prizeDetails: [
      "PlayStation 5 Pro with two controllers",
      "48-inch 4K OLED display",
      "Sim racing cockpit, wheel and pedals",
      "Mechanical keyboard, mouse and headset",
    ],
    question: {
      prompt: "The PS5 is made by which company?",
      options: ["Sony", "Microsoft", "Nintendo", "Sega"],
      answerIndex: 0,
    },
  }),
];

export const competitionCategories = [
  "All",
  "Cars & Bikes",
  "Cash",
  "Luxury",
  "Tech",
  "Lifestyle",
] as const;

export type CompetitionSort =
  | "ending-soon"
  | "newest"
  | "price-low"
  | "price-high"
  | "almost-gone";

export const competitionSorts: { value: CompetitionSort; label: string }[] = [
  { value: "ending-soon", label: "Ending soonest" },
  { value: "almost-gone", label: "Almost gone" },
  { value: "price-low", label: "Entry price: low to high" },
  { value: "price-high", label: "Entry price: high to low" },
  { value: "newest", label: "Newest" },
];

export function getCompetition(slug: string): Competition | undefined {
  return competitions.find((c) => c.slug === slug);
}

export function getRelatedCompetitions(slug: string, count = 3): Competition[] {
  const current = getCompetition(slug);
  if (!current) return competitions.slice(0, count);
  const sameCategory = competitions.filter(
    (c) => c.slug !== slug && c.category === current.category,
  );
  const others = competitions.filter(
    (c) => c.slug !== slug && c.category !== current.category,
  );
  return [...sameCategory, ...others].slice(0, count);
}

export const featuredList = competitions.filter((c) => c.featured);
export const endingSoonList = competitions
  .filter((c) => c.status === "ending-soon" || c.status === "live")
  .sort((a, b) => +new Date(a.drawDate) - +new Date(b.drawDate));
