import Container from "../layout/Container";
import Icon from "../ui/Icon";
import Reveal from "../ui/Reveal";
import { trustPoints } from "@/data/homepageMockData";

const icons = ["ticket", "lock", "shield", "trophy"] as const;

export default function TrustStrip() {
  return (
    <div className="border-b border-line bg-mist">
      <Container className="grid grid-cols-2 gap-x-6 gap-y-8 py-10 lg:grid-cols-4">
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
      </Container>
    </div>
  );
}
