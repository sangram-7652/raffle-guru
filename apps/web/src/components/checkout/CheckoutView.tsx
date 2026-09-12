"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useCart } from "../cart/CartContext";
import OrderSummary from "../cart/OrderSummary";
import PromoCode from "../cart/PromoCode";
import PaymentPlaceholder from "./PaymentPlaceholder";
import Button from "../ui/Button";
import Icon from "../ui/Icon";
import EmptyState from "../ui/EmptyState";
import { FormField, Input, Select, Checkbox } from "../ui/form";
import { buildMockOrder, saveMockOrder } from "@/lib/order";
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
  const [values, setValues] = useState<Values>(initial);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [placing, setPlacing] = useState(false);

  if (cart.hydrated && cart.items.length === 0) {
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

  function onSubmit(ev: React.FormEvent) {
    ev.preventDefault();
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length > 0) {
      document
        .querySelector<HTMLElement>('[aria-invalid="true"], [data-error="true"]')
        ?.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    setPlacing(true);
    const order = buildMockOrder({
      items: cart.items,
      subtotal: cart.subtotal,
      discount: cart.discount,
      total: cart.total,
      promoCode: cart.promo?.code ?? null,
      email: values.email.trim(),
      name: `${values.firstName.trim()} ${values.lastName.trim()}`,
    });
    saveMockOrder(order);
    cart.clear();
    router.push(routes.orderSuccess);
  }

  return (
    <form
      noValidate
      onSubmit={onSubmit}
      className="grid gap-8 lg:grid-cols-[1fr_22rem] lg:gap-12"
    >
      <div className="space-y-8">
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

        <PaymentPlaceholder />

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
      </div>

      <div className="lg:sticky lg:top-24 lg:self-start">
        <OrderSummary showItems>
          <div className="space-y-4">
            <PromoCode />
            <Button type="submit" block size="lg" loading={placing}>
              {placing ? "Placing order" : "Place order"}
            </Button>
            <ul className="space-y-1.5 text-xs text-muted">
              <li className="flex items-center gap-1.5">
                <Icon name="lock" className="h-3.5 w-3.5 text-pink-600" />
                Encrypted checkout — card details never stored
              </li>
              <li className="flex items-center gap-1.5">
                <Icon name="shield" className="h-3.5 w-3.5 text-pink-600" />
                Prototype only — no payment is taken and no order is created
              </li>
            </ul>
          </div>
        </OrderSummary>
      </div>
    </form>
  );
}
