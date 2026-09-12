/**
 * RaffleGuru — site-wide content (nav, footer, contact, brand).
 * Static prototype content only.
 */
import { routes } from "@/lib/routes";

export type NavLink = { label: string; href: string };

export const announcement = {
  text: "New competitions. Bigger prizes. More chances to win.",
  ctaLabel: "See what's live",
  ctaHref: routes.competitions,
};

export const mainNav: NavLink[] = [
  { label: "Competitions", href: routes.competitions },
  { label: "Winners", href: routes.winners },
  { label: "How It Works", href: routes.howItWorks },
  { label: "About", href: routes.about },
  { label: "FAQ", href: routes.faq },
];

export const footerColumns: { heading: string; links: NavLink[] }[] = [
  {
    heading: "Explore",
    links: [
      { label: "All Competitions", href: routes.competitions },
      { label: "Winners", href: routes.winners },
      { label: "How It Works", href: routes.howItWorks },
      { label: "About Us", href: routes.about },
    ],
  },
  {
    heading: "Support",
    links: [
      { label: "FAQ", href: routes.faq },
      { label: "Contact", href: routes.contact },
      { label: "Responsible Play", href: routes.responsiblePlay },
      { label: "Competition Rules", href: routes.competitionRules },
    ],
  },
  {
    heading: "Legal",
    links: [
      { label: "Terms & Conditions", href: routes.terms },
      { label: "Privacy Policy", href: routes.privacy },
      { label: "Cookie Policy", href: routes.cookies },
    ],
  },
];

export const socials = [
  { name: "instagram", label: "RaffleGuru on Instagram", href: "#" },
  { name: "facebook", label: "RaffleGuru on Facebook", href: "#" },
  { name: "tiktok", label: "RaffleGuru on TikTok", href: "#" },
  { name: "youtube", label: "RaffleGuru on YouTube", href: "#" },
] as const;

export const contactChannels = [
  {
    icon: "spark" as const,
    label: "Email us",
    value: "support@raffleguru.example",
    detail: "We reply to every message within one working day.",
  },
  {
    icon: "clock" as const,
    label: "Support hours",
    value: "Mon–Sat, 9am–8pm",
    detail: "Live draw nights are covered until close.",
  },
  {
    icon: "shield" as const,
    label: "Registered office",
    value: "RaffleGuru Ltd, London",
    detail: "Full company details on request. Prototype address only.",
  },
];

export const contactSubjects = [
  "General question",
  "My entries",
  "Payments & refunds",
  "A draw or result",
  "Claiming a prize",
  "Something else",
];

export const brandValues = [
  {
    icon: "shield" as const,
    title: "Transparent by default",
    copy: "Every draw is streamed live, every ticket count is published, and every winning number is posted straight after.",
  },
  {
    icon: "ticket" as const,
    title: "Fair odds, always",
    copy: "Fixed entry caps mean you always know your chances before you enter. No unlimited tickets, no moving goalposts.",
  },
  {
    icon: "gift" as const,
    title: "Prizes people actually want",
    copy: "Performance cars, tax-free cash, luxury watches and once-in-a-lifetime trips — chosen to be genuinely exciting.",
  },
  {
    icon: "trophy" as const,
    title: "Real winners, every week",
    copy: "Genuine names, real ticket numbers and delivery photos. We hand over the keys and post the proof.",
  },
];

export const companyStats = [
  { value: "£4.2M+", label: "Prizes paid out" },
  { value: "31,000+", label: "Winners so far" },
  { value: "4.9/5", label: "Rated by players" },
  { value: "600+", label: "Draws completed" },
];
