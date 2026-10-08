'use client';

import { useRouter } from 'next/navigation';
import { useState, type ChangeEvent, type FormEvent } from 'react';
import { Button, Card, Input, Notice, Select, Textarea } from '@/components/ui';
import { useAuthForm } from '@/components/auth/useAuthForm';
import { updateInstitution } from '@/lib/api/browser';
import type { InstitutionProfile, ProfileChanges } from '@/lib/api/user';
import { timeZoneChoices } from '@/lib/format/timeZones';
import { useI18n } from '@/lib/i18n/client';
import { LogoField } from './LogoField';

const FIELDS = [
  'name',
  'type',
  'description',
  'address',
  'city',
  'phone',
  'contact_email',
  'timezone',
  'language',
] as const;

const TYPES = ['school', 'university', 'association', 'other'] as const;
const DESCRIPTION_MAX = 500;

type Values = {
  name: string;
  type: InstitutionProfile['type'];
  description: string;
  address: string;
  city: string;
  phone: string;
  contact_email: string;
  timezone: string;
  language: InstitutionProfile['language'];
};

function valuesOf(profile: InstitutionProfile): Values {
  return {
    name: profile.name,
    type: profile.type,
    description: profile.description ?? '',
    address: profile.address ?? '',
    city: profile.city ?? '',
    phone: profile.phone ?? '',
    contact_email: profile.contact_email ?? '',
    timezone: profile.timezone,
    language: profile.language,
  };
}

/**
 * The profile of the institution (screen A14, left): the logo and the fields, with one Save
 * button. An owner edits; a manager sees the same fields, read-only, and no button. A field
 * error is shown under its field and the first one gets the focus; any other error is shown in
 * one alert at the top of the form.
 */
export function ProfileCard({
  profile,
  canEdit,
  onProfile,
}: {
  profile: InstitutionProfile;
  canEdit: boolean;
  /** Called with the profile the API returns, after a save or a change of logo. */
  onProfile: (profile: InstitutionProfile) => void;
}) {
  const { t } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run, idle } = useAuthForm(FIELDS, 'profile');
  const [values, setValues] = useState<Values>(() => valuesOf(profile));
  const [saved, setSaved] = useState(false);

  const change =
    (name: keyof Values) =>
    (event: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
      setSaved(false);
      setValues((current) => ({ ...current, [name]: event.target.value }));
    };

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);

    const done = await run(async () => {
      const updated = await updateInstitution(values satisfies ProfileChanges);

      onProfile(updated);
      setValues(valuesOf(updated));
    });

    if (done) {
      idle();
      setSaved(true);
      // The menu names the institution: the layout is read again.
      router.refresh();
    }
  }

  return (
    <Card title={t('institution.profile.title')} data-testid="profile-card">
      <form
        ref={form}
        method="post"
        noValidate
        onSubmit={submit}
        data-testid="profile-form"
        className="flex flex-col gap-5"
      >
        <LogoField
          name={profile.name}
          logo={profile.logo}
          canEdit={canEdit}
          onChange={(logo) => {
            onProfile({ ...profile, logo });
            router.refresh();
          }}
        />

        {!canEdit ? <Notice tone="info">{t('institution.profile.readOnly')}</Notice> : null}

        {formError ? (
          <Notice tone="danger" role="alert" tabIndex={-1} data-testid="profile-form-error">
            {formError}
          </Notice>
        ) : null}

        <Input
          label={t('institution.profile.name')}
          name="name"
          type="text"
          autoComplete="organization"
          required
          disabled={!canEdit}
          value={values.name}
          onChange={change('name')}
          data-testid="profile-name"
          error={fields.name}
          errorTestId="profile-name-error"
        />

        <div className="grid gap-5 sm:grid-cols-2">
          <Select
            label={t('institution.profile.type')}
            name="type"
            disabled={!canEdit}
            value={values.type}
            onChange={change('type')}
            data-testid="profile-type"
            error={fields.type}
            errorTestId="profile-type-error"
          >
            {TYPES.map((type) => (
              <option key={type} value={type}>
                {t(`institution.profile.types.${type}`)}
              </option>
            ))}
          </Select>
          <Input
            label={t('institution.profile.city')}
            name="city"
            type="text"
            autoComplete="address-level2"
            disabled={!canEdit}
            value={values.city}
            onChange={change('city')}
            data-testid="profile-city"
            error={fields.city}
            errorTestId="profile-city-error"
          />
          <Input
            label={t('institution.profile.phone')}
            name="phone"
            type="tel"
            autoComplete="tel"
            disabled={!canEdit}
            value={values.phone}
            onChange={change('phone')}
            data-testid="profile-phone"
            error={fields.phone}
            errorTestId="profile-phone-error"
          />
          <Input
            label={t('institution.profile.contactEmail')}
            name="contact_email"
            type="email"
            autoComplete="email"
            autoCapitalize="none"
            spellCheck={false}
            disabled={!canEdit}
            value={values.contact_email}
            onChange={change('contact_email')}
            data-testid="profile-contact-email"
            error={fields.contact_email}
            errorTestId="profile-contact-email-error"
          />
          <Select
            label={t('institution.profile.timezone')}
            name="timezone"
            disabled={!canEdit}
            value={values.timezone}
            onChange={change('timezone')}
            data-testid="profile-timezone"
            error={fields.timezone}
            errorTestId="profile-timezone-error"
          >
            {timeZoneChoices(profile.timezone).map((zone) => (
              <option key={zone} value={zone}>
                {zone}
              </option>
            ))}
          </Select>
          <Select
            label={t('institution.profile.language')}
            name="language"
            disabled={!canEdit}
            value={values.language}
            onChange={change('language')}
            data-testid="profile-language"
            error={fields.language}
            errorTestId="profile-language-error"
          >
            <option value="fr">{t('institution.profile.languages.fr')}</option>
            <option value="en">{t('institution.profile.languages.en')}</option>
          </Select>
        </div>

        <Textarea
          label={t('institution.profile.description')}
          name="description"
          rows={4}
          disabled={!canEdit}
          value={values.description}
          onChange={change('description')}
          help={t('institution.profile.descriptionCount', {
            count: Array.from(values.description).length,
            max: DESCRIPTION_MAX,
          })}
          data-testid="profile-description"
          error={fields.description}
          errorTestId="profile-description-error"
        />

        <Input
          label={t('institution.profile.address')}
          name="address"
          type="text"
          autoComplete="street-address"
          disabled={!canEdit}
          value={values.address}
          onChange={change('address')}
          data-testid="profile-address"
          error={fields.address}
          errorTestId="profile-address-error"
        />

        {canEdit ? (
          <div className="flex flex-wrap items-center gap-4">
            <Button type="submit" variant="accent" loading={busy} data-testid="profile-save">
              {t('institution.profile.save')}
            </Button>
            <p role="status" className="text-base font-medium text-teal-ink">
              {saved ? (
                <span data-testid="profile-saved">{t('institution.profile.saved')}</span>
              ) : null}
            </p>
          </div>
        ) : null}
      </form>
    </Card>
  );
}
