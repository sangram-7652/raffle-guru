import type { Metadata } from "next";
import LegalArticle from "@/components/legal/LegalArticle";
import { legalPages } from "@/data/legal";

const page = legalPages["privacy"];

export const metadata: Metadata = {
  title: page.title,
  description: page.intro,
};

export default function Page() {
  return <LegalArticle page={page} />;
}
