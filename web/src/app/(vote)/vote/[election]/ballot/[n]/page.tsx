import { BallotScreen } from '@/components/vote/BallotScreen';

export default async function BallotPage({ params }: { params: Promise<{ n: string }> }) {
  const { n } = await params;

  return <BallotScreen n={/^\d{1,3}$/.test(n) ? Number(n) : 0} />;
}
