import { Header, Footer } from "@/components/layout";
import {
  AnnouncementBar,
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
      <AnnouncementBar />
      <Header />
      <main className="flex-1">
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
      </main>
      <Footer />
    </>
  );
}
