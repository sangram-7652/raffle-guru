import Container from "../layout/Container";
import HeroBanner from "./HeroBanner";

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
    </section>
  );
}
