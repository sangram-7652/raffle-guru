import Button from "@/components/ui/Button";
import Icon from "@/components/ui/Icon";
import { routes } from "@/lib/routes";

type AccountStatusCardProps = {
  name: string;
  email: string;
  verified: boolean;
};

/** Compact account summary. Shows identity and verification state only —
 * never the password, tokens, or internal ids. */
export default function AccountStatusCard({ name, email, verified }: AccountStatusCardProps) {
  return (
    <section
      aria-labelledby="account-status-heading"
      className="rounded-card border border-line bg-card p-5 card-shadow sm:p-6"
    >
      <h2 id="account-status-heading" className="t-eyebrow text-muted">
        Account
      </h2>

      <dl className="mt-4 space-y-3 text-sm">
        <div>
          <dt className="sr-only">Name</dt>
          <dd className="font-display text-base font-extrabold tracking-tight text-heading">{name}</dd>
        </div>
        <div>
          <dt className="sr-only">Email</dt>
          <dd className="break-all text-muted">{email}</dd>
        </div>
        <div>
          <dt className="sr-only">Email verification</dt>
          <dd>
            {verified ? (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-success/25 bg-success/12 px-2.5 py-1 t-caption font-semibold text-success-600">
                <Icon name="check" className="h-3.5 w-3.5" />
                Email verified
              </span>
            ) : (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-warning/25 bg-warning/12 px-2.5 py-1 t-caption font-semibold text-warning">
                <Icon name="lock" className="h-3.5 w-3.5" />
                Email not verified
              </span>
            )}
          </dd>
        </div>
      </dl>

      <Button href={routes.accountDetails} variant="secondary" size="sm" block className="mt-6">
        Account details
      </Button>
    </section>
  );
}
