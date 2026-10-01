import { Container } from "@/components/layout";
import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import { routes } from "@/lib/routes";

export default function NotFound() {
  return (
    <Container className="flex min-h-[60vh] flex-col items-center justify-center py-20 text-center">
      <span className="t-eyebrow text-gold-700">404</span>
      <h1 className="mt-3 t-h1 text-heading">This page has been drawn away</h1>
      <p className="mt-3 max-w-md t-body text-muted">
        The page you&apos;re after doesn&apos;t exist or has moved. Let&apos;s
        get you back to the competitions.
      </p>
      <div className="mt-8 flex flex-wrap justify-center gap-3">
        <Button href={routes.competitions} size="lg">
          Browse competitions
          <Icon name="arrow" className="h-4 w-4" />
        </Button>
        <Button href={routes.home} variant="secondary" size="lg">
          Back home
        </Button>
      </div>
    </Container>
  );
}
