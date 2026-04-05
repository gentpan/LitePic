<?php
declare(strict_types=1);

/**
 * Plugin Name: LitePic External Uploader
 * Description: Upload images to LitePic image bed and manage images in WordPress admin.
 * Version: 1.2.1
 * Author: gentpan
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LitePic_External_Uploader {
    private const OPTION_KEY = 'litepic_external_uploader_options';

    public static function init(): void {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_menu', [self::class, 'add_admin_pages']);
        add_action('media_buttons', [self::class, 'render_editor_button'], 20);
        add_action('admin_footer', [self::class, 'render_footer_script']);
    }

    public static function register_settings(): void {
        register_setting('litepic_external_uploader_group', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => [
                'host' => '',
                'credential' => '',
            ],
        ]);
    }

    public static function sanitize_options($input): array {
        $output = [
            'host' => '',
            'credential' => '',
        ];

        if (is_array($input)) {
            $host = trim((string)($input['host'] ?? ''));
            $credential = trim((string)($input['credential'] ?? ''));

            // 兼容旧字段
            if ($host === '' && !empty($input['endpoint'])) {
                $host = trim((string)$input['endpoint']);
            }
            if ($credential === '' && !empty($input['token'])) {
                $credential = trim((string)$input['token']);
            }
            if ($credential === '' && !empty($input['api_key'])) {
                $credential = trim((string)$input['api_key']);
            }

            $output['host'] = esc_url_raw(self::endpoint_base($host));
            $output['credential'] = sanitize_text_field($credential);
        }

        return $output;
    }

    private static function get_options(): array {
        $options = get_option(self::OPTION_KEY, []);
        if (!is_array($options)) {
            return ['host' => '', 'credential' => ''];
        }

        $host = trim((string)($options['host'] ?? ''));
        $credential = trim((string)($options['credential'] ?? ''));
        // 兼容旧字段
        if ($host === '') {
            $host = trim((string)($options['endpoint'] ?? ''));
        }
        if ($credential === '') {
            $credential = trim((string)($options['token'] ?? ''));
        }
        if ($credential === '') {
            $credential = trim((string)($options['api_key'] ?? ''));
        }

        return [
            'host' => $host,
            'credential' => $credential,
            // 保留旧键，避免其余逻辑改动
            'token' => $credential,
        ];
    }

    private static function endpoint_base(string $host): string {
        $host = rtrim($host, '/');
        if ($host === '') {
            return '';
        }

        // 兼容用户直接填写页面路由（无后缀）
        foreach (['/upload', '/gallery', '/docs', '/settings', '/stats'] as $route) {
            if (str_ends_with($host, $route)) {
                return substr($host, 0, -strlen($route));
            }
        }

        if (str_ends_with($host, '/api/upload.php')) {
            return substr($host, 0, -strlen('/api/upload.php'));
        }

        if (str_ends_with($host, '/upload.php')) {
            return substr($host, 0, -strlen('/upload.php'));
        }

        return $host;
    }

    private static function upload_endpoint(string $host): string {
        $base = self::endpoint_base($host);
        return $base === '' ? '' : $base . '/api/upload.php';
    }

    private static function list_endpoint(string $host): string {
        $base = self::endpoint_base($host);
        return $base === '' ? '' : $base . '/api/list.php';
    }

    private static function action_endpoint(string $host): string {
        $base = self::endpoint_base($host);
        return $base === '' ? '' : $base . '/action.php';
    }

    private static function request_json(string $url, string $method, string $token): array {
        $args = [
            'method' => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $token,
            ],
        ];

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => $resp->get_error_message(), 'data' => null];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $body = (string)wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return ['ok' => false, 'message' => '返回不是有效 JSON', 'data' => null];
        }

        if ($code >= 400 || (string)($json['status'] ?? '') !== 'success') {
            $message = (string)($json['message'] ?? ('请求失败 (' . $code . ')'));
            return ['ok' => false, 'message' => $message, 'data' => $json];
        }

        return ['ok' => true, 'message' => '', 'data' => $json];
    }

    private static function test_connection(string $host, string $credential): array {
        $host = trim($host);
        $credential = trim($credential);
        if ($host === '' || $credential === '') {
            return ['ok' => false, 'message' => '请先填写图床域名和管理员密码/Token'];
        }

        $list_endpoint = self::list_endpoint($host);
        if ($list_endpoint === '') {
            return ['ok' => false, 'message' => '图床域名无效'];
        }

        $url = add_query_arg([
            'page' => 1,
            'per_page' => 1,
            'sort' => 'date-desc',
        ], $list_endpoint);

        $resp = self::request_json($url, 'GET', $credential);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => '连接失败: ' . $resp['message']];
        }

        return ['ok' => true, 'message' => '连接成功，图床 API 可用'];
    }

    public static function add_admin_pages(): void {
        add_submenu_page(
            'upload.php',
            'LitePic 图床管理',
            '图床',
            'manage_options',
            'litepic-dashboard',
            [self::class, 'render_dashboard_page']
        );

        add_submenu_page(
            'upload.php',
            'LitePic 图床设置',
            '图床设置',
            'manage_options',
            'litepic-uploader',
            [self::class, 'render_settings_page']
        );
    }

    private static function process_manage_action(array $options): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return ['type' => '', 'text' => ''];
        }

        $manage_action = isset($_POST['litepic_manage_action'])
            ? sanitize_text_field((string)wp_unslash($_POST['litepic_manage_action']))
            : '';

        if ($manage_action === '') {
            return ['type' => '', 'text' => ''];
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], 'litepic_manage_action')) {
            return ['type' => 'error', 'text' => '安全校验失败'];
        }

        $filename = isset($_POST['filename']) ? sanitize_text_field((string)wp_unslash($_POST['filename'])) : '';
        if ($filename === '') {
            return ['type' => 'error', 'text' => '缺少文件名'];
        }

        $action_endpoint = self::action_endpoint($options['host']);
        if ($action_endpoint === '' || $options['token'] === '') {
            return ['type' => 'error', 'text' => '请先在图床设置中填写图床域名和认证信息'];
        }

        if (!in_array($manage_action, ['compress', 'webp', 'delete'], true)) {
            return ['type' => 'error', 'text' => '无效操作'];
        }

        $url = add_query_arg([
            'action' => $manage_action,
            'file' => $filename,
        ], $action_endpoint);

        $resp = self::request_json($url, 'GET', $options['token']);
        if (!$resp['ok']) {
            return ['type' => 'error', 'text' => '操作失败: ' . $resp['message']];
        }

        $message = (string)($resp['data']['message'] ?? '操作成功');
        return ['type' => 'success', 'text' => $message . ' - ' . $filename];
    }

    public static function render_dashboard_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::get_options();
        $notice = self::process_manage_action($options);

        $page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 20;
        $q = isset($_GET['q']) ? sanitize_text_field((string)wp_unslash($_GET['q'])) : '';
        $sort = isset($_GET['sort']) ? sanitize_text_field((string)wp_unslash($_GET['sort'])) : 'date-desc';

        $items = [];
        $pagination = ['page' => 1, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 1];
        $list_error = '';

        if ($options['host'] === '' || $options['token'] === '') {
            $list_error = '请先在“图床设置”中配置图床域名和认证信息。';
        } else {
            $list_endpoint = self::list_endpoint($options['host']);
            if ($list_endpoint === '') {
                $list_error = '图床网址配置无效';
            } else {
                $url = add_query_arg([
                    'page' => $page,
                    'per_page' => $per_page,
                    'q' => $q,
                    'sort' => $sort,
                ], $list_endpoint);

                $resp = self::request_json($url, 'GET', $options['token']);
                if (!$resp['ok']) {
                    $list_error = $resp['message'];
                } else {
                    $data = $resp['data']['data'] ?? [];
                    if (is_array($data)) {
                        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : $pagination;
                    }
                }
            }
        }

        $current_page = max(1, (int)($pagination['page'] ?? 1));
        $total_pages = max(1, (int)($pagination['total_pages'] ?? 1));
        ?>
        <div class="wrap">
            <h1>LitePic 图床管理</h1>
            <p>在 WordPress 后台直接管理图床图片（删除、压缩、转 WebP）。</p>

            <?php if ($notice['type'] !== ''): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'success' ? 'success' : 'error'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" style="margin: 16px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="litepic-dashboard" />
                <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="搜索文件名" class="regular-text" />
                <select name="sort">
                    <option value="date-desc" <?php selected($sort, 'date-desc'); ?>>最新上传</option>
                    <option value="date-asc" <?php selected($sort, 'date-asc'); ?>>最早上传</option>
                    <option value="name-asc" <?php selected($sort, 'name-asc'); ?>>名称 A-Z</option>
                    <option value="name-desc" <?php selected($sort, 'name-desc'); ?>>名称 Z-A</option>
                    <option value="size-desc" <?php selected($sort, 'size-desc'); ?>>体积从大到小</option>
                    <option value="size-asc" <?php selected($sort, 'size-asc'); ?>>体积从小到大</option>
                </select>
                <select name="per_page">
                    <?php foreach ([20, 50, 100] as $opt): ?>
                        <option value="<?php echo esc_attr((string)$opt); ?>" <?php selected($per_page, $opt); ?>><?php echo esc_html((string)$opt); ?> / 页</option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('查询', 'secondary', '', false); ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=litepic-uploader')); ?>">图床设置</a>
            </form>

            <?php if ($list_error !== ''): ?>
                <div class="notice notice-error"><p><?php echo esc_html($list_error); ?></p></div>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:120px;">缩略图</th>
                            <th>文件名</th>
                            <th>尺寸</th>
                            <th>体积</th>
                            <th>上传时间</th>
                            <th style="width:280px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="6">暂无图片</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $filename = (string)($item['filename'] ?? '');
                                $thumb_url = (string)($item['thumb_url'] ?? $item['url'] ?? '');
                                $url = (string)($item['url'] ?? '');
                                $dimensions = (string)($item['dimensions'] ?? '-');
                                $size_text = (string)($item['size_text'] ?? '-');
                                $time_text = (string)($item['time_text'] ?? '-');
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($thumb_url !== ''): ?>
                                            <img src="<?php echo esc_url($thumb_url); ?>" alt="" style="width:100px;height:62px;object-fit:cover;border:1px solid #ddd;" />
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo esc_html($filename); ?></div>
                                        <?php if ($url !== ''): ?>
                                            <div><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">查看原图</a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($dimensions); ?></td>
                                    <td><?php echo esc_html($size_text); ?></td>
                                    <td><?php echo esc_html($time_text); ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <?php foreach (['compress' => '压缩', 'webp' => '转 WebP', 'delete' => '删除'] as $act => $label): ?>
                                                <form method="post" style="display:inline-block;">
                                                    <?php wp_nonce_field('litepic_manage_action'); ?>
                                                    <input type="hidden" name="litepic_manage_action" value="<?php echo esc_attr($act); ?>" />
                                                    <input type="hidden" name="filename" value="<?php echo esc_attr($filename); ?>" />
                                                    <button type="submit" class="button <?php echo $act === 'delete' ? 'button-link-delete' : ''; ?>">
                                                        <?php echo esc_html($label); ?>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                    <div class="tablenav" style="margin-top:10px;">
                        <div class="tablenav-pages">
                            <?php
                            $base_url = admin_url('admin.php?page=litepic-dashboard&q=' . rawurlencode($q) . '&sort=' . rawurlencode($sort) . '&per_page=' . $per_page . '&paged=%#%');
                            echo wp_kses_post((string)paginate_links([
                                'base' => $base_url,
                                'format' => '',
                                'current' => $current_page,
                                'total' => $total_pages,
                                'prev_text' => '«',
                                'next_text' => '»',
                            ]));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::get_options();
        $host = esc_attr($options['host']);
        $credential = esc_attr($options['credential']);
        $test_notice = ['ok' => null, 'message' => ''];

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['litepic_test_connection'])) {
            check_admin_referer('litepic_test_connection');
            $test_notice = self::test_connection((string)$options['host'], (string)$options['credential']);
        }
        ?>
        <div class="wrap">
            <h1>LitePic 图床设置</h1>
            <p>只需要填写图床域名和管理员密码/Token，插件会自动拼接所有接口地址。</p>

            <?php if ($test_notice['ok'] !== null): ?>
                <div class="notice notice-<?php echo $test_notice['ok'] ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html((string)$test_notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('litepic_external_uploader_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="litepic-host">图床域名</label></th>
                        <td>
                            <input id="litepic-host" name="<?php echo esc_attr(self::OPTION_KEY); ?>[host]" type="url" class="regular-text" value="<?php echo $host; ?>" placeholder="https://img.example.com" />
                            <p class="description">示例：https://img.example.com（只填域名，后缀 /upload、/api/upload.php 都不需要填）</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="litepic-credential">管理员密码 / Token</label></th>
                        <td>
                            <input id="litepic-credential" name="<?php echo esc_attr(self::OPTION_KEY); ?>[credential]" type="text" class="regular-text" value="<?php echo $credential; ?>" />
                            <p class="description">可填写后台管理员 API Key（登录密码）或第三方上传 Token。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2>连接测试</h2>
            <form method="post">
                <?php wp_nonce_field('litepic_test_connection'); ?>
                <p class="description">使用当前已保存的图床域名和管理员密码/Token进行测试。</p>
                <input type="hidden" name="litepic_test_connection" value="1" />
                <?php submit_button('测试连接', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public static function render_editor_button(): void {
        if (!current_user_can('upload_files')) {
            return;
        }

        echo '<button type="button" class="button" id="litepic-upload-btn">上传到 LitePic</button>';
        echo '<input type="file" id="litepic-upload-input" accept="image/*" style="display:none;" />';
    }

    public static function render_footer_script(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'site-editor'], true)) {
            return;
        }

        $options = self::get_options();
        $endpoint = esc_js(self::upload_endpoint($options['host']));
        $api_key = esc_js($options['credential']);
        ?>
        <script>
        (function() {
            const endpoint = '<?php echo $endpoint; ?>';
            const apiKey = '<?php echo $api_key; ?>';
            const btn = document.getElementById('litepic-upload-btn');
            const input = document.getElementById('litepic-upload-input');

            if (!btn || !input) return;

            function notify(message, type) {
                if (window.wp && wp.data && wp.data.dispatch) {
                    wp.data.dispatch('core/notices').createNotice(type || 'info', message, { isDismissible: true });
                } else {
                    alert(message);
                }
            }

            function insertImage(url) {
                if (window.wp && wp.blocks && wp.data && wp.data.dispatch && wp.data.select('core/block-editor')) {
                    const imageBlock = wp.blocks.createBlock('core/image', { url: url });
                    wp.data.dispatch('core/block-editor').insertBlocks(imageBlock);
                    return;
                }

                if (typeof window.send_to_editor === 'function') {
                    window.send_to_editor('<img src="' + url + '" alt="" />');
                    return;
                }

                notify('无法插入到当前编辑器，请手动粘贴链接: ' + url, 'warning');
            }

            async function doUpload(file) {
                if (!endpoint || !apiKey) {
                    notify('请先在 媒体 -> 图床设置 填写图床域名和管理员密码/Token', 'error');
                    return;
                }

                const formData = new FormData();
                formData.append('image', file);

                btn.disabled = true;
                const oldText = btn.textContent;
                btn.textContent = '上传中...';

                try {
                    const resp = await fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'X-API-Key': apiKey
                        },
                        body: formData
                    });

                    const data = await resp.json().catch(() => null);
                    if (!resp.ok || !data) {
                        throw new Error((data && data.message) ? data.message : ('请求失败 (' + resp.status + ')'));
                    }

                    const result = Array.isArray(data.results) ? data.results[0] : null;
                    if (!result || result.status !== 'success' || !result.url) {
                        throw new Error((result && result.message) ? result.message : '上传失败');
                    }

                    insertImage(result.url);
                    notify('上传成功', 'success');
                } catch (err) {
                    notify(err.message || '上传失败', 'error');
                } finally {
                    btn.disabled = false;
                    btn.textContent = oldText;
                }
            }

            btn.addEventListener('click', function() {
                input.click();
            });

            input.addEventListener('change', function() {
                const file = input.files && input.files[0];
                if (file) {
                    doUpload(file);
                }
                input.value = '';
            });
        })();
        </script>
        <?php
    }
}

LitePic_External_Uploader::init();
