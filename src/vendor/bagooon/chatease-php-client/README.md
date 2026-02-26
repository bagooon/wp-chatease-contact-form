<a href="https://chatease.jp/"><img width="500" height="106" alt="ChatEase logo" src="./.github/assets/chatease_logo.svg" /></a>

# bagooon/chatease-php-client

[![CI](https://github.com/bagooon/chatease-php-client/actions/workflows/php-ci.yml/badge.svg)](...)
[![Latest Version](https://img.shields.io/packagist/v/bagooon/chatease-php-client.svg)](...)
[![PHP Version](https://img.shields.io/packagist/php-v/bagooon/chatease-php-client.svg)](...)

PHP 用の **ChatEase チャットボード API クライアント** です。  
サーバーサイド（PHP）専用で、ブラウザからの直接利用は想定していません。

---

## Requirements

- PHP **8.1+**
- `ext-json`
- `ext-curl`
- ChatEase のワークスペース & API トークン

---

## Installation

```bash
composer require bagooon/chatease-php-client
```

---

## Quick Start

```php
<?php

use Bagooon\ChatEase\ChatEaseClient;

require __DIR__ . '/vendor/autoload.php';

$client = new ChatEaseClient(
    apiToken: getenv('CHATEASE_API_TOKEN'),
    workspaceSlug: 'your-workspace-slug'
);

// 1) チャットボードのみ生成
$res1 = $client->createBoard([
    'title' => 'お問い合わせ #1001',
    'guest' => [
        'name'  => '田中太郎',
        'email' => 'taro@example.com',
    ],
    'boardUniqueKey' => '20260225-1001',
]);

// 2) 初期ステータス付きで生成
$res2 = $client->createBoardWithStatus([
    'title' => '見積依頼 #1002',
    'guest' => [
        'name'  => '鈴木花子',
        'email' => 'hanako@example.com',
    ],
    'boardUniqueKey' => '20260225-1002',
    'initialStatus' => [
        'statusKey' => 'scheduled_for_response',
        'timeLimit' => '2026-02-28', // YYYY-MM-DD
    ],
]);

// 3) 初期ステータス + 初期投稿付きで生成
$res3 = $client->createBoardWithStatusAndMessage([
    'title' => 'デザイン相談 #1003',
    'guest' => [
        'name'  => 'John Smith',
        'email' => 'john@example.com',
    ],
    'boardUniqueKey' => '20260225-1003',
    'initialStatus' => [
        'statusKey' => 'scheduled_for_proof',
        'timeLimit' => '2026-03-05',
    ],
    'initialGuestComment' => [
        'content' => 'ロゴデザインについて相談したいです。',
    ],
]);

// 4) ワークスペース名を取得する
$name = $client->getWorkspaceName();

```

---

## API

`new ChatEaseClient(string $apiToken, string $workspaceSlug, ?string $baseUrl = null)`

- `$apiToken` – ChatEase の API トークン
- `$workspaceSlug` – ワークスペースの slug
- `$baseUrl` – ステージングなどを使う場合に差し替え。通常は省略可（https://chatease.jp）

---

### `createBoard(array $params): array`

```php
[
  'title' => string,
  'guest' => [
    'name'  => string,
    'email' => string,
  ],
  'boardUniqueKey' => string,
  'inReplyTo'      => string|null, // optional
]
```

戻り値：

```php
[
  'slug'     => string,
  'hostURL'  => string,
  'guestURL' => string,
]
```

---

### `createBoardWithStatus(array $params): array`

`createBoard` のパラメータに `initialStatus` を追加：

```php
'initialStatus' => [
  'statusKey' => string,       // 下記参照
  'timeLimit' => string|null,  // YYYY-MM-DD
],
```

`statusKey` は以下のいずれか：

- `scheduled_for_proof` - 校正予定
- `scheduled_for_response` - 返答予定
- `scheduled_for_completion` - 完了予定
- `waiting_for_reply` - 返答待ち

`scheduled_for_*` の場合は `timeLimit` が必須です（`YYYY-MM-DD` & 実在日付）。
`waiting_for_reply` の場合は `timeLimit` は不要です。

---

### `createBoardWithStatusAndMessage(array $params): array`

さらに `initialGuestComment` を追加：

```php
'initialGuestComment' => [
  'content' => string,
],
```

---

### `getWorkspaceName(): string`

ワークスペースの表示名を取得します。  
APIトークン＋ワークスペーススラッグが正しく設定されているかを確認する用途に利用できます。

戻り値： ワークスペース名

例外：

- `RuntimeException`
    - 401（APIトークンまたはWorkspace Slugが不正）
    - HTTP ステータス異常
    - JSON デコードエラー
    - レスポンス形式不正

---

## Validation

このクライアントは、API 呼び出し前に以下の実行時チェックを行います：

- `guest.email`
    - PHP の `filter_var($email, FILTER_VALIDATE_EMAIL)` による簡易チェック
- `boardUniqueKey`
    - 空文字禁止
    - 前後空白禁止
    - 空白文字（スペース・タブ・改行など）を含まない
    - 最大 255 文字
- `initialStatus`
    - `statusKey` が `scheduled_for_*` の場合は `timeLimit` 必須
    - `timeLimit` は `YYYY-MM-DD` 形式 & `checkdate` による実在日付チェック

バリデーションエラーの場合は `InvalidArgumentException` が投げられます。  
HTTP エラーや JSON デコードエラーなどは `RuntimeException` が投げられます。

---

## Development

```bash
composer install
composer test
```

---

## License

MIT