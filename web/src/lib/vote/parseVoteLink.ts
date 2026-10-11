const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** A bare election address, then a full link: `.../vote/<uuid>` with an optional path, query and `#CODE`. */
const BARE = new RegExp(`^(${UUID})(?:#([^\\s#]*))?$`, 'i');
const LINK = new RegExp(
  `^(?:(?:[a-z][a-z0-9+.-]*://)?[a-z0-9.-]+(?::\\d{1,5})?)?(?:/[^\\s?#]*?)?/vote/(${UUID})(?:/[^\\s?#]*)?(?:\\?[^\\s#]*)?(?:#([^\\s#]*))?$`,
  'i',
);
/** What a fragment may hold to be carried on: an access code as written in a link (letters, digits, hyphen, percent escapes). */
const FRAGMENT = /^[A-Za-z0-9%_.-]{1,64}$/;

export type VoteLink = {
  /** The election's UUID, lower case. */
  election: string;
  /** The `#CODE` fragment without the `#`, or null. Never sent to a server by the home page. */
  fragment: string | null;
};

/**
 * Reads what a voter pasted: a voting link (with or without `#CODE`) or a bare election UUID.
 * Only a strict UUID is ever taken out of it; null for anything else.
 */
export function parseVoteLink(input: string): VoteLink | null {
  const text = input.trim();

  if (text === '' || text.length > 2048) return null;

  const match = BARE.exec(text) ?? LINK.exec(text);

  if (!match) return null;

  const [, election = '', fragment] = match;

  return {
    election: election.toLowerCase(),
    fragment: fragment !== undefined && FRAGMENT.test(fragment) ? fragment : null,
  };
}

/** The in-app address to go to: the path of the election, keeping the fragment. */
export function voteHref(link: VoteLink): string {
  return `/vote/${link.election}${link.fragment ? `#${link.fragment}` : ''}`;
}
