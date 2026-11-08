<?php
/**
 * Session and form-flow regression tests with WordPress / ChatEase I/O doubles.
 * Run with PHP 8.1+; no WordPress installation or external connections required.
 */
namespace Bagooon\ChatEase {
  class ChatEaseClient
  {
    public function __construct($token, $workspace) {}

    public function createBoardWithStatusAndMessage($payload): void
    {
      \check_session_closed('ChatEase API');
      file_put_contents($GLOBALS['test_dir'] . '/calls', json_encode($payload) . "\n", FILE_APPEND | LOCK_EX);
      if (!empty($GLOBALS['request']['api_error'])) {
        throw new \RuntimeException('Simulated API failure');
      }
      usleep((int) ($GLOBALS['request']['api_delay_us'] ?? 0));
    }
  }
}

namespace {
  function check($condition, string $message): void
  {
    if (!$condition) {
      throw new \RuntimeException($message);
    }
  }

  function check_session_closed(string $where): void
  {
    check(session_status() !== PHP_SESSION_ACTIVE, 'Session lock held during ' . $where);
  }

  // WordPress doubles cover hooks, validation, rendering, CAPTCHA and notification I/O.
  function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
  function add_filter(...$args) {}
  function add_shortcode(...$args) {}
  function do_action($hook) { foreach ($GLOBALS['hooks'][$hook] ?? [] as $callback) { $callback(); } }
  function register_post_type(...$args) {}
  function is_admin() { return ($GLOBALS['request']['context'] ?? '') === 'admin'; }
  function wp_doing_ajax() { return ($GLOBALS['request']['context'] ?? '') === 'ajax'; }
  function wp_doing_cron() { return ($GLOBALS['request']['context'] ?? '') === 'cron'; }
  function wp_verify_nonce($nonce, $action) { return $nonce === 'test-' . $action; }
  function wp_unslash($value) { return stripslashes($value); }
  function sanitize_text_field($value) { return trim(strip_tags($value)); }
  function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
  function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
  function wp_kses_post($value) { return strip_tags($value, '<p><br>'); }
  function get_post_type($id) { return 'chatease_form'; }
  function get_post_meta($id, $key, $single) { return ''; }
  function get_option($key, $default = '') {
    $options = ['chatease_api_token' => 'test-token', 'chatease_workspace_slug' => 'test-workspace',
      'admin_email' => 'admin@example.test'];
    if (!empty($GLOBALS['request']['captcha'])) {
      $options += ['chatease_turnstile_site_key' => 'test-site', 'chatease_turnstile_secret_key' => 'test-secret'];
    }
    return $options[$key] ?? $default;
  }
  function wp_remote_post(...$args) {
    check_session_closed('CAPTCHA HTTP request');
    return ['body' => json_encode(['success' => empty($GLOBALS['request']['captcha_error'])])];
  }
  function is_wp_error($response) { return false; }
  function wp_remote_retrieve_body($response) { return $response['body']; }
  function wp_timezone() { return new \DateTimeZone('Asia/Tokyo'); }
  function wp_generate_password(...$args) { return 'test-key'; }
  function wp_mail(...$args) {
    check_session_closed('notification mail');
    $GLOBALS['mail_count']++;
    return true;
  }
  function shortcode_atts($defaults, $atts, $tag) { return array_merge($defaults, $atts); }
  function wp_enqueue_style(...$args) {}
  function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
  function esc_attr($value) { return esc_html($value); }
  function esc_textarea($value) { return esc_html($value); }
  function wp_nonce_field($action, $name) { echo '<input name="' . esc_attr($name) . '" value="test-' . esc_attr($action) . '">'; }

  if (($argv[1] ?? '') === '--request') {
    $GLOBALS['test_dir'] = $argv[2];
    $GLOBALS['request'] = $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $GLOBALS['mail_count'] = 0;
    set_error_handler(static function ($severity, $message, $file, $line) {
      throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    session_save_path($GLOBALS['test_dir']);
    $_POST = $request['post'] ?? [];
    $_COOKIE = empty($request['sid']) ? [] : [session_name() => $request['sid']];
    // PHP normally imports this cookie before plugin execution.
    if (!empty($request['sid'])) { session_id($request['sid']); }
    $_SERVER['REQUEST_METHOD'] = empty($_POST) ? 'GET' : 'POST';
    define('ABSPATH', __DIR__);
    if (($request['context'] ?? '') === 'rest') { define('REST_REQUEST', true); }
    require __DIR__ . '/../src/chatease-contact-form.php';
    do_action('init');
    check_session_closed('WordPress init');
    if (!empty($request['external_session'])) {
      session_start();
      $_SESSION['other_plugin'] = 'preserved';
    }
    if (!empty($request['early_output'])) { echo "Early output\n"; }
    do_action('template_redirect');
    if (!empty($request['external_session'])) {
      check(session_status() === PHP_SESSION_ACTIVE, 'Closed another plugin session');
      check($_SESSION['other_plugin'] === 'preserved', 'Changed another plugin data');
      session_write_close();
    }
    check_session_closed('template_redirect');
    $sid = session_id();
    // A real theme can send headers before invoking a shortcode.
    echo "Theme output\n";
    $html = chatease_render_contact_form(['id' => (string) ($request['form_id'] ?? 12)]);
    $html_again = chatease_render_contact_form(['id' => (string) ($request['form_id'] ?? 12)]);
    check($html === $html_again, 'Repeated shortcode rendering changed the result');
    check_session_closed('shortcode rendering');
    echo json_encode(['sid' => $sid, 'html' => $html, 'states' => $GLOBALS['chatease_form_states'] ?? [],
      'mail_count' => $GLOBALS['mail_count'], 'snapshot' => $GLOBALS['chatease_form_values'] ?? []], JSON_THROW_ON_ERROR);
    exit;
  }

  $test_dir = sys_get_temp_dir() . '/chatease-session-tests-' . bin2hex(random_bytes(6));
  mkdir($test_dir, 0700);
  function start_request(array $request): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--request', $GLOBALS['test_dir']],
      [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    return [$process, $pipes];
  }
  function finish_request(array $running): array {
    [$process, $pipes] = $running;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Request failed: ' . $err . $out);
    check($err === '', 'Unexpected PHP output: ' . $err);
    return json_decode(substr($out, strpos($out, '{')), true, 512, JSON_THROW_ON_ERROR);
  }
  function request(array $request = []): array { return finish_request(start_request($request)); }
  function post(string $step, int $id = 12): array {
    return ['chatease_step' => $step, 'chatease_form_post_id' => (string) $id,
      '_chatease_nonce' => 'test-chatease_contact_form_' . ($id > 0 ? 'form_' . $id : 'default'),
      'cf_company' => 'Test Company', 'cf_name' => 'Test User', 'cf_email' => 'user@example.test',
      'cf_message' => 'Test inquiry', 'cf-turnstile-response' => 'test-response'];
  }
  function state(array $response, string $form = 'form_12'): array { return $response['states'][$form]; }
  function api_calls(): int {
    $path = $GLOBALS['test_dir'] . '/calls';
    return is_file($path) ? count(file($path)) : 0;
  }

  try {
    foreach (['admin', 'rest', 'ajax', 'cron', 'public'] as $context) {
      $response = request(['context' => $context]);
      check($response['sid'] === '', $context . ' created a session');
      request(['context' => $context, 'sid' => 'existing-cookie']);
      if ($context !== 'public') {
        check(!is_file($test_dir . '/sess_existing-cookie'), $context . ' opened an existing session');
      }
    }
    $invalid = post('confirm');
    $invalid['_chatease_nonce'] = 'invalid';
    $response = request(['post' => $invalid]);
    check($response['sid'] === '' && state($response)['step'] === 'input', 'Invalid nonce created a session');
    check(count(state($response)['errors']) === 1, 'Invalid nonce was not rejected');
    $response = request(['post' => post('confirm'), 'captcha' => true]);
    $sid = $response['sid'];
    check($sid !== '' && state($response)['step'] === 'confirm', 'Confirm failed');
    check(str_contains($response['html'], 'Test inquiry'), 'Confirmation lost input');
    $response = request(['sid' => $sid, 'post' => post('input')]);
    check(state($response)['stored']['email'] === 'user@example.test', 'Back lost input');
    $response = request(['sid' => $sid]);
    check(str_contains($response['html'], 'user@example.test'), 'Reload lost input');
    $response = request(['sid' => $sid, 'form_id' => 34]);
    check(!str_contains($response['html'], 'user@example.test'), 'Data leaked into another form');
    $response = request(['post' => post('submit')]);
    check(state($response)['step'] === 'input' && api_calls() === 0, 'Expired session submitted');
    $response = request(['post' => post('confirm'), 'captcha' => true, 'captcha_error' => true]);
    check(state($response)['step'] === 'input', 'Invalid CAPTCHA accepted');
    $response = request(['post' => post('confirm'), 'early_output' => true]);
    check(state($response)['step'] === 'input' && state($response)['errors'] !== [], 'Storage failure accepted');
    $response = request(['sid' => $sid, 'post' => post('submit'), 'api_error' => true]);
    check(state($response)['step'] === 'input' && $response['mail_count'] === 0, 'API error flow failed');
    $response = request(['sid' => $sid]);
    check(str_contains($response['html'], 'user@example.test'), 'API error did not restore input');
    request(['sid' => $sid, 'post' => post('confirm')]);
    $before = api_calls();
    $first = start_request(['sid' => $sid, 'post' => post('submit'), 'api_delay_us' => 300000]);
    $second = start_request(['sid' => $sid, 'post' => post('submit'), 'api_delay_us' => 300000]);
    $responses = [finish_request($first), finish_request($second)];
    $steps = array_map(static fn ($response) => state($response)['step'], $responses);
    sort($steps);
    check($steps === ['complete', 'input'], 'Concurrent submissions were not consumed once');
    check(api_calls() === $before + 1, 'Concurrent requests created duplicate boards');
    check(array_sum(array_column($responses, 'mail_count')) === 1, 'Notification repeated');
    $response = request(['sid' => $sid]);
    check(!str_contains($response['html'], 'user@example.test'), 'Success did not clear input');
    $response = request(['sid' => $sid, 'post' => post('submit')]);
    check(state($response)['step'] === 'input' && api_calls() === $before + 1, 'Replay submitted again');
    $response = request(['post' => post('confirm', 0), 'form_id' => 0]);
    check(state($response, 'default')['step'] === 'confirm', 'Default shortcode failed');
    request(['sid' => $sid, 'external_session' => true]);
    echo 'PASS: request contexts, nonce, CAPTCHA, confirm/back/reload, form isolation, expiry, storage failure, API retry, concurrent submit, replay, default form, session ownership.' . PHP_EOL;
  } finally {
    // Only temporary fixtures created by this test are removed.
    foreach (glob($test_dir . '/*') as $path) { unlink($path); }
    rmdir($test_dir);
  }
}
