/* The few icons of "График", drawn like the menu's: a stroke in the colour of the text, hidden from screen readers. */

type IconProps = { size?: number; className?: string };

function Icon({ size = 20, className, paths }: IconProps & { paths: string[] }) {
  return (
    <svg className={className} viewBox="0 0 24 24" width={size} height={size} aria-hidden="true" focusable="false" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      {paths.map((path) => <path key={path} d={path} />)}
    </svg>
  );
}

export const ChevronIcon = (props: IconProps) => <Icon size={18} {...props} paths={['m6 9 6 6 6-6']} />;
export const PreviousIcon = (props: IconProps) => <Icon {...props} paths={['m15 6-6 6 6 6']} />;
export const NextIcon = (props: IconProps) => <Icon {...props} paths={['m9 6 6 6-6 6']} />;
export const CloseIcon = (props: IconProps) => <Icon {...props} paths={['M6 6l12 12', 'M18 6 6 18']} />;
export const EyeIcon = (props: IconProps) => <Icon {...props} paths={['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z']} />;
