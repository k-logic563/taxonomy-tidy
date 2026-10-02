import { expect, type Page } from '@playwright/test';

export class HistoryPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.getByRole('link', { name: /^操作履歴/ }).click();
    await expect(this.page.getByRole('heading', { name: '操作履歴', level: 2 })).toBeVisible();
  }

  async expectLatest(action: string, status = '完了'): Promise<void> {
    const first = this.page.locator('.term-steward-history-table tbody tr').first();
    await expect(first).toContainText(action);
    await expect(first).toContainText(status);
  }

  async openLatest(): Promise<void> {
    await this.page.locator('.term-steward-history-table tbody tr').first().getByRole('link', { name: '詳細' }).click();
    await expect(this.page.getByRole('heading', { name: '操作の詳細' })).toBeVisible();
  }

  async undo(): Promise<void> {
    await this.page.getByRole('button', { name: '変更を元に戻す' }).click();
    const dialog = this.page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('取り消し内容のプレビュー');
    await dialog.getByRole('button', { name: '元に戻す' }).click();
    await expect(dialog).toContainText('取り消し結果', { timeout: 60_000 });
  }
}
