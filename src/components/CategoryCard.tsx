import Image from "next/image";
import type { Category } from "@/data/homepageMockData";
import Icon from "./Icon";

type CategoryCardProps = {
  category: Category;
  /** wide editorial tile */
  featured?: boolean;
};

export default function CategoryCard({ category, featured = false }: CategoryCardProps) {
  return (
    <a
      href="#featured"
      className={`group relative flex w-full overflow-hidden rounded-card border border-line bg-card card-shadow ${
        featured ? "min-h-[16rem] lg:min-h-[22rem]" : "min-h-[14rem]"
      }`}
    >
      <Image
        src={category.image}
        alt={category.name}
        fill
        sizes={featured ? "(max-width: 1024px) 100vw, 40vw" : "(max-width: 1024px) 50vw, 20vw"}
        className="object-cover transition-transform duration-700 ease-out group-hover:scale-105"
      />
      <div className="absolute inset-0 bg-gradient-to-t from-ink via-ink/50 to-ink/5 transition-opacity duration-300" />
      <div className="absolute inset-0 opacity-0 mix-blend-soft-light transition-opacity duration-300 group-hover:opacity-70 brand-gradient" />

      <div className="relative z-10 mt-auto flex w-full items-end justify-between gap-3 p-5">
        <div>
          <span className="text-[10px] font-semibold uppercase tracking-[0.2em] text-pink-300">
            {category.count}
          </span>
          <h3 className="mt-1 font-display text-xl font-extrabold tracking-tight text-white sm:text-2xl">
            {category.name}
          </h3>
          <p className="mt-1 text-sm text-white/75">{category.tagline}</p>
        </div>
        <span className="inline-flex items-center gap-1 rounded-full border border-white/25 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur transition-colors group-hover:border-white/60">
          Explore
          <Icon name="arrow" className="h-3.5 w-3.5" />
        </span>
      </div>
    </a>
  );
}
