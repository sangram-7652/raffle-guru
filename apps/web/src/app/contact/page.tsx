import type { Metadata } from "next";
import Link from "next/link";
import { Container, PageHeader } from "@/components/layout";
import Icon from "@/components/ui/Icon";
import ContactForm from "@/components/contact/ContactForm";
import { contactChannels } from "@/data/site";
import { routes } from "@/lib/routes";

export const metadata: Metadata = {
  title: "Contact",
  description:
    "Get in touch with the RaffleGuru team about your entries, a draw, a prize or anything else.",
};

export default function ContactPage() {
  return (
    <>
      <PageHeader
        eyebrow="Contact"
        title="Talk to the RaffleGuru team"
        lede="Questions about your entries, a draw or a prize? Send us a message and we'll come back to you within one working day."
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: "Contact" },
        ]}
      />

      <Container className="py-12 lg:py-16">
        <div className="grid gap-10 lg:grid-cols-[1fr_1.15fr] lg:gap-14">
          <div>
            <h2 className="font-display text-lg font-extrabold tracking-tight text-heading">
              Other ways to reach us
            </h2>
            <ul className="mt-6 space-y-4">
              {contactChannels.map((c) => (
                <li
                  key={c.label}
                  className="flex gap-4 rounded-2xl border border-line bg-card p-4 card-shadow"
                >
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-line bg-panel text-pink-600">
                    <Icon name={c.icon} className="h-[18px] w-[18px]" />
                  </span>
                  <div>
                    <p className="t-eyebrow text-muted">{c.label}</p>
                    <p className="mt-1 font-display font-bold text-heading">
                      {c.value}
                    </p>
                    <p className="mt-0.5 text-xs text-muted">{c.detail}</p>
                  </div>
                </li>
              ))}
            </ul>

            <div className="mt-6 rounded-2xl border border-line bg-mist p-4 text-sm text-muted">
              Looking for a quick answer? Our{" "}
              <Link
                href={routes.faq}
                className="font-medium text-pink-700 underline underline-offset-2 hover:text-pink-600"
              >
                FAQ
              </Link>{" "}
              covers entries, payments, draws and prizes.
            </div>
          </div>

          <ContactForm />
        </div>
      </Container>
    </>
  );
}
