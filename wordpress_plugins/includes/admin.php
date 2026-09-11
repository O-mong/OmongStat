<?php

defined('ABSPATH') || exit;

add_action('admin_menu', 'omongstat_register_admin_page');

function omongstat_register_admin_page(): void
{
    add_menu_page(
        'OmongStat',
        'Analytics',
        'manage_options',
        'omongstat',
        'omongstat_render_admin_page',
        'dashicons-chart-area',
        30
    );
}

function omongstat_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    global $wpdb;

    $table = omongstat_table_name();
    $today = gmdate('Y-m-d 00:00:00');

    $total_views = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM ($table)"
    );
    
    $today_views = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM ($table)
            WHERE occured_at >= %s",
            $today
        )
    );

    $recent_events = $wpdb->get_results(
        "SELECT occured_at, path, referrer
        FROM ($table)
        ORDER BY id DESC
        LIMIT 20",
        ARRAY_A
    );
    ?>
    <div class "wrap">
        <h1>OmongStat Analytics</h1>
        <p>Total Views: <?php echo esc_html($total_views); ?></p>
        <p>Today's Views: <?php echo esc_html($today_views); ?></p>

        <h2>Recent Events</h2>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th scope="col" class="manage-column">Date</th>
                    <th scope="col" class="manage-column">Path</th>
                    <th scope="col" class="manage-column">Referrer</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_events as $event): ?>
                    <tr>
                        <td><?php echo esc_html($event['occured_at']); ?></td>
                        <td><?php echo esc_html($event['path']); ?></td>
                        <td><?php echo esc_html($event['referrer']); ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$recent_events): ?>
                    <tr>
                        <td colspan="3">
                            No recent events found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
}