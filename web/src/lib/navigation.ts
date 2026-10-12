/**
 * A full page load (not a client-side transition): used when the session changed under the page
 * (acting as an institution's owner, and leaving it), so the server layout reads it again and
 * nothing of the previous screen stays in memory.
 */
export function loadPage(path: string): void {
  window.location.assign(path);
}
