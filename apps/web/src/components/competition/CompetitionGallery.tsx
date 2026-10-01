"use client";

import { useState } from "react";
import Image from "next/image";
import ImageFrame from "../media/ImageFrame";

export default function CompetitionGallery({
  images,
  title,
}: {
  images: string[];
  title: string;
}) {
  const [active, setActive] = useState(0);
  const list = images.length > 0 ? images : [""];

  return (
    <div>
      <ImageFrame
        key={active}
        src={list[active]}
        alt={`${title} — image ${active + 1} of ${list.length}`}
        ratio="4/3"
        radius="panel"
        priority
        sizes="(max-width: 1024px) 100vw, 55vw"
        className="card-shadow-lg"
      />

      {list.length > 1 && (
        <ul
          className="mt-3 flex gap-3 overflow-x-auto pb-1"
          aria-label={`${title} thumbnails`}
        >
          {list.map((src, i) => (
            <li key={src + i} className="shrink-0">
              <button
                type="button"
                aria-label={`Show image ${i + 1}`}
                aria-current={i === active}
                onClick={() => setActive(i)}
                className={`relative block h-16 w-24 overflow-hidden rounded-lg border-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 ${
                  i === active
                    ? "border-gold"
                    : "border-transparent hover:border-line"
                }`}
              >
                <Image
                  src={src}
                  alt=""
                  fill
                  sizes="96px"
                  className="object-cover"
                />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
