import {
  Children,
  cloneElement,
  isValidElement,
  useId,
  type ReactElement,
  type ReactNode,
} from "react";
import Label from "./Label";
import HelperText from "./HelperText";
import ErrorMessage from "./ErrorMessage";
import type { FieldState } from "./styles";

type FormFieldProps = {
  label: ReactNode;
  /** the control element — Input / Textarea / Select */
  children: ReactElement<Record<string, unknown>>;
  hint?: ReactNode;
  error?: ReactNode;
  success?: ReactNode;
  required?: boolean;
  optional?: boolean;
  className?: string;
};

/**
 * Wires a label, control and message together with matching ids and
 * `aria-describedby` / `aria-invalid`. Pass a single control as the child:
 *
 *   <FormField label="Email" error={errors.email}>
 *     <Input type="email" />
 *   </FormField>
 */
export default function FormField({
  label,
  children,
  hint,
  error,
  success,
  required,
  optional,
  className = "",
}: FormFieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const msgId = `${id}-msg`;
  const state: FieldState = error ? "error" : success ? "success" : "default";

  const control = Children.only(children);
  const described =
    [hint ? hintId : null, error || success ? msgId : null]
      .filter(Boolean)
      .join(" ") || undefined;

  const enhanced = isValidElement(control)
    ? cloneElement(control, {
        id,
        state,
        "aria-describedby": described,
      } as Record<string, unknown>)
    : control;

  return (
    <div className={`flex flex-col gap-1.5 ${className}`}>
      <Label htmlFor={id} required={required} optional={optional}>
        {label}
      </Label>
      {enhanced}
      {hint && !error && <HelperText id={hintId}>{hint}</HelperText>}
      {error ? (
        <ErrorMessage id={msgId}>{error}</ErrorMessage>
      ) : success ? (
        <p
          id={msgId}
          className="t-caption font-medium text-success-600"
        >
          {success}
        </p>
      ) : null}
    </div>
  );
}
