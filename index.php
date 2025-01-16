<?php

/**
 * Plugin Name: RSS Feed Monitor
 * Description: A WordPress plugin to monitor an RSS feed at hourly, daily, or weekly intervals and store the data in the database for later download.
 * Version: 1.1.0
 * Author: Adam Durrant
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class RSS_Feed_Monitor {

    private $option_name = 'rss_feed_monitor_options';

    public function __construct() {
        add_action('admin_menu', [$this, 'create_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rss_feed_monitor_cron_hook', [$this, 'fetch_and_store_rss_feed']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        if (!wp_next_scheduled('rss_feed_monitor_cron_hook')) {
            wp_schedule_event(time(), 'hourly', 'rss_feed_monitor_cron_hook');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('rss_feed_monitor_cron_hook');
    }

    public function create_settings_page() {
        add_options_page(
            'RSS Feed Monitor',
            'RSS Feed Monitor',
            'manage_options',
            'rss-feed-monitor',
            [$this, 'settings_page_html']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [$this, 'validate_options']);

        add_settings_section(
            'rss_feed_monitor_settings_section',
            'RSS Feed Monitor Settings',
            null,
            'rss-feed-monitor'
        );


        add_settings_field(
            'rss_feed_url',
            'RSS Feed URL',
            [$this, 'rss_feed_url_html'],
            'rss-feed-monitor',
            'rss_feed_monitor_settings_section'
        );

        add_settings_field(
            'rss_feed_frequency',
            'Monitor Frequency',
            [$this, 'rss_feed_frequency_html'],
            'rss-feed-monitor',
            'rss_feed_monitor_settings_section'
        );

        add_settings_field(
            'delete_every',
            'Delete Every',
            [$this, 'delete_every_html'],
            'rss-feed-monitor',
            'rss_feed_monitor_settings_section'
        );
    }

    public function validate_options($input) {
        $validated = [];
        $validated['rss_feed_url'] = esc_url_raw($input['rss_feed_url']);
        $validated['rss_feed_frequency'] = in_array($input['rss_feed_frequency'], ['hourly', 'daily', 'weekly']) ? $input['rss_feed_frequency'] : 'hourly';
        $validated['delete_every'] = in_array($input['delete_every'], ['week', 'month', 'year']) ? $input['delete_every'] : 'week';

        $existing_options = get_option($this->option_name);

        // Trigger immediate fetch if the feed URL is added or changed
        if (
            empty($existing_options['rss_feed_url']) ||
            $existing_options['rss_feed_url'] !== $validated['rss_feed_url']
        ) {
            $this->fetch_and_store_rss_feed();
        }

        // Reschedule cron if frequency or feed URL changes
        if (
            $existing_options['rss_feed_frequency'] !== $validated['rss_feed_frequency'] ||
            $existing_options['rss_feed_url'] !== $validated['rss_feed_url']
        ) {
            $this->update_cron_schedule($validated['rss_feed_frequency']);
        }

        return $validated;
    }


    public function cleanup_old_feeds() {
        $options = get_option($this->option_name);
        $delete_every = isset($options['delete_every']) ? $options['delete_every'] : 'week';

        global $wpdb;
        $table_name = $wpdb->prefix . 'rss_feed_monitor';
        $date_limit = date('Y-m-d H:i:s', strtotime("-1 $delete_every"));

        $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE retrieved_at < %s", $date_limit)
        );
    }

    public function update_cron_schedule($frequency) {
        wp_clear_scheduled_hook('rss_feed_monitor_cron_hook');
        wp_schedule_event(time(), $frequency, 'rss_feed_monitor_cron_hook');
    }

    public function rss_feed_url_html() {
        $options = get_option($this->option_name);
        $rss_feed_url = isset($options['rss_feed_url']) ? $options['rss_feed_url'] : '';
        echo "<input type='text' name='{$this->option_name}[rss_feed_url]' value='" . esc_attr($rss_feed_url) . "' class='regular-text'>";
    }

    public function delete_every_html() {
        $options = get_option($this->option_name);
        $delete_every = isset($options['delete_every']) ? $options['delete_every'] : 'week';
        echo "<select name='{$this->option_name}[delete_every]'>
                <option value='week' " . selected($delete_every, 'week', false) . ">Week</option>
                <option value='month' " . selected($delete_every, 'month', false) . ">Month</option>
                <option value='year' " . selected($delete_every, 'year', false) . ">Year</option>
              </select>";
    }

    public function rss_feed_frequency_html() {
        $options = get_option($this->option_name);
        $frequency = isset($options['rss_feed_frequency']) ? $options['rss_feed_frequency'] : 'hourly';
        echo "<select name='{$this->option_name}[rss_feed_frequency]'>
                <option value='hourly' " . selected($frequency, 'hourly', false) . ">Hourly</option>
                <option value='daily' " . selected($frequency, 'daily', false) . ">Daily</option>
                <option value='weekly' " . selected($frequency, 'weekly', false) . ">Weekly</option>
              </select>";
    }

    public function settings_page_html() {
        $options = get_option($this->option_name);
        $rss_feed_url = isset($options['rss_feed_url']) ? $options['rss_feed_url'] : '';
        $next_cron = wp_next_scheduled('rss_feed_monitor_cron_hook');
        $next_cron_time = $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Not scheduled';
        $is_feed_empty = empty($rss_feed_url);

        echo "<div class='wrap'>
                <h1>RSS Feed Monitor</h1>
                <form method='post' action='options.php'>";
        settings_fields($this->option_name);
        do_settings_sections('rss-feed-monitor');
        submit_button();
        echo "</form>
              <div class='info-container'>
                <h2>Next Feed Fetch</h2>
                <p>Next fetch scheduled for: <strong>{$next_cron_time}</strong></p>";

        if ($is_feed_empty) {
            echo "<p style='color: red;'>Add a feed to start monitoring.</p>";
        } else {
            echo "<p id='countdown-timer'></p>";
        }

        echo "</div>
              <h2>Saved RSS Feed Data</h2>
              " . $this->display_saved_feeds() . "
              </div>";
    }


    private function display_saved_feeds() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rss_feed_monitor';
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY retrieved_at DESC");
        if (empty($results)) {
            return '<p>No saved feeds found.</p>';
        }

        $output = '<table class="widefat fixed"><thead><tr><th>ID</th><th>Feed URL</th><th>Retrieved At</th><th>Actions</th></tr></thead><tbody>';
        foreach ($results as $row) {
            $output .= "<tr>
                            <td>{$row->id}</td>
                            <td>{$row->feed_url}</td>
                            <td>{$row->retrieved_at}</td>
                            <td>
                            <a href='" . esc_url(add_query_arg(['download_feed_id' => $row->id], admin_url('options-general.php?page=rss-feed-monitor'))) . "'>Download</a>
                            |
                            <a href='" . esc_url(add_query_arg(['view_feed_id' => $row->id], admin_url('options-general.php?page=rss-feed-monitor'))) . "' target='_blank'>View</a>
                            |
                            <a style='color: red;' href='" . esc_url(add_query_arg(['delete_feed_id' => $row->id], admin_url('options-general.php?page=rss-feed-monitor'))) . "'>Delete</a>
                            </td>
                        </tr>";
        }
        $output .= '</tbody></table>';

        return $output;
    }

    public function fetch_and_store_rss_feed() {

        add_action('rss_feed_monitor_cron_hook', [$this, 'fetch_and_store_rss_feed']);
        add_action('rss_feed_monitor_cron_hook', [$this, 'cleanup_old_feeds']);

        $options = get_option($this->option_name);
        $rss_feed_url = isset($options['rss_feed_url']) ? $options['rss_feed_url'] : '';

        if (empty($rss_feed_url)) {
            return;
        }

        $response = wp_remote_get($rss_feed_url);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            echo `Error fetching feed: $response`;
            return;
        }

        $rss_data = wp_remote_retrieve_body($response);
        $timestamp = current_time('mysql');

        global $wpdb;
        $table_name = $wpdb->prefix . 'rss_feed_monitor';

        $wpdb->insert(
            $table_name,
            [
                'feed_url' => $rss_feed_url,
                'feed_data' => $rss_data,
                'retrieved_at' => $timestamp
            ],
            [
                '%s',
                '%s',
                '%s'
            ]
        );
    }
}

new RSS_Feed_Monitor();

// Create the table on plugin activation
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rss_feed_monitor';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        feed_url TEXT NOT NULL,
        feed_data LONGTEXT NOT NULL,
        retrieved_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

// Add download, view & delete functionality
add_action('admin_init', function () {
    if (isset($_GET['download_feed_id']) || isset($_GET['view_feed_id'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rss_feed_monitor';

        $id = intval($_GET['download_feed_id'] ?? $_GET['view_feed_id']);
        $feed = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if ($feed) {
            header('Content-Type: application/xml');
            if (isset($_GET['download_feed_id'])) {
                header('Content-Disposition: attachment; filename="rss_feed_' . $feed->id . '.xml"');
            }
            echo $feed->feed_data;
            exit;
        }
    }

    if (isset($_GET['delete_feed_id'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rss_feed_monitor';

        // Get the feed ID from the query parameter
        $id = intval($_GET['delete_feed_id']);

        // Delete the row from the database
        $wpdb->delete($table_name, ['id' => $id], ['%d']);

        // Redirect to avoid resubmitting the delete request
        wp_redirect(admin_url('options-general.php?page=rss-feed-monitor'));
        exit;
    }
});
