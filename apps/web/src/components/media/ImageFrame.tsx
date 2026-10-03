import Image, { type ImageProps } from "next/image";
import Icon from "../ui/Icon";

export type ImageRatio = "4/3" | "5/4" | "16/9" | "3/2" | "1/1" | "21/9";
export type ImageRadius = "none" | "card" | "panel" | "full";

type ImageFrameProps = {
  src: string;
  alt: string;
  ratio?: ImageRatio;
  radius?: ImageRadius;
  /** cover (default) fills + crops; contain shows the whole image */
  fit?: "cover" | "contain";
  priority?: boolean;
  sizes?: string;
  /** extra classes on the <img> (e.g. hover scale) */
  imgClassName?: string;
  className?: string;
} & Omit<
  ImageProps,
  "src" | "alt" | "fill" | "width" | "height" | "className" | "sizes"
>;

const ratioClass: Record<ImageRatio, string> = {
  "4/3": "aspect-[4/3]",
  "5/4": "aspect-[5/4]",
  "16/9": "aspect-video",
  "3/2": "aspect-[3/2]",
  "1/1": "aspect-square",
  "21/9": "aspect-[21/9]",
};

const radiusClass: Record<ImageRadius, string> = {
  none: "",
  card: "rounded-card",
  panel: "rounded-panel",
  full: "rounded-full",
};

/**
 * The one way to render a bounded, responsive image on the site.
 * Guarantees a predictable aspect ratio (no surprise cropping), consistent
 * corner radius, `object-fit`, and native lazy-loading (opt out with
 * `priority` for above-the-fold images).
 */
export default function ImageFrame({
  src,
  alt,
  ratio = "4/3",
  radius = "none",
  fit = "cover",
  priority = false,
  sizes = "(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw",
  imgClassName = "",
  className = "",
  ...rest
}: ImageFrameProps) {
  if (!src) {
    // No image supplied (e.g. an admin-created competition with no images).
    // next/image cannot render an empty src, so show a neutral frame with
    // the same ratio instead of failing the whole page.
    return (
      <div
        role="img"
        aria-label={alt}
        className={`grid w-full place-items-center overflow-hidden bg-mist text-gold-700 ${ratioClass[ratio]} ${radiusClass[radius]} ${className}`}
      >
        <Icon name="trophy" className="h-8 w-8" />
      </div>
    );
  }

  return (
    <div
      className={`relative w-full overflow-hidden ${ratioClass[ratio]} ${radiusClass[radius]} ${className}`}
    >
      <Image
        src={src}
        alt={alt}
        fill
        priority={priority}
        sizes={sizes}
        className={`${fit === "cover" ? "object-cover" : "object-contain"} ${imgClassName}`}
        {...rest}
      />
    </div>
  );
}
