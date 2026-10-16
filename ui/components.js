import { useEffect } from "react";

export function Notice({ status = "info", children, onRemove }) {
  const role = status === "error" ? "alert" : "status";
  return (
    <div className={`pendulum-arc-notice pendulum-arc-notice--${status}`} role={role}>
      {children}
      {onRemove ? (
        <button type="button" className="pendulum-arc-notice__dismiss" onClick={onRemove}>
          Dismiss
        </button>
      ) : null}
    </div>
  );
}

export function Spinner() {
  return <span className="pendulum-arc-spinner" role="status" aria-label="Loading" />;
}

export function TextControl({
  id: idProp,
  label,
  type = "text",
  value,
  onChange,
  autoComplete,
  help,
  className = "",
  readOnly = false,
  title,
  onClick,
  onFocus,
}) {
  const id =
    idProp ||
    `pendulum-arc-${String(label).toLowerCase().replace(/[^a-z0-9]+/g, "-")}`;
  const classes = ["pendulum-arc-field", className].filter(Boolean).join(" ");
  return (
    <div className={classes}>
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        type={type}
        value={value}
        autoComplete={autoComplete}
        readOnly={readOnly}
        title={title}
        onClick={onClick}
        onFocus={onFocus}
        onChange={(event) => onChange(event.target.value)}
      />
      {help ? <p className="pendulum-arc-field__help">{help}</p> : null}
    </div>
  );
}

export function SelectControl({ label, value, options, onChange, help, className = "" }) {
  const id = `pendulum-arc-${String(label).toLowerCase().replace(/[^a-z0-9]+/g, "-")}`;
  const classes = ["pendulum-arc-field", className].filter(Boolean).join(" ");
  return (
    <div className={classes}>
      <label htmlFor={id}>{label}</label>
      <select id={id} value={value} onChange={(event) => onChange(event.target.value)}>
        {(options || []).map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {help ? <p className="pendulum-arc-field__help">{help}</p> : null}
    </div>
  );
}

export function Modal({ title, onRequestClose, className = "", children }) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === "Escape") {
        onRequestClose();
      }
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onRequestClose]);

  return (
    <div className="pendulum-arc-modal-overlay" onClick={onRequestClose}>
      <div
        className={`pendulum-arc-dialog ${className}`}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <h2>{title}</h2>
        {children}
      </div>
    </div>
  );
}
