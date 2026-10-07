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
