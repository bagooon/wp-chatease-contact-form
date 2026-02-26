<a href="https://chatease.jp/"><img width="500" height="106" alt="ChatEase logo" src="./.github/assets/chatease_logo.svg" /></a>

# ChatEase Contact Form for WordPress

ChatEase のチャットボードと連携できる、WordPress 用お問い合わせフォームプラグインです。

- フロント：
  - 会社名 / お名前 / メールアドレス / お問い合わせ内容 のシンプルなフォーム
  - 入力 → 確認 → 完了 の 3 ステップ
  - reCAPTCHA v2（チェックボックス）対応
- バックエンド：
  - ChatEase API を使ってチャットボードを自動生成
  - ステータスは `scheduled_for_response`（返答予定）にセット
  - 「返答期限（日数）」をフォームごとに設定可能（デフォルト 1 日後）
  - 管理者へメール通知
    - フォームごとにラベル・通知先メールアドレスを設定可能

> ⚠ このプラグインは ChatEase 専用です。  
> ChatEase のワークスペース / API トークンが必要です。

---

## 必要条件

- PHP 8.1 以上
- WordPress 6.x 目安
- ChatEase ワークスペース（API トークン・ワークスペーススラッグ）
- reCAPTCHA v2（チェックボックス）の Site Key / Secret Key

---

## インストール方法

1. GitHub の Releases から `chatease-contact-form.zip` をダウンロード  
   または `dist/chatease-contact-form.zip` を取得
2. WordPress 管理画面 → プラグイン → 新規追加 → プラグインのアップロード
3. `chatease-contact-form.zip` をアップロードして有効化

---

## 初期設定

### 1. ChatEase API 設定

管理画面 → **ChatEase → 共通設定** で次の項目を設定します。

- ChatEase API トークン
- ワークスペーススラッグ
- 返答期限（日数）
- 通知先メールアドレス
- reCAPTCHA v2 Site Key
- reCAPTCHA v2 Secret Key

> フォームごとに上書き設定が可能なので、ここは「全体の初期値」として使われます。

---

### 2. ChatEase フォームの作成

1. 管理画面左メニュー → **ChatEase → フォーム一覧** → 新規フォームを追加
2. フォームごとに以下を設定できます：
   - フォーム名（投稿タイトル）
   - ラベル類
     - 会社名ラベル
     - お名前ラベル
     - メールアドレスラベル
     - お問い合わせ内容ラベル
   - フォーム固有の返答期限（日数）
   - フォーム固有の通知先メールアドレス
3. 共通設定と異なるワークスペースに連携する場合は、そのワークスペースの API トークン と ワークスペーススラッグ を入力します、

---

## フォームの設置方法

`ChatEase` → `フォーム一覧` に表示されたショートコードを、固定ページや投稿本文に埋め込みます：

```text
[chatease_contact_form id="123"]
```

これで：

- 入力画面
- 確認画面
- 完了画面

が表示されます。

---

## ChatEase との連携仕様

フォーム送信完了時に、ChatEase API を使ってチャットボードを自動生成します。

- 生成先：設定した Workspace Slug
- ゲスト情報：
    - 名前：フォームで入力された「お名前」
    - メールアドレス：フォームで入力されたメールアドレス
- ステータス：
    - `statusKey: scheduled_for_response`（返答予定）
    - timeLimit: YYYY-MM-DD（フォーム固有の「返答期限（日数）」分だけ未来の日付）
- 初回投稿内容：
    - お問い合わせ内容

※ 実際の API 呼び出しには、別途公開している PHP 用 ChatEase SDK を内部で利用しています。

---

## reCAPTCHA について

このプラグインは reCAPTCHA v2（チェックボックス） に対応しています。

- Site Key / Secret Key を設定すると、フォームに reCAPTCHA ウィジェットが表示されます
- 送信時に g-recaptcha-response を検証し、失敗した場合は送信を拒否します

スパムが多いサイトでは、reCAPTCHA の設定を強く推奨します。

---

## 今後の拡張予定

- 任意項目の追加と、ChatEase 側へのメモ情報としての連携
- メールテンプレートのカスタマイズ（件名・本文のプレースホルダ対応）

---

## ライセンス

MIT

---

## 開発

ローカルで ZIP を作成する場合：

```bash
npm install
npm run build:zip
```

`dist/chatease-contact-form.zip` が作成されます。

GitHub Actions での ZIP 自動生成は `.github/workflows/build-zip.yml` を参照してください。

