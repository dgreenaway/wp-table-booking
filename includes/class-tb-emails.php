<?php
defined('ABSPATH') || exit;

/**
 * Handles all outbound emails: admin notifications, client confirmations, reminders.
 */
class TB_Emails {

    // =========================================================================
    // Public senders
    // =========================================================================

    public static function send_admin_notification(int $id): bool {
        $cfg = TB_Database::get_all_settings();
        if (empty($cfg['notify_admin'])) return false;

        $r = self::load($id);
        if (!$r) return false;

        $admin_email = !empty($cfg['admin_email']) ? $cfg['admin_email'] : get_option('admin_email');
        $subject     = sprintf('[%s] New Booking — %s, %s',
            $r['restaurant'],
            $r['customer_name'],
            $r['date_label']
        );

        $detail_rows = self::detail_rows([
            'Reference'    => $r['reservation_number'],
            'Guest'        => $r['customer_name'],
            'Email'        => $r['customer_email'],
            'Phone'        => $r['customer_phone'] ?: '—',
            'Date'         => $r['date_label'],
            'Time'         => $r['time_label'],
            'Party size'   => $r['party_size'] . ' guests',
            'Area'         => $r['area_label'],
            'Table'        => $r['table_name'] ?: 'Auto-assign',
        ]);

        if (!empty($r['special_requests'])) {
            $detail_rows .= self::detail_row('Requests', nl2br(esc_html($r['special_requests'])));
        }

        $admin_url  = admin_url('admin.php?page=tb-reservations&view=' . $id);
        $body_html  = '<p style="' . self::S_BODY . '">A new table reservation has been submitted. Please review and confirm.</p>'
                    . '<p style="margin:20px 0 0;"><a href="' . esc_url($admin_url) . '" style="' . self::S_BTN . '">View Reservation</a></p>';

        $html = self::wrap(
            heading:      'New Booking Received',
            subheading:   'Reservation #' . esc_html($r['reservation_number']),
            detail_rows:  $detail_rows,
            body_html:    $body_html,
            cfg:          $cfg,
            accent_color: '#1e3a5f'
        );

        $sent = self::mail($admin_email, $subject, $html, $cfg);
        if ($sent) {
            TB_Logger::info("Admin notification sent to {$admin_email} for #{$r['reservation_number']}", 'email');
        } else {
            TB_Logger::warning("Admin notification failed for {$admin_email} (#{$r['reservation_number']})", 'email');
        }
        return $sent;
    }

    public static function send_client_confirmation(int $id): bool {
        $cfg = TB_Database::get_all_settings();
        if (empty($cfg['email_notifications'])) return false;

        $r = self::load($id);
        if (!$r) return false;

        $subject = sprintf('Booking Confirmed — %s · %s',
            $r['reservation_number'],
            $r['restaurant']
        );

        $detail_rows = self::detail_rows([
            'Reference'  => $r['reservation_number'],
            'Date'       => $r['date_label'],
            'Time'       => $r['time_label'],
            'Area'       => $r['area_label'],
            'Party size' => $r['party_size'] . ' guests',
        ]);

        if (!empty($r['special_requests'])) {
            $detail_rows .= self::detail_row('Requests', nl2br(esc_html($r['special_requests'])));
        }

        $cancellation_note = !empty($cfg['cancellation_policy'])
            ? '<p style="' . self::S_NOTE . '">' . esc_html($cfg['cancellation_policy']) . '</p>'
            : '';

        $body_html = '<p style="' . self::S_BODY . '">Hi ' . esc_html($r['customer_name']) . ', thanks for your reservation! '
                   . 'We\'ve received your booking and will confirm it shortly.</p>'
                   . $cancellation_note;

        $html = self::wrap(
            heading:      'Your Booking is Received',
            subheading:   'We look forward to welcoming you!',
            detail_rows:  $detail_rows,
            body_html:    $body_html,
            cfg:          $cfg,
            accent_color: '#2563eb'
        );

        $sent = self::mail($r['customer_email'], $subject, $html, $cfg);
        if ($sent) {
            TB_Logger::info("Confirmation email sent to {$r['customer_email']} (#{$r['reservation_number']})", 'email');
        } else {
            TB_Logger::warning("Confirmation email failed for {$r['customer_email']} (#{$r['reservation_number']})", 'email');
        }
        return $sent;
    }

    /**
     * Send a reminder email to the guest.
     * $reminder = ['hours' => 24, 'label' => '24 hours']
     */
    public static function send_client_reminder(int $id, array $reminder): bool {
        $cfg = TB_Database::get_all_settings();
        $r   = self::load($id);
        if (!$r) return false;

        $hours_label = $reminder['hours'] >= 24
            ? ($reminder['hours'] / 24) . ' day' . ($reminder['hours'] >= 48 ? 's' : '')
            : $reminder['hours'] . ' hour' . ($reminder['hours'] !== 1 ? 's' : '');

        $subject = sprintf('Reminder: Your table at %s is %s away',
            $r['restaurant'],
            $hours_label
        );

        $detail_rows = self::detail_rows([
            'Reference'  => $r['reservation_number'],
            'Date'       => $r['date_label'],
            'Time'       => $r['time_label'],
            'Area'       => $r['area_label'],
            'Party size' => $r['party_size'] . ' guests',
        ]);

        $address_line = !empty($cfg['restaurant_address'])
            ? '<p style="' . self::S_BODY . '"><strong>Address:</strong> ' . esc_html($cfg['restaurant_address']) . '</p>'
            : '';

        $body_html = '<p style="' . self::S_BODY . '">Hi ' . esc_html($r['customer_name']) . ', just a friendly reminder that your table '
                   . 'is booked in <strong>' . esc_html($hours_label) . '</strong>. We\'ll see you soon!</p>'
                   . $address_line;

        $html = self::wrap(
            heading:      'Upcoming Reservation Reminder',
            subheading:   'Your table at ' . esc_html($r['restaurant']),
            detail_rows:  $detail_rows,
            body_html:    $body_html,
            cfg:          $cfg,
            accent_color: '#059669'
        );

        $sent = self::mail($r['customer_email'], $subject, $html, $cfg);
        if ($sent) {
            TB_Logger::info("Reminder ({$hours_label}) sent to {$r['customer_email']} (#{$r['reservation_number']})", 'email');
        } else {
            TB_Logger::warning("Reminder ({$hours_label}) failed for {$r['customer_email']} (#{$r['reservation_number']})", 'email');
        }
        return $sent;
    }

    // =========================================================================
    // Template builders
    // =========================================================================

    const S_BODY = 'margin:0 0 14px;font-size:15px;color:#374151;line-height:1.6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';
    const S_NOTE = 'margin:14px 0 0;font-size:12px;color:#9ca3af;line-height:1.5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';
    const S_BTN  = 'display:inline-block;padding:12px 24px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';

    private static function wrap(
        string $heading,
        string $subheading,
        string $detail_rows,
        string $body_html,
        array  $cfg,
        string $accent_color = '#2563eb'
    ): string {
        $restaurant = esc_html($cfg['restaurant_name'] ?? get_bloginfo('name'));
        $site_url   = esc_url(get_bloginfo('url'));
        $year       = date('Y');
        $footer     = !empty($cfg['email_footer']) ? esc_html($cfg['email_footer']) : "$restaurant · $site_url";
        $logo_url   = !empty($cfg['email_logo_url']) ? esc_url($cfg['email_logo_url']) : '';

        $header_content = $logo_url
            ? '<img src="' . $logo_url . '" alt="' . $restaurant . '" style="display:block;max-height:64px;max-width:220px;margin-bottom:12px;border:0;">'
              . '<p style="margin:0;font-size:13px;color:rgba(255,255,255,0.8);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">' . $restaurant . ' · Table Reservation</p>'
            : '<p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;font-family:Georgia,serif;">' . $restaurant . '</p>'
              . '<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.75);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">Table Reservation</p>';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>$heading</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f0f2f5;padding:32px 16px;">
<tr><td align="center">

  <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600"
         style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">

    <!-- HEADER -->
    <tr>
      <td style="background:{$accent_color};padding:28px 36px;">
        $header_content
      </td>
    </tr>

    <!-- HEADING -->
    <tr>
      <td style="padding:28px 36px 8px;">
        <h1 style="margin:0 0 4px;font-size:20px;font-weight:700;color:#111827;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">$heading</h1>
        <p style="margin:0;font-size:13px;color:#6b7280;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">$subheading</p>
      </td>
    </tr>

    <!-- DETAIL BOX -->
    <tr>
      <td style="padding:16px 36px;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"
               style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
          $detail_rows
        </table>
      </td>
    </tr>

    <!-- BODY -->
    <tr>
      <td style="padding:8px 36px 28px;">
        $body_html
      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td style="border-top:1px solid #f3f4f6;padding:16px 36px;background:#f9fafb;">
        <p style="margin:0;font-size:11px;color:#9ca3af;line-height:1.6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
          $footer<br>
          &copy; $year — This email was sent because a reservation was made on $site_url.
        </p>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>
HTML;
    }

    private static function detail_rows(array $pairs): string {
        $html    = '';
        $is_odd  = true;
        foreach ($pairs as $label => $value) {
            $html   .= self::detail_row($label, $value, $is_odd);
            $is_odd  = !$is_odd;
        }
        return $html;
    }

    private static function detail_row(string $label, string $value, bool $odd = true): string {
        $bg = $odd ? '#ffffff' : '#f9fafb';
        return '<tr style="background:' . $bg . ';">'
             . '<td style="padding:10px 16px;font-size:13px;color:#6b7280;font-weight:500;width:130px;border-bottom:1px solid #f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
             . esc_html($label)
             . '</td>'
             . '<td style="padding:10px 16px;font-size:13px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
             . $value
             . '</td>'
             . '</tr>';
    }

    // =========================================================================
    // Mailer
    // =========================================================================

    private static function mail(string $to, string $subject, string $html, array $cfg): bool {
        $from_name  = !empty($cfg['email_from_name'])    ? $cfg['email_from_name']    : ($cfg['restaurant_name'] ?? get_bloginfo('name'));
        $from_email = !empty($cfg['email_from_address']) ? $cfg['email_from_address'] : get_option('admin_email');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        $set_html = function () { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html);
        $result = wp_mail($to, $subject, $html, $headers);
        remove_filter('wp_mail_content_type', $set_html);

        return $result;
    }

    // =========================================================================
    // Data loader
    // =========================================================================

    private static function load(int $id): ?array {
        $res = new TB_Reservations();
        $row = $res->get($id);
        if (!$row) return null;

        $cfg  = TB_Database::get_all_settings();
        $areas = json_decode($cfg['areas'] ?? '[]', true);

        $area_label = $row['seating_area'];
        foreach ((array) $areas as $a) {
            if ($a['id'] === $row['seating_area']) { $area_label = $a['label']; break; }
        }

        $sit_min  = max(1, (int) ($cfg['sitting_duration'] ?? 90));
        $time_ts  = strtotime($row['reservation_time']);
        $end_ts   = $time_ts + $sit_min * 60;

        return [
            'reservation_number' => $row['reservation_number'],
            'customer_name'      => $row['customer_name'],
            'customer_email'     => $row['customer_email'],
            'customer_phone'     => $row['customer_phone'] ?? '',
            'date_label'         => date('l, F j, Y', strtotime($row['reservation_date'])),
            'time_label'         => date('g:i A', $time_ts) . ' – ' . date('g:i A', $end_ts),
            'party_size'         => $row['party_size'],
            'area_label'         => $area_label,
            'table_name'         => $row['table_name'] ?? '',
            'special_requests'   => $row['special_requests'] ?? '',
            'restaurant'         => $cfg['restaurant_name'] ?? get_bloginfo('name'),
            'reservation_date'   => $row['reservation_date'],
            'reservation_time'   => $row['reservation_time'],
            'status'             => $row['status'],
        ];
    }
}
