import { test as base, expect } from '@playwright/test';
import { monitorNetwork, type AllowedHttpError } from '../helpers/network';
import { resetE2EState } from '../helpers/wordpress';

type Options = { allowedHttpErrors: AllowedHttpError[] };

export const test = base.extend<Options>({
  allowedHttpErrors: [[], { option: true }],
  page: async ({ page, allowedHttpErrors }, use, testInfo) => {
    resetE2EState();
    const assertClean = monitorNetwork(page, testInfo, allowedHttpErrors);
    await use(page);
    await assertClean();
  },
});

export { expect };
