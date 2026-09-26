import { expect, type Page } from '@playwright/test';

export class PlanBoardPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.getByRole('link', { name: /^操作計画/ }).click();
    await expect(this.page.getByRole('heading', { name: '操作計画', level: 2 })).toBeVisible();
  }

  async expectDraft(target: string, change?: string): Promise<void> {
    const row = this.page.getByRole('row').filter({ hasText: target });
    await expect(row).toBeVisible();
    if (change) await expect(row).toContainText(change);
  }

  async preview(): Promise<void> {
    await this.page.getByRole('button', { name: '変更内容を確認' }).click();
  }

  async remove(target: string): Promise<void> {
    await this.page.getByRole('button', { name: new RegExp(`${target}.*操作計画から削除`) }).click();
  }
}
