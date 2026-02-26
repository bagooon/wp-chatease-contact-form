<?php
/*
Plugin Name: ChatEase Contact Form
Description: ChatEase 連携用の確認画面付き問い合わせフォーム（reCAPTCHA v2 対応・セッション方式）
Version: 0.1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
  exit;
}

// autoload を先に読み込む
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

use Bagooon\ChatEase\ChatEaseClient;

/**
 * フロント側でセッションを開始
 */
add_action('init', function () {
  $labels = [
    'name'               => 'ChatEase フォーム',
    'singular_name'      => 'ChatEase フォーム',
    'add_new'            => '新規フォームを追加',
    'add_new_item'       => '新規フォームを追加',
    'edit_item'          => 'フォームを編集',
    'new_item'           => '新規フォーム',
    'view_item'          => 'フォームを表示',
    'search_items'       => 'フォームを検索',
    'not_found'          => 'フォームが見つかりません',
    'not_found_in_trash' => 'ゴミ箱にフォームはありません',
    'all_items'          => 'フォーム一覧',
    'menu_name'          => 'ChatEase フォーム',
  ];

  $args = [
    'labels'             => $labels,
    'public'             => false,
    'show_ui'            => true,
    'show_in_menu'       => true,
    'supports'           => ['title'],
    'has_archive'        => false,
    'show_in_nav_menus'  => false,
    'show_in_rest'       => false,
    'menu_position'      => 25,
  ];

  register_post_type('chatease_form', $args);

  if (!is_admin() && php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
      session_start();
    }
  }
});

/**
 * reCAPTCHA スクリプト読み込み
 */
add_action('wp_enqueue_scripts', function () {
  $site_key = get_option('chatease_recaptcha_site_key', '');
  if ($site_key !== '') {
    wp_enqueue_script(
      'chatease-recaptcha',
      'https://www.google.com/recaptcha/api.js',
      [],
      null,
      true
    );
  }
});

/**
 * 管理画面：設定項目の登録
 */
add_action('admin_init', function () {
  register_setting('chatease_contact_form', 'chatease_api_token');
  register_setting('chatease_contact_form', 'chatease_workspace_slug');
  register_setting('chatease_contact_form', 'chatease_recaptcha_site_key');
  register_setting('chatease_contact_form', 'chatease_recaptcha_secret_key');
  register_setting('chatease_contact_form', 'chatease_notify_email');
  register_setting('chatease_contact_form', 'chatease_response_deadline_days');
});

/**
 * 管理画面：メニュー追加
 */
add_action('admin_menu', function () {
  add_options_page(
    'ChatEase Contact Form 設定',
    'ChatEase Contact Form',
    'manage_options',
    'chatease_contact_form',
    'chatease_render_settings_page'
  );
});

/**
 * 設定画面の描画
 */
function chatease_render_settings_page()
{
  if (!current_user_can('manage_options')) {
    return;
  }

?>
  <div class="wrap">
    <h1>ChatEase Contact Form 設定</h1>
    <form method="post" action="options.php">
      <?php settings_fields('chatease_contact_form'); ?>
      <?php do_settings_sections('chatease_contact_form'); ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="chatease_api_token">API トークン</label></th>
          <td>
            <input type="text" id="chatease_api_token" name="chatease_api_token"
              value="<?php echo esc_attr(get_option('chatease_api_token', '')); ?>"
              class="regular-text" />
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="chatease_workspace_slug">ワークスペーススラッグ</label></th>
          <td>
            <input type="text" id="chatease_workspace_slug" name="chatease_workspace_slug"
              value="<?php echo esc_attr(get_option('chatease_workspace_slug', '')); ?>"
              class="regular-text" />
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="chatease_response_deadline_days">返答期限（日数）</label></th>
          <td>
            <input type="number"
              min="1"
              id="chatease_response_deadline_days"
              name="chatease_response_deadline_days"
              value="<?php echo esc_attr(get_option('chatease_response_deadline_days', 1)); ?>"
              class="small-text" />
            <p class="description">
              チャットボードの「返答予定日」を何日後にセットするか設定します（デフォルト: 1日）。<br>
              ※フォーム送信者からは見えません。
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="chatease_notify_email">通知先メールアドレス</label></th>
          <td>
            <input type="email" id="chatease_notify_email" name="chatease_notify_email"
              value="<?php echo esc_attr(get_option('chatease_notify_email', get_option('admin_email'))); ?>"
              class="regular-text" />
            <p class="description">未設定の場合はサイト管理者メールアドレスが使用されます。</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="chatease_recaptcha_site_key">reCAPTCHA v2 Site Key</label></th>
          <td>
            <input type="text" id="chatease_recaptcha_site_key" name="chatease_recaptcha_site_key"
              value="<?php echo esc_attr(get_option('chatease_recaptcha_site_key', '')); ?>"
              class="regular-text" />
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="chatease_recaptcha_secret_key">reCAPTCHA v2 Secret Key</label></th>
          <td>
            <input type="text" id="chatease_recaptcha_secret_key" name="chatease_recaptcha_secret_key"
              value="<?php echo esc_attr(get_option('chatease_recaptcha_secret_key', '')); ?>"
              class="regular-text" />
          </td>
        </tr>
      </table>

      <?php submit_button(); ?>
    </form>
  </div>
<?php
}

/**
 * ショートコード [chatease_contact_form]
 */
add_shortcode('chatease_contact_form', 'chatease_render_contact_form');

/**
 * フォーム表示・確認・送信処理本体
 */
function chatease_render_contact_form($atts)
{
  $atts = shortcode_atts([
    'id' => '', // chatease_form の投稿ID
  ], $atts, 'chatease_contact_form');

  $form_post_id = $atts['id'] !== '' ? (int)$atts['id'] : 0;

  // ラベル設定を取得
  $labels = chatease_get_form_labels($form_post_id);

  $form_id = $form_post_id > 0 ? 'form_' . $form_post_id : 'default';
  $session_key = 'chatease_form_' . $form_id;


  // セッションから前回値を取得
  $stored = $_SESSION[$session_key] ?? [
    'company' => '',
    'name'    => '',
    'email'   => '',
    'message' => '',
  ];

  $step   = isset($_POST['chatease_step']) ? sanitize_text_field(wp_unslash($_POST['chatease_step'])) : 'input';
  $errors = [];

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ノンスチェック（簡易）
    if (!isset($_POST['_chatease_nonce']) || !wp_verify_nonce($_POST['_chatease_nonce'], 'chatease_contact_form_' . $form_id)) {
      $errors[] = '不正な送信です。もう一度お試しください。';
      $step = 'input';
    } else {
      if ($step === 'confirm') {
        // 入力値の取得・バリデーション
        $stored['company'] = isset($_POST['company']) ? chatease_sanitize_text($_POST['company']) : '';
        $stored['name']    = isset($_POST['name']) ? chatease_sanitize_text($_POST['name']) : '';
        $stored['email']   = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $stored['message'] = isset($_POST['message']) ? chatease_sanitize_textarea($_POST['message']) : '';

        // reCAPTCHA 検証
        $recaptcha_ok = chatease_verify_recaptcha();
        if (!$recaptcha_ok) {
          $errors[] = 'reCAPTCHA の認証に失敗しました。もう一度お試しください。';
        }

        // 必須チェック
        if ($stored['name'] === '') {
          $errors[] = 'お名前を入力してください。';
        }
        if ($stored['email'] === '' || !is_email($stored['email'])) {
          $errors[] = '正しいメールアドレスを入力してください。';
        }
        if ($stored['message'] === '') {
          $errors[] = 'お問い合わせ内容を入力してください。';
        }

        if (!empty($errors)) {
          $step = 'input';
        } else {
          // 問題なければセッションに保存して確認画面へ
          $_SESSION[$session_key] = $stored;
          $step = 'confirm';
        }
      } elseif ($step === 'submit') {
        // セッションに値がある前提で送信処理
        if (!isset($_SESSION[$session_key])) {
          $errors[] = 'セッションが切れました。もう一度入力してください。';
          $step = 'input';
        } else {
          $stored = $_SESSION[$session_key];

          // ここで ChatEase に連携（SDK 呼び出し）
          $chatease_error = chatease_send_to_chatease($stored, $form_post_id);

          if ($chatease_error !== '') {
            $errors[] = 'チャットボード連携中にエラーが発生しました: ' . esc_html($chatease_error);
            $step = 'input';
          } else {
            // 管理者にメール送信
            chatease_send_notify_email($stored, $form_post_id);

            // セッションをクリア
            unset($_SESSION[$session_key]);
            $step = 'complete';
          }
        }
      }
    }
  }

  ob_start();

  if (!empty($errors)) {
    echo '<div class="chatease-errors"><ul>';
    foreach ($errors as $error) {
      echo '<li>' . esc_html($error) . '</li>';
    }
    echo '</ul></div>';
  }

  if ($step === 'input') {
    chatease_render_input_form($form_id, $stored, $labels);
  } elseif ($step === 'confirm') {
    chatease_render_confirm_screen($form_id, $stored, $labels);
  } elseif ($step === 'complete') {
    chatease_render_complete_screen();
  }

  return ob_get_clean();
}

/**
 * 入力フォームの描画
 */
function chatease_render_input_form(string $form_id, array $values, array $labels): void
{
  $site_key = get_option('chatease_recaptcha_site_key', '');

?>
  <form method="post" class="chatease-contact-form">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="confirm" />

    <p>
      <label><?php echo esc_html($labels['company']); ?><br>
        <input type="text" name="company" value="<?php echo esc_attr($values['company']); ?>" />
      </label>
    </p>

    <p>
      <label><?php echo esc_html($labels['name']); ?><br>
        <input type="text" name="name" value="<?php echo esc_attr($values['name']); ?>" required />
      </label>
    </p>

    <p>
      <label><?php echo esc_html($labels['email']); ?><br>
        <input type="email" name="email" value="<?php echo esc_attr($values['email']); ?>" required />
      </label>
    </p>

    <p>
      <label><?php echo esc_html($labels['message']); ?><br>
        <textarea name="message" rows="5" required><?php echo esc_textarea($values['message']); ?></textarea>
      </label>
    </p>

    <?php if ($site_key !== ''): ?>
      <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
    <?php endif; ?>

    <p>
      <button type="submit">確認画面へ</button>
    </p>
  </form>
<?php
}

/**
 * 確認画面の描画
 */
function chatease_render_confirm_screen(string $form_id, array $values, array $labels): void
{
?>
  <form method="post" class="chatease-contact-form-confirm">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="submit" />

    <h2>入力内容の確認</h2>
    <table class="chatease-confirm-table">
      <tr>
        <th><?php echo esc_html($labels['company']); ?></th>
        <td><?php echo $values['company'] !== '' ? esc_html($values['company']) : '（未入力）'; ?></td>
      </tr>
      <tr>
        <th><?php echo esc_html($labels['name']); ?></th>
        <td><?php echo esc_html($values['name']); ?></td>
      </tr>
      <tr>
        <th><?php echo esc_html($labels['email']); ?></th>
        <td><?php echo esc_html($values['email']); ?></td>
      </tr>
      <tr>
        <th><?php echo esc_html($labels['message']); ?></th>
        <td><?php echo nl2br(esc_html($values['message'])); ?></td>
      </tr>
    </table>

    <p>
      <button type="submit">送信する</button>
    </p>
  </form>

  <form method="post" class="chatease-contact-form-back">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="input" />
    <p>
      <button type="submit">入力画面に戻る</button>
    </p>
  </form>
<?php
}

/**
 * 完了画面の描画
 */
function chatease_render_complete_screen(): void
{
?>
  <div class="chatease-complete">
    <p>お問い合わせありがとうございました。送信が完了しました。</p>
  </div>
<?php
}

/**
 * テキストフィールド用の sanitize
 */
function chatease_sanitize_text($value): string
{
  $value = wp_unslash($value);
  $value = trim($value);
  return sanitize_text_field($value);
}

/**
 * テキストエリア用の sanitize
 */
function chatease_sanitize_textarea($value): string
{
  $value = wp_unslash($value);
  $value = trim($value);
  return wp_kses_post($value);
}

/**
 * reCAPTCHA v2 の検証
 */
function chatease_verify_recaptcha(): bool
{
  $site_key   = get_option('chatease_recaptcha_site_key', '');
  $secret_key = get_option('chatease_recaptcha_secret_key', '');

  // 設定されていない場合はスキップ（本番では必須にしても良い）
  if ($site_key === '' || $secret_key === '') {
    return true;
  }

  $response_token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'])) : '';

  if ($response_token === '') {
    return false;
  }

  $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';

  $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
    'body' => [
      'secret'   => $secret_key,
      'response' => $response_token,
      'remoteip' => $remote_ip,
    ],
    'timeout' => 10,
  ]);

  if (is_wp_error($response)) {
    return false;
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);

  if (!is_array($data)) {
    return false;
  }

  return !empty($data['success']);
}

/**
 * ChatEase に送信する（実際には SDK を呼び出す）
 *
 * @param array{company:string,name:string,email:string,message:string} $values
 * @param int $form_post_id chatease_form の投稿ID（フォームごとの設定に使用）
 * @return string エラー時はメッセージ、成功時は空文字
 */
function chatease_send_to_chatease(array $values, int $form_post_id = 0): string
{
  $api_token      = get_option('chatease_api_token', '');
  $workspace_slug = get_option('chatease_workspace_slug', '');

  if ($api_token === '' || $workspace_slug === '') {
    return 'API トークンまたは Workspace Slug が設定されていません。';
  }

  try {
    $client = new ChatEaseClient($api_token, $workspace_slug);

    $uniqueKey = 'wp-' . date('Ymd-His') . '-' . wp_generate_password(8, false);

    $days = chatease_get_deadline_days($form_post_id);

    $tz = wp_timezone();
    $now = new DateTime('now', $tz);
    $now->modify('+' . $days . ' day');
    $timeLimit = $now->format('Y-m-d');

    $client->createBoardWithStatusAndMessage([
      'title' => 'Webフォームからのお問い合わせ',
      'guest' => [
        'name'  => $values['name'],
        'email' => $values['email'],
      ],
      'boardUniqueKey' => $uniqueKey,
      'initialStatus' => [
        'statusKey' => 'scheduled_for_response',
        'timeLimit' => $timeLimit,
      ],
      'initialGuestComment' => [
        'content' => chatease_build_initial_message($values),
      ],
    ]);
  } catch (\Throwable $e) {
    return $e->getMessage();
  }

  return '';
}

/**
 * 管理者通知メールの送信
 *
 * @param array{company:string,name:string,email:string,message:string} $values
 * @param int $form_post_id chatease_form の投稿ID
 */
function chatease_send_notify_email(array $values, int $form_post_id = 0): void
{
  // フォームごとの通知先メールを取得
  $to = chatease_get_notify_email($form_post_id);

  $subject = '【ChatEase】Webフォームからのお問い合わせ';
  $body    = "Webフォームからお問い合わせがありました。\n\n";
  $body   .= "会社名: " . ($values['company'] !== '' ? $values['company'] : '（未入力）') . "\n";
  $body   .= "お名前: " . $values['name'] . "\n";
  $body   .= "メールアドレス: " . $values['email'] . "\n";
  $body   .= "お問い合わせ内容:\n" . $values['message'] . "\n";

  $headers = ['Content-Type: text/plain; charset=UTF-8'];

  wp_mail($to, $subject, $body, $headers);
}

/**
 * 初期投稿用メッセージを組み立てる例（必要ならコメントアウトを外して利用）
 *
 * @param array{company:string,name:string,email:string,message:string} $values
 * @return string
 */
function chatease_build_initial_message(array $values): string
{
  $lines = [];
  if ($values['company'] !== '') {
    $lines[] = '会社名: ' . $values['company'];
  }
  $lines[] = 'お名前: ' . $values['name'];
  $lines[] = 'メールアドレス: ' . $values['email'];
  $lines[] = '---';
  $lines[] = $values['message'];

  return implode("\n", $lines);
}

add_action('add_meta_boxes', function () {
  add_meta_box(
    'chatease_form_labels',
    'フォームラベル設定',
    'chatease_render_form_labels_metabox',
    'chatease_form',
    'normal',
    'default'
  );
});

function chatease_render_form_labels_metabox(WP_Post $post)
{
  wp_nonce_field('chatease_form_labels', '_chatease_form_labels_nonce');

  $label_company = get_post_meta($post->ID, '_chatease_label_company', true);
  $label_name    = get_post_meta($post->ID, '_chatease_label_name', true);
  $label_email   = get_post_meta($post->ID, '_chatease_label_email', true);
  $label_message = get_post_meta($post->ID, '_chatease_label_message', true);
  $deadline_days = get_post_meta($post->ID, '_chatease_deadline_days', true);
  $notify_email  = get_post_meta($post->ID, '_chatease_notify_email', true);

  // デフォルト値
  if ($label_company === '') {
    $label_company = '会社名（任意）';
  }
  if ($label_name === '') {
    $label_name = 'お名前（必須）';
  }
  if ($label_email === '') {
    $label_email = 'メールアドレス（必須）';
  }
  if ($label_message === '') {
    $label_message = 'お問い合わせ内容（必須）';
  }
?>
  <table class="form-table">
    <tr>
      <th><label for="chatease_label_company">会社名ラベル</label></th>
      <td>
        <input type="text" id="chatease_label_company" name="chatease_label_company"
          value="<?php echo esc_attr($label_company); ?>" class="regular-text" />
      </td>
    </tr>
    <tr>
      <th><label for="chatease_label_name">名前ラベル</label></th>
      <td>
        <input type="text" id="chatease_label_name" name="chatease_label_name"
          value="<?php echo esc_attr($label_name); ?>" class="regular-text" />
      </td>
    </tr>
    <tr>
      <th><label for="chatease_label_email">メールラベル</label></th>
      <td>
        <input type="text" id="chatease_label_email" name="chatease_label_email"
          value="<?php echo esc_attr($label_email); ?>" class="regular-text" />
      </td>
    </tr>
    <tr>
      <th><label for="chatease_label_message">お問い合わせ内容ラベル</label></th>
      <td>
        <input type="text" id="chatease_label_message" name="chatease_label_message"
          value="<?php echo esc_attr($label_message); ?>" class="regular-text" />
      </td>
    </tr>
    <tr>
      <th><label for="chatease_deadline_days">返答期限（日数）</label></th>
      <td>
        <input type="number"
          min="1"
          id="chatease_deadline_days"
          name="chatease_deadline_days"
          value="<?php echo esc_attr($deadline_days); ?>"
          class="small-text" />
        <p class="description">
          フォーム送信日から何日後を「返答予定日」とするか設定します（未設定時はグローバル設定 → それもなければ 1 日）。
        </p>
      </td>
    </tr>
    <tr>
      <th><label for="chatease_form_notify_email">通知メールアドレス</label></th>
      <td>
        <input type="email"
          id="chatease_form_notify_email"
          name="chatease_form_notify_email"
          value="<?php echo esc_attr($notify_email); ?>"
          class="regular-text" />
        <p class="description">
          このフォームからの通知メール送信先。未設定時はグローバル設定 → それもなければサイト管理者メールが使われます。
        </p>
      </td>
    </tr>
  </table>
<?php
}

add_action('save_post_chatease_form', function ($post_id) {
  if (
    !isset($_POST['_chatease_form_labels_nonce']) ||
    !wp_verify_nonce($_POST['_chatease_form_labels_nonce'], 'chatease_form_labels')
  ) {
    return;
  }

  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
    return;
  }

  if (!current_user_can('edit_post', $post_id)) {
    return;
  }

  $fields = [
    'chatease_label_company' => '_chatease_label_company',
    'chatease_label_name'    => '_chatease_label_name',
    'chatease_label_email'   => '_chatease_label_email',
    'chatease_label_message' => '_chatease_label_message',
    'chatease_deadline_days'   => '_chatease_deadline_days',
    'chatease_form_notify_email' => '_chatease_notify_email',
  ];

  foreach ($fields as $input => $meta_key) {
    if (isset($_POST[$input])) {
      $value = sanitize_text_field(wp_unslash($_POST[$input]));
      update_post_meta($post_id, $meta_key, $value);
    }
  }
});

/**
 * 指定フォームIDのラベルを取得（なければデフォルト）
 *
 * @return array{
 *   company: string,
 *   name: string,
 *   email: string,
 *   message: string
 * }
 */
function chatease_get_form_labels(int $form_post_id): array
{
  $defaults = [
    'company' => '会社名（任意）',
    'name'    => 'お名前（必須）',
    'email'   => 'メールアドレス（必須）',
    'message' => 'お問い合わせ内容（必須）',
  ];

  if ($form_post_id <= 0) {
    return $defaults;
  }

  if (get_post_type($form_post_id) !== 'chatease_form') {
    return $defaults;
  }

  $company = get_post_meta($form_post_id, '_chatease_label_company', true);
  $name    = get_post_meta($form_post_id, '_chatease_label_name', true);
  $email   = get_post_meta($form_post_id, '_chatease_label_email', true);
  $message = get_post_meta($form_post_id, '_chatease_label_message', true);

  return [
    'company' => $company !== '' ? $company : $defaults['company'],
    'name'    => $name    !== '' ? $name    : $defaults['name'],
    'email'   => $email   !== '' ? $email   : $defaults['email'],
    'message' => $message !== '' ? $message : $defaults['message'],
  ];
}

/**
 * 返答期限（日数）をフォームごとに取得（なければグローバル → なければ 1）
 */
function chatease_get_deadline_days(int $form_post_id): int
{
  // フォーム個別設定
  if ($form_post_id > 0 && get_post_type($form_post_id) === 'chatease_form') {
    $v = get_post_meta($form_post_id, '_chatease_deadline_days', true);
    if ($v !== '' && (int)$v > 0) {
      return (int)$v;
    }
  }

  // グローバル設定
  $global = (int) get_option('chatease_response_deadline_days', 1);
  if ($global > 0) {
    return $global;
  }

  // 最低保証
  return 1;
}

/**
 * 通知メールアドレスをフォームごとに取得（なければグローバル → admin_email）
 */
function chatease_get_notify_email(int $form_post_id): string
{
  // フォーム個別設定
  if ($form_post_id > 0 && get_post_type($form_post_id) === 'chatease_form') {
    $v = get_post_meta($form_post_id, '_chatease_notify_email', true);
    $v = trim($v);
    if ($v !== '' && is_email($v)) {
      return $v;
    }
  }

  // グローバル設定
  $global = trim((string) get_option('chatease_notify_email', ''));
  if ($global !== '' && is_email($global)) {
    return $global;
  }

  // 最低保証
  return (string) get_option('admin_email');
}
