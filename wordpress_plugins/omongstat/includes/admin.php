<?php

defined('ABSPATH') || exit();

add_action('admin_menu', 'omongstat_register_admin_menu');
add_action('admin_init', 'omongstat_register_settings');
add_action('admin_enqueue_scripts', 'omongstat_enqueue_admin_assets');
add_filter('script_loader_tag', 'omongstat_admin_module_tag', 10, 2);

function omongstat_register_admin_menu(): void
{
    add_menu_page(
        'OmongStat',
        'Analytics',
        'manage_options',
        'omongstat',
        'omongstat_admin_page',
        'dashicons-chart-area',
        30,
    );
}

function omongstat_register_settings(): void
{
    $defaults = [
        'omongstat_exclude_admin' => true,
        'omongstat_delete_on_uninstall' => false,
    ];

    foreach ($defaults as $name => $default) {
        register_setting('omongstat', $name, [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => $default,
        ]);
    }
}

function omongstat_admin_assets(): ?array
{
    $build_directory = OMONSTAT_DIR . 'assets/admin/';
    $manifest_path = $build_directory . '.vite/manifest.json';

    if (!is_file($manifest_path)) {
        return null;
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);
    $entry = $manifest['src/main.tsx'] ?? null;

    if (!$entry || !is_file($build_directory . $entry['file'])) {
        return null;
    }

    foreach ($entry['css'] ?? [] as $stylesheet) {
        if (!is_file($build_directory . $stylesheet)) {
            return null;
        }
    }

    return $entry;
}

function omongstat_enqueue_admin_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_omongstat' || !current_user_can('manage_options')) {
        return;
    }

    $entry = omongstat_admin_assets();
    if (!$entry) {
        return;
    }

    $build_url = OMONSTAT_URL . 'assets/admin/';
    wp_enqueue_script('omongstat-admin', $build_url . $entry['file'], [], OMONSTAT_VERSION, true);
    wp_localize_script('omongstat-admin', 'OmongStatAdmin', [
        'restUrl' => wp_make_link_relative(rest_url('omongstat/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'timezone' => wp_timezone_string(),
        'today' => wp_date('Y-m-d'),
    ]);

    foreach ($entry['css'] ?? [] as $index => $stylesheet) {
        wp_enqueue_style(
            'omongstat-admin-' . $index,
            $build_url . $stylesheet,
            [],
            OMONSTAT_VERSION,
        );
    }
}

function omongstat_admin_module_tag(string $tag, string $handle): string
{
    if ($handle !== 'omongstat-admin') {
        return $tag;
    }

    $tag = str_replace([' type="text/javascript"', " type='text/javascript'"], '', $tag);
    return preg_replace('/<script\b/', '<script type="module"', $tag, 1);
}

function omongstat_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Administrator access required.');
    }

    $schema_is_current = (int) get_option('omongstat_schema_version', 0) >= OMONSTAT_SCHEMA_VERSION;
    $settings = [
        'omongstat_exclude_admin' => 'Exclude visits from logged-in administrators',
        'omongstat_delete_on_uninstall' =>
            'Delete all analytics data when the plugin is uninstalled',
    ];
    ?>
    <div class="wrap">
        <h1>OmongStat Analytics</h1>

        <?php if (!$schema_is_current): ?>
            <div class="notice notice-error">
                <p>The OmongStat database upgrade has not completed. Check the server error log.</p>
            </div>
        <?php endif; ?>

        <?php if (omongstat_admin_assets()): ?>
            <div id="omongstat-root"></div>
        <?php else: ?>
            <div class="notice notice-error">
                <p>The OmongStat admin build is missing. Run npm run build and deploy the plugin again.</p>
            </div>
        <?php endif; ?>

        <details>
            <summary>Collection and data settings</summary>
            <form method="post" action="options.php">
                <?php settings_fields('omongstat'); ?>
                <?php foreach ($settings as $name => $label): ?>
                    <p>
                        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0">
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr($name); ?>"
                                value="1"
                                <?php checked(
                                    get_option($name, $name === 'omongstat_exclude_admin'),
                                    true,
                                ); ?>
                            >
                            <?php echo esc_html($label); ?>
                        </label>
                    </p>
                <?php endforeach; ?>
                <?php submit_button('Save settings'); ?>
            </form>
        </details>
    </div>
    <?php
}
