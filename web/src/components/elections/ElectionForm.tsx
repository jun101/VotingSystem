'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { linkSecondary } from '@/components/admin/classes';
import { useAuthForm } from '@/components/auth/useAuthForm';
import { Button, Input, Notice, Select, Textarea } from '@/components/ui';
import {
  createElection,
  removeElectionCover,
  updateElection,
  uploadElectionCover,
} from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import { isSupportedZone, toLocalInput } from '@/lib/format/zonedTime';
import { DEFAULT_TIME_ZONE, timeZoneChoices } from '@/lib/format/timeZones';
import { useI18n } from '@/lib/i18n/client';
import { CoverField, usePickedCover } from './CoverField';
import { CoverPreview } from './CoverPreview';
import { dateFields } from './dateFields';
import { DateRangePicker } from './DateRangePicker';
import { NextSteps } from './NextSteps';
import { Panel } from './Panel';
import { SchedulePanel } from './SchedulePanel';

const FIELDS = [
  'title',
  'description',
  'starts_at',
  'ends_at',
  'timezone',
  'language',
  'candidate_order',
  'results_display',
] as const;

const DESCRIPTION_MAX = 5000;

type Values = {
  title: string;
  description: string;
  starts: string;
  ends: string;
  timezone: string;
  language: Election['language'];
  order: Election['candidate_order'];
  results: Election['results_display'];
};

/** A cover that was refused: told under the cover field, not in the alert of the form. */
class CoverFailure extends ApiError {}

function valuesOf(election: Election): Values {
  return {
    title: election.title,
    description: election.description ?? '',
    starts: toLocalInput(election.starts_at, election.timezone),
    ends: toLocalInput(election.ends_at, election.timezone),
    timezone: election.timezone,
    language: election.language,
    order: election.candidate_order,
    results: election.results_display,
  };
}

/**
 * The election form (screen A04, and the edit of a draft): the fields on the left, and from `lg`
 * a sticky side panel on the right with the schedule in words, the cover and the next steps. The
 * typed dates are read in the chosen time zone. A field error is shown under its field and the
 * first one gets the focus. Save and Cancel are at the top right on a desktop and at the bottom
 * on a phone.
 *
 * A cover picked on a new election is sent right after the election is created; if that fails
 * the election exists, and the next save changes it instead of creating another.
 */
export function ElectionForm({
  election,
  defaults,
}: {
  /** The election being edited; absent for a new one. */
  election?: Election;
  /** The time zone and the language of the institution: the starting point of a new election. */
  defaults: { timezone: string; language: Election['language'] };
}) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS, 'election');
  const [values, setValues] = useState<Values>(() =>
    election
      ? valuesOf(election)
      : {
          title: '',
          description: '',
          starts: '',
          ends: '',
          timezone: isSupportedZone(defaults.timezone) ? defaults.timezone : DEFAULT_TIME_ZONE,
          language: defaults.language,
          order: 'manual',
          results: 'full',
        },
  );
  const cover = usePickedCover();
  const [storedCover, setStoredCover] = useState<Election['cover']>(election?.cover ?? null);
  const [coverError, setCoverError] = useState<string | null>(null);
  const coverErrorRef = useRef<HTMLParagraphElement>(null);
  // The election exists once created, even when the picture then failed.
  const [createdId, setCreatedId] = useState<string | null>(null);
  const id = election?.id ?? createdId;

  // The cover alert gets the focus when a picture is refused.
  useEffect(() => {
    if (coverError) coverErrorRef.current?.focus();
  }, [coverError]);

  const change =
    (name: keyof Values) =>
    (event: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
      setValues((current) => ({ ...current, [name]: event.target.value }));
    };

  function coverProblem(caught: unknown): string {
    const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
    const code = failure.fields.file?.[0];

    return code ? fieldText('cover.file', code, tIfAny) : errorText(failure, tIfAny);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setCoverError(null);

    const dates = dateFields(values, election);
    const body = {
      title: values.title,
      description: values.description,
      // An empty or half-typed date is sent empty (the API says it is required); on an edit a date
      // is sent only when it was changed; in a zone this browser cannot compute, none is sent.
      ...dates,
      timezone: values.timezone,
      language: values.language,
      candidate_order: values.order,
      results_display: values.results,
    };

    await run(
      async () => {
        const saved = id
          ? await updateElection(id, body)
          : await createElection({
              ...body,
              starts_at: body.starts_at ?? '',
              ends_at: body.ends_at ?? '',
            });

        setCreatedId(saved.id);

        if (cover.picked) {
          try {
            await uploadElectionCover(saved.id, cover.picked.file);
          } catch (caught) {
            setCoverError(coverProblem(caught));

            throw new CoverFailure(0, 'cover');
          }
        } else if (storedCover === null && election?.cover) {
          await removeElectionCover(saved.id);
        }

        router.push(`/admin/elections/${saved.id}`);
      },
      (error) => error instanceof CoverFailure,
    );
  }

  const zones = timeZoneChoices(values.timezone);
  const shownCover = cover.picked?.url ?? storedCover?.md ?? null;
  const backTo = id ? `/admin/elections/${id}` : '/admin/elections';

  return (
    <form
      ref={form}
      method="post"
      noValidate
      onSubmit={submit}
      data-testid="election-form"
      className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start lg:gap-x-5"
    >
      <p className="text-base text-ink-soft lg:col-start-1 lg:row-start-1 lg:self-center">
        {t('elections.form.hint')}
      </p>

      <div
        data-testid="election-form-zone"
        className="flex min-w-0 flex-col gap-4.5 rounded-2xl border border-line bg-surface p-5 shadow-1 lg:col-start-1 lg:row-start-2"
      >
        {formError ? (
          <Notice tone="danger" role="alert" tabIndex={-1} data-testid="election-form-error">
            {formError}
          </Notice>
        ) : null}
        {createdId && coverError ? <Notice tone="warm">{t('elections.form.saved')}</Notice> : null}

        <Panel
          plain
          tone="info"
          icon="draft"
          title={t('elections.form.sections.info')}
          id="election-info-title"
          headerTestId="election-section-header-info"
        >
          <Input
            label={t('elections.form.title')}
            name="title"
            type="text"
            required
            autoComplete="off"
            value={values.title}
            onChange={change('title')}
            data-testid="election-title"
            error={fields.title}
            errorTestId="election-title-error"
          />

          <Textarea
            label={t('elections.form.description')}
            name="description"
            rows={3}
            value={values.description}
            onChange={change('description')}
            help={t('elections.form.descriptionHelp', {
              count: Array.from(values.description).length,
              max: DESCRIPTION_MAX,
            })}
            data-testid="election-description"
            error={fields.description}
            errorTestId="election-description-error"
          />
        </Panel>

        <Panel
          plain
          tone="calendar"
          icon="calendar"
          title={t('elections.form.sections.calendar')}
          id="election-calendar-section-title"
          headerTestId="election-section-header-calendar"
        >
          <DateRangePicker
            starts={values.starts}
            ends={values.ends}
            zone={values.timezone}
            onChange={(next) => setValues((current) => ({ ...current, ...next }))}
            errors={{ starts: fields.starts_at, ends: fields.ends_at }}
          />
        </Panel>

        <Panel
          plain
          tone="settings"
          icon="ballot"
          title={t('elections.form.sections.settings')}
          id="election-settings-section-title"
          headerTestId="election-section-header-settings"
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('elections.form.timezone')}
              name="timezone"
              value={values.timezone}
              onChange={change('timezone')}
              data-testid="election-timezone"
              error={
                fields.timezone ??
                (isSupportedZone(values.timezone) ? undefined : t('elections.form.zoneUnsupported'))
              }
              errorTestId="election-timezone-error"
            >
              {zones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </Select>
            <Select
              label={t('elections.form.language')}
              name="language"
              value={values.language}
              onChange={change('language')}
              data-testid="election-language"
              error={fields.language}
              errorTestId="election-language-error"
            >
              <option value="fr">{t('elections.form.languages.fr')}</option>
              <option value="en">{t('elections.form.languages.en')}</option>
            </Select>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Choices
              legend={t('elections.form.orderTitle')}
              name="candidate_order"
              value={values.order}
              onChange={(order) => setValues((current) => ({ ...current, order }))}
              options={[
                {
                  value: 'manual',
                  label: t('elections.form.order.manual'),
                  testId: 'election-order-manual',
                },
                {
                  value: 'shuffled',
                  label: t('elections.form.order.shuffled'),
                  testId: 'election-order-shuffled',
                },
              ]}
              error={fields.candidate_order}
            />
            <Choices
              legend={t('elections.form.resultsTitle')}
              name="results_display"
              value={values.results}
              onChange={(results) => setValues((current) => ({ ...current, results }))}
              options={[
                {
                  value: 'full',
                  label: t('elections.form.results.full'),
                  testId: 'election-results-full',
                },
                {
                  value: 'winners',
                  label: t('elections.form.results.winners'),
                  testId: 'election-results-winners',
                },
              ]}
              error={fields.results_display}
            />
          </div>
        </Panel>
      </div>

      <div className="flex min-w-0 flex-col gap-4 lg:sticky lg:top-20 lg:col-start-2 lg:row-start-2 lg:self-start">
        <SchedulePanel starts={values.starts} ends={values.ends} zone={values.timezone} />
        <CoverPreview url={shownCover}>
          <CoverField
            stored={storedCover}
            picked={cover.picked}
            onPick={(file) => {
              setCoverError(null);
              cover.pick(file);
            }}
            onRemove={() => {
              setCoverError(null);

              if (cover.picked) cover.forget();
              else setStoredCover(null);
            }}
            error={coverError}
            errorRef={coverErrorRef}
            onTooLarge={setCoverError}
          />
        </CoverPreview>
        <NextSteps title={t('elections.next.title')} />
      </div>

      <div className="flex flex-col gap-2 lg:col-start-2 lg:row-start-1 lg:items-center sm:flex-row sm:justify-end">
        <Link
          href={backTo}
          data-testid="election-cancel"
          className={`${linkSecondary} w-full sm:w-auto`}
        >
          {t('elections.form.cancel')}
        </Link>
        <Button
          type="submit"
          shimmer
          loading={busy}
          data-testid="election-save"
          className="w-full sm:w-auto"
        >
          {t(election ? 'elections.form.saveEdit' : 'elections.form.save')}
        </Button>
      </div>
    </form>
  );
}

type ChoicesProps<V extends string> = {
  legend: string;
  name: string;
  value: V;
  onChange: (value: V) => void;
  options: { value: V; label: string; testId: string }[];
  error?: string;
};

/** Two or three real radio buttons in one group, each in a card that is at least 44 px high. */
function Choices<V extends string>({
  legend,
  name,
  value,
  onChange,
  options,
  error,
}: ChoicesProps<V>) {
  return (
    <fieldset className="flex min-w-0 flex-col gap-2">
      <legend className="mb-1 text-base font-semibold text-ink">{legend}</legend>
      {options.map((option) => (
        <label
          key={option.value}
          className={`ui-control flex min-h-11 cursor-pointer items-center gap-3 rounded-md border px-3 text-base text-ink ${
            value === option.value
              ? 'border-primary bg-primary-soft'
              : 'border-line-strong bg-surface hover:bg-surface-alt'
          } focus-within:ring-4 focus-within:ring-primary-soft`}
        >
          <input
            type="radio"
            name={name}
            value={option.value}
            checked={value === option.value}
            onChange={() => onChange(option.value)}
            data-testid={option.testId}
            className="size-4 accent-primary"
          />
          <span>{option.label}</span>
        </label>
      ))}
      {error ? <p className="text-sm font-medium text-danger">{error}</p> : null}
    </fieldset>
  );
}
