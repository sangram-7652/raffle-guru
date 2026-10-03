import Accordion from "../ui/Accordion";
import type { Competition } from "@/data/competitions";

export default function CompetitionInfo({
  competition,
}: {
  competition: Competition;
}) {
  const items = [
    {
      id: "about",
      title: "About this competition",
      content: (
        <div className="space-y-3">
          {competition.description.map((p, i) => (
            <p key={i}>{p}</p>
          ))}
        </div>
      ),
    },
    {
      id: "prize",
      title: "Prize details",
      content: (
        <div className="space-y-6">
          {competition.prizes.map((prize) => (
            <div key={prize.id} className="space-y-3">
              {competition.prizes.length > 1 && (
                <p className="font-semibold text-heading">
                  {prize.title}
                  {prize.quantity > 1 && <> × {prize.quantity}</>}
                </p>
              )}
              <ul className="list-disc space-y-1.5 pl-5 marker:text-gold-700">
                {(prize.description.length > 0 ? prize.description : [prize.title]).map((d, i) => (
                  <li key={i}>{d}</li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      ),
    },
    {
      id: "rules",
      title: "Rules & how the draw works",
      content: (
        <ol className="list-decimal space-y-1.5 pl-5">
          {competition.rules.map((r, i) => (
            <li key={i}>{r}</li>
          ))}
        </ol>
      ),
    },
    {
      id: "faq",
      title: "Competition FAQ",
      content: (
        <dl className="space-y-4">
          {competition.faqs.map((f, i) => (
            <div key={i}>
              <dt className="font-semibold text-heading">{f.q}</dt>
              <dd className="mt-1">{f.a}</dd>
            </div>
          ))}
        </dl>
      ),
    },
  ];

  return <Accordion items={items} headingLevel={2} defaultOpen={["about"]} />;
}
