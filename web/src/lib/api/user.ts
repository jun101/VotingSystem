import type { operations } from './schema';

/** The current user and their institution: the `data` of `GET /auth/me`. */
export type CurrentUser =
  operations['auth.me']['responses'][200]['content']['application/json']['data'];

export type RegisterBody = {
  institution_name: string;
  name: string;
  email: string;
  password: string;
  language: 'fr' | 'en';
};

/** The profile of the institution: the `data` of `GET /institution`. */
export type InstitutionProfile =
  operations['institution.show']['responses'][200]['content']['application/json']['data'];

/** The fields `PATCH /institution` takes (all optional). */
export type ProfileChanges = NonNullable<
  operations['institution.update']['requestBody']
>['content']['application/json'];

/** One item of `GET /users`. */
export type TeamMember =
  operations['user.index']['responses'][200]['content']['application/json']['data'][number];

/** One item of `GET /invitations`, and the answer of `POST /invitations`. */
export type PendingInvitation =
  operations['invitation.index']['responses'][200]['content']['application/json']['data'][number];

export type InvitedRole = 'owner' | 'manager';

/** One page of a list, and how many items there are in all. */
export type Listing<T> = { items: T[]; total: number };

/** The state of the signed-in user's own two-factor authentication: the `data` of `GET /auth/two-factor`. */
export type TwoFactorState =
  operations['twoFactor.show']['responses'][200]['content']['application/json']['data'];

/** The secret and the `otpauth` link of a setup that is not confirmed yet. */
export type TwoFactorSetup =
  operations['twoFactor.setup']['responses'][200]['content']['application/json']['data'];

/** What `POST /auth/login` answers when the password is right but a code is still needed. */
export type TwoFactorRequired = { two_factor_required: boolean };
