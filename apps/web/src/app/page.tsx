import {
  Hero,
  TrustStrip,
  EndingSoon,
  CategorySection,
  FeaturedCompetitions,
  HowItWorks,
  WhyChooseUs,
  ReviewsSection,
  Faq,
  FinalCTA,
} from "@/components/sections";
import WinnerSection from "@/components/winner/WinnerSection";
import { Suspense } from "react";
import { CompetitionSectionSkeleton } from "@/components/sections/CompetitionSectionBody";

// Competition sections read the live API on every request. Prerendering
// would bake in whatever the API returned at build time (and build machines
// often have no API at all).
export const dynamic = "force-dynamic";

export default function Home() {
  return (
    <>
      <Hero />
      <TrustStrip />
      <Suspense fallback={<CompetitionSectionSkeleton columns={3} />}>
        <EndingSoon />
      </Suspense>
      <CategorySection />
      <Suspense fallback={<CompetitionSectionSkeleton columns={4} />}>
        <FeaturedCompetitions />
      </Suspense>
      <HowItWorks />
      <WhyChooseUs />
      <WinnerSection />
      <ReviewsSection />
      <Faq />
      <FinalCTA />
    </>
  );
}
