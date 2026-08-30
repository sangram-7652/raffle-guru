import type { ReactNode } from "react";

/**
 * Long-form content wrapper — legal pages, about copy. Styles descendant
 * elements with the design-system type scale and rhythm so page bodies
 * never hand-pick sizes.
 */
export default function Prose({
  children,
  className = "",
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={`
        max-w-none text-muted
        [&_h2]:t-h3 [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-heading first:[&_h2]:mt-0
        [&_h3]:t-h4 [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:text-heading
        [&_p]:t-body [&_p]:mb-4
        [&_ul]:mb-4 [&_ul]:mt-1 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5
        [&_ol]:mb-4 [&_ol]:mt-1 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-5
        [&_li]:t-body [&_li]:marker:text-pink-300
        [&_a]:font-medium [&_a]:text-pink-700 [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-pink-600
        [&_strong]:font-semibold [&_strong]:text-heading
        ${className}
      `}
    >
      {children}
    </div>
  );
}
