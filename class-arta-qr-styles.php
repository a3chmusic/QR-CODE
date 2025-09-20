<?php
/**
 * Arta QR – Product Options (Color/Frame/Visual/Shape) + Live Preview
 */
namespace ArtaQR;

if (!defined('ABSPATH')) exit;

if (!class_exists('\\ArtaQR\\Styles')){
final class Styles {

    const ITEM_COLOR_META   = '_arta_qr_color_item';
    const ITEM_FRAME_META   = '_arta_qr_frame_item';
    const ITEM_CAPTION_META = '_arta_qr_caption_item';
    const ITEM_VISUAL_META  = '_arta_qr_visual_item';
    const ITEM_SHAPE_META   = '_arta_qr_shape_item';

    private static $did_render = false; // prevent duplicate UI

    /** Map of color key => [label, hex] */
    public static function color_map(): array {
        return [
            'black' => ['Black', '#000000'],
            'blue'  => ['Blue',  '#0057ff'],
            'red'   => ['Red',   '#e53935'],
            'green' => ['Green', '#2e7d32'],
            'pink'  => ['Pink',  '#d81b60'],
        ];
    }

    /** Map of frame key => label */
    public static function frame_styles(): array {
        return [
            'none'    => 'No Frame',
            'classic' => 'Classic',
            'rounded' => 'Rounded',
        ];
    }

    public static function init(){
        // Primary hook (standard)
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render_product_ui'], 50);
        // Fallback hook (some themes move the button block)
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render_product_ui'], 35);

        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_item_meta'], 10, 4);

        add_filter('arta_qr_generate_style_item', [__CLASS__, 'filter_generate_style_item'], 10, 3);
    }

    /** Render product UI controls and live preview */
    public static function render_product_ui(){
        if (self::$did_render) return; // avoid double output if both hooks fire
        self::$did_render = true;

        $plugin_root  = plugin_dir_url(dirname(__FILE__)); // .../wp-content/plugins/arta-qr/
        $previews_url = $plugin_root . 'assets/previews/';

        $colors = self::color_map();
        $frames = self::frame_styles();

        $default_color_key = array_key_exists('black',$colors) ? 'black' : array_key_first($colors);
        $default_frame_key = array_key_exists('classic',$frames) ? 'classic' : array_key_first($frames);
        $default_visual    = 'solid';
        $default_shape     = 'square';

        $default_img = esc_url($previews_url . $default_visual . '-' . $default_frame_key . '-' . $default_color_key . '.png');
        ?>
        <div class="artaqr-options" style="margin:16px 0;">
            <div class="artaqr-preview" style="display:flex;flex-direction:column;align-items:center">
                <img id="artaqr-live-preview"
                     src="<?php echo $default_img; ?>"
                     alt="QR Preview"
                     style="max-width:320px;height:auto;border:1px solid #eee;border-radius:10px;padding:10px;background:#fff" />
                <div id="artaqr-caption-preview" style="font-family:Arial, sans-serif;font-weight:700;font-size:17px;margin-top:8px;text-align:center"></div>
                <div id="artaqr-visual-note" style="margin-top:6px;font-size:12px;color:#555;text-align:center"></div>
            </div>

            <div class="artaqr-controls" style="margin-top:16px">
                <p>
                    <label for="arta_qr_frame" style="font-weight:600;">Frame style</label><br/>
                    <select id="arta_qr_frame" name="arta_qr_frame" style="min-width:260px">
                        <?php foreach($frames as $key=>$label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($default_frame_key,$key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="arta_qr_color" style="font-weight:600;">Color</label><br/>
                    <select id="arta_qr_color" name="arta_qr_color" style="min-width:260px">
                        <?php foreach($colors as $key=>$def): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($default_color_key,$key); ?>>
                                <?php echo esc_html($def[0]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="arta_qr_visual" style="font-weight:600;">Visual style</label><br/>
                    <select id="arta_qr_visual" name="arta_qr_visual" style="min-width:260px">
                        <option value="solid" selected>Single solid color (default)</option>
                        <option value="two_tone">Two-tone (tinted background)</option>
                        <option value="negative">Negative (light modules on dark)</option>
                    </select>
                </p>

                <p>
                    <label for="arta_qr_shape" style="font-weight:600;">Module shape</label><br/>
                    <select id="arta_qr_shape" name="arta_qr_shape" style="min-width:260px">
                        <option value="square" selected>Square (default)</option>
                        <option value="dots">Dots</option>
                        <option value="triangles">Triangles</option>
                        <option value="diamond">Diamond</option>
                    </select>
                </p>

                <p><label for="arta_qr_caption" style="font-weight:600;">Caption under QR (optional)</label><br/>
                    <input type="text"
                           id="arta_qr_caption"
                           name="arta_qr_caption"
                           maxlength="30"
                           placeholder="Max 30 characters"
                           style="width:100%;max-width:420px;" />
                    <small>Shown under the preview and printed under the QR in PNG/PDF.</small>
                </p>
            </div>
        </div>

        <script>
        (function(){
            const $ = (sel)=>document.querySelector(sel);
            const frameSel   = $('#arta_qr_frame');
            const colorSel   = $('#arta_qr_color');
            const visualSel  = $('#arta_qr_visual');
            const shapeSel   = $('#arta_qr_shape');
            const captionInp = $('#arta_qr_caption');
            const img        = $('#artaqr-live-preview');
            const capPrev    = $('#artaqr-caption-preview');
            const visualNote = $('#artaqr-visual-note');
            const previewsBase = <?php echo json_encode($previews_url); ?>;

            const visualLabels = {
                solid: 'Single solid color',
                two_tone: 'Two-tone (tinted background)',
                negative: 'Negative (light modules on dark)'
            };

            function updateVisualNote(){
                if (!visualNote) return;
                const key = visualSel && visualSel.value ? visualSel.value : 'solid';
                const label = visualLabels[key] || key;
                visualNote.textContent = 'Visual style: ' + label + ' (applied to PNG/PDF & preview image)';
            }

            function updateImage(){
                const frameKey  = frameSel && frameSel.value ? frameSel.value : '<?php echo esc_js($default_frame_key); ?>';
                const colorKey  = colorSel && colorSel.value ? colorSel.value : '<?php echo esc_js($default_color_key); ?>';
                const visualKey = visualSel && visualSel.value ? visualSel.value : 'solid';
                const shapeKey  = shapeSel && shapeSel.value ? shapeSel.value : 'square';
                const visualSlug = visualKey.replace(/_/g,'-');
                const shapeSlug  = shapeKey.replace(/_/g,'-');

                const candidates = [
                    visualSlug + '-' + shapeSlug + '-' + frameKey + '-' + colorKey + '.png',
                    visualSlug + '-' + frameKey + '-' + colorKey + '.png',
                    'solid-' + frameKey + '-' + colorKey + '.png',
                    'solid-classic-black.png'
                ];
                if (!img) return;
                let idx = 0;
                function tryNext(){
                    if (idx >= candidates.length) return;
                    img.onerror = function(){ idx++; tryNext(); };
                    img.src = previewsBase + candidates[idx];
                }
                tryNext();
            }
            function updateCaption(){
                if (!capPrev) return;
                const v = captionInp && captionInp.value ? captionInp.value : '';
                capPrev.textContent = v;
            }

            if (frameSel)  frameSel.addEventListener('change', updateImage);
            if (colorSel)  colorSel.addEventListener('change', updateImage);
            if (visualSel) visualSel.addEventListener('change', ()=>{ updateImage(); updateVisualNote(); });
            if (shapeSel)  shapeSel.addEventListener('change', ()=>{ updateImage(); });
            if (captionInp){
                captionInp.addEventListener('input', updateCaption);
                captionInp.addEventListener('change', updateCaption);
            }

            updateImage();
            updateVisualNote();
            updateCaption();
        })();
        </script>
        <?php
    }

    /** Add cart item data upon add to cart */
    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id){
        if (isset($_POST['arta_qr_color']))   $cart_item_data[self::ITEM_COLOR_META]   = sanitize_text_field(wp_unslash($_POST['arta_qr_color']));
        if (isset($_POST['arta_qr_frame']))   $cart_item_data[self::ITEM_FRAME_META]   = sanitize_text_field(wp_unslash($_POST['arta_qr_frame']));
        if (isset($_POST['arta_qr_caption'])) $cart_item_data[self::ITEM_CAPTION_META] = sanitize_text_field(wp_unslash($_POST['arta_qr_caption']));
        if (isset($_POST['arta_qr_visual']))  $cart_item_data[self::ITEM_VISUAL_META]  = sanitize_text_field(wp_unslash($_POST['arta_qr_visual']));
        if (isset($_POST['arta_qr_shape']))   $cart_item_data[self::ITEM_SHAPE_META]   = sanitize_text_field(wp_unslash($_POST['arta_qr_shape']));
        return $cart_item_data;
    }

    /** Display selected data in cart/checkout */
    public static function display_cart_item_data($item_data, $cart_item){
        if (isset($cart_item[self::ITEM_VISUAL_META])){
            $val = (string)$cart_item[self::ITEM_VISUAL_META];
            $labels = [
                'solid'=>'Single solid',
                'two_tone'=>'Two-tone',
                'negative'=>'Negative',
            ];
            $label = $labels[$val] ?? $val;
            $item_data[] = ['key'=>__('Visual style','arta-qr'),'value'=>esc_html($label),'display'=>esc_html($label)];
        }
        if (isset($cart_item[self::ITEM_SHAPE_META])){
            $val = (string)$cart_item[self::ITEM_SHAPE_META];
            $labels = [
                'square'=>'Square',
                'dots'=>'Dots',
                'triangles'=>'Triangles',
                'diamond'=>'Diamond',
            ];
            $label = $labels[$val] ?? $val;
            $item_data[] = ['key'=>__('Module shape','arta-qr'),'value'=>esc_html($label),'display'=>esc_html($label)];
        }
        if (isset($cart_item[self::ITEM_FRAME_META])){
            $frames = self::frame_styles();
            $key = $cart_item[self::ITEM_FRAME_META];
            $label = $frames[$key] ?? $key;
            $item_data[] = ['key'=>__('Frame','arta-qr'),'value'=>esc_html($label),'display'=>esc_html($label)];
        }
        if (isset($cart_item[self::ITEM_COLOR_META])){
            $colors = self::color_map();
            $key = $cart_item[self::ITEM_COLOR_META];
            $label = $colors[$key][0] ?? $key;
            $item_data[] = ['key'=>__('Color','arta-qr'),'value'=>esc_html($label),'display'=>esc_html($label)];
        }
        if (isset($cart_item[self::ITEM_CAPTION_META])){
            $cap = (string) $cart_item[self::ITEM_CAPTION_META];
            if ($cap !== '') $item_data[] = ['key'=>__('Caption','arta-qr'),'value'=>esc_html($cap),'display'=>esc_html($cap)];
        }
        return $item_data;
    }

    /** Save selected data to order item meta */
    public static function save_order_item_meta($item, $cart_item_key, $values, $order){
        if (isset($values[self::ITEM_COLOR_META]))   $item->add_meta_data(self::ITEM_COLOR_META,   sanitize_text_field((string)$values[self::ITEM_COLOR_META]));
        if (isset($values[self::ITEM_FRAME_META]))   $item->add_meta_data(self::ITEM_FRAME_META,   sanitize_text_field((string)$values[self::ITEM_FRAME_META]));
        if (isset($values[self::ITEM_CAPTION_META])) $item->add_meta_data(self::ITEM_CAPTION_META, sanitize_text_field((string)$values[self::ITEM_CAPTION_META]));
        if (isset($values[self::ITEM_VISUAL_META]))  $item->add_meta_data(self::ITEM_VISUAL_META,  sanitize_text_field((string)$values[self::ITEM_VISUAL_META]));
        if (isset($values[self::ITEM_SHAPE_META]))   $item->add_meta_data(self::ITEM_SHAPE_META,   sanitize_text_field((string)$values[self::ITEM_SHAPE_META]));
    }

    public static function get_item_hex($order_id, $order_item_id): string {
        $order = wc_get_order($order_id);
        if (!$order) return '';
        $item  = $order->get_item($order_item_id);
        if (!$item) return '';
        $key = (string) $item->get_meta(self::ITEM_COLOR_META);
        $map = self::color_map();
        return isset($map[$key]) ? $map[$key][1] : '';
    }

    public static function get_item_visual($order_id, $order_item_id): string {
        $order = wc_get_order($order_id);
        if (!$order) return 'solid';
        $item  = $order->get_item($order_item_id);
        if (!$item) return 'solid';
        $choice = (string) $item->get_meta(self::ITEM_VISUAL_META);
        $allowed = ['solid','two_tone','negative'];
        return in_array($choice, $allowed, true) ? $choice : 'solid';
    }

    public static function get_item_shape($order_id, $order_item_id): string {
        $order = wc_get_order($order_id);
        if (!$order) return 'square';
        $item  = $order->get_item($order_item_id);
        if (!$item) return 'square';
        $choice = (string) $item->get_meta(self::ITEM_SHAPE_META);
        $allowed = ['square','dots','triangles','diamond'];
        return in_array($choice, $allowed, true) ? $choice : 'square';
    }

    public static function get_item_frame($order_id, $order_item_id): string {
        $order = wc_get_order($order_id);
        if (!$order) return 'none';
        $item  = $order->get_item($order_item_id);
        if (!$item) return 'none';
        $choice = (string) $item->get_meta(self::ITEM_FRAME_META);
        $frames = self::frame_styles();
        return array_key_exists($choice, $frames) ? $choice : 'none';
    }

    /** Pass selected color/frame/visual/shape to generator */
    public static function filter_generate_style_item($style, $order_id, $order_item_id){
        $style = is_array($style) ? $style : [];
        $hex    = self::get_item_hex($order_id, $order_item_id);
        $frame  = self::get_item_frame($order_id, $order_item_id);
        $visual = self::get_item_visual($order_id, $order_item_id);
        $shape  = self::get_item_shape($order_id, $order_item_id);
        if ($hex)    $style['color'] = $hex;
        if ($frame)  $style['frame_style'] = $frame;
        if ($visual) $style['visual'] = $visual;
        if ($shape)  $style['shape'] = $shape;
        return $style;
    }
}
}
// Ensure init is called (in case the bootstrap doesn't call it)
if (class_exists('\\ArtaQR\\Styles')) { \ArtaQR\Styles::init(); }
