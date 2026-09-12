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

export default function Home() {
  return (
    <>
      <Hero />
      <TrustStrip />
      <EndingSoon />
      <CategorySection />
      <FeaturedCompetitions />
      <HowItWorks />
      <WhyChooseUs />
      <WinnerSection />
      <ReviewsSection />
      <Faq />
      <FinalCTA />
    </>
  );
}
