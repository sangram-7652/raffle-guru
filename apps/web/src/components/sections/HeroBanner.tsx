import Button from "../ui/Button";
import Icon from "../ui/Icon";
import ImageFrame from "../media/ImageFrame";
import { heroHighlight } from "@/data/homepageMockData";

export default function HeroBanner() {
  return (
    <div className="card-shadow-lg relative overflow-hidden rounded-panel border border-gold/15 bg-ink">
      <div className="grid gap-8 px-6 py-10 sm:px-10 sm:py-12 lg:grid-cols-2 lg:items-center lg:gap-12 lg:px-14 lg:py-16">
        <div className="flex flex-col items-start gap-4">
          <span className="text-[11px] font-semibold uppercase tracking-[0.22em] text-gold-pale">
            {heroHighlight.eyebrow}
          </span>
          <h1 className="max-w-xl font-display text-3xl font-extrabold uppercase leading-[1.05] tracking-tight text-white sm:text-4xl lg:text-5xl">
            {heroHighlight.headline}
          </h1>
          <p className="text-base font-semibold text-gold-pale sm:text-lg">
            {heroHighlight.altPrizeLine}
          </p>
          <p className="text-sm text-white/75 sm:text-base">
            {heroHighlight.ticketPrice}
          </p>

          <Button href={heroHighlight.ctaHref} size="lg" className="mt-2">
            {heroHighlight.ctaLabel}
            <Icon name="arrow" className="h-4 w-4" />
          </Button>

          <p className="mt-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/55 sm:text-xs">
            {heroHighlight.supportingLine}
          </p>
        </div>

        <ImageFrame
          src={heroHighlight.image}
          alt={heroHighlight.imageAlt}
          ratio="4/3"
          radius="card"
          priority
          sizes="(max-width: 1024px) 100vw, 50vw"
        />
      </div>
    </div>
  );
}
