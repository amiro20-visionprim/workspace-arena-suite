<?php
/**
 * Vision Prime Connector — Traffic Telemetry (DataBridge قدم ۱)
 *
 * شمارش بازدید سمت سرور (بدون جاوااسکریپت، بدون کوکی شخصی) + گزارش روزانه
 * امضاشده به پلتفرم. وقتی Google Search Console در دسترس نیست (نت ملی/تحریم)،
 * این تلمتری جایگزین دادهٔ حلقهٔ اندازه‌گیری تأثیر انتشار می‌شود.
 *
 * شمارش: در init روی هر request عمومی غیر-مدیریتی، post_type=post|page|product
 * شمرده می‌شود (transient ساعتی per post، بدون کوکی — شمارش views).
 * ارسال: cron روزانه (wp_scheduled_daily / 02:30) کل دیروز را به
 * POST /connector/traffic با همان امضای HMAC connector می‌فرستد.
 *
 * Matomo: اگر WP-Matomo فعال باشد و Matomo_URL/Matomo_SITE_ID ست شده باشند،
 * از Matomo Reporting API (self-hosted) روزانه per-page views خوانده و به‌عنوان
 * منبع دقیق‌تر جایگزین شمارش داخلی می‌شود.
 */

defined('ABSPATH') || exit;

final class VP_Telemetry {

    public static function init(): void {
        // شمارش در هر request عمومی (نه admin-ajax/REST/feed/cron)
        add_action('wp', [self::class, 'maybe_count_view'], 5);
        // گزارش روزانه — 02:30 هر شب
        add_action('vp_traffic_daily_report', [self::class, 'send_daily_report']);
        if (! wp_next_scheduled('vp_traffic_daily_report')) {
            wp_schedule_event(strtotime('tomorrow 02:30') + 0, 'daily', 'vp_traffic_daily_report');
        }
    }

    /** آیا این request قابل شمارش است؟ فقط front-end روی post/page/product */
    public static function maybe_count_view(): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_preview()) {
            return;
        }
        // درخواست‌های REST وردپرس را شمارش نکن
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        $post_id = get_queried_object_id();
        if ($post_id <= 0) {
            return;
        }
        $type = get_post_type($post_id);
        if (! in_array($type, ['post', 'page', 'product'], true)) {
            return;
        }
        // بات‌ها را در حد امکان رد کن (شمارش سمت سرور دقیق‌ترین ممکن نیست؛ target: روند، نه عدد مطلق)
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|monitor|pingdom|uptime/i', $ua)) {
            return;
        }
        self::count_view($post_id);
    }

    private static function count_view(int $post_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'vp_traffic_daily';
        $day = current_time('Y-m-d');
        $hour = (int) current_time('G');

        // تجمع hourly bucket داخل یک ردیف روزانه per post — سنگین نیست و قفل کمتری می‌خورد
        $col = 'h' . $hour;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (post_id, day, {$col}, total, visitors_max, created_at, updated_at)
             VALUES (%d, %s, 1, 1, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE {$col} = {$col} + 1, total = total + 1, visitors_max = visitors_max + 1, updated_at = NOW()",
            $post_id, $day
        ));
    }

    /**
     * صفحات دارای بازدید دیروز + آمار؛ اولویت با Matomo اگر موجود باشد.
     * @return array<int, array{page_url:string,views:int,visitors:int,entrances:int}>
     */
    private static function collect_day(string $day): array {
        $matomo = self::matomo_day($day);
        if ($matomo !== null) {
            return $matomo; // منبع دقیق‌تر
        }
        return self::internal_day($day);
    }

    /** شمارش داخلی خود پلاگین */
    private static function internal_day(string $day): array {
        global $wpdb;
        $table = $wpdb->prefix . 'vp_traffic_daily';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, total, visitors_max FROM {$table} WHERE day = %s AND total > 0",
            $day
        ));
        $out = [];
        foreach ($rows as $r) {
            $url = get_permalink((int) $r->post_id);
            if (! is_string($url) || $url === '') {
                continue;
            }
            $out[] = [
                'page_url'  => $url,
                'views'     => (int) $r->total,
                'visitors'  => (int) $r->visitors_max, // تخمین سقف — سمت پلتفرم avg می‌شود
                'entrances' => 0, // سمت سرور قابل اندازه‌گیری دقیق نیست
            ];
        }

        return $out;
    }

    /** آداپتور Matomo سلف‌هاست (WP-Matomo plugin یا مقادیر ثابت) */
    private static function matomo_day(string $day): ?array {
        $url = self::matomo_url();
        $site_id = self::matomo_site_id();
        $token = self::matomo_token();
        if ($url === '' || $site_id === '' || $token === '') {
            return null;
        }
        $api = untrailingslashit($url) . '/index.php?module=API&format=json'
            . '&method=Actions.getPageUrls'
            . '&idSite=' . rawurlencode($site_id)
            . '&period=day&date=' . rawurlencode($day)
            . '&filter_limit=-1'
            . '&expanded=1&flat=1'
            . '&token_auth=' . rawurlencode($token);
        $resp = wp_remote_get($api, ['timeout' => 20]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (! is_array($data)) {
            return null;
        }
        $out = [];
        foreach ($data as $row) {
            $page_url = (string) ($row['url'] ?? '');
            if ($page_url === '') {
                continue;
            }
            // فقط صفحات محتوای خود سایت — URLهای tag/feed/category فیلتر شوند در پلتفرم
            $out[] = [
                'page_url'  => $page_url,
                'views'     => (int) ($row['nb_hits'] ?? 0),
                'visitors'  => (int) ($row['nb_uniq_visitors'] ?? ($row['nb_visitors'] ?? 0)),
                'entrances' => (int) ($row['entrances'] ?? 0),
            ];
        }

        return $out !== [] ? $out : null;
    }

    private static function matomo_url(): string {
        if (class_exists('WpMatomo') && defined('MATOMO_ANALYTICS_PATH')) {
            $opt = get_option('matomo_global_settings', []);
            return is_array($opt) ? (string) ($opt['matomo_url'] ?? '') : '';
        }

        return (string) (get_option('vp_matomo_url', '') ?: '');
    }

    private static function matomo_site_id(): string {
        return (string) (get_option('vp_matomo_site_id', '') ?: (defined('MATOMO_SITE_ID') ? (string) MATOMO_SITE_ID : ''));
    }

    private static function matomo_token(): string {
        return (string) (get_option('vp_matomo_token', '') ?: '');
    }

    /** گزارش روزانهٔ امضاشده — کل دیروز */
    public static function send_daily_report(): void {
        $settings = VP_Secret::unlock(get_option(VISION_PRIME_OPTION, []));
        if (empty($settings['secret']) || empty($settings['site_id']) || empty($settings['platform_url'])) {
            return; // متصل نیست — سکوت
        }
        $day = date('Y-m-d', strtotime('-1 day'));

        // منبع یک‌بار تعیین شود — Matomo دقیق‌تر است؛ در نبود آن شمارش داخلی
        $matomo_rows = self::matomo_day($day);
        $source = $matomo_rows !== null ? 'matomo' : 'plugin';
        $rows = $matomo_rows !== null ? $matomo_rows : self::internal_day($day);

        if ($rows === []) {
            return; // دیروز هیچ بازدیدی ثبت نشده — ارسال خالی بی‌فایده است
        }

        $batch_id = 'traffic-' . $day . '-' . md5(wp_json_encode([$settings['site_id'], $day, count($rows)]));

        $body = [
            'site_id' => (int) $settings['site_id'],
            'batch_id' => $batch_id,
            'day' => $day,
            'rows' => array_slice($rows, 0, 1000),
            'telemetry' => [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plugin_version' => VISION_PRIME_CONNECTOR_VERSION,
                'source' => $source,
            ],
        ];

        $signed = VP_API_Client::signed_request($settings, 'POST', 'connector/traffic', $body);
        if (is_wp_error($signed)) {
            return;
        }
        wp_remote_post(rtrim($settings['platform_url'], '/') . '/connector/traffic', [
            'timeout' => 20,
            'headers' => $signed['headers'],
            'body' => $signed['body'],
        ]);
        // نتیجه مهم نیست — idempotency سمت پلتفرم retry امن می‌کند
    }

    /** ساخت جدول شمارش داخلی هنگام فعال‌سازی */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'vp_traffic_daily';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            h0 INT UNSIGNED NOT NULL DEFAULT 0, h1 INT UNSIGNED NOT NULL DEFAULT 0,
            h2 INT UNSIGNED NOT NULL DEFAULT 0, h3 INT UNSIGNED NOT NULL DEFAULT 0,
            h4 INT UNSIGNED NOT NULL DEFAULT 0, h5 INT UNSIGNED NOT NULL DEFAULT 0,
            h6 INT UNSIGNED NOT NULL DEFAULT 0, h7 INT UNSIGNED NOT NULL DEFAULT 0,
            h8 INT UNSIGNED NOT NULL DEFAULT 0, h9 INT UNSIGNED NOT NULL DEFAULT 0,
            h10 INT UNSIGNED NOT NULL DEFAULT 0, h11 INT UNSIGNED NOT NULL DEFAULT 0,
            h12 INT UNSIGNED NOT NULL DEFAULT 0, h13 INT UNSIGNED NOT NULL DEFAULT 0,
            h14 INT UNSIGNED NOT NULL DEFAULT 0, h15 INT UNSIGNED NOT NULL DEFAULT 0,
            h16 INT UNSIGNED NOT NULL DEFAULT 0, h17 INT UNSIGNED NOT NULL DEFAULT 0,
            h18 INT UNSIGNED NOT NULL DEFAULT 0, h19 INT UNSIGNED NOT NULL DEFAULT 0,
            h20 INT UNSIGNED NOT NULL DEFAULT 0, h21 INT UNSIGNED NOT NULL DEFAULT 0,
            h22 INT UNSIGNED NOT NULL DEFAULT 0, h23 INT UNSIGNED NOT NULL DEFAULT 0,
            total INT UNSIGNED NOT NULL DEFAULT 0,
            visitors_max INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_post_day (post_id, day),
            KEY day_idx (day)
        ) {$charset};");
    }
}
