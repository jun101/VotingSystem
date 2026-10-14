'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Button, Input } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { parseVoteLink, voteHref } from '@/lib/vote/parseVoteLink';

/**
 * The voter's door: a field for the voting link or the election address. Nothing typed here is sent
 * anywhere: the election's UUID is read out of it in the browser and the page navigates to its voting page.
 */
export function VoterDoor() {
  const { t } = useI18n();
  const router = useRouter();
  const [value, setValue] = useState('');
  const [invalid, setInvalid] = useState(false);

  function onSubmit(event: FormEvent) {
    event.preventDefault();

    const link = parseVoteLink(value);

    if (!link) {
      setInvalid(true);
      return;
    }

    setInvalid(false);
    router.push(voteHref(link));
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3 sm:flex-row sm:items-start">
      <div className="min-w-0 flex-1">
        <Input
          data-testid="home-link-input"
          type="text"
          inputMode="url"
          autoComplete="off"
          autoCapitalize="none"
          spellCheck={false}
          label={t('home.voter.label')}
          placeholder={t('home.voter.placeholder')}
          value={value}
          onChange={(event) => {
            setValue(event.target.value);
            setInvalid(false);
          }}
          error={invalid ? t('home.voter.error') : undefined}
          errorTestId="home-link-error"
        />
      </div>
      <Button
        type="submit"
        size="voter"
        data-testid="home-vote"
        shimmer
        className="w-full sm:w-auto"
      >
        {t('home.voter.button')}
      </Button>
    </form>
  );
}
