import Link from "next/link";
import { announcement } from "@/data/site";
import Container from "../layout/Container";
import Icon from "../ui/Icon";

export default function AnnouncementBar() {
  return (
    <div className="relative z-40 border-b border-gold/20 bg-ink text-white">
      <Container className="flex items-center justify-center gap-2 py-2 text-center text-[13px] font-medium sm:gap-3">
        <span aria-hidden className="text-xs text-gold">
          ★
        </span>
        <span className="truncate">{announcement.text}</span>
        <Link
          href={announcement.ctaHref}
          className="hidden shrink-0 items-center gap-1 rounded-full border border-gold/40 px-3 py-1 text-xs font-semibold text-gold transition-colors hover:bg-gold/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 sm:inline-flex"
        >
          {announcement.ctaLabel}
          <Icon name="arrow" className="h-3.5 w-3.5" />
        </Link>
      </Container>
    </div>
  );
}
