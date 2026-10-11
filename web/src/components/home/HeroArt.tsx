import { Icon } from '@/components/admin/Icon';
import { Float } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { Translate } from '@/lib/i18n/messages';

const TILES = [
  { key: 'one', tint: 'from-primary-line to-primary', selected: true },
  { key: 'two', tint: 'from-warm-soft to-accent', selected: false },
  { key: 'three', tint: 'from-teal-soft to-teal', selected: false },
  { key: 'blank', tint: 'from-accent-light to-accent', selected: false },
] as const;

/** The right side of the hero: a ballot of photo tiles with one chosen, and two chips drifting around it. */
export function HeroArt({ t }: { t: Translate }) {
  return (
    <div aria-hidden="true" className="relative mx-auto h-80 w-full max-w-md md:h-96">
      <div className="float-art absolute top-14 left-0 z-10 flex items-center gap-2 rounded-md bg-surface px-3 py-2.5 text-sm font-semibold text-ink shadow-3">
        <span className="flex size-6 items-center justify-center rounded-full bg-teal text-surface">
          <Icon name="lock" size={14} />
        </span>
        {t('home.art.secret')}
      </div>

      <Float tilt="b" className="absolute top-2 right-0 w-[min(100%,22rem)]">
        <div className="rounded-xl bg-surface p-4 text-ink shadow-3">
          <p className="bg-hero rounded-md px-3.5 py-3 font-display text-base font-bold text-surface">
            {t('home.art.ballot')}
          </p>
          <ul className="mt-3 grid grid-cols-2 gap-2.5">
            {TILES.map((tile) => (
              <li
                key={tile.key}
                className={cx(
                  'relative rounded-md bg-primary-soft p-2 text-center text-sm font-semibold',
                  tile.selected ? 'ring-[3px] ring-primary' : undefined,
                )}
              >
                <span
                  className={cx('mb-1.5 block h-16 rounded-sm bg-linear-to-br md:h-20', tile.tint)}
                />
                {t(`home.art.${tile.key}`)}
                {tile.selected ? (
                  <span className="absolute top-2 right-2 flex size-6 items-center justify-center rounded-full bg-teal text-surface ring-[3px] ring-surface">
                    <Icon name="check" size={14} />
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
        </div>
      </Float>

      <div className="float-art absolute bottom-3 left-4 z-10 flex items-center gap-2 rounded-md bg-surface px-3 py-2.5 text-sm font-semibold text-ink shadow-3">
        <span className="flex size-6 items-center justify-center rounded-full bg-accent text-deep">
          <Icon name="phone" size={14} />
        </span>
        {t('home.art.phone')}
      </div>
    </div>
  );
}
