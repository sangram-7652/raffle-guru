import Image from "next/image";

type LogoProps = {
  className?: string;
  priority?: boolean;
};

/**
 * RaffleGuru brand logo (client-supplied asset).
 * Source: public/brand/raffle-guru-gold.png — the black + gold mark that
 * anchors the current brand identity. Background is transparent (the
 * artwork's own black design elements — the bikini top, text panels,
 * ticket outline — are preserved; only the surrounding black backdrop
 * was removed), so it reads correctly on both the light header
 * (--color-paper) and the dark footer (--color-ink) without a mismatched
 * black box around it.
 *
 * `unoptimized`: Next's built-in optimizer re-encodes this to WebP for
 * any browser that asks for it (i.e. all of them) — confirmed by hand
 * that the resulting WebP has fully-opaque alpha (255) at every pixel
 * that should be transparent, flattening the cutout back into a solid
 * black box, while the unprocessed PNG and the optimizer's own
 * PNG-format fallback both render correctly. Root cause not pinned down
 * further (looks like a sharp/libwebp alpha-encoding issue specific to
 * this image's size/color profile, not a mistake in the source file —
 * verified against several quality levels). `unoptimized` serves the
 * source file as-is, skipping that broken re-encode entirely; this
 * loses Next's automatic responsive resizing for this one image, which
 * is the right trade for a header/footer logo rendering correctly over
 * saving bytes on an asset loaded once and cached.
 */
export default function Logo({ className = "h-10 w-auto", priority = false }: LogoProps) {
  return (
    <Image
      src="/brand/raffle-guru-gold.png"
      alt="RaffleGuru"
      width={1844}
      height={853}
      priority={priority}
      unoptimized
      className={className}
    />
  );
}
