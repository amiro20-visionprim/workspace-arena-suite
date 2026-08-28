<?php
/**
 * Plugin Name: Vision Prime Connector
 * Description: Secure connection between WordPress and Vision Prime.
 * Version: 1.4.2
 * Requires PHP: 8.2
 */


defined('ABSPATH') || exit;

define('VISION_PRIME_CONNECTOR_VERSION', '1.4.2');
define('VISION_PRIME_OPTION', 'vision_prime_connector');

/**
 * Integrity guard for the Vision Prime Connector.
 *
 * The shipped distribution is a single encoded file. At build time the SHA-256
 * of the file (with the SELF_HASH constant zeroed) is embedded into SELF_HASH.
 * At runtime we recompute the same value and compare — if the file was modified
 * in any way, the guard reports tampered and every sensitive operation refuses
 * to run, and the platform is told about it via the integrity fields on every
 * signed request.
 */
final class VP_Guard {
    /** Filled at build time with the self-referential SHA-256 of this file. */
    public const SELF_HASH = '';

    public static function current_file(): string {
        return __FILE__;
    }

    /** Stable fingerprint of this build, reported to the platform on every signed request. */
    public static function file_hash(): string {
        $raw = (string) file_get_contents(self::current_file());

        return hash('sha256', $raw);
    }

    /**
     * True when the file on disk does not match the build-time fingerprint.
     * Works by zeroing the embedded SELF_HASH before hashing (the value is
     * excluded from its own fingerprint, like a Git blob hash).
     */
    public static function tampered(): bool {
        if (self::SELF_HASH === '') {
            return false; // source tree (dev) — the encoded distribution carries the fingerprint
        }

        $raw = (string) file_get_contents(self::current_file());
        $normalized = preg_replace(
            "/const SELF_HASH = '[0-9a-f]{64}'/",
            "const SELF_HASH = '" . str_repeat('0', 64) . "'",
            $raw
        );
        $actual = hash('sha256', (string) $normalized);

        return ! hash_equals(self::SELF_HASH, $actual);
    }

    public static function is_tampered_flag(): int {
        return self::tampered() ? 1 : 0;
    }
}
/**
 * Secret protection for the Vision Prime Connector.
 *
 * The pairing secret is stored in wp_options. A plaintext secret there would be
 * exposed by any database leak or backup. We store it AES-256-GCM encrypted with
 * a key derived from the site's AUTH_KEY salt (wp_salt), so reading the database
 * alone is not enough to recover the secret — the attacker would also need file
 * access to wp-config.php.
 */
final class VP_Secret {
    private const PREFIX = 'vp1:';
    private const OPTION = 'vision_prime_connector';

    public static function encrypt(string $plain): string {
        if (! function_exists('openssl_encrypt') || $plain === '') {
            return $plain; // degrade gracefully when openssl is unavailable
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string {
        if (! str_starts_with($stored, self::PREFIX)) {
            return $stored; // legacy plaintext
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 12 + 16);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /**
     * Return settings with a usable (decrypted) secret, migrating a legacy
     * plaintext secret to encrypted storage on first use.
     *
     * @param array $settings Raw settings as stored in wp_options.
     * @return array Settings with the secret decrypted in place.
     */
    public static function unlock(array $settings): array {
        if (empty($settings['secret'])) {
            return $settings;
        }
        $stored = (string) $settings['secret'];
        if (! str_starts_with($stored, self::PREFIX)) {
            $settings['secret'] = self::encrypt($stored);
            update_option(self::OPTION, $settings, false); // persist migrated (encrypted) form
        }
        $settings['secret'] = self::decrypt((string) $settings['secret']);

        return $settings;
    }

    private static function key(): string {
        $salt = function_exists('wp_salt') ? (string) wp_salt('auth') : '';
        if ($salt === '') {
            $salt = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        }

        return hash('sha256', 'vision-prime-connector-v1|' . $salt, true); // 32 bytes
    }
}
final class VP_API_Client {
    /**
     * Pair this site with the Vision Prime platform.
     *
     * @param array $settings Plugin settings (platform_url, site_id).
     * @return array|WP_Error Response body with `secret` on success.
     */
    public static function pair(array $settings, string $token): array|WP_Error {
        $response = wp_remote_post(rtrim($settings['platform_url'], '/') . '/connector/pair', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['site_id' => (int) $settings['site_id'], 'pairing_token' => $token, 'platform_url' => home_url('/'), 'plugin_version' => VISION_PRIME_CONNECTOR_VERSION]),
            'sslverify' => false,
        ]);
        if (is_wp_error($response)) return $response;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || empty($body['secret'])) return new WP_Error('vision_prime_pairing_failed', 'Pairing failed.');
        return $body;
    }

    /**
     * Sign a request body for the platform (HMAC-SHA256 over method, path, timestamp, nonce, body hash).
     *
     * The platform verifies with Laravel's request->path(), which has NO leading slash,
     * so the path must be signed without one (e.g. `connector/health`).
     *
     * Refuses to sign when the connector file has been tampered with.
     *
     * @return array{headers: array, body: string}|WP_Error
     */
    public static function signed_request(array $settings, string $method, string $path, array $body): array|WP_Error {
        if (VP_Guard::tampered()) {
            return new WP_Error('vision_prime_tampered', 'Connector integrity check failed; refusing to sign.');
        }
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $encoded = wp_json_encode($body);
        $payload = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $encoded);
        $signature = hash_hmac('sha256', $payload, $settings['secret']);
        return [
            'headers' => ['Content-Type' => 'application/json', 'X-VP-Timestamp' => $timestamp, 'X-VP-Nonce' => $nonce, 'X-VP-Signature' => $signature],
            'body' => $encoded,
        ];
    }

    public static function signed_health(array $settings): array|WP_Error {
        $signed = self::signed_request($settings, 'POST', 'connector/health', [
            'site_id' => (int) $settings['site_id'],
            'plugin_version' => VISION_PRIME_CONNECTOR_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'rest_api' => true,
            'integrity_hash' => VP_Guard::file_hash(),
            'tampered' => VP_Guard::is_tampered_flag(),
        ]);
        if (is_wp_error($signed)) return $signed;
        return wp_remote_post(rtrim($settings['platform_url'], '/') . '/connector/health', ['timeout' => 20, 'headers' => $signed['headers'], 'body' => $signed['body'], 'sslverify' => false]);
    }

    /**
     * Report a command execution result back to the platform.
     *
     * @param array $settings Plugin settings.
     * @param array{site_id:int,idempotency_key:string,status:string,result:?array,error:?string} $result
     * @return array|WP_Error wp_remote_post response.
     */
    public static function send_command_result(array $settings, array $result): array|WP_Error {
        $result['integrity_hash'] = VP_Guard::file_hash();
        $result['tampered'] = VP_Guard::is_tampered_flag();
        $signed = self::signed_request($settings, 'POST', 'connector/command-result', $result);
        if (is_wp_error($signed)) return $signed;
        return wp_remote_post(rtrim($settings['platform_url'], '/') . '/connector/command-result', ['timeout' => 20, 'headers' => $signed['headers'], 'body' => $signed['body'], 'sslverify' => false]);
    }
}
final class VP_Request_Verifier {
    public static function verify(WP_REST_Request $request): bool|WP_Error {
        if (VP_Guard::tampered()) {
            return new WP_Error('vision_prime_tampered', 'Connector integrity check failed.', ['status' => 403]);
        }
        $settings = VP_Secret::unlock(get_option('vision_prime_connector', []));
        $secret = $settings['secret'] ?? '';
        $timestamp = (string) $request->get_header('x-vp-timestamp');
        $nonce = (string) $request->get_header('x-vp-nonce');
        $signature = (string) $request->get_header('x-vp-signature');
        if ($secret === '' || $timestamp === '' || $nonce === '' || $signature === '') return new WP_Error('vision_prime_unauthorized', 'Missing connector signature.', ['status'=>401]);
        if (abs(time() - (int)$timestamp) > 300) return new WP_Error('vision_prime_expired', 'Expired connector request.', ['status'=>401]);
        $key = 'vision_prime_nonce_' . hash('sha256', $nonce);
        if (get_transient($key)) return new WP_Error('vision_prime_replay', 'Replay request rejected.', ['status'=>409]);
        // The platform signs requests with the route as matched, e.g. /vision-prime/v1/content (leading slash included).
        $path = (string) $request->get_route();
        $payload = strtoupper($request->get_method())."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $request->get_body());
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) return new WP_Error('vision_prime_invalid_signature', 'Invalid connector signature.', ['status'=>401]);
        set_transient($key, '1', 10 * MINUTE_IN_SECONDS);
        return true;
    }
}
final class Vision_Prime_Connector {
    private const OPTION = VISION_PRIME_OPTION;

    public function __construct() {
        add_action('admin_menu', [$this, 'settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vision_prime_pair', [$this, 'pair']);
        add_action('admin_post_vision_prime_health', [$this, 'health_check']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_settings(): void {
        register_setting('vision-prime', self::OPTION, ['sanitize_callback' => [$this, 'sanitize_settings']]);
    }

    public function sanitize_settings(array $value): array {
        return ['platform_url' => esc_url_raw($value['platform_url'] ?? ''), 'site_id' => absint($value['site_id'] ?? 0), 'secret' => sanitize_text_field($value['secret'] ?? '')];
    }

    public function settings_page(): void {
        // Top-level menu with the brand icon so the plugin is visible in the
        // admin sidebar (WP 7 no longer renders icons for self-hosted plugins
        // anywhere else — not in the plugins list, not in the details modal).
        add_menu_page('Vision Prime', 'Vision Prime', 'manage_options', 'vision-prime', [$this, 'render_settings'], self::menu_icon(), 3);
    }

    private static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><defs><linearGradient id="vp" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4338CA"/><stop offset="1" stop-color="#8B5CF6"/></linearGradient></defs><rect width="20" height="20" rx="4" fill="url(#vp)"/><text x="10" y="13.5" font-family="Arial,sans-serif" font-size="9" font-weight="bold" fill="#ffffff" text-anchor="middle">VP</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function render_settings(): void {
        if (! current_user_can('manage_options')) return;
        $settings = get_option(self::OPTION, []);
        $connected = ! empty($settings['secret']);
        $tamp = VP_Guard::tampered(); ?>
        <style>
            .vp-wrap{max-width:720px;margin:20px auto;font-family:Tahoma,sans-serif}
            .vp-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;margin-bottom:16px}
            .vp-card h2{margin:0 0 16px;font-size:18px;color:#1e293b}
            .vp-field{margin-bottom:16px}
            .vp-field label{display:block;font-weight:600;margin-bottom:6px;color:#334155}
            .vp-field input[type=text],.vp-field input[type=password]{width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;direction:ltr;text-align:left;box-sizing:border-box}
            .vp-field .hint{color:#64748b;font-size:12px;margin-top:4px}
            .vp-status{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:16px}
            .vp-status.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
            .vp-status.off{background:#fefce8;border:1px solid #fde68a;color:#92400e}
            .vp-status.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
            .vp-step{display:flex;gap:12px;align-items:flex-start;margin-bottom:12px}
            .vp-step-num{width:28px;height:28px;border-radius:50%;background:#4338ca;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0}
            .vp-step-text{flex:1;color:#475569;font-size:14px;line-height:1.7}
        </style>
        <div class="vp-wrap">
            <div style="text-align:center;margin-bottom:24px">
                <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#4338CA,#8B5CF6);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:bold">VP</div>
                <h1 style="margin:12px 0 4px;font-size:22px;color:#1e293b">اتصال وردپرس به ویژن پرایم</h1>
                <p style="color:#64748b;font-size:14px">همگام‌سازی خودکار محتوا و مدیریت سئو</p>
            </div>

            <?php if ($notice = get_transient('vision_prime_notice')) { delete_transient('vision_prime_notice'); ?>
                <div class="vp-status ok"><?php echo esc_html($notice); ?></div>
            <?php } ?>

            <?php if ($tamp) { ?>
                <div class="vp-status err">⚠️ بررسی یکپارچگی فایل ناموفق بود. لطفاً افزونه را مجدداً نصب کنید.</div>
            <?php } ?>

            <div class="vp-status <?php echo $connected ? 'ok' : 'off'; ?>">
                <?php if ($connected) { ?>
                    ✅ <strong>وضعیت: متصل</strong> — اتصال با موفقیت برقرار شده است.
                <?php } else { ?>
                    ⏳ <strong>وضعیت: متصل نیست</strong> — مراحل زیر را انجام دهید.
                <?php } ?>
            </div>

            <?php if (! $connected) { ?>
            <div class="vp-card">
                <h2>📋 راهنمای اتصال (گام به گام)</h2>
                <div class="vp-step"><div class="vp-step-num">۱</div><div class="vp-step-text">وارد پنل مدیریت ویژن پرایم شوید و از بخش <strong>سایت‌ها</strong>، سایت خود را انتخاب کنید.</div></div>
                <div class="vp-step"><div class="vp-step-num">۲</div><div class="vp-step-text">در صفحه اتصال وردپرس، روی <strong>«صدور کد اتصال»</strong> کلیک کنید تا توکن ساخته شود.</div></div>
                <div class="vp-step"><div class="vp-step-num">۳</div><div class="vp-step-text">اطلاعات زیر را از پنل کپی کنید و در فرم پایین وارد کنید.</div></div>
                <div class="vp-step"><div class="vp-step-num">۴</div><div class="vp-step-text">توکن را در فیلد «کد اتصال» وارد کرده و دکمه «اتصال» را بزنید.</div></div>
            </div>
            <?php } ?>

            <div class="vp-card">
                <h2>⚙️ تنظیمات اتصال</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('vision-prime'); ?>
                    <div class="vp-field">
                        <label>آدرس پلتفرم (Platform URL)</label>
                        <input type="text" name="<?php echo esc_attr(self::OPTION); ?>[platform_url]" value="<?php echo esc_attr($settings['platform_url'] ?? ''); ?>" placeholder="https://visionprime-suite.ir" dir="ltr">
                        <div class="hint">آدرس سایت ویژن پرایم را وارد کنید (بدون اسلش انتهایی)</div>
                    </div>
                    <div class="vp-field">
                        <label>شناسه سایت (Site ID)</label>
                        <input type="text" name="<?php echo esc_attr(self::OPTION); ?>[site_id]" value="<?php echo esc_attr($settings['site_id'] ?? ''); ?>" placeholder="مثلاً 2" dir="ltr">
                        <div class="hint">عدد شناسه سایت از پنل مدیریت ویژن پرایم</div>
                    </div>
                    <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', false, ['style' => 'background:#4338ca;border-color:#4338ca']); ?>
                </form>
            </div>

            <div class="vp-card">
                <h2>🔗 جفت‌سازی (اتصال)</h2>
                <?php if (! $connected) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vision_prime_pair">
                    <?php wp_nonce_field('vision_prime_pair'); ?>
                    <div class="vp-field">
                        <label>کد اتصال (Pairing Token)</label>
                        <input type="password" name="pairing_token" autocomplete="off" placeholder="کد ۶۴ کاراکتری را اینجا وارد کنید" dir="ltr">
                        <div class="hint">کد را از پنل مدیریت ویژن پرایم کپی کنید</div>
                    </div>
                    <?php submit_button('اتصال با ویژن پرایم', 'primary', 'submit', false, ['style' => 'background:#4338ca;border-color:#4338ca']); ?>
                </form>
                <?php } else { ?>
                <p style="color:#166534;font-weight:600">✅ این سایت قبلاً با موفقیت متصل شده است.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                    <input type="hidden" name="action" value="vision_prime_health">
                    <?php wp_nonce_field('vision_prime_health'); ?>
                    <?php submit_button('بررسی سلامت اتصال', 'secondary', 'submit', false); ?>
                </form>
                <?php } ?>
            </div>
        </div>
        <?php
    }


        public function pair(): void {
        if (! current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('vision_prime_pair');
        $settings = get_option(self::OPTION, []);
        $token = sanitize_text_field($_POST['pairing_token'] ?? '');
        $result = VP_API_Client::pair($settings, $token);
        if (is_wp_error($result)) { set_transient('vision_prime_notice', $result->get_error_message(), 60); }
        else { $settings['secret'] = VP_Secret::encrypt($result['secret']); update_option(self::OPTION, $settings, false); set_transient('vision_prime_notice', '✅ اتصال با موفقیت انجام شد.', 60); }
        wp_safe_redirect(admin_url('admin.php?page=vision-prime'));
        exit;
    }

    public function health_check(): void {
        if (! current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('vision_prime_health');
        $settings = VP_Secret::unlock(get_option(self::OPTION, []));
        $result = empty($settings['secret']) ? new WP_Error('vision_prime_not_connected', 'سایت هنوز متصل نشده است.') : VP_API_Client::signed_health($settings);
        $message = is_wp_error($result) ? $result->get_error_message() : (wp_remote_retrieve_response_code($result) === 200 ? '✅ بررسی سلامت موفقیت‌آمیز بود.' : '❌ بررسی سلامت ناموفق بود.');
        set_transient('vision_prime_notice', $message, 60);
        wp_safe_redirect(admin_url('admin.php?page=vision-prime'));
        exit;
    }

    public function register_routes(): void {
        register_rest_route('vision-prime/v1', '/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => '__return_true']);
        register_rest_route('vision-prime/v1', '/content', ['methods' => 'GET', 'callback' => [$this, 'content'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
        register_rest_route('vision-prime/v1', '/commands', ['methods' => 'POST', 'callback' => [$this, 'commands'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
        register_rest_route('vision-prime/v1', '/rollback', ['methods' => 'POST', 'callback' => [$this, 'rollback'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
        register_rest_route('vision-prime/v1', '/product-info', ['methods' => 'POST', 'callback' => [$this, 'product_info'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
        register_rest_route('vision-prime/v1', '/taxonomies', ['methods' => 'GET', 'callback' => [$this, 'taxonomies'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
        register_rest_route('vision-prime/v1', '/media', ['methods' => 'POST', 'callback' => [$this, 'media'], 'permission_callback' => [VP_Request_Verifier::class, 'verify']]);
    }

    public function health(): WP_REST_Response {
        return new WP_REST_Response(['plugin_version' => VISION_PRIME_CONNECTOR_VERSION, 'wordpress_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'rest_api' => true, 'integrity_hash' => VP_Guard::file_hash(), 'tampered' => VP_Guard::is_tampered_flag()]);
    }

    public function content(WP_REST_Request $request): WP_REST_Response {
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $query = new WP_Query(['post_type' => ['post','page','product'], 'post_status' => ['publish','draft','private'], 'posts_per_page' => $per_page, 'paged' => $page, 'orderby' => 'modified', 'order' => 'DESC']);
        $items = array_map(function (WP_Post $post): array {
            $content = (string) $post->post_content;
            preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $matches);
            $headings = array_map('wp_strip_all_tags', $matches[1] ?? []);
            return ['id' => $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post), 'slug' => $post->post_name, 'type' => $post->post_type, 'status' => $post->post_status, 'modified_at' => get_post_modified_time('c', true, $post), 'meta_title' => self::read_meta($post->ID, 'title'), 'meta_description' => self::read_meta($post->ID, 'description'), 'headings' => $headings, 'word_count' => str_word_count(wp_strip_all_tags($content)), 'content_hash' => hash('sha256', $content), 'content' => $content];
        }, $query->posts);
        return new WP_REST_Response(['data' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts, 'total_pages' => (int) $query->max_num_pages]);
    }

    /**
     * Execute a command dispatched by the Vision Prime platform.
     *
     * The platform signs the request (HMAC + timestamp + nonce) and waits for
     * this endpoint's response, so we must NEVER block on the result callback.
     * The result is reported back asynchronously to /connector/command-result.
     */
    public function commands(WP_REST_Request $request): WP_REST_Response {
        if (VP_Guard::tampered()) {
            return new WP_REST_Response(['error' => 'integrity check failed'], 403);
        }
        $params = $request->get_json_params();
        $settings = VP_Secret::unlock(get_option(self::OPTION, []));
        $idempotency_key = sanitize_key((string) ($params['idempotency_key'] ?? ''));
        $type = sanitize_key((string) ($params['type'] ?? ''));
        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : [];

        // Never execute the same command twice.
        $dedupe_key = 'vision_prime_cmd_' . md5($idempotency_key);
        if ($idempotency_key !== '' && get_transient($dedupe_key)) {
            return new WP_REST_Response(['status' => 'ack', 'deduplicated' => true], 200);
        }

        try {
            $result = $this->execute_command($type, $payload);
            $status = 'executed';
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
            $status = 'failed';
        }

        if ($idempotency_key !== '') {
            set_transient($dedupe_key, '1', DAY_IN_SECONDS);
        }

        VP_API_Client::send_command_result($settings, [
            'site_id' => (int) $settings['site_id'],
            'idempotency_key' => $idempotency_key,
            'status' => $status,
            'result' => $status === 'executed' ? $result : null,
            'error' => $status === 'failed' ? ($result['error'] ?? 'unknown error') : null,
        ]);

        return new WP_REST_Response(['status' => 'ack'], 200);
    }

    /**
     * Restore a previously executed change from its pre-change snapshot (D-013 auto rollback).
     *
     * Payload: { command_id, type, previous, post_id, idempotency_key }
     * The `previous` value comes from the encrypted rollback_snapshots stored server-side.
     * The result is reported back asynchronously with status=rolled_back so the platform
     * can mark the command and measure the rollback in impact_events.
     */
    public function rollback(WP_REST_Request $request): WP_REST_Response {
        if (VP_Guard::tampered()) {
            return new WP_REST_Response(['error' => 'integrity check failed'], 403);
        }
        $params = $request->get_json_params();
        $settings = VP_Secret::unlock(get_option(self::OPTION, []));
        $idempotency_key = sanitize_key((string) ($params['idempotency_key'] ?? ''));
        $dedupe_key = 'vision_prime_rb_' . md5($idempotency_key);
        if ($idempotency_key !== '' && get_transient($dedupe_key)) {
            return new WP_REST_Response(['status' => 'ack', 'deduplicated' => true], 200);
        }

        try {
            // The platform sends post_id at the TOP level of the rollback payload;
            // restore_command also accepts it nested inside `previous` for compatibility.
            $result = $this->restore_command((string) ($params['type'] ?? ''), is_array($params['previous'] ?? null) ? $params['previous'] : [], absint($params['post_id'] ?? 0));
            $status = 'rolled_back';
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
            $status = 'failed';
        }

        if ($idempotency_key !== '') {
            set_transient($dedupe_key, '1', DAY_IN_SECONDS);
        }

        VP_API_Client::send_command_result($settings, [
            'site_id' => (int) $settings['site_id'],
            'idempotency_key' => $idempotency_key,
            'status' => $status,
            'result' => $status === 'rolled_back' ? $result : null,
            'error' => $status === 'failed' ? ($result['error'] ?? 'unknown error') : null,
        ]);

        // Rollback is synchronous on the platform side: it needs the actual restore
        // outcome here (not only the async command-result) so it can mark the command
        // rolled_back only when WordPress confirms the mutation was really undone.
        return new WP_REST_Response(['status' => 'ack', 'restored' => $status === 'rolled_back', 'result' => $result], 200);
    }

    /**
     * Restore the pre-change values of an executed command from its snapshot.
     *
     * @return array<string,mixed>
     * @throws RuntimeException When the target post is missing or the type is unknown.
     */
    private function restore_command(string $type, array $previous, int $fallback_post_id = 0): array {
        $post_id = absint($previous['post_id'] ?? 0);
        if ($post_id === 0) {
            $post_id = $fallback_post_id;
        }
        if ($post_id === 0) {
            throw new RuntimeException('Rollback payload has no valid post_id.');
        }
        if (get_post($post_id) === null) {
            throw new RuntimeException('Target post does not exist: ' . $post_id);
        }
        switch ($type) {
            case 'update_meta_title':
                $keys = self::meta_keys('title');
                $restored = sanitize_text_field((string) ($previous['title'] ?? ''));
                foreach ($keys as $key) { update_post_meta($post_id, $key, $restored); }
                return ['post_id' => $post_id, 'restored' => true, 'meta_keys' => $keys];
            case 'update_meta_description':
                $keys = self::meta_keys('description');
                $restored = sanitize_text_field((string) ($previous['description'] ?? ''));
                foreach ($keys as $key) { update_post_meta($post_id, $key, $restored); }
                return ['post_id' => $post_id, 'restored' => true, 'meta_keys' => $keys];
            case 'update_content':
            case 'update_product_description':
                $content = (string) ($previous['content'] ?? '');
                $updated = wp_update_post(['ID' => $post_id, 'post_content' => wp_kses_post($content)], true);
                if (is_wp_error($updated) || (int) $updated === 0) {
                    throw new RuntimeException('Failed to restore content for post ' . $post_id);
                }
                return ['post_id' => $post_id, 'restored' => true, 'content_length' => mb_strlen($content)];
            case 'update_product_title':
                $restored = sanitize_text_field((string) ($previous['title'] ?? ''));
                $updated = wp_update_post(['ID' => $post_id, 'post_title' => $restored], true);
                if (is_wp_error($updated) || (int) $updated === 0) {
                    throw new RuntimeException('Failed to restore product title for post ' . $post_id);
                }
                return ['post_id' => $post_id, 'restored' => true];
            case 'publish_new_article':
                // بازگشت مقالهٔ جدید = حذف پست ساخته‌شده (فقط در صورتی که واقعاً توسط VP ساخته شده باشد).
                $created_flag = (string) get_post_meta($post_id, '_vp_created_by', true);
                if ($created_flag !== 'vision-prime') {
                    throw new RuntimeException('Refusing to delete post ' . $post_id . ': not created by Vision Prime.');
                }
                $deleted = wp_delete_post($post_id, true);
                if ($deleted === false || $deleted === null) {
                    throw new RuntimeException('Failed to delete article post ' . $post_id);
                }
                return ['post_id' => $post_id, 'restored' => true, 'deleted' => true];
            default:
                throw new RuntimeException('Unknown command type for rollback: ' . $type);
        }
    }

    /**
     * @return array<string,mixed>
     * @throws RuntimeException When the target post is missing or the type is unknown.
     */
    private function execute_command(string $type, array $payload): array {
        $post_id = absint($payload['post_id'] ?? 0);
        if ($post_id === 0 && ! empty($payload['url'])) {
            $by_url = get_page_by_path($this->url_to_path((string) $payload['url']), OBJECT, ['post', 'page']);
            $post_id = $by_url instanceof WP_Post ? $by_url->ID : 0;
        }
        // publish_new_article پستِ جدید می‌سازد و به post_id/url از قبل موجود نیاز ندارد.
        if ($post_id === 0 && $type !== 'publish_new_article') {
            throw new RuntimeException('Command payload has no valid post_id or url target.');
        }
        switch ($type) {
            case 'update_meta_title':
                $keys = self::meta_keys('title');
                $previous = self::read_meta($post_id, 'title');
                $new = sanitize_text_field((string) ($payload['title'] ?? ''));
                foreach ($keys as $key) { update_post_meta($post_id, $key, $new); }
                return ['post_id' => $post_id, 'previous' => ['title' => $previous], 'new' => $new, 'meta_keys' => $keys];
            case 'update_meta_description':
                $keys = self::meta_keys('description');
                $previous = self::read_meta($post_id, 'description');
                $new = sanitize_text_field((string) ($payload['description'] ?? ''));
                foreach ($keys as $key) { update_post_meta($post_id, $key, $new); }
                return ['post_id' => $post_id, 'previous' => ['description' => $previous], 'new' => $new, 'meta_keys' => $keys];
            case 'update_content':
                $new = (string) ($payload['content'] ?? '');
                if ($new === '') {
                    throw new RuntimeException('Command payload has no content.');
                }
                $previous = get_post_field('post_content', $post_id);
                $updated = wp_update_post(['ID' => $post_id, 'post_content' => wp_kses_post($new)], true);
                if (is_wp_error($updated) || (int) $updated === 0) {
                    throw new RuntimeException('Failed to update content for post ' . $post_id);
                }
                // Full previous content is required for a lossless rollback snapshot (D-013).
                return ['post_id' => $post_id, 'previous' => ['content' => (string) $previous], 'new_length' => mb_strlen($new), 'updated' => (int) $updated];
            case 'update_product_title':
                $this->assert_product($post_id);
                $new = sanitize_text_field((string) ($payload['title'] ?? ''));
                if ($new === '') {
                    throw new RuntimeException('Command payload has no title.');
                }
                $previous = get_post_field('post_title', $post_id);
                $updated = wp_update_post(['ID' => $post_id, 'post_title' => $new], true);
                if (is_wp_error($updated) || (int) $updated === 0) {
                    throw new RuntimeException('Failed to update product title for post ' . $post_id);
                }
                // previous باید آرایه باشد تا با قرارداد rollback (RollbackCommand و restore_command) سازگار بماند.
                return ['post_id' => $post_id, 'previous' => ['title' => (string) $previous], 'new' => $new];
            case 'update_product_description':
                $this->assert_product($post_id);
                $new = (string) ($payload['description'] ?? '');
                if ($new === '') {
                    throw new RuntimeException('Command payload has no description.');
                }
                $previous = get_post_field('post_content', $post_id);
                $updated = wp_update_post(['ID' => $post_id, 'post_content' => wp_kses_post($new)], true);
                if (is_wp_error($updated) || (int) $updated === 0) {
                    throw new RuntimeException('Failed to update product description for post ' . $post_id);
                }
                // Full previous content is required for a lossless rollback snapshot (D-013).
                return ['post_id' => $post_id, 'previous' => ['content' => (string) $previous], 'new_length' => mb_strlen($new)];
            case 'publish_new_article':
                $title = sanitize_text_field((string) ($payload['title'] ?? ''));
                $content = (string) ($payload['content'] ?? '');
                if ($title === '' || $content === '') {
                    throw new RuntimeException('Command payload has no title or content.');
                }
                $slug = sanitize_title((string) ($payload['slug'] ?? ''));
                // پیش‌نویس محصول → پست ووکامرس (post_type=product)؛ مقاله → پست معمولی
                $content_type = (string) ($payload['content_type'] ?? 'article');
                $post_type = $content_type === 'product' ? 'product' : 'post';
                // وضعیت پست از فرمان می‌آید (publish|draft|pending) — پیش‌فرض publish
                $requested_status = sanitize_key((string) ($payload['status'] ?? 'publish'));
                $post_status = in_array($requested_status, ['publish', 'draft', 'pending'], true) ? $requested_status : 'publish';
                $post_id = wp_insert_post([
                    'post_type' => $post_type,
                    'post_status' => $post_status,
                    'post_title' => $title,
                    'post_content' => wp_kses_post($content),
                    'post_name' => $slug !== '' ? $slug : null,
                ], true);
                if (is_wp_error($post_id) || (int) $post_id === 0) {
                    throw new RuntimeException('Failed to create article post: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'));
                }
                // علامت امنیت: پست‌هایی که VP ساخته — rollback فقط همین‌ها را حذف می‌کند.
                update_post_meta((int) $post_id, '_vp_created_by', 'vision-prime');

                // v1.4: دسته‌ها و برچسب‌ها (id یا نام — ساخت خودکار) + تصویر شاخص/گالری
                // v1.4.1: برای محصول، تاکسونومی ووکامرس (product_cat/product_tag) استفاده می‌شود
                $categories = is_array($payload['categories'] ?? null) ? $payload['categories'] : [];
                $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
                $cat_tax = ($post_type === 'product' && self::woo_active()) ? 'product_cat' : 'category';
                $tag_tax = ($post_type === 'product' && self::woo_active()) ? 'product_tag' : 'post_tag';
                $cat_ids = self::resolve_terms($categories, $cat_tax);
                $tag_ids = self::resolve_terms($tags, $tag_tax);
                if ($cat_ids !== []) wp_set_object_terms($post_id, $cat_ids, $cat_tax, true);
                if ($tag_ids !== []) wp_set_object_terms($post_id, $tag_ids, $tag_tax, true);

                $featured = absint($payload['featured_media_id'] ?? 0);
                if ($featured > 0) set_post_thumbnail($post_id, $featured);
                $gallery = array_filter(array_map('absint', is_array($payload['gallery_media_ids'] ?? null) ? $payload['gallery_media_ids'] : []));
                if ($gallery !== [] && $post_type === 'product') update_post_meta((int) $post_id, '_product_image_gallery', implode(',', $gallery));
                // Meta SEO (title/description) را روی پست جدید می‌نویسیم تا همانجا دیده شود.
                $meta_title = sanitize_text_field((string) ($payload['meta_title'] ?? ''));
                if ($meta_title !== '') {
                    foreach (self::meta_keys('title') as $key) { update_post_meta((int) $post_id, $key, $meta_title); }
                }
                // اسکیمای پیشنهادی (Article/FAQPage) را به‌صورت JSON-LD در meta ذخیره می‌کنیم.
                $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : [];
                if ($schema !== []) {
                    update_post_meta((int) $post_id, '_vp_schema_jsonld', wp_json_encode($schema, JSON_UNESCAPED_UNICODE));
                }
                // Rollback برای مقاله/محصول جدید = حذف پست ساخته‌شده؛ snapshot فقط post_id را نگه می‌دارد.
                return ['post_id' => (int) $post_id, 'previous' => ['created' => true, 'post_id' => (int) $post_id, 'post_type' => $post_type], 'new_length' => mb_strlen($content), 'new_title' => $title];
            default:
                throw new RuntimeException('Unknown command type: ' . $type);
        }
    }

    /**
     * Return real WooCommerce price/stock for a product (or a plain post), so
     * reviewers can see the live values before approving a product publish.
     *
     * Payload: { post_id } or { slug } (WP post id or product slug).
     * Responds synchronously (no async callback) with the product data.
     */
    /**
     * فهرست دسته‌ها و برچسب‌ها (امضاشده) — جایگزین REST عمومی در همگام‌سازی.
     */
    public function taxonomies(WP_REST_Request $request): WP_REST_Response {
        $map_terms = static function (array $terms): array {
            $out = [];
            foreach ($terms as $t) {
                if (!is_object($t)) continue;
                $out[] = ['id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count];
            }
            return $out;
        };
        $data = [
            'categories' => $map_terms(get_categories(['hide_empty' => false, 'number' => 200])),
            'tags' => $map_terms(get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 200])),
        ];
        // ووکامرس: دسته/برچسب محصول (برای استودیوی محصول)
        if (self::woo_active()) {
            $data['product_cats'] = $map_terms(get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200]));
            $data['product_tags'] = $map_terms(get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 200]));
        }
        return new WP_REST_Response($data);
    }

    /**
     * آپلود رسانه (امضاشده): file_b64 یا download_url + alt → media_id + url.
     */
    public function media(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $alt = sanitize_text_field((string) ($params['alt'] ?? ''));
        $b64 = (string) ($params['file_b64'] ?? '');
        $download_url = esc_url_raw((string) ($params['download_url'] ?? ''));

        // در وردپرس واقعی فایل‌های ادمین لود می‌شوند؛ اگر توابع از قبل تعریف باشند (محیط تست) رد می‌شویم.
        if (! function_exists('wp_upload_bits')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        if (! function_exists('media_handle_sideload')) { require_once ABSPATH . 'wp-admin/includes/media.php'; }
        if (! function_exists('wp_generate_attachment_metadata')) { require_once ABSPATH . 'wp-admin/includes/image.php'; }

        try {
            if ($b64 !== '') {
                $raw = base64_decode($b64, true);
                if ($raw === false || strlen($raw) < 64) throw new RuntimeException('file_b64 نامعتبر است.');
                $filename = 'vp-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.png';
                $upload = wp_upload_bits($filename, null, $raw);
                if (!empty($upload['error'])) throw new RuntimeException('آپلود ناموفق: ' . $upload['error']);
                $file = $upload['file'];
                $attachment_id = wp_insert_attachment([
                    'post_mime_type' => 'image/png',
                    'post_title' => ($alt !== '' ? $alt : 'vision-prime-image'),
                    'post_status' => 'inherit',
                ], $file);
                if (is_wp_error($attachment_id) || (int) $attachment_id === 0) throw new RuntimeException('ثبت پیوست ناموفق.');
                wp_update_attachment_metadata((int) $attachment_id, wp_generate_attachment_metadata((int) $attachment_id, $file));
            } elseif ($download_url !== '') {
                $tmp = download_url($download_url, 120);
                if (is_wp_error($tmp)) throw new RuntimeException('دانلود ناموفق: ' . $tmp->get_error_message());
                $ext = strtolower(pathinfo((string) wp_parse_url($download_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
                $file_array = ['name' => 'vp-' . gmdate('Ymd-His') . '.' . $ext, 'tmp_name' => $tmp];
                $id = media_handle_sideload($file_array, 0, $alt);
                if (is_wp_error($id)) { @unlink($tmp); throw new RuntimeException('sideload ناموفق: ' . $id->get_error_message()); }
                $attachment_id = $id;
            } else {
                throw new RuntimeException('file_b64 یا download_url لازم است.');
            }

            if ($alt !== '') update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $alt);

            return new WP_REST_Response(['media_id' => (int) $attachment_id, 'url' => (string) wp_get_attachment_url((int) $attachment_id)], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 422);
        }
    }


    /** آیا ووکامرس فعال است؟ */
    private static function woo_active(): bool {
        return class_exists("WooCommerce");
    }

    /** تبدیل ورودی دسته/برچسب (id یا نام) به term_id — با ساخت خودکار. */
    private static function resolve_terms(array $input, string $taxonomy): array {
        $ids = [];
        foreach ($input as $item) {
            if (is_int($item) || ctype_digit((string) $item)) { $ids[] = (int) $item; continue; }
            $name = sanitize_text_field((string) $item);
            if ($name === '') continue;
            $term = get_term_by('name', $name, $taxonomy);
            if ($term === false) $term = get_term_by('slug', sanitize_title($name), $taxonomy);
            if ($term === false) {
                $created = wp_insert_term($name, $taxonomy);
                $ids[] = is_wp_error($created) ? 0 : (int) $created['term_id'];
            } else {
                $ids[] = (int) $term->term_id;
            }
        }
        return array_values(array_filter($ids));
    }

    public function product_info(WP_REST_Request $request): WP_REST_Response {
        if (VP_Guard::tampered()) {
            return new WP_REST_Response(['error' => 'integrity check failed'], 403);
        }
        $params = $request->get_json_params();
        $post_id = absint($params['post_id'] ?? 0);
        $slug = sanitize_title((string) ($params['slug'] ?? ''));
        $post = $post_id > 0 ? get_post($post_id) : null;
        if ($post === null && $slug !== '') {
            $found = get_page_by_path($slug, OBJECT, ['product', 'post']);
            if ($found instanceof WP_Post) {
                $post = $found;
                $post_id = (int) $found->ID;
            }
        }
        if ($post === null) {
            return new WP_REST_Response(['error' => 'product_not_found'], 404);
        }

        $is_product = get_post_type($post_id) === 'product';
        $price = $regular_price = $sale_price = null;
        $stock_quantity = null;
        $stock_status = null;
        $currency = null;
        $in_stock = null;

        // WooCommerce product — real price/stock from the product object.
        if ($is_product && function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            if ($product instanceof WC_Product) {
                $price = $product->get_price();
                $regular_price = $product->get_regular_price();
                $sale_price = $product->get_sale_price();
                $stock_quantity = $product->get_stock_quantity();
                $stock_status = $product->get_stock_status();
                $currency = get_woocommerce_currency();
                $in_stock = $product->is_in_stock();
            }
        } elseif ($is_product) {
            // No WooCommerce — fall back to raw post meta (still useful).
            $price = get_post_meta($post_id, '_price', true);
            $stock_quantity = get_post_meta($post_id, '_stock', true);
            $stock_status = get_post_meta($post_id, '_stock_status', true);
            $in_stock = $stock_status !== 'outofstock';
        }

        return new WP_REST_Response([
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'post_type' => get_post_type($post_id),
            'url' => get_permalink($post_id),
            'is_product' => $is_product,
            'price' => $price !== null && $price !== '' ? (string) $price : null,
            'regular_price' => $regular_price !== null && $regular_price !== '' ? (string) $regular_price : null,
            'sale_price' => $sale_price !== null && $sale_price !== '' ? (string) $sale_price : null,
            'currency' => $currency,
            'stock_quantity' => $stock_quantity !== null && $stock_quantity !== '' ? (int) $stock_quantity : null,
            'stock_status' => $stock_status,
            'in_stock' => $in_stock,
        ]);
    }

    /**
     * Ensure the target is an existing product (WooCommerce) before a product mutation.
     */
    private function assert_product(int $post_id): void
    {
        if (get_post($post_id) === null) {
            throw new RuntimeException('Target post does not exist: ' . $post_id);
        }
        if (get_post_type($post_id) !== 'product') {
            throw new RuntimeException('Target post is not a product (type: ' . (get_post_type($post_id) ?: 'unknown') . ').');
        }
    }

    private static function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '…';
    }

    private function url_to_path(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return trim($path, '/');
    }

    /**
     * Which SEO engine(s) are installed and active on this site.
     *
     * Rank Math and Yoast store their meta under different keys; we write to
     * every engine that is actually active so the platform's recommendations
     * always land where the site owner can see them.
     */
    private static function seo_engine(): string
    {
        $rankMath = defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper');
        $yoast = defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
        if ($rankMath && $yoast) { return 'both'; }
        if ($rankMath) { return 'rank_math'; }
        if ($yoast) { return 'yoast'; }
        return 'none';
    }

    /**
     * Meta keys to write for a field, according to the active SEO engine(s).
     * Falls back to the Yoast keys when no engine is detected (backwards
     * compatible with the original behaviour and bare WordPress installs).
     */
    private static function meta_keys(string $field): array
    {
        $rankMathKey = $field === 'title' ? 'rank_math_title' : 'rank_math_description';
        $yoastKey = $field === 'title' ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
        switch (self::seo_engine()) {
            case 'rank_math': return [$rankMathKey];
            case 'yoast': return [$yoastKey];
            case 'both': return [$rankMathKey, $yoastKey];
            default: return [$yoastKey];
        }
    }

    /**
     * Read the current meta value for a field, preferring Rank Math when set.
     */
    private static function read_meta(int $postId, string $field): string
    {
        $rankMathKey = $field === 'title' ? 'rank_math_title' : 'rank_math_description';
        $yoastKey = $field === 'title' ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
        $rankMath = get_post_meta($postId, $rankMathKey, true);
        if (is_string($rankMath) && $rankMath !== '') { return $rankMath; }
        $yoast = get_post_meta($postId, $yoastKey, true);
        return is_string($yoast) ? $yoast : '';
    }
}
new Vision_Prime_Connector();

