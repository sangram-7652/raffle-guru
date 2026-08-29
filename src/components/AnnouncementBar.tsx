import { announcement } from "@/data/homepageMockData";
import Icon from "./Icon";

export default function AnnouncementBar() {
  return (
    <div className="relative z-40 border-b border-white/10 brand-gradient text-white">
      <div className="mx-auto flex max-w-7xl items-center justify-center gap-2 px-4 py-2 text-center text-[13px] font-medium sm:gap-3">
        <span aria-hidden className="text-sm">
          🔥
        </span>
        <span className="truncate">{announcement.text}</span>
        <a
          href={announcement.ctaHref}
          className="hidden shrink-0 items-center gap-1 rounded-full bg-black/20 px-3 py-1 text-xs font-semibold transition-colors hover:bg-black/35 sm:inline-flex"
        >
          {announcement.ctaLabel}
          <Icon name="arrow" className="h-3.5 w-3.5" />
        </a>
      </div>
    </div>
  );
}
