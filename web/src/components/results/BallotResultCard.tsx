'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Panel, type PanelTone } from '@/components/elections/Panel';
import { CountUp, GrowBar } from '@/components/motion';
import { Button, Textarea } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { percentText } from '@/components/turnout/turnoutModel';
import { initialsOf, toneOf } from '@/components/voters/voterText';
import { saveResultNote } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { ResultBallot } from '@/lib/api/results';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import {
  NOTE_MAX,
  barShare,
  canCreateSecondRound,
  candidateState,
  fullName,
  noteChanged,
  noteLength,
  noteTooLong,
  percentOf,
  normalizeNote,
  tiedNames,
} from './resultsModel';

const TONES: PanelTone[] = ['info', 'facts', 'calendar', 'settings'];

const AVATAR_TONES = [
  'bg-primary-soft text-primary-hover',
  'bg-teal-soft text-teal-ink',
  'bg-warm-soft text-warm-ink',
  'bg-status-published-soft text-status-published',
] as const;

const TAG =
  'inline-flex min-h-7 items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-ink';

type NoteState = 'idle' | 'saving' | 'saved' | 'error';

/**
 * The result of one ballot (A13): its candidates with votes, percentages and an animated bar,
 * the winners or the tie, the blank votes, the bulletin check, the public note and, for a tie,
 * the second round. `n` counts from 1, in the order the API gives the ballots.
 */
export function BallotResultCard({
  n,
  ballot,
  onSecondRound,
}: {
  n: number;
  ballot: ResultBallot;
  onSecondRound: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const [saved, setSaved] = useState<string | null>(ballot.note);
  const [draft, setDraft] = useState(ballot.note ?? '');
  const [state, setState] = useState<NoteState>('idle');
  const [problem, setProblem] = useState<string | null>(null);

  const tooLong = noteTooLong(draft);
  const saving = state === 'saving';
  const names = tiedNames(ballot);
  const secondRound = ballot.second_round ?? null;

  async function save() {
    if (saving || tooLong) return;

    setState('saving');
    setProblem(null);

    try {
      const note = await saveResultNote(ballot.id, normalizeNote(draft) || null);

      setSaved(note);
      setDraft(note ?? '');
      setState('saved');
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const code = failure.fields.note?.[0];

      setProblem(code ? fieldText('result.note', code, tIfAny) : errorText(failure, tIfAny));
      setState('error');
    }
  }

  const seats = t(`results.ballot.seats.${pluralForm(locale, ballot.seats)}`, {
    count: ballot.seats,
  });
  const scope =
    ballot.scope === 'group' && ballot.groups.length > 0
      ? t('results.ballot.scopeGroups', {
          names: new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(
            ballot.groups.map((group) => group.name),
          ),
        })
      : t('results.ballot.scopeGeneral');

  return (
    <Panel
      tone={TONES[(n - 1) % TONES.length]!}
      icon="ballot"
      title={ballot.title}
      id={`result-ballot-title-${n}`}
      testId={`result-ballot-${n}`}
      aside={
        <span className="inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap">
          <Icon name="seat" size={16} />
          {seats}
        </span>
      }
    >
      <ul className="flex flex-wrap gap-2" aria-label={t('results.ballot.tags')}>
        <li className={TAG}>
          <Icon name={ballot.scope === 'group' ? 'people' : 'globe'} size={16} />
          {scope}
        </li>
        <li className={TAG} data-testid={`result-turnout-${n}`}>
          <Icon name="check" size={16} />
          {t('results.ballot.turnout', {
            voted: ballot.voted,
            eligible: ballot.eligible,
            percent: percentText(percentOf(ballot.voted, ballot.eligible), locale),
          })}
        </li>
      </ul>

      {ballot.candidates.length > 0 ? (
        <ul className="grid grid-cols-1 gap-3 @xl:grid-cols-2">
          {ballot.candidates.map((candidate, index) => {
            const m = index + 1;
            const name = fullName(candidate);
            const kind = candidateState(ballot, candidate.id);
            const percent = percentText(candidate.percentage, locale);

            return (
              <li
                key={candidate.id}
                data-testid={`result-candidate-${n}-${m}`}
                data-state={kind}
                className={cx(
                  'flex min-w-0 flex-col gap-2.5 rounded-lg border p-3.5 shadow-1',
                  kind === 'winner'
                    ? 'border-status-open bg-status-open-soft'
                    : kind === 'tied'
                      ? 'border-warm bg-warm-softer'
                      : 'border-line bg-surface',
                )}
              >
                <div className="flex min-w-0 items-center gap-3">
                  <span
                    aria-hidden="true"
                    className={cx(
                      'flex size-11 shrink-0 items-center justify-center rounded-full font-display text-base font-extrabold',
                      AVATAR_TONES[toneOf(name, AVATAR_TONES.length)],
                    )}
                  >
                    {initialsOf(name)}
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col">
                    <b className="text-md leading-tight font-bold break-words text-ink">{name}</b>
                    <span className="text-sm break-words text-ink-soft">
                      {candidate.party ?? t('results.candidate.noParty')}
                    </span>
                  </span>
                </div>
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                  <span className="text-base font-medium text-ink">
                    <b className="font-display text-xl font-extrabold">
                      <CountUp value={candidate.votes} locale={locale} duration={700} />
                    </b>{' '}
                    {t(`results.candidate.votes.${pluralForm(locale, candidate.votes)}`)}
                  </span>
                  <b className="font-display text-xl font-extrabold text-ink">
                    {t('results.percent', { percent })}
                  </b>
                </div>
                <GrowBar
                  value={barShare(candidate.votes, ballot.cast)}
                  trackClassName="h-2.5"
                  className={kind === 'tied' ? 'bg-warm' : 'bg-bar-open'}
                />
                {kind === 'winner' ? (
                  <span
                    data-testid={`result-winner-${n}-${m}`}
                    className="inline-flex w-fit items-center gap-1.5 rounded-full bg-status-open px-3 py-1 text-sm font-bold text-surface"
                  >
                    <Icon name="check" size={16} />
                    {t('results.candidate.winner')}
                  </span>
                ) : kind === 'tied' ? (
                  <span
                    data-testid={`result-tied-${n}-${m}`}
                    className="inline-flex w-fit items-center gap-1.5 rounded-full bg-warm-soft px-3 py-1 text-sm font-bold text-warm-ink"
                  >
                    <Icon name="warn" size={16} />
                    {t('results.candidate.tied')}
                  </span>
                ) : null}
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="text-base text-ink-soft">{t('results.candidate.none')}</p>
      )}

      <div
        data-testid={`result-blank-${n}`}
        className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-dashed border-line-strong bg-surface-alt px-3.5 py-3 text-base text-ink"
      >
        <span aria-hidden="true" className="text-ink-soft">
          <Icon name="blank" size={20} />
        </span>
        <b className="font-bold">{t('results.blank.title')}</b>
        <span className="ml-auto">
          {t(`results.blank.line.${pluralForm(locale, ballot.blank)}`, {
            count: ballot.blank,
            percent: percentText(percentOf(ballot.blank, ballot.cast), locale),
          })}
        </span>
      </div>

      {ballot.tie ? (
        <div
          data-testid={`result-tie-${n}`}
          className="flex gap-3 rounded-lg border border-warm bg-warm-softer p-3.5 text-base text-warm-ink"
        >
          <span aria-hidden="true" className="mt-0.5 shrink-0">
            <Icon name="warn" size={22} />
          </span>
          <div className="flex min-w-0 flex-col gap-1">
            <b className="font-display text-lg font-extrabold">{t('results.tie.title')}</b>
            <p>
              {t(`results.tie.text.${pluralForm(locale, ballot.tie.seats_left)}`, {
                seats: ballot.tie.seats_left,
                names: new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(
                  names,
                ),
              })}
            </p>
            <p>{t('results.tie.never')}</p>
          </div>
        </div>
      ) : null}

      <p
        data-testid={`result-check-${n}`}
        data-ok={ballot.check.ok ? 'true' : 'false'}
        className={cx(
          'flex items-start gap-2.5 rounded-lg px-3.5 py-3 text-base',
          ballot.check.ok
            ? 'bg-status-open-soft text-status-open'
            : 'border border-warm bg-warm-softer text-warm-ink',
        )}
      >
        <span aria-hidden="true" className="mt-0.5 shrink-0">
          <Icon name={ballot.check.ok ? 'check' : 'warn'} size={20} />
        </span>
        <span>
          <b className="font-bold">
            {ballot.check.ok ? t('results.check.ok') : t('results.check.bad')}
          </b>{' '}
          {t('results.check.line', {
            recorded: ballot.check.recorded,
            expected: ballot.check.expected,
          })}
          {ballot.check.ok ? null : <> {t('results.check.badHelp')}</>}
        </span>
      </p>

      <div className="flex flex-col gap-2 border-t border-line pt-3.5">
        <Textarea
          data-testid={`result-note-${n}`}
          label={t('results.note.label')}
          help={t('results.note.help')}
          value={draft}
          error={tooLong ? t('results.note.tooLong', { max: NOTE_MAX }) : undefined}
          onChange={(event) => {
            setDraft(event.target.value);
            if (state !== 'saving') setState('idle');
          }}
          rows={3}
        />
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
          <span
            data-testid={`result-note-count-${n}`}
            className={cx('text-sm font-medium', tooLong ? 'text-danger' : 'text-ink-soft')}
          >
            {t('results.note.count', { count: noteLength(draft), max: NOTE_MAX })}
          </span>
          <span role="status" className="text-sm font-medium">
            {state === 'saved' && !noteChanged(draft, saved) ? (
              <span className="inline-flex items-center gap-1.5 text-status-open">
                <Icon name="check" size={16} />
                {t('results.note.saved')}
              </span>
            ) : null}
          </span>
          {problem ? (
            <span
              role="alert"
              data-testid={`result-note-error-${n}`}
              className="text-sm font-medium text-danger"
            >
              {problem}
            </span>
          ) : null}
          <Button
            variant="secondary"
            className="ml-auto max-sm:w-full"
            loading={saving}
            disabled={saving || tooLong}
            onClick={() => void save()}
            data-testid={`result-note-save-${n}`}
          >
            {t('results.note.save')}
          </Button>
        </div>
      </div>

      {ballot.tie && secondRound ? (
        <div className="flex flex-wrap items-center gap-3 rounded-lg bg-primary-soft px-3.5 py-3 text-base text-ink">
          <span aria-hidden="true" className="text-primary">
            <Icon name="flag" size={20} />
          </span>
          <span className="min-w-0 flex-1">{t('results.second.created')}</span>
          <Link
            href={`/admin/elections/${secondRound}`}
            data-testid="second-round-link"
            className={cx(
              'ui-control inline-flex min-h-11 items-center gap-2 rounded-full border border-primary bg-surface px-4 text-base font-bold text-primary-hover hover:bg-primary-soft',
              focusRing,
            )}
          >
            {t('results.second.link')}
            <Icon name="right" size={18} />
          </Link>
        </div>
      ) : null}

      {canCreateSecondRound(ballot) ? (
        <Button
          variant="primary"
          className="w-full gap-2 sm:w-fit"
          onClick={onSecondRound}
          data-testid={`result-second-round-${n}`}
        >
          <Icon name="flag" size={18} />
          {t('results.second.button')}
        </Button>
      ) : null}
    </Panel>
  );
}
