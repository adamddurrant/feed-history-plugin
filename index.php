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
    }

    public function validate_options($input) {
        $validated = [];
        $validated['rss_feed_url'] = esc_url_raw($input['rss_feed_url']);
        $validated['rss_feed_frequency'] = in_array($input['rss_feed_frequency'], ['hourly', 'daily', 'weekly']) ? $input['rss_feed_frequency'] : 'hourly';

        // Update cron schedule based on frequency
        $this->update_cron_schedule($validated['rss_feed_frequency']);

        return $validated;
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
        $next_cron = wp_next_scheduled('rss_feed_monitor_cron_hook');
        $next_cron_time = $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Not scheduled';
    
        echo "<div class='wrap'>
                <h1>RSS Feed Monitor</h1>
                <form method='post' action='options.php'>";
        settings_fields($this->option_name);
        do_settings_sections('rss-feed-monitor');
        submit_button();
        echo "</form>
              <h2>Next Feed Fetch</h2>
              <p id='cron-time'>Next fetch scheduled for: <strong>{$next_cron_time}</strong></p>
              <p>Countdown: <span id='countdown-timer'>Loading...</span></p>
              <h2>Saved RSS Feed Data</h2>
              " . $this->display_saved_feeds() . "
              </div>
              <script>
                  document.addEventListener('DOMContentLoaded', function() {
                      const nextCronTimestamp = " . ($next_cron * 1000) . "; // Convert to milliseconds
                      const timerElement = document.getElementById('countdown-timer');
    
                      function updateCountdown() {
                          const now = new Date().getTime();
                          const distance = nextCronTimestamp - now;
    
                          if (distance <= 0) {
                              timerElement.textContent = 'Fetching now or no fetch scheduled!';
                              return;
                          }
    
                          const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                          const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                          const seconds = Math.floor((distance % (1000 * 60)) / 1000);
    
                          timerElement.textContent = hours + 'h ' + minutes + 'm ' + seconds + 's ';
                      }
    
                      updateCountdown();
                      setInterval(updateCountdown, 1000);
                  });
              </script>";
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
                            </td>
                        </tr>";
        }
        $output .= '</tbody></table>';

        return $output;
    }

    public function fetch_and_store_rss_feed() {
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

// Add download & view functionality
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
});
