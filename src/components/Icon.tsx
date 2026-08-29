type IconName =
  | "arrow"
  | "shield"
  | "ticket"
  | "gift"
  | "trophy"
  | "check"
  | "lock"
  | "clock"
  | "spark"
  | "menu"
  | "close"
  | "instagram"
  | "facebook"
  | "tiktok"
  | "youtube";

type IconProps = {
  name: IconName;
  className?: string;
};

const paths: Record<IconName, React.ReactNode> = {
  arrow: <path d="M5 12h14M13 6l6 6-6 6" />,
  shield: <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4" />,
  ticket: (
    <path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H6a2 2 0 0 1-2-2 2 2 0 0 0 0-4zM12 6v2M12 12v2M12 16v2" />
  ),
  gift: (
    <path d="M4 12h16v8H4zM4 8h16v4H4zM12 8v12M12 8S9 8 8 6.5 9 3.5 12 8zM12 8s3 0 4-1.5S15 3.5 12 8z" />
  ),
  trophy: (
    <path d="M8 4h8v5a4 4 0 0 1-8 0zM8 6H5v1a3 3 0 0 0 3 3M16 6h3v1a3 3 0 0 1-3 3M10 15h4M9 20h6M12 15v5" />
  ),
  check: <path d="M4 12l5 5L20 6" />,
  lock: <path d="M6 10V8a6 6 0 0 1 12 0v2M5 10h14v10H5zM12 14v3" />,
  clock: <path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />,
  spark: <path d="M12 3l2.2 5.5L20 11l-5.8 2.5L12 19l-2.2-5.5L4 11l5.8-2.5L12 3z" />,
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  close: <path d="M6 6l12 12M18 6L6 18" />,
  instagram: (
    <path d="M4 8a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM16.5 7.5v.01" />
  ),
  facebook: <path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v8h4v-8h3l1-4h-4V8a1 1 0 0 1 1-1z" />,
  tiktok: <path d="M14 4v9.5a3.5 3.5 0 1 1-3.5-3.5M14 4c0 3 2 5 5 5" />,
  youtube: (
    <path d="M3 8a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3zM10 9l5 3-5 3z" />
  ),
};

export default function Icon({ name, className = "h-5 w-5" }: IconProps) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {paths[name]}
    </svg>
  );
}
