/**
 * Central route table. Import these instead of hardcoding paths so links
 * stay consistent across the site.
 */
export const routes = {
  home: "/",
  competitions: "/competitions",
  competition: (slug: string) => `/competitions/${slug}`,
  cart: "/cart",
  checkout: "/checkout",
  orderSuccess: "/order/success",
  winners: "/winners",
  howItWorks: "/how-it-works",
  faq: "/faq",
  about: "/about",
  contact: "/contact",
  terms: "/terms",
  privacy: "/privacy",
  cookies: "/cookies",
  responsiblePlay: "/responsible-play",
  competitionRules: "/competition-rules",
  login: "/login",
  register: "/register",
  forgotPassword: "/forgot-password",
  resetPassword: "/reset-password",
  verifyEmail: "/verify-email",
  account: "/account",
  accountEntries: "/account/entries",
} as const;

export const legalSlugs = [
  "terms",
  "privacy",
  "cookies",
  "responsible-play",
  "competition-rules",
] as const;

export type LegalSlug = (typeof legalSlugs)[number];
