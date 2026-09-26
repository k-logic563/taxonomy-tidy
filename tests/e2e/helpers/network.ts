import type { Page, TestInfo } from '@playwright/test';

export type AllowedHttpError = number | { status: number; url: RegExp };

export function monitorNetwork(page: Page, testInfo: TestInfo, allowed: AllowedHttpError[]): () => Promise<void> {
  const failures: string[] = [];
  const isAllowed = (status: number, url: string) => allowed.some((entry) =>
    typeof entry === 'number' ? entry === status : entry.status === status && entry.url.test(url));

  page.on('pageerror', (error) => failures.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') failures.push(`console.error: ${message.text()}`);
  });
  page.on('requestfailed', (request) => failures.push(
    `requestfailed: ${request.method()} ${request.url()} (${request.failure()?.errorText ?? 'unknown'})`));
  page.on('response', (response) => {
    const status = response.status();
    if (status >= 400 && !isAllowed(status, response.url())) {
      failures.push(`HTTP ${status}: ${response.request().method()} ${response.url()}`);
    }
  });

  return async () => {
    if (failures.length > 0) {
      await testInfo.attach('browser-errors', {
        body: Buffer.from(failures.join('\n')),
        contentType: 'text/plain',
      });
      throw new Error(`Unexpected browser errors:\n${failures.join('\n')}`);
    }
  };
}
