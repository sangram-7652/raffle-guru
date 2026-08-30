import { announcement } from "@/data/homepageMockData";
import Container from "../layout/Container";
import Icon from "../ui/Icon";

export default function AnnouncementBar() {
  return (
    <div className="relative z-40 border-b border-white/10 brand-gradient text-white">
      <Container className="flex items-center justify-center gap-2 py-2 text-center text-[13px] font-medium sm:gap-3">
        <span aria-hidden className="text-sm">
          🔥
        </span>
        <span className="truncate">{announcement.text}</span>
        <a
          href={announcement.ctaHref}
          className="hidden shrink-0 items-center gap-1 rounded-full bg-black/20 px-3 py-1 text-xs font-semibold transition-colors hover:bg-black/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 sm:inline-flex"
        >
          {announcement.ctaLabel}
          <Icon name="arrow" className="h-3.5 w-3.5" />
        </a>
      </Container>
    </div>
  );
}
