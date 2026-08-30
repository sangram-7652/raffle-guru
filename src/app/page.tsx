import AnnouncementBar from "@/components/AnnouncementBar";
import Header from "@/components/Header";
import Hero from "@/components/Hero";
import TrustStrip from "@/components/TrustStrip";
import EndingSoon from "@/components/EndingSoon";
import CategorySection from "@/components/CategorySection";
import FeaturedCompetitions from "@/components/FeaturedCompetitions";
import HowItWorks from "@/components/HowItWorks";
import WhyChooseUs from "@/components/WhyChooseUs";
import WinnerSection from "@/components/WinnerSection";
import ReviewsSection from "@/components/ReviewsSection";
import Faq from "@/components/Faq";
import FinalCTA from "@/components/FinalCTA";
import Footer from "@/components/Footer";

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
