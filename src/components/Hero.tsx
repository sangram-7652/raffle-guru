import Image from "next/image";
import Button from "./Button";
import Icon from "./Icon";
import ProgressBar from "./ProgressBar";
import { heroFeatured, stats } from "@/data/homepageMockData";

export default function Hero() {
  return (
    <section
      id="top"
      className="brand-glow relative overflow-hidden border-b border-line"
    >
      <div className="grid-texture pointer-events-none absolute inset-0 opacity-[0.35]" />

      <div className="relative z-10 mx-auto grid max-w-7xl gap-14 px-4 pb-20 pt-14 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:items-center lg:gap-10 lg:pb-28 lg:pt-20 xl:px-8">
        {/* Left */}
        <div
          className="flex flex-col items-start gap-7"
          style={{ animation: "var(--animate-rise)" }}
        >
          {/* <span className="inline-flex items-center gap-2 rounded-full border border-line bg-white/80 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] text-pink-600 backdrop-blur">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-pink opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-pink" />
            </span>
            UK Competition Platform
          </span> */}

          <h1 className="font-display text-[2.75rem] font-extrabold leading-[0.98] tracking-tight text-heading sm:text-6xl lg:text-[4.2rem]">
            Your next big win
            <br />
            <span className="text-gradient">starts here.</span>
          </h1>

          <p className="max-w-lg text-lg text-muted">
            Discover incredible prizes, exciting competitions and your chance to
            win something extraordinary.
          </p>

          <div className="flex flex-col gap-3 sm:flex-row">
            <Button href="#featured" size="lg">
              EXPLORE COMPETITIONS
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
            <Button href="#how-it-works" size="lg" variant="secondary">
              HOW IT WORKS
            </Button>
          </div>

          <dl className="mt-2 grid w-full max-w-lg grid-cols-3 gap-4 border-t border-line pt-6">
            {stats.map((s) => (
              <div key={s.label} className="flex flex-col">
                <dt className="order-2 text-xs text-muted">{s.label}</dt>
                <dd className="order-1 font-display text-xl font-extrabold text-heading sm:text-2xl">
                  {s.value}
                </dd>
              </div>
            ))}
          </dl>
        </div>

        {/* Right — featured prize visual */}
        <div
          className="relative"
          style={{
            animation: "var(--animate-rise)",
            animationDelay: "120ms",
          }}
        >
          <div className="relative overflow-hidden rounded-[1.75rem] border border-line shadow-[0_34px_80px_-30px_rgba(15,15,28,0.30)]">
            <Image
              src={heroFeatured.image}
              alt="Featured competition prize"
              width={1400}
              height={1000}
              priority
              sizes="(max-width: 1024px) 100vw, 42vw"
              className="aspect-[4/3] w-full object-cover"
            />
            <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-paper/90 via-paper/10 to-transparent" />

            {/* Floating prize badge */}
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
              <span className="inline-flex items-center gap-1.5 rounded-full brand-gradient px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white">
                <Icon name="spark" className="h-3 w-3" />
                {heroFeatured.badge}
              </span>
              <span className="inline-flex items-center gap-1 text-xs text-muted">
                <Icon name="clock" className="h-3.5 w-3.5" />
                {heroFeatured.drawLabel}
              </span>
            </div>

            <h3 className="mt-3 font-display text-xl font-extrabold tracking-tight text-heading">
              {heroFeatured.title}
            </h3>
            <p className="mt-1 text-sm text-muted">{heroFeatured.model}</p>

            <ProgressBar value={heroFeatured.soldPct} className="mt-4" />

            <Button href="#featured" className="mt-4 w-full">
              ENTER NOW
              <Icon name="arrow" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>
    </section>
  );
}
