'use client';

import { Icon } from '@/components/admin/Icon';
import { Panel, type PanelTone } from '@/components/elections/Panel';
import { Button } from '@/components/ui';
import { lineKey, type CredentialLine, type GeneratedFile } from '@/lib/api/credentials';
import { clock, longDay, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { canGenerateGroup, lineState, missingIn, percentOf } from './codeRules';

/** The colours of the card headers, one after the other (tokens only, through the panel tones). */
const TONES: readonly PanelTone[] = ['info', 'settings', 'calendar', 'facts'];

/** A download arrow as one stroke (the icon set has none). */
const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

/**
 * One group of the codes page: its name and state, how many of its voters have a code, the
 * button that makes the missing ones, and a download button for every ready PDF of the group
 * (the parts of a split run, a reissue's one-slip file). `n` picks the colour of the header.
 */
export function GroupCard({
  line,
  n,
  files,
  zone,
  running,
  canGenerate,
  busy,
  downloading,
  onGenerate,
  onDownload,
}: {
  line: CredentialLine;
  n: number;
  /** Every ready file of this group, newest first, then in order of part. */
  files: GeneratedFile[];
  /** The election's time zone, for the date of a file. */
  zone: string;
  running: boolean;
  /** False once the election is locked: no generate button at all. */
  canGenerate: boolean;
  busy: boolean;
  /** The UUID of the file being downloaded, if any. */
  downloading: string | null;
  onGenerate: (line: CredentialLine) => void;
  onDownload: (file: GeneratedFile) => void;
}) {
  const { t, locale } = useI18n();
  const key = lineKey(line);
  const state = lineState(line);
  const missing = missingIn(line);
  const name = line.group?.name ?? t('codes.group.noGroup');
  const percent = percentOf(line.with_code, line.voters);

  /** The words of a download button: the part of a split run, or the slips and the date when there are several files. */
  function fileLabel(file: GeneratedFile, several: boolean): string {
    if (file.parts > 1) {
      return t('codes.group.part', { part: file.part, parts: file.parts });
    }

    if (!several) return t('codes.group.download');

    const when = new Date(file.created_at);

    return t(`codes.group.fileOf.${pluralForm(locale, file.voter_count)}`, {
      count: file.voter_count,
      date: `${longDay(when, locale, zone)} ${clock(when, locale, zone)}`,
    });
  }

  return (
    <Panel
      tone={TONES[n % TONES.length]!}
      icon={line.group ? 'people' : 'user'}
      title={name}
      id={`codes-group-title-${key}`}
      testId={`codes-group-${key}`}
      aside={
        <span className="inline-flex h-7.5 items-center rounded-full bg-surface px-3 text-sm font-bold whitespace-nowrap text-ink">
          {t(`codes.group.state.${state}`)}
        </span>
      }
    >
      <p className="text-base text-ink-soft">
        {t(`codes.group.voters.${pluralForm(locale, line.voters)}`, { count: line.voters })}
      </p>

      <div className="flex flex-col gap-2">
        <p className="flex items-baseline gap-2">
          <b
            data-testid={`codes-group-${key}-count`}
            className="font-display text-[32px] leading-none font-extrabold text-ink"
          >
            {line.with_code}
          </b>
          <span className="text-base text-ink-soft">
            {t('codes.group.withCode', { total: line.voters })}
          </span>
        </p>
        <div aria-hidden="true" className="h-2 overflow-hidden rounded-full bg-line-soft">
          <div className="h-full rounded-full bg-primary" style={{ width: `${percent}%` }} />
        </div>
      </div>

      <div className="mt-auto flex flex-col gap-2 pt-1">
        {canGenerate ? (
          <Button
            variant={missing > 0 ? 'primary' : 'secondary'}
            disabled={!canGenerateGroup(line, running) || busy}
            onClick={() => onGenerate(line)}
            data-testid={`codes-group-${key}-generate`}
          >
            <span className="inline-flex items-center gap-2">
              <Icon name={missing > 0 ? 'qr' : 'check'} size={18} />
              {missing > 0
                ? t(`codes.group.generate.${pluralForm(locale, missing)}`, { count: missing })
                : t('codes.group.complete')}
            </span>
          </Button>
        ) : null}
        {files.map((file, index) => (
          // The wrapper is `codes-group-{key}-file-{n}`; the first file's button is also `-download`.
          <div
            key={file.id}
            className="flex flex-col"
            data-testid={`codes-group-${key}-file-${index + 1}`}
          >
            <Button
              variant="secondary"
              loading={downloading === file.id}
              onClick={() => onDownload(file)}
              data-testid={index === 0 ? `codes-group-${key}-download` : undefined}
            >
              <span className="inline-flex items-center gap-2">
                <Icon path={DOWNLOAD_PATH} size={18} />
                {fileLabel(file, files.length > 1)}
              </span>
            </Button>
          </div>
        ))}
      </div>
    </Panel>
  );
}
