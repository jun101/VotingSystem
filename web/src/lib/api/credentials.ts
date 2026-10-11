import type { components, operations } from './schema';

type StateAnswer =
  operations['credentials.credentialState']['responses'][200]['content']['application/json'];
type RunAnswer =
  operations['credentials.credentialRun']['responses'][202]['content']['application/json'];
type PreviewAnswer =
  operations['credentials.credentialPreview']['responses'][200]['content']['application/json'];
type ReissueAnswer =
  operations['credentials.voterCredential']['responses'][201]['content']['application/json'];

/** The access codes of an election: totals and one line per group (A11). */
export type CredentialState = StateAnswer['data'];

/** One line of the state: a group (or `null` for the voters with no group). */
export type CredentialLine = CredentialState['groups'][number];

/** One generated file: `queued`, `ready` or `failed`. */
export type GeneratedFile = RunAnswer['data']['files'][number];

/** What a run answers: the files queued, the voters who will get a code, the ones skipped. */
export type CredentialRun = RunAnswer['data'];

/**
 * The body of `POST /elections/{election}/credential-runs`: the scope, and the channels of slice 11
 * (`email` with the count the person confirmed, and the self-distribution `file`).
 */
export type RunBody = components['schemas']['StartCredentialRunRequest'];

/** The body of `POST /elections/{election}/credential-previews`: the scope of a run. */
export type PreviewBody = components['schemas']['PreviewCredentialRunRequest'];

/** What a run would do for a scope: the numbers the person confirms before emails are sent. */
export type CredentialPreview = PreviewAnswer['data'];

/** A voter left for print: no address. */
export type PreviewVoter = CredentialPreview['voters_without_email'][number];

/** What a reissue answers: the code, in clear, once, and the one-slip file. */
export type Reissue = ReissueAnswer['data'];

/** The key of a line in the test ids and the lists: the group's UUID, or `none`. */
export function lineKey(line: CredentialLine): string {
  return line.group?.id ?? 'none';
}
