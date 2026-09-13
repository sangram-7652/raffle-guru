"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useCart } from "../cart/CartContext";
import OrderSummary from "../cart/OrderSummary";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import EmptyState from "../ui/EmptyState";
import { FormField, Input, Select, Checkbox } from "../ui/form";
import { useAuth } from "@/lib/auth/AuthContext";
import { createOrder } from "@/lib/orders/client";
import type { ApiOrder } from "@/lib/orders/types";
import { ApiValidationError } from "@/lib/api/http";
import { useRazorpayCheckout } from "@/lib/payments/useRazorpayCheckout";
import { gbp } from "@/lib/format";
import { routes } from "@/lib/routes";

type Field =
  | "firstName"
  | "lastName"
  | "email"
  | "phone"
  | "dob"
  | "address1"
  | "city"
  | "postcode"
  | "country"
  | "terms";

type Values = Record<Exclude<Field, "terms">, string> & { terms: boolean };

const initial: Values = {
  firstName: "",
  lastName: "",
  email: "",
  phone: "",
  dob: "",
  address1: "",
  city: "",
  postcode: "",
  country: "United Kingdom",
  terms: false,
};

const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const ukPostcodeRe = /^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i;

export default function CheckoutView() {
  const router = useRouter();
  const cart = useCart();
  const { status: authStatus } = useAuth();
  const [values, setValues] = useState<Values>(initial);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [creatingOrder, setCreatingOrder] = useState(false);
  const [order, setOrder] = useState<ApiOrder | null>(null);
  const [orderError, setOrderError] = useState<string | null>(null);

  const { state: paymentState, pay } = useRazorpayCheckout((paidOrder) => {
    router.push(`${routes.orderSuccess}?reference=${encodeURIComponent(paidOrder.reference)}`);
  });

  // Defensive guest redirect — CartView/EntrySelector already gate adding
  // to a basket on being signed in, but a guest could still navigate here
  // directly.
  useEffect(() => {
    if (authStatus === "guest") {
      router.replace(`${routes.login}?redirect=${encodeURIComponent(routes.checkout)}`);
    }
  }, [authStatus, router]);

  if (authStatus === "loading" || authStatus === "guest") {
    return <div className="h-96 rounded-card border border-line bg-mist" />;
  }

  // Once an order has been created, the cart is already cleared — don't
  // show the empty-basket state while a payment attempt is still in
  // flight (or being retried) for that order.
  if (cart.hydrated && cart.items.length === 0 && !order) {
    return (
      <EmptyState
        icon="ticket"
        title="Nothing to check out"
        description="Your basket is empty — add some entries first."
        action={
          <Button href={routes.competitions}>Browse competitions</Button>
        }
      />
    );
  }

  const set =
    (key: Exclude<Field, "terms">) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
      setValues((v) => ({ ...v, [key]: e.target.value }));
      setErrors((x) => ({ ...x, [key]: undefined }));
    };

  function validate() {
    const e: Partial<Record<Field, string>> = {};
    if (!values.firstName.trim()) e.firstName = "Enter your first name.";
    if (!values.lastName.trim()) e.lastName = "Enter your last name.";
    if (!emailRe.test(values.email.trim()))
      e.email = "Enter a valid email address.";
    if (values.phone.replace(/\D/g, "").length < 10)
      e.phone = "Enter a valid phone number.";
    if (!values.dob) e.dob = "Enter your date of birth.";
    else {
      const age =
        (Date.now() - new Date(values.dob).getTime()) / 3.15576e10;
      if (age < 18) e.dob = "You must be 18 or over to enter.";
    }
    if (!values.address1.trim()) e.address1 = "Enter your address.";
    if (!values.city.trim()) e.city = "Enter your town or city.";
    if (!ukPostcodeRe.test(values.postcode.trim()))
      e.postcode = "Enter a valid UK postcode.";
    if (!values.terms) e.terms = "Please accept the terms to continue.";
    return e;
  }

  async function onSubmit(ev: React.FormEvent) {
    ev.preventDefault();
    setOrderError(null);

    // The order already exists (a previous attempt was dismissed or
    // failed) — retry payment for it directly, no new order and no
    // re-validation of a form that's already done its job.
    if (order) {
      void pay(order.reference);
      return;
    }

    const e = validate();
    setErrors(e);
    if (Object.keys(e).length > 0) {
      document
        .querySelector<HTMLElement>('[aria-invalid="true"], [data-error="true"]')
        ?.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }

    setCreatingOrder(true);
    try {
      const { data: newOrder } = await createOrder();
      await cart.refresh();
      setOrder(newOrder);
      setCreatingOrder(false);
      void pay(newOrder.reference);
    } catch (err) {
      setCreatingOrder(false);
      setOrderError(
        err instanceof ApiValidationError
          ? (err.fieldError("cart") ?? err.message)
          : "Something went wrong placing your order. Please try again.",
      );
    }
  }

  const paying = paymentState.status === "processing" || paymentState.status === "verifying";
  const placing = creatingOrder || paying;

  let submitLabel = "Place order";
  if (creatingOrder) submitLabel = "Placing order";
  else if (paymentState.status === "processing") submitLabel = "Opening secure checkout";
  else if (paymentState.status === "verifying") submitLabel = "Confirming payment";
  else if (order) submitLabel = "Retry payment";

  return (
    <form
      noValidate
      onSubmit={onSubmit}
      className="grid gap-8 lg:grid-cols-[1fr_22rem] lg:gap-12"
    >
      <div className="space-y-8">
        <fieldset disabled={!!order} className="space-y-8">
          <section aria-labelledby="cust-h">
            <h2
              id="cust-h"
              className="font-display text-lg font-extrabold tracking-tight text-heading"
            >
              Your details
            </h2>
            <div className="mt-4 grid gap-5 sm:grid-cols-2">
              <FormField label="First name" error={errors.firstName} required>
                <Input
                  value={values.firstName}
                  onChange={set("firstName")}
                  autoComplete="given-name"
                />
              </FormField>
              <FormField label="Last name" error={errors.lastName} required>
                <Input
                  value={values.lastName}
                  onChange={set("lastName")}
                  autoComplete="family-name"
                />
              </FormField>
              <FormField label="Email" error={errors.email} required>
                <Input
                  type="email"
                  value={values.email}
                  onChange={set("email")}
                  autoComplete="email"
                />
              </FormField>
              <FormField label="Phone" error={errors.phone} required>
                <Input
                  type="tel"
                  value={values.phone}
                  onChange={set("phone")}
                  autoComplete="tel"
                />
              </FormField>
              <FormField
                label="Date of birth"
                error={errors.dob}
                hint="You must be 18 or over."
                required
                className="sm:col-span-2 sm:max-w-[50%]"
              >
                <Input
                  type="date"
                  value={values.dob}
                  onChange={set("dob")}
                  autoComplete="bday"
                />
              </FormField>
            </div>
          </section>

          <section aria-labelledby="bill-h">
            <h2
              id="bill-h"
              className="font-display text-lg font-extrabold tracking-tight text-heading"
            >
              Billing address
            </h2>
            <div className="mt-4 grid gap-5 sm:grid-cols-2">
              <FormField
                label="Address"
                error={errors.address1}
                required
                className="sm:col-span-2"
              >
                <Input
                  value={values.address1}
                  onChange={set("address1")}
                  autoComplete="address-line1"
                />
              </FormField>
              <FormField label="Town / city" error={errors.city} required>
                <Input
                  value={values.city}
                  onChange={set("city")}
                  autoComplete="address-level2"
                />
              </FormField>
              <FormField label="Postcode" error={errors.postcode} required>
                <Input
                  value={values.postcode}
                  onChange={set("postcode")}
                  autoComplete="postal-code"
                />
              </FormField>
              <FormField label="Country" className="sm:col-span-2">
                <Select value={values.country} onChange={set("country")}>
                  <option>United Kingdom</option>
                </Select>
              </FormField>
            </div>
          </section>

          <div data-error={errors.terms ? "true" : undefined}>
            <Checkbox
              id="terms"
              checked={values.terms}
              onChange={(e) => {
                setValues((v) => ({ ...v, terms: e.target.checked }));
                setErrors((x) => ({ ...x, terms: undefined }));
              }}
              label={
                <>
                  I confirm I am 18 or over and accept the{" "}
                  <Link
                    href={routes.terms}
                    className="font-medium text-pink-700 underline underline-offset-2"
                  >
                    Terms
                  </Link>{" "}
                  and{" "}
                  <Link
                    href={routes.competitionRules}
                    className="font-medium text-pink-700 underline underline-offset-2"
                  >
                    Competition Rules
                  </Link>
                  .
                </>
              }
            />
            {errors.terms && (
              <p className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-error-600">
                <Icon name="close" className="h-3.5 w-3.5" />
                {errors.terms}
              </p>
            )}
          </div>
        </fieldset>

        {order && (
          <p className="flex items-center gap-1.5 rounded-xl border border-line bg-panel px-3.5 py-3 text-sm text-muted">
            <Icon name="check" className="h-4 w-4 shrink-0 text-pink-600" />
            Order {order.reference} created — payment pending.
          </p>
        )}
      </div>

      <div className="lg:sticky lg:top-24 lg:self-start">
        {order ? (
          <div className="rounded-card border border-line bg-card p-5 card-shadow sm:p-6">
            <h2 className="font-display text-lg font-extrabold tracking-tight text-heading">
              Order summary
            </h2>
            <ul className="mt-4 space-y-2 border-b border-line pb-4 text-sm">
              {order.items.map((i) => (
                <li key={i.id} className="flex justify-between gap-3 text-muted">
                  <span className="min-w-0 truncate">
                    {i.competition_title} <span className="text-xs">× {i.quantity}</span>
                  </span>
                  <span className="shrink-0 tabular-nums text-heading">
                    {gbp(i.line_total / 100)}
                  </span>
                </li>
              ))}
            </ul>
            <dl className="mt-4 space-y-2.5 text-sm">
              <div className="flex justify-between border-t border-line pt-3 font-display text-base font-extrabold text-heading">
                <dt>Total (payment pending)</dt>
                <dd className="tabular-nums">{gbp(order.total / 100)}</dd>
              </div>
            </dl>
            <div className="mt-5 space-y-4">
              <PaymentActions
                orderError={orderError}
                paymentState={paymentState}
                submitLabel={submitLabel}
                placing={placing}
              />
            </div>
          </div>
        ) : (
          <OrderSummary showItems>
            <div className="space-y-4">
              <PaymentActions
                orderError={orderError}
                paymentState={paymentState}
                submitLabel={submitLabel}
                placing={placing}
              />
            </div>
          </OrderSummary>
        )}
      </div>
    </form>
  );
}

function PaymentActions({
  orderError,
  paymentState,
  submitLabel,
  placing,
}: {
  orderError: string | null;
  paymentState: ReturnType<typeof useRazorpayCheckout>["state"];
  submitLabel: string;
  placing: boolean;
}) {
  return (
    <>
      {orderError && (
        <p role="alert" className="flex items-center gap-1.5 text-xs font-medium text-error-600">
          <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
          {orderError}
        </p>
      )}
      {paymentState.status === "dismissed" && (
        <p className="flex items-center gap-1.5 text-xs font-medium text-warning">
          <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
          Checkout closed — no payment was taken. You can try again.
        </p>
      )}
      {paymentState.status === "error" && (
        <p role="alert" className="flex items-center gap-1.5 text-xs font-medium text-error-600">
          <Icon name="close" className="h-3.5 w-3.5 shrink-0" />
          {paymentState.message}
        </p>
      )}
      <Button type="submit" block size="lg" loading={placing} disabled={placing}>
        {submitLabel}
      </Button>
      <ul className="space-y-1.5 text-xs text-muted">
        <li className="flex items-center gap-1.5">
          <Icon name="lock" className="h-3.5 w-3.5 text-pink-600" />
          Secure checkout via Razorpay — card details never touch our servers
        </li>
        <li className="flex items-center gap-1.5">
          <Icon name="shield" className="h-3.5 w-3.5 text-pink-600" />
          Your order isn&apos;t marked paid until payment is verified
        </li>
      </ul>
    </>
  );
}
