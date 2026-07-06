type InputProps = {
  id: string;
  label: string;
  type?: string;
  placeholder?: string;
};

/**
 * Renders a labeled input field.
 */
export function Input({
  id,
  label,
  type = "text",
  placeholder,
}: InputProps) {
  return (
    <label htmlFor={id} className="block space-y-2">
      <span className="text-sm font-medium text-[var(--foreground)]">
        {label}
      </span>
      <input
        id={id}
        type={type}
        placeholder={placeholder}
        className="h-12 w-full rounded-[var(--radius-md)] border border-[var(--nexus-border)] bg-white px-4 text-sm outline-none transition placeholder:text-gray-400 focus:border-[var(--nexus-primary)] focus:ring-4 focus:ring-blue-900/10"
      />
    </label>
  );
}
