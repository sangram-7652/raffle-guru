import type { MetadataRoute } from "next";

// Site-wide noindex — see the `robots` meta tag in layout.tsx for the
// authoritative deindex signal; this additionally keeps crawlers from
// fetching the site at all.
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      disallow: "/",
    },
  };
}
