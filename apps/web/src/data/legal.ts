/**
 * RaffleGuru — Legal page content (PROTOTYPE / SAMPLE COPY ONLY).
 * ------------------------------------------------------------------
 * This is placeholder wording written to demonstrate page layout. It is
 * NOT legal advice and is NOT a set of enforceable terms. Every page
 * carries a visible sample-copy notice. Replace wholesale with content
 * reviewed by a qualified solicitor before any real launch.
 */
import type { LegalSlug } from "@/lib/routes";

export type LegalSection = {
  id: string;
  heading: string;
  body: string[];
  list?: string[];
};

export type LegalPage = {
  slug: LegalSlug;
  title: string;
  updated: string;
  intro: string;
  sections: LegalSection[];
};

export const legalPages: Record<LegalSlug, LegalPage> = {
  terms: {
    slug: "terms",
    title: "Terms & Conditions",
    updated: "2026-08-01",
    intro:
      "These sample terms describe how a RaffleGuru account and entry would work. They are placeholder wording for this prototype only.",
    sections: [
      {
        id: "about",
        heading: "1. About these terms",
        body: [
          "These terms would form the agreement between you and RaffleGuru Ltd when you create an account or enter a competition.",
          "By entering a competition you would confirm that you accept these terms and the specific Competition Rules for that competition.",
        ],
      },
      {
        id: "eligibility",
        heading: "2. Eligibility",
        body: ["To enter a competition you must:"],
        list: [
          "be aged 18 or over",
          "be resident in the United Kingdom",
          "not be an employee of RaffleGuru or a member of their household",
          "provide accurate account information and keep it up to date",
        ],
      },
      {
        id: "entries",
        heading: "3. Entries and payment",
        body: [
          "Each paid entry buys one ticket in the chosen competition, subject to the maximum entries shown on the competition page.",
          "A free postal entry route would be available and set out in full in the Competition Rules. Free and paid entries have an equal chance of winning.",
          "Payment is taken at checkout. Entries are confirmed by email with your ticket numbers.",
        ],
      },
      {
        id: "draws",
        heading: "4. Draws and winners",
        body: [
          "Each competition has a published closing date and draw date. If all tickets sell first, the draw may be brought forward.",
          "Winners are selected at random using a verified random number generator, live on camera. We contact winners using their account details within 24 hours.",
        ],
      },
      {
        id: "prizes",
        heading: "5. Prizes",
        body: [
          "Prizes are as described on the competition page. Where a cash alternative is offered, the winner chooses within 14 days of the draw.",
          "Cash is paid by UK bank transfer within 48 hours. Physical prizes are arranged directly with the winner.",
        ],
      },
      {
        id: "conduct",
        heading: "6. Account conduct",
        body: [
          "You must not use automated tools to enter, open multiple accounts, or attempt to manipulate a competition. We may suspend accounts and void entries where we reasonably believe these terms have been broken.",
        ],
      },
      {
        id: "liability",
        heading: "7. Liability",
        body: [
          "Nothing in these terms would limit liability that cannot be limited by law. Subject to that, RaffleGuru's liability in connection with a competition would be limited to the total entry fees you paid for it.",
        ],
      },
      {
        id: "changes",
        heading: "8. Changes and contact",
        body: [
          "We may update these terms from time to time and would post the new version here with an updated date.",
          "Questions about these terms can be sent through the contact page.",
        ],
      },
    ],
  },
  privacy: {
    slug: "privacy",
    title: "Privacy Policy",
    updated: "2026-08-01",
    intro:
      "This sample policy outlines the personal data a platform like RaffleGuru would handle and why. Placeholder wording for this prototype only.",
    sections: [
      {
        id: "who",
        heading: "1. Who we are",
        body: [
          "RaffleGuru Ltd would be the controller of personal data collected through this website. A real policy would include a registered address and a contact for data protection queries.",
        ],
      },
      {
        id: "what",
        heading: "2. What we collect",
        body: ["We would collect:"],
        list: [
          "account details — name, email, phone number, date of birth, address",
          "entry and order history",
          "payment confirmation data from our payment provider (not full card numbers)",
          "technical data — device, browser and general location from your IP address",
          "communications you send us",
        ],
      },
      {
        id: "why",
        heading: "3. Why we use it",
        body: [
          "To run competitions and confirm entries, to verify age and identity, to contact winners, to provide support, to prevent fraud, to meet legal obligations, and — with your consent — to send marketing.",
        ],
      },
      {
        id: "sharing",
        heading: "4. Who we share it with",
        body: [
          "Service providers who help us operate — payment processing, hosting, email delivery, identity checks — under contract. We would also share data where required by law.",
          "We would not sell your personal data.",
        ],
      },
      {
        id: "retention",
        heading: "5. How long we keep it",
        body: [
          "Account and entry records would be kept for as long as your account is open and for a limited period afterwards to meet legal and accounting requirements, then deleted or anonymised.",
        ],
      },
      {
        id: "rights",
        heading: "6. Your rights",
        body: [
          "Under UK data protection law you have rights to access, correct, delete and port your data, and to object to or restrict certain processing. You can also complain to the Information Commissioner's Office.",
        ],
      },
      {
        id: "cookies",
        heading: "7. Cookies",
        body: [
          "We use cookies and similar technologies as described in the Cookie Policy.",
        ],
      },
    ],
  },
  cookies: {
    slug: "cookies",
    title: "Cookie Policy",
    updated: "2026-08-01",
    intro:
      "This sample policy explains the cookie categories a site like RaffleGuru would use. Placeholder wording for this prototype only — no analytics or advertising cookies are set here.",
    sections: [
      {
        id: "what",
        heading: "1. What cookies are",
        body: [
          "Cookies are small files stored on your device that help a website work and remember your choices. Similar technologies include local storage and pixels.",
        ],
      },
      {
        id: "types",
        heading: "2. Categories we would use",
        body: [],
        list: [
          "Essential — sign-in, security, basket contents and checkout. These cannot be switched off.",
          "Functional — remembering preferences such as a chosen filter or region.",
          "Analytics — understanding how pages are used so we can improve them. Set only with consent.",
          "Marketing — measuring campaigns and showing relevant ads on other sites. Set only with consent.",
        ],
      },
      {
        id: "manage",
        heading: "3. Managing cookies",
        body: [
          "A real deployment would show a consent banner on first visit and a settings link in the footer so you can change your choices at any time. You can also clear or block cookies in your browser settings, though essential features may stop working.",
        ],
      },
      {
        id: "prototype",
        heading: "4. This prototype",
        body: [
          "This prototype stores a small amount of data in your browser's local storage to remember your demo basket. Nothing is sent to a server and no tracking cookies are used.",
        ],
      },
    ],
  },
  "responsible-play": {
    slug: "responsible-play",
    title: "Responsible Play",
    updated: "2026-08-01",
    intro:
      "Entering competitions should stay fun and affordable. This page shows the kind of tools and signposting a responsible platform would provide. Sample content for this prototype.",
    sections: [
      {
        id: "principles",
        heading: "Our approach",
        body: [
          "We would want every entrant to play within their means. That means clear pricing, published odds through entry caps, and easy ways to take a break.",
        ],
      },
      {
        id: "tips",
        heading: "Staying in control",
        body: ["A few simple habits help:"],
        list: [
          "Set yourself a monthly budget for entries and stick to it.",
          "Treat entry fees as money spent for entertainment, not an investment.",
          "Never enter with money set aside for bills or essentials.",
          "Take a break if it stops being fun or starts feeling like a habit.",
        ],
      },
      {
        id: "tools",
        heading: "Tools we would offer",
        body: [],
        list: [
          "Spend limits — daily, weekly or monthly caps you set yourself.",
          "Cooling-off periods — pause your account for a set time.",
          "Self-exclusion — close your account for a minimum of six months.",
          "Activity summaries — a clear view of what you've spent.",
        ],
      },
      {
        id: "support",
        heading: "Free, confidential support",
        body: [
          "If gambling or competition spending is causing you harm, free and confidential help is available in the UK from organisations such as GamCare (0808 8020 133) and the National Gambling Helpline. BeGambleAware.org has more information and a self-assessment tool.",
          "These organisations are independent of RaffleGuru. This prototype does not provide counselling or account tools.",
        ],
      },
    ],
  },
  "competition-rules": {
    slug: "competition-rules",
    title: "Competition Rules",
    updated: "2026-08-01",
    intro:
      "These sample rules apply to every competition alongside the specific details on each competition page. Placeholder wording for this prototype only.",
    sections: [
      {
        id: "entry",
        heading: "1. How to enter",
        body: [
          "Choose a competition, select your number of entries (up to the maximum shown), answer the multiple-choice question and complete checkout. Each valid entry with a correct answer receives one ticket number.",
        ],
      },
      {
        id: "free",
        heading: "2. Free postal entry",
        body: [
          "You may enter without payment by sending an unenclosed postcard with your full name, address, date of birth, email, the competition name and your answer to the published address.",
          "One entry per postcard. Postal entries must arrive before the closing date. Free entries are allocated a ticket number in the same way as paid entries and have an equal chance of winning.",
        ],
      },
      {
        id: "closing",
        heading: "3. Closing and draw",
        body: [
          "Each competition closes at the date and time shown, or when all tickets are sold if sooner. The draw takes place on the published draw date, live on camera.",
          "The winning ticket is selected using a verified random number generator. The result is published immediately after the draw.",
        ],
      },
      {
        id: "winner",
        heading: "4. Claiming a prize",
        body: [
          "We contact the winner by phone and email within 24 hours. The winner must respond within 14 days and may need to provide proof of age and address.",
          "If a winner cannot be contacted after reasonable attempts over 14 days, we may redraw. The prize, or its cash alternative where offered, is provided within 30 days of a successful claim.",
        ],
      },
      {
        id: "conduct",
        heading: "5. Fair play",
        body: [
          "Bulk automated entries, multiple accounts and any attempt to interfere with a draw are not allowed and will result in entries being voided without refund.",
        ],
      },
      {
        id: "promoter",
        heading: "6. Promoter",
        body: [
          "The promoter would be RaffleGuru Ltd. A real version of this page would include the registered company number and address and any applicable licence details.",
        ],
      },
    ],
  },
};
