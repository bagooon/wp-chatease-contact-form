<?php

declare(strict_types=1);

namespace Bagooon\ChatEase;

use InvalidArgumentException;
use RuntimeException;

class ChatEaseClient
{
    private string $apiToken;
    private string $workspaceSlug;
    private string $baseUrl;

    /** ChatEaseStatusKey: scheduled_for_* 系 */
    private const SCHEDULED_STATUS_KEYS = [
        'scheduled_for_proof',
        'scheduled_for_response',
        'scheduled_for_completion',
    ];

    /** ChatEaseStatusKey: 非 scheduled 系 */
    private const NON_SCHEDULED_STATUS_KEYS = [
        'waiting_for_reply',
    ];

    public function __construct(
        string $apiToken,
        string $workspaceSlug,
        ?string $baseUrl = null
    ) {
        if ($apiToken === '') {
            throw new InvalidArgumentException('apiToken is required');
        }
        if ($workspaceSlug === '') {
            throw new InvalidArgumentException('workspaceSlug is required');
        }

        $this->apiToken      = $apiToken;
        $this->workspaceSlug = $workspaceSlug;
        $this->baseUrl       = rtrim($baseUrl ?? 'https://chatease.jp', '/');
    }

    /**
     * ワークスペース名を取得する
     *
     * POST /api/v1/board/name
     * Header:  X-Chatease-API-Token: {apiToken}
     * Body:    { "workspaceSlug": "xxx" }
     *
     * 正常: { "name": "ワークスペース名" }
     * 401:  Unauthorized
     *
     * @throws \RuntimeException
     */
    public function getWorkspaceName(): string
    {
        $url     = $this->baseUrl . '/api/v1/board/name';
        $payload = json_encode(
            ['workspaceSlug' => $this->workspaceSlug],
            JSON_THROW_ON_ERROR
        );

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Chatease-API-Token: ' . $this->apiToken,
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $err = curl_error($ch);
            throw new \RuntimeException('cURL error: ' . $err);
        }

        // PHPStan 対策：ここで string に絞る
        if (!is_string($body)) {
            throw new \RuntimeException('Unexpected cURL response type.');
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($status === 401) {
            throw new \RuntimeException('Unauthorized (401): APIトークンまたはWorkspace Slugが不正です。');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Unexpected status code: ' . $status);
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            throw new \RuntimeException('Invalid response format: "name" が取得できません。');
        }

        return $data['name'];
    }


    /**
     * ① チャットボードのみ生成
     *
     * @param array{
     *   title: string,
     *   guest: array{name: string, email: string},
     *   boardUniqueKey: string,
     *   inReplyTo?: string
     * } $params
     *
     * @return array{slug: string, hostURL: string, guestURL: string}
     */
    public function createBoard(array $params): array
    {
        return $this->createBoardInternal($params);
    }

    /**
     * ② チャットボード + 初期ステータス
     *
     * @param array{
     *   title: string,
     *   guest: array{name: string, email: string},
     *   boardUniqueKey: string,
     *   inReplyTo?: string,
     *   initialStatus: array{
     *     statusKey: string,
     *     timeLimit?: string
     *   }
     * } $params
     *
     * @return array{slug: string, hostURL: string, guestURL: string}
     */
    public function createBoardWithStatus(array $params): array
    {
        return $this->createBoardInternal($params);
    }

    /**
     * ③ チャットボード + 初期ステータス + 初期投稿
     *
     * @param array{
     *   title: string,
     *   guest: array{name: string, email: string},
     *   boardUniqueKey: string,
     *   inReplyTo?: string,
     *   initialStatus: array{
     *     statusKey: string,
     *     timeLimit?: string
     *   },
     *   initialGuestComment: array{
     *     content: string
     *   }
     * } $params
     *
     * @return array{slug: string, hostURL: string, guestURL: string}
     */
    public function createBoardWithStatusAndMessage(array $params): array
    {
        return $this->createBoardInternal($params);
    }

    /**
     * 実処理本体。3メソッドからここに集約。
     *
     * @param array<string, mixed> $params
     * @return array{slug: string, hostURL: string, guestURL: string}
     */
    private function createBoardInternal(array $params): array
    {
        $this->validateParams($params);

        $body = $params;
        $body['workspaceSlug'] = $this->workspaceSlug;

        $url = $this->baseUrl . '/api/v1/board';

        $responseBody = $this->postJson($url, $body);

        if (!is_array($responseBody)) {
            throw new RuntimeException('Invalid JSON response from ChatEase API');
        }

        foreach (['slug', 'hostURL', 'guestURL'] as $key) {
            if (!isset($responseBody[$key]) || !is_string($responseBody[$key])) {
                throw new RuntimeException("ChatEase API response missing field: {$key}");
            }
        }

        /** @var array{slug: string, hostURL: string, guestURL: string} $responseBody */
        return $responseBody;
    }

    /**
     * 入力パラメータのバリデーション
     *
     * @param array<string, mixed> $params
     */
    private function validateParams(array $params): void
    {
        // guest.email
        if (!isset($params['guest']['email']) || !is_string($params['guest']['email'])) {
            throw new InvalidArgumentException('guest.email is required and must be string');
        }
        if (!$this->isValidEmail($params['guest']['email'])) {
            throw new InvalidArgumentException('guest.email is invalid: ' . $params['guest']['email']);
        }

        // boardUniqueKey
        if (!isset($params['boardUniqueKey']) || !is_string($params['boardUniqueKey'])) {
            throw new InvalidArgumentException('boardUniqueKey is required and must be string');
        }
        if (!$this->isValidBoardUniqueKey($params['boardUniqueKey'])) {
            throw new InvalidArgumentException(
                'boardUniqueKey is invalid. It must be a non-empty string without whitespace and <= 255 chars.'
            );
        }

        // initialStatus があれば検証
        if (isset($params['initialStatus']) && is_array($params['initialStatus'])) {
            /** @var array<string, mixed> $initialStatus */
            $initialStatus = $params['initialStatus'];
            $this->validateInitialStatus($initialStatus);
        }
    }

    /**
     * 初期ステータスの検証（scheduled_for_* なら timeLimit 必須 & 日付チェック）
     *
     * @param array<string, mixed> $status
     */
    private function validateInitialStatus(array $status): void
    {
        // statusKey 必須 & 非空文字列
        if (!array_key_exists('statusKey', $status) || !is_string($status['statusKey']) || $status['statusKey'] === '') {
            throw new InvalidArgumentException('initialStatus.statusKey is required and must be non-empty string');
        }

        $statusKey = $status['statusKey'];

        // scheduled_for_* の場合
        if (in_array($statusKey, self::SCHEDULED_STATUS_KEYS, true)) {
            if (!array_key_exists('timeLimit', $status) || !is_string($status['timeLimit']) || $status['timeLimit'] === '') {
                throw new InvalidArgumentException(
                    'initialStatus.timeLimit is required when statusKey is scheduled_for_*'
                );
            }

            if (!$this->isValidIsoDate($status['timeLimit'])) {
                throw new InvalidArgumentException(
                    'initialStatus.timeLimit must be a valid date in YYYY-MM-DD format. Got: ' . $status['timeLimit']
                );
            }

            return;
        }

        // waiting_for_reply など scheduled 以外
        if (in_array($statusKey, self::NON_SCHEDULED_STATUS_KEYS, true)) {
            // timeLimit はあってもなくても特にチェックしない（API側で無視される想定）
            return;
        }

        // どのグループにも属さない statusKey
        throw new InvalidArgumentException('Unknown initialStatus.statusKey: ' . $statusKey);
    }

    /**
     * YYYY-MM-DD & 実在日付チェック
     */
    private function isValidIsoDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        // checkdate はローカルタイムゾーンには依存せず日付としての妥当性だけ見る
        return checkdate($month, $day, $year);
    }

    /**
     * メールの簡易チェック
     */
    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * boardUniqueKey の妥当性チェック
     */
    private function isValidBoardUniqueKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (trim($key) !== $key) {
            return false;
        }
        if (strlen($key) > 255) {
            return false;
        }
        // 空白文字禁止
        if (preg_match('/\s/', $key) === 1) {
            return false;
        }

        return true;
    }

    /**
     * cURL を使った JSON POST
     *
     * @param string $url
     * @param array<string,mixed> $body
     * @return mixed decoded JSON
     */
    protected function postJson(string $url, array $body): mixed
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        $headers = [
            'Content-Type: application/json',
            'X-Chatease-API-Token: ' . $this->apiToken,
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $json,
        ]);

        $responseBody = curl_exec($ch);
        $errno        = curl_errno($ch);
        $error        = curl_error($ch);
        $statusCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('cURL error: ' . $error, $errno);
        }

        if (!is_string($responseBody)) {
            throw new RuntimeException('Empty response from ChatEase API');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            // エラー内容をそのままメッセージに載せる
            throw new RuntimeException(
                sprintf(
                    'ChatEase API error: %d - %s',
                    $statusCode,
                    $responseBody
                )
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
