import Image from 'next/image';

/**
 * The product's mark: the app icon (the same picture as the browser tab and the home screen). Decorative unless it
 * is given a `label`. The file is served as it is, the picture is already the right size for a menu.
 */
export function LogoMark({ size = 48, label }: { size?: number; label?: string }) {
  return (
    <Image
      src="/icon.png"
      width={size}
      height={size}
      alt={label ?? ''}
      aria-hidden={label ? undefined : true}
      unoptimized
      priority
      className="shrink-0"
    />
  );
}
