/**
 * The edition of this installation (`DEPLOYMENT_MODE`, shared with the API): `school` has no email anywhere, so the
 * sign-up and link pages do not exist and the admin screens create people with a one-time password
 * (docs/slices/19c-installers.md). Read on the server at each request, never baked into the browser bundle.
 */
export function isSchoolMode(): boolean {
  return process.env.DEPLOYMENT_MODE === 'school';
}
