# Term Steward 管理画面デザインシステム

## 1. 適用範囲

この文書はTerm Stewardの管理画面だけに適用する。CSSのルートは`.term-steward`であり、通常画面の`.term-steward-screen`と、`body`直下へ移動するプレビューモーダルの双方に付与する。ルート外のWordPress管理画面、他プラグイン、公開側へスタイルを適用しない。

見た目専用クラスは`tt-*`、既存の構造・JavaScriptフックは`term-steward-*`を使う。JavaScriptは原則として`term-steward-*`、ID、`data-*`を参照し、`tt-*`を挙動の条件にしない。WordPress標準の`.button`、`.nav-tab`、`.notice`、`.wp-list-table`は意味と基本挙動を再利用し、寸法や余白だけを`.term-steward`内で補正する。

## 2. デザイントークン

トークンは`assets/css/admin.css`の`.term-steward`で定義する。

| 分類 | トークン | 値・用途 |
|---|---|---|
| 文字 | `--tt-color-text` | `#1d2327`、本文 |
| 補助文字 | `--tt-color-muted` | `#646970`、説明・件数 |
| 境界線 | `--tt-color-border` / `--tt-color-border-subtle` | `#c3c4c7` / `#dcdcde` |
| 背景 | `--tt-color-surface` / `--tt-color-surface-subtle` | `#fff` / `#f6f7f7` |
| 主要操作 | `--tt-color-primary` / `--tt-color-primary-hover` | WordPress管理色。未定義時は`#2271b1` / `#135e96` |
| 状態 | `--tt-color-danger` / `--tt-color-success` / `--tt-color-warning` / `--tt-color-error` | 危険、成功、警告、エラー |
| フォーカス | `--tt-color-focus` | WordPress管理色。未定義時は`#2271b1` |
| 文字 | `--tt-font-size-body` / `--tt-font-size-small` / `--tt-font-size-section` / `--tt-font-size-page` | `14px` / `13px` / `14px` / `23px` |
| 行間 | `--tt-line-height-body` | `1.5` |
| 余白 | `--tt-space-1` / `2` / `3` / `4` / `6` / `8` | `4` / `8` / `12` / `16` / `24` / `32px` |
| 角丸 | `--tt-radius-sm` / `--tt-radius-md` | `3px` / `6px` |
| 高さ | `--tt-control-height` | デスクトップ`36px`、管理画面モバイル幅では主要操作を最低`40px` |
| 影 | `--tt-shadow-modal` | モーダルだけに使用する控えめな影 |

色だけで状態を伝えず、必ず文言、`aria-*`、disabled属性などを併用する。

## 3. 余白

- 4px: アイコンと文字、ページ番号間などの最小間隔。
- 8px: ラベルと入力、入力と補足、関連する小要素。
- 12px: 同一グループ、ボタングループ、メッセージ内。
- 16px: 段落、フィールド、通常のコンテンツ間。
- 24px: 小セクション、パネル内側、モーダル左右。
- 32px: 独立した主要セクション間。必要な場合だけ使う。

見出し直後は12px、段落下は16px、フィールド間は16～24px、エラーは対象の8px下、テーブルと上下ページネーションは12pxを基準とする。検索・処理パネルの内側はデスクトップ24px、狭幅16px。モーダルは左右24px、狭幅16pxとする。

## 4. タイポグラフィ

WordPress管理画面のフォントを継承する。本文は14px・行間1.5、補足・エラーは13px、セクション見出しは14px・600、ページタイトルは23px・400とする。本文段落の下余白は16px。長い説明やログは親幅を上限とし、`overflow-wrap: anywhere`で折り返す。個別画面の都合だけで新しい文字サイズを追加しない。

## 5. ボタン

- Primary: `.tt-button.tt-button--primary`とWordPressの`.button-primary`。計画追加、変更内容確認、実行、再開、Undo実行だけに使う。
- Secondary: `.tt-button.tt-button--secondary`。条件リセット、キャンセル、Undoプレビューなど補助操作に使う。
- Destructive: `.term-steward-plan-delete`と`.term-steward-discard`。赤い文字と中立境界線を使い、hover時だけ淡い危険背景を加える。
- Pagination: `.tt-button--pagination.term-steward-page-link`。正方形に近い最小幅、現在・無効状態を明示する。
- Link button: `.tt-link-button`。履歴の「詳しく見る」のように軽い展開操作へ使う。

ボタンは原則36px高、左右12px、14px・500、角丸3px。`:focus-visible`は2pxのアウトラインを残す。disabledは属性に加えて低彩度の背景、境界線、`not-allowed`、不透明度で示す。非同期処理中はdisabledに加え`.is-loading`と`aria-busy=true`を付ける。閉じるアイコンは36px四方で、読み上げ名を必須とする。

## 6. フォーム

テキスト入力とselectは`.tt-control`、既存処理パネルでは`.term-steward-field-control`も共通寸法を受ける。高さ36px、14px、角丸3pxとし、フォーカス時はWordPress管理色の境界線と1pxのリングを表示する。textareaを追加する場合も同じクラスを使用し、高さだけ内容に応じて設定する。

ラベルは入力の8px上、補足は8px下、フィールド間は16～24px。radio・checkboxはラベルとflexで揃え、コントロール自体を縮めない。必須表示を追加する場合は文言でも必須と分かるようにする。エラーは対象直後の`.term-steward-field-error`へ日本語で表示し、`aria-invalid`と`aria-describedby`で関連付ける。セクション全体へエラー用outlineを付けない。readonly表示は`.term-steward-field-display`、disabledはネイティブ属性を使う。

## 7. パネル、セクション、メッセージ

検索パネルと処理パネルは共通の`.term-steward-panel`、summary、heading、iconを使い、初期状態は閉じる。開閉状態は`details`と同期した`aria-expanded`で伝える。パネルの境界線、背景、summary高、内側余白を共通化する。

操作計画・操作履歴はページ見出しから24px、主要な小見出しから12px空ける。プレビュー項目は境界線で分割し、カードを増やしすぎない。メッセージはWordPress noticeまたは`.term-steward-message`を使い、成功・警告・エラーの文言とroleを保つ。空状態は短い説明と次の行動だけにし、余分な枠は追加しない。

## 8. テーブルとページネーション

一覧、計画、履歴はWordPressの`.wp-list-table`を基礎にする。ヘッダー、striped行、sortの標準表現を残し、長い内容は折り返す。選択状態はネイティブcheckbox、hoverはWordPress標準に従う。狭幅のterm一覧は既存のラベル付き縦配置を維持する。

term一覧の上下ページネーションは同じ`render_pagination()`と`.term-steward-table-nav`を使う。表示件数、件数範囲、先頭・前・数値・次・最終を同じ高さに揃える。現在ページは`aria-current`、境界は`aria-disabled`と低彩度表示を併用する。0件ではページリンクを表示せず、表内の日本語空状態を表示する。履歴ページ送りはWordPressの`paginate_links()`を保ち、同じ36px高へ揃える。

## 9. モーダル

モーダル自身にも`.term-steward`を付け、`body`直下へ移動してもトークンとスコープを維持する。構成は固定ヘッダー、スクロール可能な本文、固定フッター。外側余白24px、ヘッダー・フッター16px×24px、本文16px×24px、角丸6pxとする。

ヘッダーにはタイトルと36px四方の閉じる操作、フッターにはSecondaryのキャンセルとPrimaryの実行だけを置く。ボタン間隔は12px。実行中は閉じる、キャンセル、実行をdisabledにし、進捗を`aria-live`で通知する。`:focus-visible`、フォーカストラップ、終了時の起点復帰、Esc・背景クリックの共通終了処理を維持する。375px以下では外側8px、操作を縦積みにし、本文とフッターへ到達可能にする。

## 10. WordPress標準を残す箇所

タブ、一覧テーブル、notice、基本ボタンの意味と管理色、フォームのブラウザ・WordPress互換挙動は標準を残す。独自UIへ置き換えるより管理画面内の予測可能性が高く、WordPressの配色変更にも追従できるためである。Term Steward側はルート内で寸法、間隔、状態の不足だけを補う。

## 11. 実装チェック

新しい表示要素を追加するときは、既存トークンで説明できるか、同じ役割のコンポーネントがないか、JavaScriptフックと見た目のクラスが分離されているか、ルート外へ漏れないか、focus・disabled・狭幅・長文を確認する。場当たり的な色、余白、文字サイズは追加しない。
