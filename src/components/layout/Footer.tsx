import Logo from "../media/Logo";
import Icon from "../ui/Icon";
import Container from "./Container";
import FooterColumn from "./footer/FooterColumn";
import SocialLinks from "./footer/SocialLinks";
import { footerLinks } from "@/data/homepageMockData";

export default function Footer() {
  return (
    <footer className="border-t border-line bg-mist">
      <Container className="py-16">
        <div className="grid gap-12 lg:grid-cols-[1.4fr_2fr]">
          <div>
            <Logo className="h-16 w-auto" />
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted">
              A premium UK competition platform. Incredible prizes, transparent
              draws and real winners — every single week.
            </p>
            <SocialLinks className="mt-6" />
          </div>

          <div className="grid grid-cols-2 gap-8 sm:grid-cols-3">
            {Object.entries(footerLinks).map(([heading, links]) => (
              <FooterColumn key={heading} heading={heading} links={links} />
            ))}
          </div>
        </div>

        <div className="mt-14 flex flex-col gap-4 border-t border-line pt-8 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
          <p>© {new Date().getFullYear()} RaffleGuru. All rights reserved.</p>
          <p className="flex items-center gap-2">
            <Icon name="shield" className="h-3.5 w-3.5 text-pink-600" />
            18+ only. Please play responsibly. Full terms apply.
          </p>
        </div>

        <p className="mt-6 rounded-xl border border-line bg-card p-3 text-center text-[11px] leading-relaxed text-muted">
          Design concept only — this is a non-functional homepage prototype
          created for client review. No purchases, accounts or draws are live.
        </p>
      </Container>
    </footer>
  );
}
