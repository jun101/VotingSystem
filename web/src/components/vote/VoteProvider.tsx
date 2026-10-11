'use client';

import { useRouter } from 'next/navigation';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { ApiError } from '@/lib/api/errors';
import { forgetVoteLocale } from '@/lib/i18n/locale';
import {
  endSession,
  fetchBallots,
  fetchInfo,
  VoteError,
  type VoteInfo,
  type VotePhase,
  type VoterBallots,
} from '@/lib/api/vote';

/** What the code screen says on arrival. */
export type VoteNotice = 'expired' | 'ended' | 'alreadyVoted' | null;

type VoteState = {
  election: string;
  info: VoteInfo;
  /** The voter's name, group and ballots, once read. Memory only. */
  ballots: VoterBallots | null;
  /** Ballot uuid → the candidates chosen (empty: a blank vote). A ballot not in here has no choice yet. */
  choices: Record<string, string[]>;
  votedAt: string | null;
  notice: VoteNotice;
  setPhase: (phase: VotePhase) => void;
  reloadInfo: () => Promise<void>;
  setBallots: (ballots: VoterBallots | null) => void;
  /** Sets the choice of a ballot; `null` takes it back (no choice yet). */
  choose: (ballot: string, candidates: string[] | null) => void;
  setVotedAt: (time: string | null) => void;
  /** Forgets the voter: ballots, choices and time. */
  forget: () => void;
  /** Forgets the ballots and the choices only (the vote is recorded; the time stays to be shown). */
  clearVoter: () => void;
  /** Back to the code screen, optionally with a message. Forgets the voter. */
  leave: (notice?: VoteNotice) => void;
  /** Ends the session on the server (best effort), then `leave`. */
  signOut: (notice?: VoteNotice) => Promise<void>;
};

const Context = createContext<VoteState | null>(null);

/**
 * What the voting pages share while the voter moves between them: the election, the ballots read
 * once and the choices made so far. Everything here is React state: nothing is written to a
 * storage, an address or a cookie, and a reload of the page forgets it (the session cookie, which
 * only the browser and the API see, is what remains).
 */
export function VoteProvider({
  election,
  initial,
  children,
}: {
  election: string;
  initial: VoteInfo;
  children: ReactNode;
}) {
  const router = useRouter();
  const [info, setInfo] = useState(initial);
  const [ballots, setBallots] = useState<VoterBallots | null>(null);
  const [choices, setChoices] = useState<Record<string, string[]>>({});
  const [votedAt, setVotedAt] = useState<string | null>(null);
  const [notice, setNotice] = useState<VoteNotice>(null);

  const forget = useCallback(() => {
    setBallots(null);
    setChoices({});
    setVotedAt(null);
  }, []);

  const clearVoter = useCallback(() => {
    setBallots(null);
    setChoices({});
  }, []);

  const leave = useCallback(
    (next: VoteNotice = null) => {
      forget();
      setNotice(next);
      // The language chosen with the switch ends with the voting session; the server renders again in
      // the election's language (the layout is kept by client navigation, so it is asked for).
      const hadChoice = forgetVoteLocale();

      router.replace(`/vote/${election}`);

      if (hadChoice) router.refresh();
    },
    [election, forget, router],
  );

  const signOut = useCallback(
    async (next: VoteNotice = null) => {
      try {
        await endSession(election);
      } catch {
        // The cookie expires by itself: the voter goes on.
      }

      leave(next);
    },
    [election, leave],
  );

  const setPhase = useCallback(
    (phase: VotePhase) => setInfo((current) => ({ ...current, phase })),
    [],
  );

  const choose = useCallback(
    (ballot: string, candidates: string[] | null) =>
      setChoices((current) => {
        const next = { ...current };

        if (candidates === null) delete next[ballot];
        else next[ballot] = candidates;

        return next;
      }),
    [],
  );

  // Stable: a countdown depends on it, and the answer it stores changes `info`.
  const reloadInfo = useCallback(async () => {
    try {
      setInfo(await fetchInfo(election));
    } catch {
      // Keep what is shown; the next tick asks again.
    }
  }, [election]);

  const value = useMemo<VoteState>(
    () => ({
      election,
      info,
      ballots,
      choices,
      votedAt,
      notice,
      setPhase,
      reloadInfo,
      setBallots,
      choose,
      setVotedAt,
      forget,
      clearVoter,
      leave,
      signOut,
    }),
    [
      election,
      info,
      ballots,
      choices,
      votedAt,
      notice,
      setPhase,
      reloadInfo,
      choose,
      forget,
      clearVoter,
      leave,
      signOut,
    ],
  );

  return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useVote(): VoteState {
  const value = useContext(Context);

  if (!value) throw new Error('useVote needs a VoteProvider');

  return value;
}

/** Eligible ballots of the session: read once, from the server, when a page needs them. */
export function useVoterBallots(): {
  ballots: VoterBallots | null;
  failure: ApiError | null;
  retry: () => void;
} {
  const vote = useVote();
  const { ballots, election, setBallots, setPhase, leave } = vote;
  const [failure, setFailure] = useState<ApiError | null>(null);
  const [attempt, setAttempt] = useState(0);
  const asked = useRef(-1);

  useEffect(() => {
    if (ballots || asked.current === attempt) return;
    asked.current = attempt;

    let live = true;

    fetchBallots(election).then(
      (read) => {
        if (live) setBallots(read);
      },
      (error: unknown) => {
        if (!live) return;

        const problem = error instanceof ApiError ? error : new ApiError(0, 'network');

        if (problem.code === 'voter_session_expired') return leave('expired');
        if (problem.code === 'already_voted') return leave('alreadyVoted');

        if (problem.code === 'election_not_open') {
          const phase = error instanceof VoteError ? error.phase : null;

          if (phase) setPhase(phase);

          return leave(null);
        }

        setFailure(problem);
      },
    );

    return () => {
      live = false;
      // A strict-mode re-run asks again: the first answer is dropped.
      asked.current = -1;
    };
  }, [ballots, election, attempt, setBallots, setPhase, leave]);

  return {
    ballots,
    failure,
    retry: () => {
      setFailure(null);
      setAttempt((n) => n + 1);
    },
  };
}
