/** The name of the text file the recovery codes are saved in. */
export const RECOVERY_FILE_NAME = 'codes-de-recuperation.txt';

/** The file holds the codes, one per line, and nothing else: no address, no institution. */
export function recoveryFileText(codes: readonly string[]): string {
  return `${codes.join('\n')}\n`;
}

/** Saves a text as a file, from the browser; nothing is sent anywhere. */
export function saveTextFile(name: string, text: string): void {
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
  const link = document.createElement('a');

  link.href = url;
  link.download = name;
  link.hidden = true;
  document.body.append(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}
