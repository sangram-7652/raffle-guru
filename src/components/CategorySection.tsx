import CategoryCard from "./CategoryCard";
import Reveal from "./Reveal";
import SectionHeading from "./SectionHeading";
import { categories } from "@/data/homepageMockData";

export default function CategorySection() {
  const [feature, ...rest] = categories;

  return (
    <section
      id="categories"
      className="border-y border-line bg-mist py-20 lg:py-28"
    >
      <div className="mx-auto max-w-7xl px-4 sm:px-6 xl:px-8">
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
      </div>
    </section>
  );
}
