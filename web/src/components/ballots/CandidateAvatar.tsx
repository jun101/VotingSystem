import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { Sex } from '@/lib/api/candidates';

/**
 * The default avatar of a candidate, chosen by sex: a silhouette on violet (female) or blue
 * (male). With a `photo` (an address), the picture takes its place in the same box: square,
 * rounded, never larger than the box. Decorative: the name always sits beside it.
 */
export function CandidateAvatar({
  sex,
  size,
  iconSize,
  className,
  testId,
  photo,
  photoTestId,
}: {
  sex: Sex;
  /** Tailwind size classes of the circle. */
  size: string;
  iconSize: number;
  className?: string;
  testId?: string;
  /** The address of the photo; no photo, the avatar. */
  photo?: string | null;
  photoTestId?: string;
}) {
  if (photo) {
    return (
      // An already optimised picture of the media disk (or a local preview), shown inside its box.
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={photo}
        alt=""
        data-testid={photoTestId}
        className={cx(
          'shrink-0 rounded-lg border border-line bg-surface object-cover shadow-1',
          size,
          className,
        )}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      data-sex={sex}
      data-testid={testId}
      className={cx(
        'cand-avatar flex shrink-0 items-center justify-center rounded-full shadow-1',
        size,
        className,
      )}
    >
      <Icon name="user" size={iconSize} />
    </span>
  );
}
