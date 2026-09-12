import Section from "../layout/Section";
import SectionHeading from "../layout/SectionHeading";
import Reveal from "../ui/Reveal";
import CategoryCard from "../category/CategoryCard";
import { categories } from "@/data/homepageMockData";

export default function CategorySection() {
  const [feature, ...rest] = categories;

  return (
    <Section id="categories" surface="mist" bordered className="scroll-mt-24">
      <SectionHeading
        eyebrow="Choose your prize"
        title="Pick the win that fits you"
        subtitle="From performance cars to tax-free cash — browse by what you actually want."
      />

      <div className="mt-12 grid gap-6 lg:grid-cols-2">
        <Reveal className="flex">
          <CategoryCard category={feature} featured />
        </Reveal>
        <div className="grid gap-6 sm:grid-cols-2">
          {rest.map((category, i) => (
            <Reveal key={category.id} delay={(i + 1) * 70}>
              <CategoryCard category={category} />
            </Reveal>
          ))}
        </div>
      </div>
    </Section>
  );
}
