"use client";

import { useId, useState } from "react";
import Icon from "../ui/Icon";
import type { CompetitionQuestion as QuestionType } from "@/data/competitions";

/**
 * Skill question. UI only — the "correct" answer is checked in the browser
 * purely to show feedback. Nothing is submitted anywhere.
 *
 * `answerIndex` is -1 for real (API-backed) competitions: the public API
 * never sends the answer (see docs/COMPETITIONS_API.md), so there's
 * nothing to check client-side any more — any selection is treated as a
 * valid entry rather than falsely claiming it's "wrong".
 */
export default function CompetitionQuestion({
  question,
  onAnsweredChange,
}: {
  question: QuestionType;
  onAnsweredChange?: (correct: boolean) => void;
}) {
  const name = useId();
  const [selected, setSelected] = useState<number | null>(null);
  const hasKnownAnswer = question.answerIndex >= 0;

  const choose = (i: number) => {
    setSelected(i);
    onAnsweredChange?.(hasKnownAnswer ? i === question.answerIndex : true);
  };

  const correct = hasKnownAnswer ? selected === question.answerIndex : true;

  return (
    <fieldset className="rounded-2xl border border-line bg-card p-5">
      <legend className="flex items-center gap-2 px-1 font-display text-sm font-bold tracking-tight text-heading">
        <Icon name="spark" className="h-4 w-4 text-gold-700" />
        Entry question
      </legend>
      <p className="mt-1 text-sm text-muted">{question.prompt}</p>

      <div className="mt-4 grid gap-2 sm:grid-cols-2">
        {question.options.map((opt, i) => {
          const active = selected === i;
          return (
            <label
              key={opt}
              className={`flex cursor-pointer items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-sm transition-colors ${
                active
                  ? "border-gold bg-gold/5 text-heading"
                  : "border-line text-muted hover:border-gold/40"
              }`}
            >
              <input
                type="radio"
                name={name}
                checked={active}
                onChange={() => choose(i)}
                className="h-4 w-4 accent-gold"
              />
              {opt}
            </label>
          );
        })}
      </div>

      {selected !== null && (
        <p
          role="status"
          className={`mt-3 flex items-center gap-1.5 text-xs font-medium ${
            correct ? "text-success-600" : "text-warning"
          }`}
        >
          <Icon
            name={correct ? "check" : "close"}
            className="h-3.5 w-3.5 shrink-0"
          />
          {!hasKnownAnswer
            ? "Answer selected."
            : correct
              ? "Correct — you'd be entered into the draw."
              : "Not quite. Only correct answers are entered into the draw."}
        </p>
      )}
    </fieldset>
  );
}
