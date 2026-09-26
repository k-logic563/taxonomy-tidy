import { expect, type Page } from '@playwright/test';

export class TaxonomyListPage {
  constructor(private readonly page: Page) {}

  async open(): Promise<void> {
    await this.page.goto('/wp-admin/tools.php?page=taxonomy-tidy');
    await expect(this.page.getByRole('heading', { name: 'Taxonomy Tidy', level: 1 })).toBeVisible();
  }

  async openCategories(): Promise<void> {
    await this.page.locator('.nav-tab-wrapper').getByRole('link', { name: /^カテゴリー/ }).click();
  }

  async openTags(): Promise<void> {
    await this.page.locator('.nav-tab-wrapper').getByRole('link', { name: /^タグ/ }).click();
  }

  async toggleSearch(): Promise<void> {
    await this.page.getByText('検索パネル', { exact: true }).click();
  }

  async search(name: string): Promise<void> {
    await this.page.getByRole('searchbox', { name: 'キーワード' }).fill(name);
    await this.page.getByRole('button', { name: '条件を適用' }).click();
  }

  async resetSearch(): Promise<void> {
    await this.page.getByRole('link', { name: '条件をリセット' }).click();
  }

  async selectTerm(name: string): Promise<void> {
    await this.page.getByRole('checkbox', { name: `${name}を選択`, exact: true }).check();
  }

  async toggleActions(): Promise<void> {
    await this.page.getByText('処理パネル', { exact: true }).click();
  }

  async chooseAction(name: '名称変更' | '統合' | '削除'): Promise<void> {
    await this.page.getByRole('radio', { name }).check();
  }

  async enterName(name: string): Promise<void> {
    await this.page.getByLabel('新しい名前').fill(name);
  }

  async chooseDestination(name: string): Promise<void> {
    await this.page.getByLabel('統合先').selectOption({ label: name });
  }

  async addToPlan(): Promise<void> {
    await this.page.getByRole('button', { name: '計画に追加' }).click();
  }

  row(name: string) {
    return this.page.getByRole('row').filter({ hasText: name });
  }
}
