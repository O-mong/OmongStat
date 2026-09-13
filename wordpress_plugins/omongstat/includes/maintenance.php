<?php

defined('ABSPATH') || exit();

add_action('omongstat_upgrade_event', 'omongstat_maybe_migrate');
add_action('admin_init', 'omongstat_admin_upgrade');
add_action('omongstat_maintenance_event', 'omongstat_run_maintenance');

function omongstat_schedule_upgrade(): void
{
    if ((int) get_option('omongstat_schema_version', 0) < OMONSTAT_SCHEMA_VERSION) {
        if (
            !get_transient(omongstat_state_key('upgrade_retry')) &&
            !wp_next_scheduled('omongstat_upgrade_event')
        ) {
            wp_schedule_single_event(time() + 5, 'omongstat_upgrade_event');
        }
    } elseif (!wp_next_scheduled('omongstat_maintenance_event')) {
        omongstat_schedule_maintenance(60);
    }
}

function omongstat_admin_upgrade(): void
{
    if (
        current_user_can('manage_options') &&
        !get_transient(omongstat_state_key('upgrade_retry'))
    ) {
        omongstat_maybe_migrate();
    }
}

function omongstat_schedule_maintenance(int $delay = 60): void
{
    if (!wp_next_scheduled('omongstat_maintenance_event')) {
        wp_schedule_single_event(time() + $delay, 'omongstat_maintenance_event');
    }
}

function omongstat_run_maintenance(): void
{
    global $wpdb;
    if ((int) get_option('omongstat_schema_version', 0) < OMONSTAT_SCHEMA_VERSION) {
        return;
    }
    $lock = 'omongstat_maint_' . substr(hash('sha256', DB_NAME . omongstat_table_name()), 0, 32);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
        omongstat_schedule_maintenance();
        return;
    }
    $more = false;
    try {
        if (!get_option(omongstat_state_key('backfill_complete'), false)) {
            $more = !omongstat_backfill_technology(omongstat_table_name());
        }
        $limits = omongstat_limits_table();
        $wpdb->query(
            $wpdb->prepare("DELETE FROM `$limits` WHERE expires_at < %d LIMIT 500", time()),
        );
        // Zero preserves all events. Only an administrator's explicit setting enables pruning.
        $days = (int) get_option('omongstat_retention_days', 0);
        if ($days > 0) {
            $table = omongstat_table_name();
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$table` WHERE occurred_at < %s ORDER BY occurred_at LIMIT 500",
                    gmdate('Y-m-d H:i:s', time() - min($days, 3650) * DAY_IN_SECONDS),
                ),
            );
            if ($deleted === false) {
                throw new RuntimeException($wpdb->last_error);
            }
            $more = $more || $deleted === 500;
        }
    } catch (Throwable $error) {
        omongstat_log_failure('maintenance', $error->getMessage());
        $more = true;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        omongstat_schedule_maintenance($more ? 60 : HOUR_IN_SECONDS);
    }
}
