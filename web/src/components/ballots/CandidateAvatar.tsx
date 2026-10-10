import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { Sex } from '@/lib/api/candidates';

/**
 * The default avatar of a candidate, chosen by sex: a silhouette on violet (female) or blue
 * (male). The photo of slice 06d will replace it. Decorative: the name always sits beside it.
 */
export function CandidateAvatar({
  sex,
  size,
  iconSize,
  className,
  testId,
}: {
  sex: Sex;
  /** Tailwind size classes of the circle. */
  size: string;
  iconSize: number;
  className?: string;
  testId?: string;
}) {
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
