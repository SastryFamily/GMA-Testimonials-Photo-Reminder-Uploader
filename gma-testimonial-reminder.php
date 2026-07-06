<?php
/*
Plugin Name: GMA Testimonial Reminder
Description: v5.10 - Renames menu and page title to 'GMA Testimonial Photo Reminder'. Includes all v5.9 fixes (self-healing cron, max-reminders enforcement, IST timestamps, log migration).
Version: 5.10
Author: GMA
*/

if (!defined('ABSPATH')) exit;

class GMA_TR {

    private $option           = 'gma_tr_settings';
    private $last_run_key     = 'gma_tr_last_run';
    private $next_run_key     = 'gma_tr_next_run';
    private $last_sent_day_key = 'gma_tr_last_sent_day';
    private $send_counts_key  = 'gma_tr_send_counts';   // per-recipient send count
    private $ist              = null;                    // DateTimeZone for Asia/Kolkata

    // Convenience: current date/datetime in IST
    private function ist_now()      { return new DateTime('now', $this->ist); }
    private function ist_date()     { return $this->ist_now()->format('Y-m-d'); }
    private function ist_datetime() { return $this->ist_now()->format('Y-m-d H:i:s') . ' IST'; }

    public function __construct() {
        $this->ist = new DateTimeZone('Asia/Kolkata');
        add_action('admin_menu',  [$this, 'menu']);
        add_action('admin_init',  [$this, 'register']);
        add_action('gma_tr_event', [$this, 'run']);

        // Self-healing cron — reschedule if event is missing for any reason
        add_action('init', [$this, 'maybe_reschedule']);

        // One-time migration: build send counts from existing log history
        add_action('init', [$this, 'migrate_counts']);

        register_activation_hook(__FILE__,   [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']); // FIX 2: clean up on deactivate
    }

    // -------------------------------------------------------------------------
    // Lifecycle hooks
    // -------------------------------------------------------------------------

    public function activate() {
        // Only write defaults if no settings exist yet (preserve existing config on re-activate)
        if (!get_option($this->option)) {
            update_option($this->option, [
                'time'    => '10:00',
                'max'     => 2,
                'subject' => 'Quick request to add your photo 😊',
                'body'    => "Hi {first_name},<br><br>\nThanks again for sharing your testimonial — we really appreciate it 🙌<br><br>\nPlease upload your photo here:<br><br>\n<a href='https://globalmentoringacademy.com/upload-testimonial-photo/' target='_blank'>Upload your photo</a><br><br>\nThanks so much,<br>Global Mentoring Academy"
            ]);
        }
        $this->schedule_next();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('gma_tr_event');
    }

    // -------------------------------------------------------------------------
    // FIX 1: Self-healing — called on every page load via 'init'
    // -------------------------------------------------------------------------

    public function maybe_reschedule() {
        if (!wp_next_scheduled('gma_tr_event')) {
            $this->schedule_next();
        }
    }

    // -------------------------------------------------------------------------
    // One-time migration: rebuild send counts from v5.8 log history
    // Runs once on first page load after v5.9 is installed, then never again.
    // -------------------------------------------------------------------------

    public function migrate_counts() {
        if (get_option('gma_tr_counts_migrated')) return;

        $logs        = get_option('gma_tr_logs', []);
        $send_counts = [];

        foreach ($logs as $entry) {
            // Count Auto and Manual sends; ignore Test entries
            if (isset($entry['type']) && in_array($entry['type'], ['Auto', 'Manual'], true)) {
                $email = $entry['email'] ?? '';
                if ($email) {
                    $send_counts[$email] = ($send_counts[$email] ?? 0) + 1;
                }
            }
        }

        update_option($this->send_counts_key, $send_counts);
        update_option('gma_tr_counts_migrated', true);
    }

    // -------------------------------------------------------------------------
    // Settings registration
    // -------------------------------------------------------------------------

    public function register() {
        register_setting($this->option, $this->option);
    }

    public function menu() {
        add_menu_page('GMA Testimonial Photo Reminder', 'GMA Testimonial Photo Reminder', 'manage_options', 'gma-tr', [$this, 'page']);
    }

    // -------------------------------------------------------------------------
    // Scheduling
    // -------------------------------------------------------------------------

    private function schedule_next() {
        $opt      = get_option($this->option, []);
        $time_ist = !empty($opt['time']) ? $opt['time'] : '10:00';

        $now_ist = $this->ist_now();
        $run_ist = new DateTime($time_ist, $this->ist);
        $run_ist->setDate($now_ist->format('Y'), $now_ist->format('m'), $now_ist->format('d'));

        // If today's scheduled time has already passed, schedule for tomorrow
        if ($run_ist <= $now_ist) $run_ist->modify('+1 day');

        // getTimestamp() is always a UTC Unix timestamp — no timezone conversion needed
        wp_clear_scheduled_hook('gma_tr_event');
        wp_schedule_single_event($run_ist->getTimestamp(), 'gma_tr_event');

        // Store display time in IST so admin page is easy to read
        update_option($this->next_run_key, $run_ist->format('Y-m-d H:i:s') . ' IST');
    }

    // -------------------------------------------------------------------------
    // Candidate retrieval
    // -------------------------------------------------------------------------

    private function get_candidates() {
        $posts   = get_posts(['post_type' => 'wpm-testimonial', 'posts_per_page' => -1]);
        $results = [];

        foreach ($posts as $p) {
            $name  = get_post_meta($p->ID, 'client_name', true);
            $email = get_post_meta($p->ID, 'email', true);

            if (!has_post_thumbnail($p->ID) && !empty($email)) {
                $results[] = ['post' => $p, 'name' => $name, 'email' => $email];
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Run (auto or manual)
    // -------------------------------------------------------------------------

    public function run($manual = false) {

        // FIX 4: Use IST date (not PHP server TZ) for the day-guard
        $today = $this->ist_date();

        // Guard: don't auto-send more than once per day, but always reschedule
        if (!$manual && get_option($this->last_sent_day_key) === $today) {
            // FIX 2a: Still ensure next event is queued even if we exit early
            $this->schedule_next();
            return;
        }

        $opt         = get_option($this->option, []);
        $max         = (int)($opt['max'] ?? 2);
        $candidates  = $this->get_candidates();
        $send_counts = get_option($this->send_counts_key, []); // FIX 3: load per-recipient counts

        $sent_any = false;

        foreach ($candidates as $c) {
            $email = $c['email'];

            // Enforce max reminders per recipient (auto and manual)
            $count = (int)($send_counts[$email] ?? 0);
            if ($count >= $max) {
                continue; // skip — already received the maximum reminders
            }

            $subject = $opt['subject'];
            $body    = str_replace(
                ['{first_name}', '{name}', '{email}'],
                [explode(' ', $c['name'])[0], $c['name'], $email],
                $opt['body']
            );

            wp_mail(
                $email,
                $subject,
                $body,
                [
                    'From: Global Mentoring Academy <support@globalmentoringacademy.com>',
                    'Reply-To: support@globalmentoringacademy.com',
                    'Cc: ssastry1111@gmail.com, naik.nitin@gmail.com',
                    'Content-Type: text/html; charset=UTF-8'
                ]
            );

            // Increment per-recipient count for both auto and manual runs
            $send_counts[$email] = $count + 1;

            // Log the send
            $logs   = get_option('gma_tr_logs', []);
            $logs[] = [
                'time'  => $this->ist_datetime(),
                'email' => $email,
                'type'  => $manual ? 'Manual' : 'Auto',
                'count' => ($count + 1) . '/' . $max,
            ];
            if (count($logs) > 500) $logs = array_slice($logs, -500);
            update_option('gma_tr_logs', $logs);

            $sent_any = true;
        }

        // Persist updated send counts (both auto and manual runs)
        update_option($this->send_counts_key, $send_counts);

        update_option($this->last_run_key, $this->ist_datetime());
        if (!$manual) update_option($this->last_sent_day_key, $today);

        // Always reschedule next event at the end of run()
        $this->schedule_next();
    }

    // -------------------------------------------------------------------------
    // Admin page
    // -------------------------------------------------------------------------

    public function page() {

        $opt  = get_option($this->option, []);
        $last = get_option($this->last_run_key);
        $next = get_option($this->next_run_key);
        $send_counts = get_option($this->send_counts_key, []);
        $max  = (int)($opt['max'] ?? 2);

        $scheduled_ts = wp_next_scheduled('gma_tr_event');
        if ($scheduled_ts) {
            $dt = new DateTime('@' . $scheduled_ts); // UTC Unix timestamp
            $dt->setTimezone($this->ist);
            $scheduled_display = $dt->format('Y-m-d H:i:s') . ' IST';
        } else {
            $scheduled_display = '<strong style="color:red">NOT SCHEDULED — will self-heal on next page load</strong>';
        }

        echo "<div class='wrap'>";
        echo "<h1>GMA Testimonial Photo Reminder <span style='font-size:0.6em;color:#666;'>v5.10</span></h1>";

        // ── Settings form ──────────────────────────────────────────────────────
        echo "<h2>Settings</h2>";
        echo "<form method='post' action='options.php'>";
        settings_fields($this->option);

        echo "<p>Time (IST): <input type='time' name='{$this->option}[time]' value='{$opt['time']}'></p>";
        echo "<p>Max Reminders per Person: <input type='number' min='1' name='{$this->option}[max]' value='{$opt['max']}'></p>";
        echo "<p>Subject:<br><input type='text' style='width:500px' name='{$this->option}[subject]' value='" . esc_attr($opt['subject']) . "'></p>";
        echo "<p>Body:<br><textarea name='{$this->option}[body]' rows='10' cols='100'>" . esc_textarea($opt['body']) . "</textarea></p>";

        submit_button('Save Settings');
        echo "</form>";

        // ── Action buttons ─────────────────────────────────────────────────────
        echo "<h2>Actions</h2>";
        echo "<form method='post'>";
        submit_button('Run Now (Manual)',  'secondary', 'run_now');
        submit_button('Test / Preview',   'secondary', 'test');
        submit_button('Reset Send Counts','delete',    'reset_counts');
        echo "</form>";

        // ── Status panel ───────────────────────────────────────────────────────
        echo "<h2>Status</h2>";
        echo "<p>Last Run: " . ($last ?: 'Never') . "</p>";
        echo "<p>Next Scheduled Run (IST): " . ($next ?: 'Not recorded') . "</p>";
        echo "<p>WP-Cron event registered: $scheduled_display</p>";

        // ── Handle actions ─────────────────────────────────────────────────────

        if (isset($_POST['reset_counts'])) {
            delete_option($this->send_counts_key);
            echo "<div class='notice notice-success'><p>Send counts reset. All candidates will receive reminders again from next run.</p></div>";
            $send_counts = [];
        }

        if (isset($_POST['test'])) {
            $candidates = $this->get_candidates();

            echo "<h2>Test / Preview</h2>";

            if (empty($candidates)) {
                echo "<p>No testimonials without images found.</p>";
            } else {
                echo "<h3>Candidates (testimonials without a photo)</h3>";
                echo "<table border='1' cellpadding='5'>
                      <tr><th>Name</th><th>Email</th><th>Reminders Sent</th><th>Status</th></tr>";

                foreach ($candidates as $c) {
                    $count   = (int)($send_counts[$c['email']] ?? 0);
                    $reached = $count >= $max;
                    $status  = $reached
                        ? "<span style='color:red'>Max reached ($count/$max) — will be skipped</span>"
                        : "<span style='color:green'>Will receive reminder ($count/$max sent)</span>";
                    echo "<tr>
                            <td>{$c['name']}</td>
                            <td>{$c['email']}</td>
                            <td>$count / $max</td>
                            <td>$status</td>
                          </tr>";
                }
                echo "</table>";

                // Email preview for first eligible candidate
                $first_eligible = null;
                foreach ($candidates as $c) {
                    if (((int)($send_counts[$c['email']] ?? 0)) < $max) {
                        $first_eligible = $c;
                        break;
                    }
                }

                if ($first_eligible) {
                    $preview_body = str_replace(
                        ['{first_name}', '{name}', '{email}'],
                        [explode(' ', $first_eligible['name'])[0], $first_eligible['name'], $first_eligible['email']],
                        $opt['body']
                    );

                    echo "<h3>Email Preview (first eligible: {$first_eligible['name']})</h3>";
                    echo "<div style='border:1px solid #ccc;padding:15px;background:#fff;max-width:700px'>$preview_body</div>";

                    echo "<div style='margin-top:15px;'>
                    <form method='post'>
                        <input type='hidden' name='send_test' value='1'>
                        <input type='hidden' name='subject' value='" . htmlspecialchars($opt['subject'], ENT_QUOTES) . "'>
                        <input type='hidden' name='body' value='" . base64_encode($preview_body) . "'>
                        <button class='button button-primary'>Send Test Email to ssastry1111@gmail.com</button>
                    </form>
                    </div>";
                } else {
                    echo "<p><em>All candidates have reached the max reminder limit. Use 'Reset Send Counts' to re-enable reminders.</em></p>";
                }
            }
        }

        if (isset($_POST['send_test'])) {
            $decoded_body = base64_decode($_POST['body']);

            wp_mail(
                'ssastry1111@gmail.com',
                $_POST['subject'],
                $decoded_body,
                [
                    'From: Global Mentoring Academy <support@globalmentoringacademy.com>',
                    'Reply-To: support@globalmentoringacademy.com',
                    'Content-Type: text/html; charset=UTF-8'
                ]
            );

            $logs   = get_option('gma_tr_logs', []);
            $logs[] = [
                'time'  => $this->ist_datetime(),
                'name'  => 'TEST',
                'email' => 'ssastry1111@gmail.com',
                'type'  => 'Test',
                'count' => 'N/A',
            ];
            update_option('gma_tr_logs', $logs);

            echo "<div class='notice notice-success'><p>Test email sent to ssastry1111@gmail.com.</p></div>";
        }

        if (isset($_POST['run_now'])) {
            $this->run(true);
            echo "<div class='notice notice-success'><p>Manual run executed. Check logs below.</p></div>";
        }

        // ── Logs ───────────────────────────────────────────────────────────────
        echo "<hr><h2>Logs</h2>";
        echo "<form method='post'>";
        submit_button('Refresh Logs', 'secondary', 'view_logs');
        echo "</form>";

        if (isset($_POST['view_logs'])) {
            $logs = get_option('gma_tr_logs', []);

            echo "<table border='1' cellpadding='5'>
            <tr><th>Time</th><th>Name</th><th>Email</th><th>Type</th><th>Count</th></tr>";

            foreach (array_reverse($logs) as $l) {
                $cnt = isset($l['count']) ? $l['count'] : '—';
                echo "<tr>
                <td>{$l['time']}</td>
                <td>{$l['name']}</td>
                <td>{$l['email']}</td>
                <td>{$l['type']}</td>
                <td>$cnt</td>
                </tr>";
            }

            echo "</table>";
        }

        echo "</div>";
    }
}

new GMA_TR();
