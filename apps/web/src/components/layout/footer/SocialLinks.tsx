import Icon from "../../ui/Icon";
import { socials } from "@/data/site";

export default function SocialLinks({ className = "" }: { className?: string }) {
  return (
    <ul className={`flex gap-3 ${className}`}>
      {socials.map((s) => (
        <li key={s.name}>
          <a
            href={s.href}
            aria-label={s.label}
            className="grid h-10 w-10 place-items-center rounded-full border border-white/15 bg-white/5 text-white/65 transition-colors hover:border-gold/50 hover:text-gold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50"
          >
            <Icon name={s.name} className="h-4 w-4" />
          </a>
        </li>
      ))}
    </ul>
  );
}
