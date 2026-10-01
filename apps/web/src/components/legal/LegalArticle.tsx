import Link from "next/link";
import { Container, PageHeader, Prose } from "@/components/layout";
import Icon from "@/components/ui/Icon";
import { dateMedium } from "@/lib/format";
import { routes } from "@/lib/routes";
import type { LegalPage } from "@/data/legal";

/**
 * Shared layout for every legal / policy page: masthead, sample-copy
 * notice, sticky in-page contents, and the sectioned article body.
 */
export default function LegalArticle({ page }: { page: LegalPage }) {
  return (
    <>
      <PageHeader
        eyebrow="Legal"
        title={page.title}
        lede={page.intro}
        breadcrumbs={[
          { label: "Home", href: routes.home },
          { label: page.title },
        ]}
      >
        <p className="text-xs text-muted">
          Last updated {dateMedium(page.updated)}
        </p>
      </PageHeader>

      <Container className="py-12 lg:py-16">
        <div
          role="note"
          className="mb-10 flex items-start gap-3 rounded-2xl border border-warning/25 bg-warning/10 p-4 text-sm text-warning"
        >
          <Icon name="shield" className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            <strong className="font-semibold">Sample copy.</strong> This page is
            placeholder wording created to demonstrate layout only. It is not
            legal advice and not a set of enforceable terms.
          </p>
        </div>

        <div className="grid gap-10 lg:grid-cols-[16rem_1fr]">
          <nav
            aria-label="On this page"
            className="hidden lg:block lg:self-start lg:sticky lg:top-24"
          >
            <p className="t-eyebrow mb-3 text-muted">On this page</p>
            <ul className="space-y-2 border-l border-line">
              {page.sections.map((s) => (
                <li key={s.id}>
                  <a
                    href={`#${s.id}`}
                    className="-ml-px block border-l border-transparent py-1 pl-4 text-sm text-muted transition-colors hover:border-gold hover:text-heading"
                  >
                    {s.heading}
                  </a>
                </li>
              ))}
            </ul>
          </nav>

          <article>
            <Prose>
              {page.sections.map((s) => (
                <section
                  key={s.id}
                  id={s.id}
                  className="scroll-mt-24"
                  aria-labelledby={`${s.id}-h`}
                >
                  <h2 id={`${s.id}-h`}>{s.heading}</h2>
                  {s.body.map((p, i) => (
                    <p key={i}>{p}</p>
                  ))}
                  {s.list && (
                    <ul>
                      {s.list.map((li, i) => (
                        <li key={i}>{li}</li>
                      ))}
                    </ul>
                  )}
                </section>
              ))}
            </Prose>

            <div className="mt-12 rounded-2xl border border-line bg-mist p-5 text-sm text-muted">
              Questions about this page?{" "}
              <Link
                href={routes.contact}
                className="font-medium text-gold-700 underline underline-offset-2 hover:text-gold"
              >
                Contact our team
              </Link>
              .
            </div>
          </article>
        </div>
      </Container>
    </>
  );
}
