import Image from "next/image";

type LogoProps = {
  className?: string;
  priority?: boolean;
};

/**
 * RaffleGuru brand logo (client-supplied asset).
 * Source: public/raffleguru-logo-original.jpeg — the background was removed
 * to a transparent PNG (public/raffleguru-logo.png) so it sits cleanly on
 * the light theme. Swap the PNG if the client provides an official
 * transparent/vector version.
 */
export default function Logo({ className = "h-10 w-auto", priority = false }: LogoProps) {
  return (
    <Image
      src="/raffleguru-logo.png"
      alt="RaffleGuru"
      width={1466}
      height={788}
      priority={priority}
      sizes="(max-width: 640px) 160px, 220px"
      className={className}
    />
  );
}
