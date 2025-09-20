<?php
namespace ArtaQR;

if (!defined('ABSPATH')) exit;

class PDF {

  private static function ensureAutoload(): void {
    if (class_exists('\\Dompdf\\Dompdf')) return;
    $candidates = [
      dirname(__DIR__) . '/vendor/autoload.php',
      __DIR__ . '/../vendor/autoload.php',
      (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/arta-qr/vendor/autoload.php' : null),
    ];
    foreach ($candidates as $aut) { if ($aut && file_exists($aut)) require_once $aut; }
    if (class_exists('\\Dompdf\\Dompdf')) return;
    throw new \RuntimeException('Dompdf not found – please run composer install for arta-qr.');
  }

  public static function make_pdf(string $pngPath, array $meta, string $savePath): string {
    self::ensureAutoload();

    wp_mkdir_p(dirname($savePath));

    // Build data-URI image; flatten PNG transparency to JPEG for safety
    $imgTag = "<div style='width:320px;height:320px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;'>QR image unavailable</div>";
    $dataUri = '';

    if (file_exists($pngPath) && is_readable($pngPath)) {
      if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
        $im = @imagecreatefrompng($pngPath);
        if ($im) {
          $w = imagesx($im); $h = imagesy($im);
          $canvas = imagecreatetruecolor($w, $h);
          $white = imagecolorallocate($canvas, 255,255,255);
          imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
          imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
          ob_start(); imagejpeg($canvas, null, 92); $jpeg = ob_get_clean();
          imagedestroy($im); imagedestroy($canvas);
          if ($jpeg) $dataUri = 'data:image/jpeg;base64,'.base64_encode($jpeg);
        }
      }
      if ($dataUri === '') {
        $raw = @file_get_contents($pngPath);
        if ($raw) $dataUri = 'data:image/png;base64,'.base64_encode($raw);
      }
      if ($dataUri !== '') {
        $imgTag = "<img src='{$dataUri}' style='width:320px;height:auto;image-rendering:pixelated;'/>";
      }
    }

    $type        = $meta['type']        ?? '';
    $display     = $meta['display']     ?? '';
    $dynamic_url = $meta['dynamic_url'] ?? '';
    $manage_url  = $meta['manage_url']  ?? '';
    $destination = $meta['destination'] ?? '';
    $order_id    = (string)($meta['order_id'] ?? '');
    $caption     = isset($meta['caption']) ? trim((string)$meta['caption']) : '';
    $caption_html = '';
    if ($caption !== ''){
      $cap = function_exists('mb_substr') ? mb_substr($caption, 0, 30, 'UTF-8') : substr($caption, 0, 30);
      $cap = htmlspecialchars($cap, ENT_QUOTES, 'UTF-8');
      $caption_html = "<div style='margin-top:8px;text-align:center;font-family: Arial, \"DejaVu Sans\", Helvetica, sans-serif;font-size:17px;font-weight:700'>{$cap}</div>";
    }

    
    // Build customer table (from order)
    $customer_rows = '';
    $name = $phone = $email = $date_purchase = $payment_method = '';
    if (!empty($order_id)){
      $order = wc_get_order((int)$order_id);
      if ($order){
        $first = method_exists($order,'get_billing_first_name') ? $order->get_billing_first_name() : '';
        $last  = method_exists($order,'get_billing_last_name') ? $order->get_billing_last_name() : '';
        $name  = trim(trim($first.' '.$last));
        $phone = method_exists($order,'get_billing_phone') ? $order->get_billing_phone() : '';
        $email = method_exists($order,'get_billing_email') ? $order->get_billing_email() : '';
        $date_purchase = (method_exists($order,'get_date_created') && $order->get_date_created())
          ? $order->get_date_created()->date_i18n(get_option('date_format').' '.get_option('time_format'))
          : date_i18n(get_option('date_format').' '.get_option('time_format'));
        $payment_method = method_exists($order,'get_payment_method_title') ? $order->get_payment_method_title() : '';
      }
    }
    if (!empty($name))  $customer_rows .= "<tr><td>Customer name</td><td>".htmlspecialchars($name, ENT_QUOTES)."</td></tr>";
    if (!empty($phone)) $customer_rows .= "<tr><td>Phone number</td><td>".htmlspecialchars($phone, ENT_QUOTES)."</td></tr>";
    if (!empty($email)) $customer_rows .= "<tr><td>Email address</td><td>".htmlspecialchars($email, ENT_QUOTES)."</td></tr>";
    if (!empty($date_purchase)) $customer_rows .= "<tr><td>Date of purchase</td><td>".htmlspecialchars($date_purchase, ENT_QUOTES)."</td></tr>";
    if (!empty($payment_method)) $customer_rows .= "<tr><td>Payment method</td><td>".htmlspecialchars($payment_method, ENT_QUOTES)."</td></tr>";
$rows = '';
    $rows .= "<tr><td>Type</td><td>".htmlspecialchars($type, ENT_QUOTES)."</td></tr>";
    if ($display) {
      $rows .= "<tr><td>Payload</td><td><code>".htmlspecialchars($display, ENT_QUOTES)."</code></td></tr>";
    }
    if ($dynamic_url) {
      $safe = htmlspecialchars($dynamic_url, ENT_QUOTES);
      $rows .= "<tr><td>Scan URL</td><td><a href=\"{$safe}\">{$safe}</a></td></tr>";
    }
    if ($manage_url) {
      $safe = htmlspecialchars($manage_url, ENT_QUOTES);
      $rows .= "<tr><td>Manage URL</td><td><a href=\"{$safe}\">{$safe}</a></td></tr>";
    }
    if ($destination) {
      $safe = htmlspecialchars($destination, ENT_QUOTES);
      $rows .= "<tr><td>Destination</td><td><a href=\"{$safe}\">{$safe}</a></td></tr>";
    }
    if (!empty($meta['style_color_label'])){
      $rows .= "<tr><td>Color</td><td>".htmlspecialchars($meta['style_color_label'], ENT_QUOTES)."</td></tr>";
    }
    if (!empty($meta['style_frame_label'])){
      $rows .= "<tr><td>Frame</td><td>".htmlspecialchars($meta['style_frame_label'], ENT_QUOTES)."</td></tr>";
    }
    if (!empty($meta['style_visual_label'])){
      $rows .= "<tr><td>Visual style</td><td>".htmlspecialchars($meta['style_visual_label'], ENT_QUOTES)."</td></tr>";
    }
    if ($order_id !== '') {
      $rows .= "<tr><td>Order</td><td>#".htmlspecialchars($order_id, ENT_QUOTES)."</td></tr>";
    }

    $html = "
      <html><head><meta charset='utf-8'><style>
        body { font-family: DejaVu Sans, sans-serif; margin:40px; } .sitehead{font-weight:800;font-size:22px;margin-bottom:6px;text-align:left} .meta{border-collapse: collapse;width:100%;max-width:640px;} .meta th,.meta td{padding:6px 12px;border:1px solid #ddd;vertical-align:top;}
 .meta{font-size:9pt;} .note{color:#444;font-size:12px;margin-top:20px;} .thanks{margin-top:12px;font-weight:700;}
        .title { font-size:22px; margin-bottom:10px; }
        .qr { margin:20px 0; }
        .meta { border-collapse: collapse; width:100%; max-width:640px;}
        .meta td { padding:6px 12px; border:1px solid #ddd; vertical-align:top; }
        code { white-space:pre-wrap; word-break:break-word; }
        a { color:#1155cc; text-decoration:none; }
      </style></head><body>
        <div class='sitehead'>https://cheapjoesqr.com/</div><div class='title'>Your QR Code</div>
        <div class='qr'>{$imgTag}{$caption_html}</div>
        <table class='meta' style='margin-bottom:10px'>{$customer_rows}</table><table class='meta'>{$rows}</table>
        ".($manage_url ? "<p style='margin-top:14px;color:#444'>Tip: Use the <strong>Manage URL</strong> to change where this QR sends people. The QR itself does not change.</p>" : "")."
      <div class='note'>Thank you for your order. Your purchase is subject to our General Terms of Service, Refund & Returns Policy, and Privacy Policy. We may update these policies periodically; the version posted on our website at the time of purchase governs this order.</div><div class='thanks'>Thank you for your order!<br/>Cheap Joe&#039;s QR Company</div></body></html>
    ";

    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', true);
    $uploads = wp_upload_dir();
    if (!empty($uploads['basedir'])) $opts->setChroot($uploads['basedir']);
    $opts->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($opts);

    // --- Arta QR: add site name + order date above "Your QR code :" ---
    $site_header = 'www.CheapJoesQR.com';
    $order = ($order_id!=='') ? wc_get_order((int)$order_id) : null;
    $date_str = ($order && method_exists($order,'get_date_created') && $order->get_date_created())
      ? $order->get_date_created()->date_i18n(get_option('date_format'))
      : date_i18n(get_option('date_format'));

    $header = '<div style="text-align:center;font-weight:700;font-size:18px;margin:0">'
            . esc_html($site_header)
            . '</div><div style="text-align:center;font-size:12px;color:#555;margin:4px 0 12px">'
            . 'Order Date: ' . esc_html($date_str)
            . '</div>';

    if (stripos($html, 'Your QR code') !== false){
      $html = preg_replace('/Your\s+QR\s+code\s*:/i', $header.'Your QR code :', $html, 1);
    } elseif (stripos($html, 'You QR code') !== false){
      $html = preg_replace('/You\s+QR\s+code\s*:/i',  $header.'You QR code :',  $html, 1);
    } else {
      $html = $header . $html;
    }
    // --- end header injection ---

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $out = $dompdf->output();
    if (!$out) throw new \RuntimeException('Dompdf produced empty output');
    file_put_contents($savePath, $out);

    if (function_exists(__NAMESPACE__.'\\arta_qr_log')) arta_qr_log('PDF done: '.$savePath);
    return $savePath;
  }
}
