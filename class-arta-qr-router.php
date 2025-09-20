<?php
namespace ArtaQR;

if (!defined('ABSPATH')) exit;

/**
 * Router for scan + manage endpoints.
 *
 * Endpoints:
 *   - /q/{slug}          → scan handler (redirects for website payloads)
 *   - /q/{slug}/manage   → manage UI
 *
 * DB table expected: {$wpdb->prefix}arta_qr_codes
 * Columns used: id, slug, type, payload_type, payload_json, qr_data, target_url,
 *               png_path, pdf_path, order_id, customer_id, created_at
 */
class Router {

  /**
   * Kept for backward-compat with your plugin bootstrap.
   * Adds both scan and manage rewrite rules.
   */
  public static function add_rewrite(){
    // /q/{slug}
    add_rewrite_rule('^q/([^/]+)/?$', 'index.php?arta_qr_slug=$matches[1]', 'top');
    // /q/{slug}/manage
    add_rewrite_rule('^q/([^/]+)/manage/?$', 'index.php?arta_qr_slug=$matches[1]&arta_qr_manage=1', 'top');
  }

  /**
   * Optional alias if anything else in the code calls add_rewrite_rules().
   */
  public static function add_rewrite_rules(){
    self::add_rewrite();
  }

  /**
   * Register the query vars used by the router.
   */
  public static function add_query_var($vars){
    $vars[] = 'arta_qr_slug';
    $vars[] = 'arta_qr_manage';
    return $vars;
  }

  /**
   * Entry point to handle scan vs. manage views.
   */
  public static function maybe_redirect(){
    $slug   = get_query_var('arta_qr_slug');
    $manage = (string) get_query_var('arta_qr_manage') === '1';

    if (!$slug) return;

    if ($manage){
      self::render_manage($slug);
      exit;
    }

    self::handle_scan($slug);
  }

  /**
   * Handle a scan of /q/{slug}.
   * For dynamic website payloads → redirect to target_url.
   * For other payloads → show a minimal landing (usually not used because
   * their QR PNG encodes native payload, e.g., WIFI:…).
   */
  private static function handle_scan(string $slug){
    $row = self::get_row($slug);
    if (!$row){
      status_header(404);
      self::nocache();
      echo '<!doctype html><meta charset="utf-8"><title>QR not found</title><h1>QR not found</h1>';
      exit;
    }

    $payload_type = $row->payload_type ?: 'website';

    if ($payload_type === 'website'){
      $dest = esc_url_raw(trim((string)$row->target_url));
      if ($dest){
        // Allow off-site redirects
        $host = wp_parse_url($dest, PHP_URL_HOST);
        if ($host) {
          add_filter('allowed_redirect_hosts', function($hosts) use ($host) {
            if (!in_array($host, $hosts, true)) { $hosts[] = $host; }
            return $hosts;
          });
        }

        self::nocache();
        wp_safe_redirect($dest, 302, 'Arta QR');
        // HTML/JS fallback for strict scanner apps
        if (!headers_sent()) { @header('Content-Type: text/html; charset=utf-8'); }
        echo '<!doctype html><meta charset="utf-8"><title>Redirecting…</title>';
        echo '<meta http-equiv="refresh" content="0;url='.esc_attr($dest).'">';
        echo '<p style="font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial">Redirecting to <a href="'.esc_attr($dest).'">'.esc_html($dest).'</a>…</p>';
        echo '<script>try{location.replace('.json_encode($dest).')}catch(e){location.href='.json_encode($dest).'};</script>';
        exit;
      }
    }

    // Fallback landing
    $qrData = $row->qr_data ?: '';
    $manage = trailingslashit(home_url('/q/'.rawurlencode($slug))).'manage';

    status_header(200);
    self::nocache();
    echo '<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Info</title>
<style>
  body{font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px;background:#f6f7f9}
  .wrap{max-width:720px;margin:0 auto}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px}
  code{background:#f6f8fa;padding:2px 6px;border-radius:4px}
  a.btn{display:inline-block;background:#111;color:#fff;border:none;border-radius:6px;padding:10px 14px;text-decoration:none}
</style>
<div class="wrap">
  <h1>QR Info</h1>
  <div class="card">
    <p><strong>Slug:</strong> <code>'.esc_html($slug).'</code></p>
    <p><strong>Type:</strong> '.esc_html($row->type ?: '').' / '.esc_html($payload_type).'</p>'.
    ($qrData ? '<p><strong>Payload:</strong> <code>'.esc_html($qrData).'</code></p>' : '').
    '<p><a class="btn" href="'.esc_url($manage).'">Manage</a></p>
  </div>
</div>';
    exit;
  }

  /**
   * Front-end manage UI.
   * - website → edit target_url
   * - wifi    → edit SSID/security/password/hidden, rebuild PNG/PDF
   */
  private static function render_manage(string $slug){
    $row = self::get_row($slug);
    if (!$row){
      status_header(404);
      echo '<!doctype html><meta charset="utf-8"><title>QR not found</title><h1>QR not found</h1>';
      return;
    }

    // Require login
    if (!is_user_logged_in()){
      auth_redirect();
      return;
    }

    // Owner of order, or admin/editor
    $can = current_user_can('manage_options') || current_user_can('edit_pages');
    if (!$can){
      $user_id = get_current_user_id();
      if ((int)$row->customer_id === (int)$user_id){
        $can = true;
      }
    }
    if (!$can){
      status_header(403);
      echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title><h1>Forbidden</h1><p>You do not have access to edit this QR.</p>';
      return;
    }

    $type    = $row->type ?: 'dynamic';
    $pType   = $row->payload_type ?: 'website';
    $payload = json_decode((string)$row->payload_json, true);
    if (!is_array($payload)) { $payload = []; }

    $updated = false;
    $error   = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST'){
      // Use a per-slug nonce
      if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'arta_qr_manage_'.$slug)){
        status_header(400);
        echo '<!doctype html><meta charset="utf-8"><title>Bad Request</title><h1>Bad Request</h1>';
        return;
      }

      try {
        global $wpdb;
        $data = [];

        if ($pType === 'website'){
          $new = esc_url_raw(trim((string)($_POST['target_url'] ?? '')));
          if (!$new) { throw new \RuntimeException('Please enter a valid URL.'); }
          $data['target_url'] = $new;
          $updated = true;

        } elseif ($pType === 'wifi'){
          $ssid   = sanitize_text_field($_POST['wifi_ssid'] ?? '');
          $auth   = sanitize_text_field($_POST['wifi_auth'] ?? 'WPA');
          $pass   = sanitize_text_field($_POST['wifi_password'] ?? '');
          $hidden = !empty($_POST['wifi_hidden']) ? 'true' : 'false';
          if ($ssid === '') { throw new \RuntimeException('SSID is required.'); }

          $payload = [
            'ssid'     => $ssid,
            'auth'     => $auth,
            'password' => $pass,
            'hidden'   => $hidden,
          ];
          // Create native WIFI:… payload string for the QR PNG
          $qrData = \ArtaQR\Generator::build_qr_data('wifi', $payload);

          $data['payload_json'] = wp_json_encode($payload);
          $data['qr_data']      = $qrData;
          // Non-URL type: ensure no redirect target
          $data['target_url']   = null;
          $updated = true;

          // Regenerate PNG/PDF if paths exist
          if (!empty($row->png_path) && file_exists($row->png_path)){
            try { \ArtaQR\Generator::generate_png($qrData, $row->png_path, ['size'=>512, 'margin'=>4]); } catch (\Throwable $e) {}
          }
          if (!empty($row->pdf_path) && file_exists($row->pdf_path)){
            try {
              $meta = [
                'type'        => ucfirst($type).' / '.$pType,
                'display'     => $qrData,
                'dynamic_url' => '',
                'manage_url'  => trailingslashit(home_url('/q/'.$slug)).'manage',
                'destination' => '',
                'order_id'    => (int)$row->order_id,
              ];
              \ArtaQR\PDF::make_pdf($row->png_path, $meta, $row->pdf_path);
            } catch (\Throwable $e) {}
          }

        } else {
          // Extend with more payload types as you add editors
          throw new \RuntimeException('Editing this payload type from the manage page is not yet supported.');
        }

        if (!empty($data)){
          $wpdb->update($wpdb->prefix.'arta_qr_codes', $data, ['id' => (int)$row->id]);
          // reload current row for UI
          $row = self::get_row($slug);
        }
      } catch (\Throwable $e){
        $error = $e->getMessage();
      }
    }

    // Render UI
    $title = ($pType === 'website') ? 'Manage QR Destination' : 'Manage Wi-Fi QR';
    $home  = esc_url(home_url('/'));

    status_header(200);
    self::nocache();

    echo '<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>'.esc_html(get_bloginfo('name')).' – Manage QR</title>
<style>
  body{font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px;background:#f6f7f9}
  .wrap{max-width:720px;margin:0 auto}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px}
  .row{display:flex;gap:12px;margin-top:12px}
  label{display:block;margin:8px 0 4px;font-weight:600}
  input,select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px}
  .btn{display:inline-block;background:#111;color:#fff;border:none;border-radius:6px;padding:10px 14px;cursor:pointer;text-decoration:none}
  .msg{padding:10px;border-radius:6px;margin-bottom:10px}
  .ok{background:#e8f5e9;border:1px solid #a5d6a7}
  .err{background:#fdecea;border:1px solid #f5c2c7}
  code{background:#f6f8fa;padding:2px 6px;border-radius:4px}
</style>
<div class="wrap">
  <h1>'.esc_html($title).'</h1>
  <p>QR slug: <code>'.esc_html($slug).'</code></p>
  <div class="card">';

    if (!empty($updated)){
      echo '<div class="msg ok">Updated.</div>';
    }
    if (!empty($error)){
      echo '<div class="msg err">'.esc_html($error).'</div>';
    }

    echo '<form method="post">';
    wp_nonce_field('arta_qr_manage_'.$slug, '_wpnonce', true, true);

    if ($pType === 'website'){
      echo '
      <label for="target_url">Destination URL</label>
      <input type="url" name="target_url" id="target_url" placeholder="https://example.com/new" value="'.esc_attr($row->target_url ?: '').'" required>';
    } elseif ($pType === 'wifi'){
      $ssid   = esc_attr($payload['ssid'] ?? '');
      $auth   = esc_attr($payload['auth'] ?? 'WPA');
      $pass   = esc_attr($payload['password'] ?? '');
      $hidden = (($payload['hidden'] ?? 'false') === 'true') ? 'checked' : '';
      echo '
      <label for="wifi_ssid">Wi-Fi SSID</label>
      <input type="text" name="wifi_ssid" id="wifi_ssid" value="'.$ssid.'" required>
      <label for="wifi_auth">Security</label>
      <select name="wifi_auth" id="wifi_auth">
        <option value="WPA"'.($auth==='WPA'?' selected':'').'>WPA/WPA2/WPA3</option>
        <option value="WEP"'.($auth==='WEP'?' selected':'').'>WEP</option>
        <option value="nopass"'.($auth==='nopass'?' selected':'').'>Open (no password)</option>
      </select>
      <label for="wifi_password">Password</label>
      <input type="text" name="wifi_password" id="wifi_password" value="'.$pass.'">
      <label><input type="checkbox" name="wifi_hidden" value="1" '.$hidden.'> Hidden network</label>';
    } else {
      echo '<p>This payload type cannot be edited here yet.</p>';
    }

    echo '
      <div class="row">
        <button class="btn" type="submit">Save</button>
        <a class="btn" style="background:#555;margin-left:8px" href="'.$home.'">Back to site</a>
      </div>
    </form>
  </div>
</div>';
    exit;
  }

  /** Fetch one row by slug. */
  private static function get_row(string $slug){
    global $wpdb;
    $table = $wpdb->prefix.'arta_qr_codes';
    return $wpdb->get_row($wpdb->prepare(
      "SELECT id, slug, type, payload_type, payload_json, qr_data, target_url, png_path, pdf_path, order_id, customer_id, created_at FROM `$table` WHERE `slug`=%s LIMIT 1",
      sanitize_text_field($slug)
    ));
  }

  /** No-cache headers for scan/manage pages. */
  private static function nocache(){
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
}
