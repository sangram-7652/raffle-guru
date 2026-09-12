import Button from "../ui/Button";
import Badge from "../ui/Badge";
import Icon from "../ui/Icon";
import ProgressBar from "../ui/ProgressBar";
import StatCard from "../ui/StatCard";
import Container from "../layout/Container";
import ImageFrame from "../media/ImageFrame";
import HeroBanner from "./HeroBanner";
import { heroFeatured, stats } from "@/data/homepageMockData";
import { routes } from "@/lib/routes";

export default function Hero() {
  return (
    <section
      id="top"
      className="brand-glow relative overflow-hidden border-b border-line"
    >
      <div className="grid-texture pointer-events-none absolute inset-0 opacity-[0.35]" />

      <Container className="relative z-10 pt-8 lg:pt-10">
        <div style={{ animation: "var(--animate-rise)" }}>
          <HeroBanner />
        </div>
      </Container>

      <Container className="relative z-10 grid gap-14 pb-20 pt-12 lg:grid-cols-[1.05fr_1fr] lg:items-center lg:gap-10 lg:pb-28 lg:pt-14">
        {/* Left */}
        <div
          className="flex flex-col items-start gap-7"
          style={{ animation: "var(--animate-rise)" }}
        >
          <h1 className="t-display text-heading">
            Your next big win
            <br />
            <span className="text-gradient">starts here.</span>
          </h1>

          <p className="max-w-lg t-body-lg text-muted">
            Discover incredible prizes, exciting competitions and your chance to
            win something extraordinary.
          </p>

          <div className="flex flex-col gap-3 sm:flex-row">
            <Button href={routes.competitions} size="lg">
              EXPLORE COMPETITIONS
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
            <Button href={routes.howItWorks} size="lg" variant="secondary">
              HOW IT WORKS
            </Button>
          </div>

          <div className="mt-2 grid w-full max-w-lg grid-cols-3 gap-4 border-t border-line pt-6">
            {stats.map((s) => (
              <StatCard key={s.label} value={s.value} label={s.label} />
            ))}
          </div>
        </div>

        {/* Right — featured prize visual */}
        <div
          className="relative"
          style={{ animation: "var(--animate-rise)", animationDelay: "120ms" }}
        >
          <div className="relative overflow-hidden rounded-panel border border-line shadow-[var(--shadow-e3)]">
            <ImageFrame
              src={heroFeatured.image}
              alt="Featured competition prize"
              ratio="4/3"
              priority
              sizes="(max-width: 1024px) 100vw, 42vw"
            />
            <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-paper/90 via-paper/10 to-transparent" />

            <div
              className="absolute right-4 top-4 rounded-2xl border border-line bg-white/85 px-4 py-2.5 text-center shadow-lg backdrop-blur-md"
              style={{ animation: "var(--animate-float)" }}
            >
              <p className="font-display text-lg font-extrabold leading-none text-heading">
                {heroFeatured.entryLabel.replace("Entries from ", "")}
              </p>
              <p className="mt-1 text-[10px] uppercase tracking-widest text-muted">
                per entry
              </p>
            </div>
          </div>

          {/* Competition information card */}
          <div className="card-shadow-lg relative z-10 mx-auto -mt-12 w-[92%] rounded-2xl border border-line bg-card/95 p-5 backdrop-blur-xl sm:w-[86%]">
            <div className="flex items-center justify-between">
              <Badge tone="featured" icon>
                {heroFeatured.badge}
              </Badge>
              <span className="inline-flex items-center gap-1 text-xs text-muted">
                <Icon name="clock" className="h-3.5 w-3.5" />
                {heroFeatured.drawLabel}
              </span>
            </div>

            <h3 className="mt-3 t-h3 text-heading">{heroFeatured.title}</h3>
            <p className="mt-1 text-sm text-muted">{heroFeatured.model}</p>

            <ProgressBar value={heroFeatured.soldPct} className="mt-4" />

            <Button href={routes.competitions} block className="mt-4">
              ENTER NOW
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </Container>
    </section>
  );
}
