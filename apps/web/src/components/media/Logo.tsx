import Image from "next/image";

type LogoProps = {
  className?: string;
  priority?: boolean;
};

/**
 * RaffleGuru brand logo (client-supplied asset).
 * Source: public/brand/raffle-guru-gold.png — the black + gold mark that
 * anchors the current brand identity. Its own background is solid black,
 * so it sits flush against the site's dark surfaces without a cutout.
 */
export default function Logo({ className = "h-10 w-auto", priority = false }: LogoProps) {
  return (
    <Image
      src="/brand/raffle-guru-gold.png"
      alt="RaffleGuru"
      width={1844}
      height={853}
      priority={priority}
      sizes="(max-width: 640px) 180px, 240px"
      className={className}
    />
  );
}
