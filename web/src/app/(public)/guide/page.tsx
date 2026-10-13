import { redirect } from 'next/navigation';
import { guideRoleFor } from '@/components/guide/guide-data';
import { fetchCurrentUser } from '@/lib/api/server';

export const dynamic = 'force-dynamic';

/** `/guide` goes to the reader's own manual. */
export default async function GuideIndex() {
  const user = await fetchCurrentUser();

  redirect(`/guide/${guideRoleFor(user)}`);
}
