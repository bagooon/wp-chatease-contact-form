<?php
/*
Plugin Name: ChatEase Contact Form
Description: ChatEase 連携用の確認画面付き問い合わせフォーム（reCAPTCHA v2 対応・セッション方式）
Version: 0.1.0
Author: Hashimoto Giken
*/

if (!defined('ABSPATH')) {
  exit;
}

// autoload を先に読み込む
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require __DIR__ . '/vendor/autoload.php';
}

use Bagooon\ChatEase\ChatEaseClient;

const CHATEASE_PLUGIN_STYLE_HANDLE = 'chatease-contact-form';
const CHATEASE_PLUGIN_STYLE_VERSION = '0.2.0';
const CHATEASE_DEFAULT_BOARD_TITLE = 'フォームからのお問い合わせ';

/**
 * フロント側でセッションを開始
 */
add_action('init', function () {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

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
    'show_in_menu'       => 'chatease_root',
    'supports'           => ['title'],
    'has_archive'        => false,
    'show_in_nav_menus'  => false,
    'show_in_rest'       => false,
    'menu_position'      => 25,
  ];

  register_post_type('chatease_form', $args);
});

add_action('admin_enqueue_scripts', function () {
  wp_add_inline_style(
    'wp-admin',
    '
    .chatease-status-ok {
      color: #1d7a1d !important;
      font-weight: 600;
    }
    .chatease-status-error {
      color: #b32d2e !important;
      font-weight: 600;
    }
    '
  );
});

/**
 * reCAPTCHA スクリプト読み込み
 */
add_action('wp_enqueue_scripts', function () {
  wp_register_style(
    CHATEASE_PLUGIN_STYLE_HANDLE,
    plugins_url('assets/chatease-contact-form.css', __FILE__),
    [],
    CHATEASE_PLUGIN_STYLE_VERSION
  );

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
  register_setting('chatease_contact_form', 'chatease_workspace_name');

  // 設定保存後にワークスペース名チェック
  if (
    isset($_GET['page']) &&
    $_GET['page'] === 'chatease_root' &&
    !empty($_GET['settings-updated'])
  ) {
    chatease_validate_workspace_settings();
  }
});

/**
 * 管理画面：メニュー追加
 */
add_action('admin_menu', function () {
  // トップレベルメニュー「ChatEase」
  add_menu_page(
    'ChatEase',               // ページタイトル
    'ChatEase',               // メニュータイトル
    'manage_options',         // 権限
    'chatease_root',          // メニュースラッグ
    'chatease_render_settings_page', // 初期表示は共通設定ページでOK
    'dashicons-format-chat',  // アイコン（お好みで）
    25                        // メニュー位置（Yoastとかと被らないあたり）
  );

  // ▼ 共通設定サブメニュー（トップレベルと同じ slug にしても良い）
  add_submenu_page(
    'chatease_root',               // 親メニュー slug
    'ChatEase 共通設定',           // ページタイトル
    '共通設定',                    // サブメニュータイトル
    'manage_options',
    'chatease_root',               // メニュースラッグ（親と同じ＝クリックで同じ画面）
    'chatease_render_settings_page'
  );
});

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
    'chatease_label_company'      => '_chatease_label_company',
    'chatease_label_name'         => '_chatease_label_name',
    'chatease_label_email'        => '_chatease_label_email',
    'chatease_label_message'      => '_chatease_label_message',
    'chatease_board_title'        => '_chatease_board_title',
    'chatease_deadline_days'      => '_chatease_deadline_days',
    'chatease_form_notify_email'  => '_chatease_notify_email',
    'chatease_form_api_token'     => '_chatease_api_token',
    'chatease_form_workspace_slug' => '_chatease_workspace_slug',
  ];

  foreach ($fields as $input => $meta_key) {
    if (isset($_POST[$input])) {
      $value = sanitize_text_field(wp_unslash($_POST[$input]));
      update_post_meta($post_id, $meta_key, $value);
    }
  }

  // チェックボックスは未チェック時にPOSTされないため明示的に保存
  $use_plugin_style = isset($_POST['chatease_use_plugin_style']) ? '1' : '0';
  update_post_meta($post_id, '_chatease_use_plugin_style', $use_plugin_style);

  // フォーム専用のワークスペース設定を検証
  chatease_validate_form_workspace_settings($post_id);
});

/**
 * chatease_form 編集画面で、フォーム専用ワークスペースのエラー／成功メッセージを表示
 */
add_action('admin_notices', function () {
  global $pagenow, $post;

  if ($pagenow !== 'post.php') {
    return;
  }

  if (!isset($post) || $post->post_type !== 'chatease_form') {
    return;
  }

  $post_id = $post->ID;

  $error  = get_transient('chatease_form_workspace_error_' . $post_id);
  $notice = get_transient('chatease_form_workspace_notice_' . $post_id);

  if ($error) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
    delete_transient('chatease_form_workspace_error_' . $post_id);
  }

  if ($notice) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    delete_transient('chatease_form_workspace_notice_' . $post_id);
  }
});

// カスタムカラムにショートコードの中身を表示
add_action('manage_chatease_form_posts_custom_column', function (string $column, int $post_id): void {
  if ($column !== 'chatease_shortcode') {
    return;
  }

  $shortcode = sprintf(
    '[chatease_contact_form id="%d"]',
    $post_id
  );

  echo '<code>' . esc_html($shortcode) . '</code>';
}, 10, 2);

// フォーム一覧（chatease_form）のカラムに「ショートコード」を追加
add_filter('manage_edit-chatease_form_columns', function (array $columns): array {
  return [
    'cb'                  => $columns['cb'] ?? '',   // チェックボックス
    'title'               => 'フォーム名',
    'chatease_shortcode'  => 'ショートコード',
    'date'                => '作成日',
  ];
});

/**
 * 設定画面の描画
 */
function chatease_render_settings_page()
{
  if (!current_user_can('manage_options')) {
    return;
  }

  $workspace_name = get_option('chatease_workspace_name', '');

?>
  <div class="wrap">
    <h1>ChatEase Contact Form 設定</h1>
    <?php
    // ▼ 設定保存時のエラー／成功メッセージ表示
    settings_errors();
    ?>
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
          <th scope="row">ワークスペース名</th>
          <td>
            <input type="text"
              readonly
              class="regular-text"
              value="<?php echo esc_attr($workspace_name); ?>" />
            <?php if ($workspace_name !== '') : ?>
            <p class="description chatease-status-ok">
              ワークスペースとの紐付けが完了しています。
            </p>
            <?php else : ?>
            <p class="description chatease-status-error">
              ワークスペースとの紐付けが完了していません。<br>
              API トークン と ワークスペーススラッグ を入力して「保存」ボタンを押してください。
            </p>
            <?php endif; ?>
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

  // POST のときは hidden から上書き（ショートコードとズレないように）
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chatease_form_post_id'])) {
    $posted_id = (int) $_POST['chatease_form_post_id'];
    if ($posted_id > 0) {
      $form_post_id = $posted_id;
    }
  }

  if (chatease_should_use_plugin_style($form_post_id)) {
    wp_enqueue_style(CHATEASE_PLUGIN_STYLE_HANDLE);
  }

  $form_id = $form_post_id > 0 ? 'form_' . $form_post_id : 'default';
  $session_key = 'chatease_form_' . $form_id;

  $labels = chatease_get_form_labels($form_post_id);

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
        $stored['company'] = isset($_POST['cf_company']) ? chatease_sanitize_text($_POST['cf_company']) : '';
        $stored['name']    = isset($_POST['cf_name']) ? chatease_sanitize_text($_POST['cf_name']) : '';
        $stored['email']   = isset($_POST['cf_email']) ? sanitize_email(wp_unslash($_POST['cf_email'])) : '';
        $stored['message'] = isset($_POST['cf_message']) ? chatease_sanitize_textarea($_POST['cf_message']) : '';

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
  $form_post_id = chatease_extract_form_post_id_from_form_id($form_id);

  // フォーム専用 → なければグローバル の順でワークスペース名を取得
  $creds = chatease_get_api_credentials($form_post_id);
  $workspace_name = trim((string) $creds['workspace_name']);

?>
  <?php if ($workspace_name !== ''): ?>
    <div class="chatease-intro">
      <p>
        個別にチャットにてご返答させていただきます。準備が整いましたらチャットボードへのアクセス方法をメールにてお知らせいたします。<br />
        メールの件名は 『 チャットボード通知【 <?php echo esc_html($workspace_name); ?> 】 』です。
      </p>
    </div>
  <?php else: ?>
    <div class="chatease-intro">
      <p>
        個別にチャットにてご返答させていただきます。準備が整いましたらチャットボードへのアクセス方法をメールにてお知らせいたします。
      </p>
    </div>
  <?php endif; ?>

  <form method="post" class="chatease-contact-form">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="confirm" />
    <input type="hidden" name="chatease_form_post_id" value="<?php echo esc_attr((string) $form_post_id); ?>" />

    <div class="chatease-field chatease-field--company">
      <div class="chatease-field__label">
        <label for="cf_company"><?php echo esc_html($labels['company']); ?></label>
      </div>
      <div class="chatease-field__input">
        <input id="cf_company" type="text" name="cf_company" value="<?php echo esc_attr($values['company']); ?>" />
      </div>
    </div>

    <div class="chatease-field chatease-field--name chatease-required">
      <div class="chatease-field__label">
        <label for="cf_name"><?php echo esc_html($labels['name']); ?></label>
        <span class="chatease-required__label">必須</span>
      </div>
      <div class="chatease-field__input">
        <input id="cf_name" type="text" name="cf_name" value="<?php echo esc_attr($values['name']); ?>" required />
      </div>
    </div>

    <div class="chatease-field chatease-field--email">
      <div class="chatease-field__label">
        <label for="cf_email"><?php echo esc_html($labels['email']); ?></label>
        <span class="chatease-required__label">必須</span>
      </div>
      <div class="chatease-field__input">
        <input id="cf_email" type="email" name="cf_email" value="<?php echo esc_attr($values['email']); ?>" required />
      </div>
    </div>

    <div class="chatease-field chatease-field--message">
      <div class="chatease-field__label">
        <label for="cf_message"><?php echo esc_html($labels['message']); ?></label>
        <span class="chatease-required__label">必須</span>
      </div>
      <div class="chatease-field__input">
        <textarea id="cf_message" name="cf_message" rows="5" required><?php echo esc_textarea($values['message']); ?></textarea>
      </div>
    </div>

    <?php if ($site_key !== ''): ?>
      <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
    <?php endif; ?>

    <div class="chatease-form-buttons">
      <button type="submit">確認画面へ</button>
    </div>
  </form>
<?php
}

/**
 * 確認画面の描画
 */
function chatease_render_confirm_screen(string $form_id, array $values, array $labels): void
{
  $form_post_id = chatease_extract_form_post_id_from_form_id($form_id);

?>
  <form id="chatease-form-submit" method="post" class="chatease-contact-form-confirm">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="submit" />
    <input type="hidden" name="chatease_form_post_id" value="<?php echo esc_attr((string) $form_post_id); ?>" />

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
  </form>

  <form id="chatease-form-back" method="post" class="chatease-contact-form-back">
    <?php wp_nonce_field('chatease_contact_form_' . $form_id, '_chatease_nonce'); ?>
    <input type="hidden" name="chatease_step" value="input" />
    <input type="hidden" name="chatease_form_post_id" value="<?php echo esc_attr((string) $form_post_id); ?>" />
  </form>

  <div class="chatease-confirm-buttons">
    <button type="submit" form="chatease-form-back">入力画面に戻る</button>
    <button type="submit" form="chatease-form-submit">送信する</button>
  </div>
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
 * 共通設定の API トークン / Workspace Slug を検証し、
 * 成功時はワークスペース名を保存、失敗時はエラーを表示する
 */
function chatease_validate_workspace_settings(): void
{
  $api_token = trim((string) get_option('chatease_api_token', ''));
  $slug      = trim((string) get_option('chatease_workspace_slug', ''));

  // 両方とも空 → 完全未設定なので名前を消して終了（エラーは出さない）
  if ($api_token === '' && $slug === '') {
    delete_option('chatease_workspace_name');
    return;
  }

  // どちらか片方だけ → 未設定とみなしてエラー扱い
  if ($api_token === '' || $slug === '') {
    delete_option('chatease_workspace_name');

    add_settings_error(
      'chatease_contact_form',
      'chatease_workspace_validation',
      'API トークンとワークスペーススラッグは両方設定する必要があります（共通設定）。',
      'error'
    );
    return;
  }

  if (!class_exists(\Bagooon\ChatEase\ChatEaseClient::class)) {
    add_settings_error(
      'chatease_contact_form',
      'chatease_workspace_validation',
      'ChatEase クライアントが読み込まれていないため、ワークスペース名を確認できませんでした。',
      'error'
    );
    return;
  }

  try {
    $client = new \Bagooon\ChatEase\ChatEaseClient($api_token, $slug);
    $name   = $client->getWorkspaceName();

    update_option('chatease_workspace_name', $name);

    add_settings_error(
      'chatease_contact_form',
      'chatease_workspace_validation',
      'ワークスペース情報を確認しました：' . $name,
      'updated'
    );
  } catch (\Throwable $e) {
    delete_option('chatease_workspace_name');

    add_settings_error(
      'chatease_contact_form',
      'chatease_workspace_validation',
      'ワークスペース確認エラー：' . $e->getMessage(),
      'error'
    );
  }
}

/**
 * 各フォーム専用の API トークン / Workspace Slug を検証し、
 * 成功時はフォーム専用のワークスペース名を保存、失敗時はエラーを表示する
 *
 * - 両方空      → グローバル設定を使う想定なので、フォーム側の name は削除
 * - 片方だけ    → 未設定としてエラー（メタは削除）
 * - 両方あり    → API で name を取得し、_chatease_workspace_name に保存
 */
function chatease_validate_form_workspace_settings(int $post_id): void
{
  if (get_post_type($post_id) !== 'chatease_form') {
    return;
  }

  $api_token = trim((string) get_post_meta($post_id, '_chatease_api_token', true));
  $slug      = trim((string) get_post_meta($post_id, '_chatease_workspace_slug', true));

  // 両方とも空 → グローバル設定を使う前提。フォーム専用の名前は削除。
  if ($api_token === '' && $slug === '') {
    delete_post_meta($post_id, '_chatease_workspace_name');
    return;
  }

  // 片方だけ → エラー & フォーム用 name 削除
  if ($api_token === '' || $slug === '') {
    delete_post_meta($post_id, '_chatease_workspace_name');

    // 一時的にエラーメッセージを保存して、編集画面で表示する
    set_transient(
      'chatease_form_workspace_error_' . $post_id,
      'API トークンとワークスペーススラッグは両方設定する必要があります（フォーム専用設定）。',
      60
    );
    return;
  }

  if (!class_exists(\Bagooon\ChatEase\ChatEaseClient::class)) {
    set_transient(
      'chatease_form_workspace_error_' . $post_id,
      'ChatEase クライアントが読み込まれていないため、フォーム専用のワークスペース名を確認できませんでした。',
      60
    );
    return;
  }

  try {
    $client = new \Bagooon\ChatEase\ChatEaseClient($api_token, $slug);
    $name   = $client->getWorkspaceName();

    update_post_meta($post_id, '_chatease_workspace_name', $name);

    set_transient(
      'chatease_form_workspace_notice_' . $post_id,
      'フォーム専用のワークスペース情報を確認しました：' . $name,
      60
    );
  } catch (\Throwable $e) {
    delete_post_meta($post_id, '_chatease_workspace_name');

    set_transient(
      'chatease_form_workspace_error_' . $post_id,
      'フォーム専用ワークスペース確認エラー：' . $e->getMessage(),
      60
    );
  }
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
  // フォームごとに認証情報を取得
  $creds = chatease_get_api_credentials($form_post_id);
  if ($creds['error'] !== '') {
    return $creds['error'];
  }

  $api_token      = $creds['api_token'];
  $workspace_slug = $creds['workspace_slug'];

  if ($api_token === '' || $workspace_slug === '') {
    return 'API トークンまたは Workspace Slug が設定されていません。';
  }

  $days = chatease_get_deadline_days($form_post_id);

  $tz = wp_timezone();
  $now = new DateTime('now', $tz);
  $now->modify('+' . $days . ' day');
  $timeLimit = $now->format('Y-m-d');

  // 名前 = 「会社名 + 名前」/ 会社名が入力されていない場合は「名前のみ」にする
  if ($values['company'] !== '') {
    $guestName = $values['company'] . ' ' . $values['name'];
  } else {
    $guestName = $values['name'];
  }

  // 初回投稿内容は「問い合わせ内容のみ」
  $initialContent = $values['message'];

  try {
    $client = new ChatEaseClient($api_token, $workspace_slug);

    $tz  = wp_timezone(); // WordPressの設定タイムゾーン
    $now = new DateTime('now', $tz);

    $uniqueKey = sprintf('wp-%s-%s', $now->format('Ymd-His'), wp_generate_password(8, false));

    $client->createBoardWithStatusAndMessage([
      'title' => chatease_get_board_title($form_post_id),
      'guest' => [
        'name'  => $guestName,
        'email' => $values['email'],
      ],
      'boardUniqueKey' => $uniqueKey,
      'initialStatus' => [
        'statusKey' => 'scheduled_for_response',
        'timeLimit' => $timeLimit,
      ],
      'initialGuestComment' => [
        'content' => $initialContent,
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

function chatease_render_form_labels_metabox(WP_Post $post)
{
  wp_nonce_field('chatease_form_labels', '_chatease_form_labels_nonce');

  $label_company       = get_post_meta($post->ID, '_chatease_label_company', true);
  $label_name          = get_post_meta($post->ID, '_chatease_label_name', true);
  $label_email         = get_post_meta($post->ID, '_chatease_label_email', true);
  $label_message       = get_post_meta($post->ID, '_chatease_label_message', true);
  $deadline_days       = get_post_meta($post->ID, '_chatease_deadline_days', true);
  $notify_email        = get_post_meta($post->ID, '_chatease_notify_email', true);
  $form_api_token      = get_post_meta($post->ID, '_chatease_api_token', true);
  $form_slug           = get_post_meta($post->ID, '_chatease_workspace_slug', true);
  $form_workspace_name = get_post_meta($post->ID, '_chatease_workspace_name', true);
  $use_plugin_style    = get_post_meta($post->ID, '_chatease_use_plugin_style', true);
  $board_title         = trim((string) get_post_meta($post->ID, '_chatease_board_title', true));

  // デフォルト値
  if ($label_company === '') $label_company = '会社名';
  if ($label_name === '') $label_name = 'お名前';
  if ($label_email === '') $label_email = 'メールアドレス';
  if ($label_message === '') $label_message = 'お問い合わせ内容';

  // 期限日数のデフォルト：フォーム → グローバル → 1日
  if ($deadline_days === '') {
    $deadline_days = (string) get_option('chatease_response_deadline_days', 1);
  }

  // 通知メール：フォーム → グローバル → admin_email
  if ($notify_email === '') {
    $notify_email = get_option('chatease_notify_email', get_option('admin_email'));
  }

  // スタイル適用は未設定時に ON をデフォルトとする
  $is_use_plugin_style = $use_plugin_style === '' ? true : ((string) $use_plugin_style !== '0');
  if ($board_title === '') {
    $board_title = CHATEASE_DEFAULT_BOARD_TITLE;
  }

  // API トークン / slug は「空ならグローバルを参考表示」にしておくと親切
  $global_workspace_name = get_option('chatease_workspace_name', '');
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
          チャットボードの「返答予定日」をフォーム送信日から何日後にセットするか設定します。<br>
          ※フォーム送信者からは見えません。
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
    <tr>
      <th><label for="chatease_use_plugin_style">プラグイン同梱スタイルを適用</label></th>
      <td>
        <label>
          <input type="checkbox"
            id="chatease_use_plugin_style"
            name="chatease_use_plugin_style"
            value="1"
            <?php checked($is_use_plugin_style); ?> />
          フロント画面でプラグインのCSSを適用する
        </label>
      </td>
    </tr>
    <tr>
      <th><label for="chatease_board_title">チャットボードタイトル</label></th>
      <td>
        <input type="text"
          id="chatease_board_title"
          name="chatease_board_title"
          value="<?php echo esc_attr($board_title); ?>"
          class="regular-text" />
        <p class="description">
          送信時に作成されるチャットボードのタイトルです。未設定時は「<?php echo esc_html(CHATEASE_DEFAULT_BOARD_TITLE); ?>」が使われます。
        </p>
      </td>
    </tr>
    <tr>
      <th><label for="chatease_form_api_token">API トークン<br>（このフォーム専用）</label></th>
      <td>
        <input type="text"
          id="chatease_form_api_token"
          name="chatease_form_api_token"
          value="<?php echo esc_attr($form_api_token); ?>"
          class="regular-text" />
        <p class="description">
          トークン／スラッグが共に空欄の場合は共通設定
          <?php if ($global_workspace_name !== ''): ?>
            （現在設定済み）
          <?php endif; ?>
          が使用されます。
        </p>
      </td>
    </tr>
    <tr>
      <th><label for="chatease_form_workspace_slug">ワークスペーススラッグ<br>（このフォーム専用）</label></th>
      <td>
        <input type="text"
          id="chatease_form_workspace_slug"
          name="chatease_form_workspace_slug"
          value="<?php echo esc_attr($form_slug); ?>"
          class="regular-text" />
        <p class="description">
          トークン／スラッグが共に空欄の場合は共通設定
          <?php if ($global_workspace_name !== ''): ?>
            （現在設定済み）
          <?php endif; ?>
          が使用されます。
        </p>
      </td>
    </tr>
    <tr>
      <th><label>ワークスペース名</label></th>
      <td>
        <?php if ($form_workspace_name !== ''): ?>
          <input type="text"
            readonly
            class="regular-text"
            value="<?php echo esc_attr($form_workspace_name); ?>" />
          <p class="description chatease-status-ok">
            ワークスペースとの紐付けが完了しています。
          </p>
        <?php elseif ($form_api_token === '' && $form_slug === '' && $global_workspace_name !== ''): ?>
          <input type="text"
            readonly
            class="regular-text"
            value="<?php echo esc_attr($global_workspace_name); ?>" />
          <p class="description chatease-status-ok">
            共通設定のワークスペースが使用されます。
          </p>
        <?php else: ?>
          <p class="description chatease-status-error">
            ワークスペースとの紐付けが完了していません。共通設定、またはこのフォーム専用の API トークン／スラッグを設定してください。
          </p>
        <?php endif; ?>
      </td>
    </tr>
  </table>
<?php
}

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

/**
 * form_id（例: form_123）から投稿IDを取得する
 */
function chatease_extract_form_post_id_from_form_id(string $form_id): int
{
  if (strpos($form_id, 'form_') === 0) {
    return (int) substr($form_id, strlen('form_'));
  }

  return 0;
}

/**
 * フォームごとのプラグインCSS適用可否を取得（未設定時は ON）
 */
function chatease_should_use_plugin_style(int $form_post_id): bool
{
  if ($form_post_id > 0 && get_post_type($form_post_id) === 'chatease_form') {
    $v = get_post_meta($form_post_id, '_chatease_use_plugin_style', true);
    if ($v === '') {
      return true;
    }
    return (string) $v !== '0';
  }

  return true;
}

/**
 * チャットボードタイトルをフォームごとに取得（未設定時はデフォルト）
 */
function chatease_get_board_title(int $form_post_id): string
{
  if ($form_post_id > 0 && get_post_type($form_post_id) === 'chatease_form') {
    $title = trim((string) get_post_meta($form_post_id, '_chatease_board_title', true));
    if ($title !== '') {
      return $title;
    }
  }

  return CHATEASE_DEFAULT_BOARD_TITLE;
}

/**
 * フォームごとの API トークン / Workspace Slug / Workspace Name を取得
 *
 * 優先順位：
 *   1. フォーム専用設定（両方セットされている場合）
 *   2. グローバル設定（両方セットされている場合）
 *
 * @return array{
 *   api_token: string,
 *   workspace_slug: string,
 *   workspace_name: string,
 *   error: string
 * }
 */
function chatease_get_api_credentials(int $form_post_id): array
{
  $error          = '';
  $api_token      = '';
  $slug           = '';
  $workspace_name = '';

  // 1) フォーム専用設定を優先
  if ($form_post_id > 0 && get_post_type($form_post_id) === 'chatease_form') {
    $api_token = trim((string) get_post_meta($form_post_id, '_chatease_api_token', true));
    $slug      = trim((string) get_post_meta($form_post_id, '_chatease_workspace_slug', true));

    // 両方セットされている → フォーム専用設定を採用
    if ($api_token !== '' && $slug !== '') {
      $workspace_name = trim((string) get_post_meta($form_post_id, '_chatease_workspace_name', true));

      // name が空でも、とりあえずこの組み合わせを使う（API 呼び出しは別途）
      return [
        'api_token'      => $api_token,
        'workspace_slug' => $slug,
        'workspace_name' => $workspace_name,
        'error'          => '',
      ];
    }

    // 片方だけ埋まっている → 要件②：エラー
    if ($api_token !== '' || $slug !== '') {
      return [
        'api_token'      => '',
        'workspace_slug' => '',
        'workspace_name' => '',
        'error'          => 'フォーム専用の API トークンと Workspace Slug は両方設定する必要があります。',
      ];
    }
  }

  // 2) グローバル設定を使用
  $api_token = trim((string) get_option('chatease_api_token', ''));
  $slug      = trim((string) get_option('chatease_workspace_slug', ''));
  $workspace_name = trim((string) get_option('chatease_workspace_name', ''));

  // 両方空 → 未設定
  if ($api_token === '' && $slug === '') {
    $error = 'API トークンと Workspace Slug が設定されていません（共通設定）。';
  }
  // 片方だけ → エラー
  elseif ($api_token === '' || $slug === '') {
    $error = 'API トークンと Workspace Slug は両方設定する必要があります（共通設定）。';
    $api_token      = '';
    $slug           = '';
    $workspace_name = '';
  }

  return [
    'api_token'      => $api_token,
    'workspace_slug' => $slug,
    'workspace_name' => $workspace_name,
    'error'          => $error,
  ];
}
