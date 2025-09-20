<?php
namespace ArtaQR;

if (!defined('ABSPATH')) exit;

class WooCommerce {

  public static function init(){
    add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'product_fields']);
    add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'capture_cart_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_item_meta'], 10, 4);
    add_action('woocommerce_order_status_completed', [__CLASS__, 'maybe_generate_qrs']);
    add_filter('woocommerce_email_attachments', [__CLASS__, 'attach_qr_pdfs'], 10, 4);
  
        // Checkout color field
		// (removed) checkout color field injection
  }

  /** Map of default colors (used elsewhere) */
  protected static function color_map(): array {
    return [
      ''           => '#000000', // default black
      'black'      => '#000000',
      'blue'       => '#1D4ED8',
      'red'        => '#DC2626',
      'orange'     => '#F97316',
      'green'      => '#16A34A',
      'purple'     => '#7C3AED',
      'pink'       => '#DB2777',
      'light_blue' => '#38BDF8',
      'gold'       => '#D4AF37',
    ];
  }

  private static function get_order_hex($order_id){
    $choice = (string) get_post_meta($order_id, '_arta_qr_color', true);
    $map = self::color_map();
    return $map[$choice] ?? '';
  }


  /** Dynamic product fields for QR options (rich UI) */
  public static function product_fields(){
    if (!function_exists('is_product') || !is_product()) return;

    // tiny helper for an input
    $f = function($name, $label, $type='text', $attrs=''){
      $id = esc_attr($name);
      echo '<p class="arta-qr-field"><label for="'.$id.'"><strong>'.esc_html($label).'</strong></label><br/>';
      echo '<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" id="'.$id.'" '.$attrs.' /></p>';
    };
    ?>
    <div class="arta-qr-fields" style="margin:12px 0;padding:14px;border:1px solid #e2e2e2;border-radius:6px;">
      <p><label><strong>QR Type</strong><br/>
        <select name="arta_qr_type" id="arta_qr_type" required>
          <option value="static">Static</option>
          <option value="dynamic">Dynamic</option>
        </select></label>
      </p>

      <p><label><strong>Payload</strong><br/>
        <select name="arta_qr_payload_type" id="arta_qr_payload_type">
          <option value="website">Website</option>
          <option value="wifi">Wi-Fi</option>
          <option value="contact">Contact (vCard/MeCard)</option>
          <option value="business">Text</option>
        </select>
      </p>

      <!-- Payload groups (shown/hidden via JS) -->
      <div id="arta_qr_group_website" class="arta-qr-group">
        <?php $f('arta_qr_website_url','Website URL','url','placeholder="https://yoursite.com/login?token=abc123"'); ?>
      </div>

      <div id="arta_qr_group_wifi" class="arta-qr-group" style="display:none;">
        <?php
          $f('arta_qr_wifi_ssid','Wi-Fi SSID');
          echo '<p><label><strong>'.esc_html__('Security','arta-qr').'</strong></label><br/>';
          echo '<select name="arta_qr_wifi_auth" id="arta_qr_wifi_auth">';
          echo '<option value="WPA">WPA/WPA2</option>';
          echo '<option value="WEP">WEP</option>';
          echo '<option value="NOPASS">Open (no password)</option>';
          echo '</select></p>';
          $f('arta_qr_wifi_password','Wi-Fi Password','text','autocomplete="new-password"');
          echo '<p><label><input type="checkbox" name="arta_qr_wifi_hidden" value="1" /> '.esc_html__('Hidden network','arta-qr').'</label></p>';
        ?>
      </div>

      <div id="arta_qr_group_contact" class="arta-qr-group" style="display:none;">
        <?php
          $f('arta_qr_contact_first','First name');
          $f('arta_qr_contact_last','Last name');
          $f('arta_qr_contact_phone','Phone');
          $f('arta_qr_contact_email','Email','email');
        ?>
      </div>

      <div id="arta_qr_group_business" class="arta-qr-group" style="display:none;">
        <?php $f('arta_qr_business_text','Text'); ?>
      </div>

   

      <?php wp_nonce_field('arta_qr_add','arta_qr_nonce'); ?>
    </div>

    <script>
    (function(){
      const sel = document.getElementById('arta_qr_payload_type');
      const groups = {
        website:  document.getElementById('arta_qr_group_website'),
        wifi:     document.getElementById('arta_qr_group_wifi'),
        contact:  document.getElementById('arta_qr_group_contact'),
        business: document.getElementById('arta_qr_group_business'),
      };
      function refresh(){
        const v = sel ? sel.value : 'website';
        Object.keys(groups).forEach(k => { if(groups[k]) groups[k].style.display = (k===v)?'block':'none'; });
      }
      if(sel){
        sel.addEventListener('change', refresh);
        refresh();
      }
    })();
    </script>
    <?php
  }

  /** Capture posted values based on payload selection */
  public static function capture_cart_data($cart_item_data, $product_id){
    if (empty($_POST['arta_qr_nonce']) || !wp_verify_nonce($_POST['arta_qr_nonce'], 'arta_qr_add')) {
      return $cart_item_data;
    }

    $payload_type = sanitize_text_field($_POST['arta_qr_payload_type'] ?? 'website');
    $type         = sanitize_text_field($_POST['arta_qr_type'] ?? 'static');
    $caption      = sanitize_text_field($_POST['arta_qr_caption'] ?? '');
    if (function_exists('mb_substr')) { $caption = mb_substr($caption, 0, 30, 'UTF-8'); } else { $caption = substr($caption, 0, 30); }

    // Only keep the payloads you support
    $allowed_payloads = ['website','wifi','contact','business'];
    if (!in_array($payload_type, $allowed_payloads, true)) {
      $payload_type = 'website';
    }

    $payload = [];
    switch($payload_type){
      case 'website':
        $payload['url'] = esc_url_raw($_POST['arta_qr_website_url'] ?? '');
        break;

      case 'wifi':
        $payload['ssid']     = sanitize_text_field($_POST['arta_qr_wifi_ssid'] ?? '');
        $payload['auth']     = sanitize_text_field($_POST['arta_qr_wifi_auth'] ?? 'WPA');
        $payload['password'] = sanitize_text_field($_POST['arta_qr_wifi_password'] ?? '');
        $payload['hidden']   = !empty($_POST['arta_qr_wifi_hidden']) ? 'true' : 'false';
        break;

      case 'contact':
        $payload['first'] = sanitize_text_field($_POST['arta_qr_contact_first'] ?? '');
        $payload['last']  = sanitize_text_field($_POST['arta_qr_contact_last'] ?? '');
        $payload['phone'] = sanitize_text_field($_POST['arta_qr_contact_phone'] ?? '');
        $payload['email'] = sanitize_email($_POST['arta_qr_contact_email'] ?? '');
        break;

      case 'business':
        $payload['text']  = sanitize_text_field($_POST['arta_qr_business_text'] ?? '');
        break;
    }

    $cart_item_data['arta_qr'] = [
      'type'         => $type,
      'payload_type' => $payload_type,
      'payload'      => $payload,
      'caption'      => $caption,
    ];
    return $cart_item_data;
  }

  /** Persist to order item */
  public static function save_item_meta($item, $cart_item_key, $values, $order){
    if (!empty($values['arta_qr'])) {
      $item->add_meta_data('_arta_qr', $values['arta_qr']);
    }
  }

  /** Generate assets on Completed orders (includes manage_url + destination in PDF) */
  public static function maybe_generate_qrs($order_id){
    try{
      $aut = dirname(__DIR__).'/vendor/autoload.php';
      if (file_exists($aut)) require_once $aut;

      $order = wc_get_order($order_id);
      if (!$order) return;

      global $wpdb;

      foreach ($order->get_items() as $item_id => $item){
        $meta = $item->get_meta('_arta_qr');
        if (!$meta || !is_array($meta)) continue;

        $type        = $meta['type']         ?? 'static';
        $payloadType = $meta['payload_type'] ?? 'website';
        $payload     = $meta['payload']      ?? [];
        $caption     = $meta['caption']      ?? '';

        $qrData     = '';
        $display    = '';
        $dynamicUrl = '';
        $manageUrl  = '';
        $destUrl    = '';

        // (dynamic routing etc… your existing logic stays unchanged)
        if ($type === 'dynamic'){
          $slug        = wp_generate_password(8, false, false);

          if ($payloadType === 'website'){
            // Website dynamics: QR encodes /q/{slug} which redirects to target_url
            $destUrl     = $payload['url'] ?? home_url('/');
            $qrData      = home_url('/q/'.$slug); // this is what the QR encodes for website
            $display     = $qrData;
            $dynamicUrl  = $qrData;
          } else {
            // Non-URL dynamics
            $destUrl     = '';
            $qrData      = \ArtaQR\Generator::build_qr_data($payloadType, $payload);
            $display     = $qrData;
            $dynamicUrl  = '';
          }

          // store slug/manage rows in your table as you already do...
          $row_id = 0; // (left as-is)
        } else {
          // static
          $qrData  = \ArtaQR\Generator::build_qr_data($payloadType, $payload);
          $display = ($payloadType === 'website') ? ($payload['url'] ?? '') : $qrData;
          $row_id = 0;
        }

        // choose a filename (you already have your logic here) …
        $buyer_name = trim($order->get_formatted_billing_full_name());
        if ($buyer_name === '') { $buyer_name = trim($order->get_billing_first_name().' '.$order->get_billing_last_name()); }
        if ($buyer_name === '') { $buyer_name = 'customer'; }
        $buyer_slug = sanitize_title($buyer_name);
        $base_name  = $buyer_slug !== '' ? $buyer_slug : 'customer';
        $suffix     = (string) $item_id;
        $baseDirURL = wp_upload_dir();
        $dir = trailingslashit($baseDirURL['basedir']).'arta-qr/'.intval($order_id).'/';
        $url = trailingslashit($baseDirURL['baseurl']).'arta-qr/'.intval($order_id).'/';
        wp_mkdir_p($dir);
        $filename = $base_name.'-'.$suffix.'.png';
        $pngPath  = $dir.$filename;

        // Style array — include caption; color/frame can be injected elsewhere
        $style = [
          'margin'  => 2,
          'scale'   => 8,
          'caption' => $caption,
        ];

        // Allow external filters to apply per-item style (color/frame)
        if (has_filter('arta_qr_generate_style_item')){
          try {
            $style = apply_filters('arta_qr_generate_style_item', $style, $order_id, $item_id);
          } catch (\Throwable $e) {
            if (function_exists('error_log')) error_log('[ArtaQR] style inject: '.$e->getMessage());
          }
        }

        \ArtaQR\Generator::generate_png($qrData, $pngPath, $style);

        // PDF meta includes manage_url + destination for dynamic
        
        // Collect style labels for PDF
        $color_key  = (string)$item->get_meta(\ArtaQR\Styles::ITEM_COLOR_META);
        $frame_key  = (string)$item->get_meta(\ArtaQR\Styles::ITEM_FRAME_META);
        $visual_key = (string)$item->get_meta(\ArtaQR\Styles::ITEM_VISUAL_META);

        $color_label = $color_key;
        $frame_label = $frame_key;
        $visual_label = $visual_key;

        if (method_exists('\\ArtaQR\\Styles','color_map')){
          $cmap = \ArtaQR\Styles::color_map();
          if (isset($cmap[$color_key])) $color_label = $cmap[$color_key][0];
        }
        if (method_exists('\\ArtaQR\\Styles','frame_styles')){
          $fmap = \ArtaQR\Styles::frame_styles();
          if (isset($fmap[$frame_key])) $frame_label = $fmap[$frame_key];
        }
        $vmap = [
          'solid' => 'Single solid color',
          'two_tone' => 'Two-tone',
          'gradient_multi' => 'Gradient – Multi-stop',
          'texture_halftone' => 'Texture – Halftone',
          'negative' => 'Negative',
          // legacy keys:
          'gradient_linear' => 'Gradient – Linear',
          'gradient_radial' => 'Gradient – Radial',
          'texture_crosshatch' => 'Texture – Crosshatch',
        ];
        if (isset($vmap[$visual_key])) $visual_label = $vmap[$visual_key];
$pdfMeta = [
          'type'        => ucfirst($type).' / '.$payloadType,
          'display'     => $display,
          'dynamic_url' => $dynamicUrl, // empty for static/frame
          'manage_url'  => $manageUrl,  // empty for static/frame
          'destination' => $destUrl,    // empty for static/frame
          'order_id'    => $order_id,
          'caption'     => $caption,
          'style_color_key' => $color_key,
          'style_color_label' => $color_label,
          'style_frame_key' => $frame_key,
          'style_frame_label' => $frame_label,
          'style_visual_key' => $visual_key,
          'style_visual_label' => $visual_label,
        ];

        $pdfPath = $dir.str_replace('.png','.pdf',$filename);
        \ArtaQR\PDF::make_pdf($pngPath, $pdfMeta, $pdfPath);

        if ($row_id){
          $wpdb->update($wpdb->prefix.'arta_qr_codes', [
            'png_path' => $pngPath,
            'pdf_path' => $pdfPath,
          ], ['id' => $row_id]);
        }

        $item->add_meta_data('_arta_qr_pdf_path', $pdfPath, true);
        $item->save();
      }
    } catch (\Throwable $e){
      if (function_exists('error_log')) error_log('[ArtaQR] maybe_generate_qrs error: '.$e->getMessage());
    }
  }

  /** Email attachments */
  public static function attach_qr_pdfs($attachments, $email_id, $order, $email){
    if (!($order instanceof \WC_Order)) return $attachments;
    if ($email_id !== 'customer_completed_order') return $attachments;

    foreach ($order->get_items() as $item_id => $item){
      $pdf = $item->get_meta('_arta_qr_pdf_path');
      if ($pdf && file_exists($pdf)) $attachments[] = $pdf;
    }
    return $attachments;
  }
}
