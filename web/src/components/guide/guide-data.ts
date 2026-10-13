/**
 * The user manuals' structure: which manuals exist, which sections each has (in reading order), and the screenshot that
 * illustrates a section. The words (titles, numbered steps, one legend line per marker) live in
 * `lib/i18n/messages/guide/{fr,en}.json`, read by the guide page only, so the main catalogues do not carry them.
 *
 * Screenshots are in `public/guide/`, taken by `e2e/guide/capture.spec.ts` at 1280 px wide on demonstration data, in
 * French. `shots.json` is written by that spec: for each picture its size and the centre of every numbered marker, in
 * percent of the picture (`x` from the left, `y` from the top).
 */
import shots from './shots.json';

export type GuideRole = 'voter' | 'manager' | 'owner' | 'platform' | 'install';

/** Every manual, in tab order. Who may open which: `allowedGuides()`. */
export const GUIDE_ROLES: readonly GuideRole[] = [
  'voter',
  'manager',
  'owner',
  'platform',
  'install',
];

export type GuideShot = {
  src: string;
  width: number;
  height: number;
  markers: [x: number, y: number][];
};

export type GuideSection = {
  id: string;
  /** A key of `shots.json`: the section has a picture. */
  shot?: string;
};

/** The picture of a section, or null when the capture has not been run for it. */
export function shotOf(name: string | undefined): GuideShot | null {
  if (!name) return null;

  const found = (shots as unknown as Record<string, Omit<GuideShot, 'src'>>)[name];

  return found ? { ...found, src: `/guide/${name}.jpg` } : null;
}

export const GUIDE_SECTIONS: Record<GuideRole, GuideSection[]> = {
  voter: [
    { id: 'before', shot: 'voter-before' },
    { id: 'code', shot: 'voter-code' },
    { id: 'identity', shot: 'voter-identity' },
    { id: 'ballot', shot: 'voter-ballot' },
    { id: 'blank', shot: 'voter-ballot-blank' },
    { id: 'several', shot: 'voter-ballot-several' },
    { id: 'review', shot: 'voter-review' },
    { id: 'done', shot: 'voter-done' },
    { id: 'phone', shot: 'voter-phone-ballot' },
    { id: 'problems', shot: 'voter-code-error' },
    { id: 'closed', shot: 'voter-closed' },
  ],
  manager: [
    { id: 'signin', shot: 'admin-login' },
    { id: 'dashboard', shot: 'admin-dashboard' },
    { id: 'create', shot: 'admin-election-new' },
    { id: 'election', shot: 'admin-election' },
    { id: 'ballots', shot: 'admin-ballots' },
    { id: 'candidates', shot: 'admin-candidates' },
    { id: 'voters', shot: 'admin-voters' },
    { id: 'import', shot: 'admin-import' },
    { id: 'codes', shot: 'admin-codes' },
    { id: 'schedule', shot: 'admin-schedule' },
    { id: 'turnout', shot: 'admin-turnout' },
    { id: 'close', shot: 'admin-close' },
    { id: 'results', shot: 'admin-results' },
    { id: 'publication', shot: 'admin-publication' },
    { id: 'published', shot: 'admin-published' },
    { id: 'public', shot: 'public-results' },
    { id: 'account', shot: 'admin-account' },
    { id: 'limits', shot: 'manager-institution' },
  ],
  owner: [
    { id: 'register', shot: 'owner-register' },
    { id: 'verify', shot: 'owner-verify' },
    { id: 'institution', shot: 'owner-institution' },
    { id: 'team', shot: 'owner-team' },
    { id: 'invitation', shot: 'owner-invitation' },
    { id: 'audit', shot: 'owner-audit' },
    { id: 'twofactor', shot: 'owner-twofactor' },
    { id: 'public', shot: 'owner-public' },
  ],
  platform: [
    { id: 'signin', shot: 'platform-login' },
    { id: 'list', shot: 'platform-list' },
    { id: 'suspend', shot: 'platform-suspend' },
    { id: 'impersonate' },
    { id: 'twofactor' },
    { id: 'limits' },
  ],
  install: [
    { id: 'choose' },
    { id: 'linux' },
    { id: 'windows' },
    { id: 'docker' },
    { id: 'first' },
    { id: 'daily' },
    { id: 'update' },
    { id: 'backup' },
    { id: 'problems' },
    { id: 'uninstall' },
  ],
};

type Reader = { role: 'owner' | 'manager' | 'platform_admin' } | null;

/**
 * Which manuals a reader may open. The voter's manual is public (a voter has no account). A manager reads the manager's;
 * an owner the owner's too; a platform admin the platform and installation manuals. The installation manual is also for
 * owners, who run the server of a school. A UX gate only: the API enforces every tool the manuals describe.
 */
export function allowedGuides(user: Reader): GuideRole[] {
  switch (user?.role) {
    case 'manager':
      return ['voter', 'manager'];
    case 'owner':
      return ['voter', 'manager', 'owner', 'install'];
    case 'platform_admin':
      return ['voter', 'platform', 'install'];
    default:
      return ['voter'];
  }
}

/** The manual that matches an account: where the "user guide" link of the menu goes. */
export function guideRoleFor(user: Reader): GuideRole {
  switch (user?.role) {
    case 'manager':
      return 'manager';
    case 'owner':
      return 'owner';
    case 'platform_admin':
      return 'platform';
    default:
      return 'voter';
  }
}
