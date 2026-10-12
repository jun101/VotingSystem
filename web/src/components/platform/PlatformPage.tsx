'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { LanguageSwitch } from '@/components/admin/LanguageSwitch';
import { Icon } from '@/components/admin/Icon';
import { Button, ConfirmDialog, Input, Notice, Select, Textarea } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { LogoMark } from '@/components/ui/LogoMark';
import {
  fetchPlatformInstitutions,
  impersonateInstitution,
  logout,
  reactivateInstitution,
  suspendInstitution,
} from '@/lib/api/browser';
import { ApiError, errorText, fieldText, waitText } from '@/lib/api/errors';
import type {
  PlatformFilters,
  PlatformInstitution,
  PlatformPage as Page,
} from '@/lib/api/platform';
import { NO_PLATFORM_FILTERS } from '@/lib/api/platform';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { loadPage } from '@/lib/navigation';
import { InstitutionCard } from './InstitutionCard';
import { hasMore, isFiltering } from './platformModel';

type Dialog = { kind: 'suspend' | 'reactivate'; institution: PlatformInstitution };

const MIN_REASON = 3;
const MAX_REASON = 500;

/**
 * Screen A16: the institutions of the platform as cards, with a live search by name, a status
 * filter, "show more", and the two actions: suspend (a reason is required) and reactivate. It
 * shows counts only: nothing here reaches a person, a code or a vote.
 */
export function PlatformPage({ initial }: { initial: Page }) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const [filters, setFilters] = useState<PlatformFilters>(NO_PLATFORM_FILTERS);
  const [data, setData] = useState<Page>(initial);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [working, setWorking] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [entering, setEntering] = useState<string | null>(null);
  const [enterProblem, setEnterProblem] = useState<string | null>(null);
  const [signingOut, setSigningOut] = useState(false);
  const [signOutProblem, setSignOutProblem] = useState<string | null>(null);
  const ticket = useRef(0);
  const alive = useRef(true);
  const timer = useRef<number | null>(null);
  // The list request under way, so the sign-in as an owner can wait for it (see `enter`).
  const inflight = useRef<Promise<void> | null>(null);
  const filtersRef = useRef(filters);
  const reasonField = useRef<HTMLTextAreaElement>(null);

  function load(next: PlatformFilters, page: number): Promise<void> {
    const request = read(next, page);

    inflight.current = request;
    void request.finally(() => {
      if (inflight.current === request) inflight.current = null;
    });

    return request;
  }

  async function read(next: PlatformFilters, page: number) {
    const mine = ++ticket.current;

    setBusy(true);
    setProblem(null);

    try {
      const result = await fetchPlatformInstitutions(next, page);

      if (!alive.current || mine !== ticket.current) return;

      setData((before) =>
        page === 1 ? result : { ...result, items: [...before.items, ...result.items] },
      );
    } catch (caught) {
      if (!alive.current || mine !== ticket.current) return;

      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setProblem(`${t('platform.loadFailed')} ${waitText(error, tIfAny)}`);
    }

    if (alive.current && mine === ticket.current) setBusy(false);
  }

  useEffect(() => {
    alive.current = true;

    // A search typed or a status chosen before the page was interactive (a slow phone) is in the
    // field, not in the state: read it once now, so it is not lost.
    const read = (id: string) =>
      document.querySelector<HTMLInputElement | HTMLSelectElement>(`[data-testid="${id}"]`)?.value;
    const early = window.setTimeout(() => {
      const status = read('platform-filter-status');
      const next: PlatformFilters = {
        q: read('platform-search') ?? '',
        status: status === 'active' || status === 'suspended' ? status : 'all',
      };

      if (!isFiltering(next)) return;

      filtersRef.current = next;
      setFilters(next);
      void load(next, 1);
    }, 0);

    return () => {
      window.clearTimeout(early);
      if (timer.current !== null) window.clearTimeout(timer.current);
      alive.current = false;
    };
    // Once, at mount: `load` only reads refs and the setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function choose(change: Partial<PlatformFilters>, wait: number) {
    const next = { ...filtersRef.current, ...change };

    filtersRef.current = next;
    setFilters(next);

    if (timer.current !== null) window.clearTimeout(timer.current);

    timer.current = window.setTimeout(() => void load(next, 1), wait);
  }

  function open(kind: Dialog['kind'], institution: PlatformInstitution) {
    setReason('');
    setReasonError(null);
    setDialogError(null);
    setDialog({ kind, institution });
  }

  function close() {
    setDialog(null);
  }

  function replace(changed: PlatformInstitution) {
    setData((before) => ({
      ...before,
      items: before.items.map((item) => (item.id === changed.id ? changed : item)),
    }));
  }

  async function confirm() {
    if (!dialog) return;

    setDialogError(null);

    if (dialog.kind === 'suspend') {
      const value = reason.trim();
      const length = Array.from(value).length;

      if (length < MIN_REASON || length > MAX_REASON) {
        setReasonError(
          t(
            value === ''
              ? 'platform.suspendDialog.reasonRequired'
              : length < MIN_REASON
                ? 'platform.suspendDialog.reasonShort'
                : 'platform.suspendDialog.reasonLong',
          ),
        );
        reasonField.current?.focus();

        return;
      }
    }

    setReasonError(null);
    setWorking(true);

    try {
      const changed =
        dialog.kind === 'suspend'
          ? await suspendInstitution(dialog.institution.id, reason.trim())
          : await reactivateInstitution(dialog.institution.id);

      if (!alive.current) return;

      replace(changed);
      setDialog(null);
    } catch (caught) {
      if (!alive.current) return;

      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const codes = error.fields.reason;

      if (error.code === 'unauthenticated') {
        router.push('/login');

        return;
      }

      if (error.code === 'validation_failed' && codes?.[0]) {
        setReasonError(fieldText('platform.suspend.reason', codes[0], tIfAny));
        reasonField.current?.focus();
      } else {
        setDialogError(waitText(error, tIfAny));
      }
    }

    if (alive.current) setWorking(false);
  }

  async function enter(institution: PlatformInstitution) {
    if (entering) return;

    setEntering(institution.id);
    setEnterProblem(null);

    // The session id changes when the sign-in as the owner is answered: a list request still on
    // its way would answer later with the cookie of the old session and undo it. A search not yet
    // sent is dropped, one under way is waited for.
    if (timer.current !== null) window.clearTimeout(timer.current);

    ticket.current += 1;
    await inflight.current?.catch(() => undefined);

    try {
      await impersonateInstitution(institution.id);
    } catch (caught) {
      if (!alive.current) return;

      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (error.code === 'unauthenticated') {
        router.push('/login');

        return;
      }

      setEnterProblem(waitText(error, tIfAny));
      setEntering(null);
      // The search dropped above is run again.
      void load(filtersRef.current, 1);

      return;
    }

    // A full navigation: the server layout reads the new session, and nothing of the admin's own
    // screen stays in memory.
    loadPage('/admin');
  }

  async function signOut() {
    setSigningOut(true);
    setSignOutProblem(null);

    try {
      await logout();
    } catch (caught) {
      // Already signed out elsewhere: the way out is the same.
      if (!(caught instanceof ApiError && caught.code === 'unauthenticated')) {
        setSignOutProblem(
          errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny),
        );
        setSigningOut(false);

        return;
      }
    }

    router.push('/login');
    router.refresh();
  }

  const filtering = isFiltering(filters);
  const more = hasMore(data.items.length, data.total);

  return (
    <div className="flex min-h-screen flex-col bg-canvas">
      <header data-live="true" className="bg-hero relative overflow-hidden text-surface">
        <div aria-hidden="true" className="hero-light hero-light-a -top-24 -right-16 size-96" />
        <div aria-hidden="true" className="hero-light hero-light-b -bottom-32 left-1/4 size-80" />
        <div className="relative mx-auto flex w-full max-w-[120rem] flex-col gap-5 px-4 pt-4 pb-14 md:px-7 md:pt-5 md:pb-16">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="flex min-w-0 items-center gap-2.5 font-display text-lg font-extrabold">
              <LogoMark size={32} />
              <span className="truncate">{t('app.name')}</span>
            </p>
            <div className="flex flex-wrap items-center gap-2.5">
              <div className="w-44">
                <LanguageSwitch />
              </div>
              <button
                type="button"
                data-testid="platform-signout"
                disabled={signingOut}
                aria-busy={signingOut || undefined}
                onClick={() => void signOut()}
                className="ui-control inline-flex min-h-11 items-center gap-2 rounded-full border border-surface px-4 text-base font-medium text-surface hover:bg-deep/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light disabled:opacity-60"
              >
                <Icon name="left" size={18} />
                {t('platform.signOut')}
              </button>
            </div>
          </div>
          <div className="flex flex-col gap-2">
            <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
              <Icon name="school" size={18} />
              {t('platform.kicker')}
            </p>
            <h1 className="text-3xl text-surface md:text-4xl">{t('platform.title')}</h1>
            <p className="max-w-2xl text-md text-hero-ink-soft">{t('platform.lede')}</p>
          </div>
          {signOutProblem ? (
            <Notice tone="danger" role="alert" data-testid="platform-signout-error">
              {signOutProblem}
            </Notice>
          ) : null}
        </div>
      </header>

      <main
        data-testid="platform-page"
        className="relative z-10 mx-auto -mt-8 flex w-full max-w-[120rem] flex-1 flex-col gap-4 px-4 pb-10 md:px-7"
      >
        <section
          aria-label={t('platform.filters')}
          className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4 shadow-2 md:p-5"
        >
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <Input
              type="search"
              label={t('platform.search')}
              defaultValue=""
              autoComplete="off"
              spellCheck={false}
              maxLength={100}
              onChange={(event) => choose({ q: event.target.value }, 250)}
              data-testid="platform-search"
            />
            <Select
              label={t('platform.filter.label')}
              defaultValue="all"
              onChange={(event) => {
                const value = event.target.value;

                choose({ status: value === 'active' || value === 'suspended' ? value : 'all' }, 0);
              }}
              data-testid="platform-filter-status"
            >
              <option value="all">{t('platform.filter.all')}</option>
              <option value="active">{t('platform.filter.active')}</option>
              <option value="suspended">{t('platform.filter.suspended')}</option>
            </Select>
          </div>
          <p className="text-sm font-medium text-ink-soft" aria-live="polite">
            {t(`platform.count.${pluralForm(locale, data.total)}`, { count: data.total })}
          </p>
        </section>

        {enterProblem ? (
          <Notice tone="danger" role="alert" data-testid="platform-impersonate-error">
            {enterProblem}
          </Notice>
        ) : null}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="platform-load-error">
            {problem}
          </Notice>
        ) : null}

        {data.items.length > 0 ? (
          <ul
            aria-label={t('platform.list')}
            aria-busy={busy || undefined}
            className={cx(
              'grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 min-[2300px]:grid-cols-4',
              busy && 'opacity-70',
            )}
          >
            {data.items.map((institution, index) => (
              <InstitutionCard
                key={institution.id}
                institution={institution}
                n={index + 1}
                onSuspend={() => open('suspend', institution)}
                onReactivate={() => open('reactivate', institution)}
                onImpersonate={() => void enter(institution)}
                entering={entering === institution.id}
              />
            ))}
          </ul>
        ) : busy ? (
          <p role="status" className="text-ink-soft">
            {t('platform.loading')}
          </p>
        ) : problem ? null : (
          <div
            data-testid="platform-empty"
            className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-line-strong bg-surface p-8 text-center"
          >
            <span
              aria-hidden="true"
              className="flex size-14 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
            >
              <Icon name="school" size={28} />
            </span>
            <b className="font-display text-xl font-extrabold">
              {t(filtering ? 'platform.emptyFiltered.title' : 'platform.empty.title')}
            </b>
            <p className="max-w-md text-ink-soft">
              {t(filtering ? 'platform.emptyFiltered.text' : 'platform.empty.text')}
            </p>
          </div>
        )}

        {more ? (
          <div className="flex flex-col items-center gap-2">
            <p className="text-sm text-ink-soft">
              {t('platform.moreCount', { shown: data.items.length, total: data.total })}
            </p>
            <Button
              variant="secondary"
              data-testid="platform-more"
              disabled={busy}
              onClick={() => void load(filters, data.page + 1)}
              className="max-md:w-full"
            >
              {t('platform.more')}
            </Button>
          </div>
        ) : null}
      </main>

      {dialog?.kind === 'suspend' ? (
        <ConfirmDialog
          title={t('platform.suspendDialog.title', { name: dialog.institution.name })}
          confirmLabel={t('platform.suspendDialog.confirm')}
          cancelLabel={t('platform.cancel')}
          busy={working}
          error={dialogError}
          describedBy="platform-suspend-text"
          onConfirm={() => void confirm()}
          onCancel={close}
          testIds={{
            dialog: 'suspend-dialog',
            confirm: 'suspend-confirm',
            cancel: 'suspend-cancel',
            error: 'platform-error',
          }}
        >
          <div className="flex flex-col gap-3">
            <p id="platform-suspend-text">{t('platform.suspendDialog.text')}</p>
            <Textarea
              ref={reasonField}
              label={t('platform.suspendDialog.reasonLabel')}
              help={t('platform.suspendDialog.reasonHelp')}
              value={reason}
              required
              autoFocus
              onChange={(event) => {
                setReason(event.target.value);
                setReasonError(null);
              }}
              error={reasonError ?? undefined}
              errorTestId="suspend-reason-error"
              data-testid="suspend-reason"
            />
          </div>
        </ConfirmDialog>
      ) : null}

      {dialog?.kind === 'reactivate' ? (
        <ConfirmDialog
          title={t('platform.reactivateDialog.title', { name: dialog.institution.name })}
          confirmLabel={t('platform.reactivateDialog.confirm')}
          cancelLabel={t('platform.cancel')}
          confirmVariant="primary"
          busy={working}
          error={dialogError}
          onConfirm={() => void confirm()}
          onCancel={close}
          testIds={{
            dialog: 'reactivate-dialog',
            confirm: 'reactivate-confirm',
            cancel: 'reactivate-cancel',
            error: 'platform-error',
          }}
        >
          <p>{t('platform.reactivateDialog.text')}</p>
        </ConfirmDialog>
      ) : null}
    </div>
  );
}
