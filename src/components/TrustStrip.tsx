import Icon from "./Icon";
import Reveal from "./Reveal";
import { trustPoints } from "@/data/homepageMockData";

const icons = ["ticket", "lock", "shield", "trophy"] as const;

export default function TrustStrip() {
  return (
    <section className="border-b border-line bg-mist">
      <div className="mx-auto grid max-w-7xl grid-cols-2 gap-x-6 gap-y-8 px-4 py-10 sm:px-6 lg:grid-cols-4 xl:px-8">
        {trustPoints.map((point, i) => (
          <Reveal
            key={point.label}
            delay={i * 70}
            className="flex items-start gap-3"
          >
            <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-xl border border-line bg-panel text-pink-600">
              <Icon name={icons[i]} className="h-[18px] w-[18px]" />
            </span>
            <div>
              <p className="font-display text-sm font-bold tracking-tight text-heading">
                {point.label}
              </p>
              <p className="mt-0.5 text-xs leading-relaxed text-muted">
                {point.detail}
              </p>
            </div>
          </Reveal>
        ))}
      </div>
    </section>
  );
}
