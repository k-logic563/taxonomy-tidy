import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const authFile = path.resolve('playwright/.auth/admin.json');

setup('管理者認証を保存する', async ({ page }) => {
  fs.mkdirSync(path.dirname(authFile), { recursive: true });
  await page.goto('/wp-login.php');
  const user = process.env.E2E_ADMIN_USER ?? 'e2e-admin';
  const password = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-local-password';
  await page.locator('#user_pass').fill(password);
  await page.locator('#user_login').fill(user);
  if (await page.locator('#user_login').inputValue() !== user || await page.locator('#user_pass').inputValue() !== password) {
    throw new Error('The WordPress login fields could not be populated.');
  }
  await page.getByRole('button', { name: 'ログイン' }).click();
  await expect(page.getByRole('heading', { name: 'ダッシュボード', level: 1 })).toBeVisible();
  await expect(page).toHaveURL(/\/wp-admin\//);
  await page.context().storageState({ path: authFile });
});
