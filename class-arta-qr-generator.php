<?php
namespace ArtaQR;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (!defined('ABSPATH')) exit;

class Generator {

  protected static function log($msg){
    if (defined('WP_DEBUG') && WP_DEBUG){
      error_log('[ArtaQR] '.$msg);
    }
  }

  protected static function normalize_hex($hex){
    $hex = trim((string)$hex);
    if ($hex === '') return '';
    if ($hex[0] !== '#') $hex = '#'.$hex;
    if (!preg_match('/^#([0-9a-f]{6})$/i', $hex)) return '';
    return strtoupper($hex);
  }

  protected static function hex2rgb($hex){
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  }

  /** Build QR data string */
  public static function build_qr_data(string $payloadType, array $d): string {
    switch ($payloadType){
      case 'website':
        return isset($d['url']) ? (string)$d['url'] : '';

      case 'wifi':
        $t = strtoupper($d['auth'] ?? 'WPA');
        $s = (string)($d['ssid'] ?? '');
        $p = (string)($d['password'] ?? '');
        $h = (!empty($d['hidden']) && $d['hidden'] !== 'false') ? 'true' : 'false';
        $esc = function($v){ return strtr($v, [';' => '\;', ',' => '\,', ':' => '\:', '"' => '\"']); };
        if ($t === 'NOPASS'){ $p = ''; }
        return 'WIFI:T:'.$t.';S:'.$esc($s).';'.($p!==''?'P:'.$esc($p).';':'').'H:'.$h.';';

      case 'contact':
        $first = (string)($d['first'] ?? '');
        $last  = (string)($d['last'] ?? '');
        $phone = (string)($d['phone'] ?? '');
        $email = sanitize_email($d['email'] ?? '');
        $v  =  "BEGIN:VCARD\nVERSION:3.0\n";
        $v .= "N:{$last};{$first};;;\n";
        $v .= "FN:{$first} {$last}\n";
        if ($phone !== '') $v .= "TEL;TYPE=CELL:{$phone}\n";
        if ($email !== '') $v .= "EMAIL:{$email}\n";
        $v .= "END:VCARD";
        return $v;

      case 'business':
        return (string)($d['text'] ?? '');

      default:
        return '';
    }
  }

  /* ---------- PNG generator (color + frame + caption) ---------- */
  public static function generate_png(string $data, string $savePath, array $style = []): string {
    if (!class_exists('\\chillerlan\\QRCode\\QRCode')){
      $autoloads = [
        dirname(__DIR__).'/vendor/autoload.php',
        __DIR__.'/../vendor/autoload.php',
        defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR.'/arta-qr/vendor/autoload.php' : null,
      ];
      foreach ($autoloads as $a){
        if ($a && file_exists($a)) { require_once $a; break; }
      }
    }

    $hex   = self::normalize_hex($style['color'] ?? '');
    $frame = (string)($style['frame_style'] ?? 'none');

    $optsArr = [
      'eccLevel'    => $style['ecc']    ?? QRCode::ECC_H,
      'scale'       => $style['scale']  ?? 10,
      'margin'      => $style['margin'] ?? 2,
      'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
      'imageBase64' => false,
    ];

    if ($hex){
      [$r,$g,$b] = self::hex2rgb($hex);
      $optsArr['moduleValues'] = [
        'L' => [255,255,255],
        'D' => [$r,$g,$b],
      ];
    }

    $opts = new QROptions($optsArr);
    $png  = (new QRCode($opts))->render($data);

    if (is_string($png) && strncmp($png, 'data:image', 10) === 0 && preg_match('#^data:image/[^;]+;base64,(.+)$#', $png, $m)) {
      $png = base64_decode($m[1]);
    }
    if (!is_string($png)){
      self::log('QR render did not return a string');
      return $savePath;
    }

    wp_mkdir_p(dirname($savePath));

    file_put_contents($savePath, $png);

    // Recolor + apply module styles (gradient/two-tone/negative) and shapes
    if (function_exists('imagecreatefrompng') && is_readable($savePath)){
      try {
        $im = @imagecreatefrompng($savePath);
        if ($im){
          $w = imagesx($im); $h = imagesy($im);

          if ($w > 0 && $h > 0){
            $visual = (string)($style['visual'] ?? 'solid');
            $shape  = strtolower((string)($style['shape'] ?? 'square'));

            $hexVis = self::normalize_hex($style['color'] ?? '');
            if ($hexVis === '') $hexVis = '#000000';
            [$fr,$fg,$fb] = self::hex2rgb($hexVis);

            $blend = function($a,$b,$t){ return (int)round($a + ($b - $a) * max(0.0, min(1.0, $t))); };
            $lighten = function($r,$g,$b,$t) use($blend){ return [$blend($r,255,$t), $blend($g,255,$t), $blend($b,255,$t)]; };
            $darken  = function($r,$g,$b,$t) use($blend){ return [$blend($r,0,$t), $blend($g,0,$t), $blend($b,0,$t)]; };

            $bg_rgb    = [255,255,255];
            $mod_color = [$fr,$fg,$fb];
            if ($visual === 'two_tone'){
              $bg_rgb   = $lighten($fr,$fg,$fb,0.85);
              $mod_color = $darken($fr,$fg,$fb,0.15);
            } elseif ($visual === 'negative'){
              $bg_rgb    = [$fr,$fg,$fb];
              $mod_color = [255,255,255];
            }

            $grad = function($x,$y) use($w,$h,$fr,$fg,$fb,$lighten,$darken,$blend,$visual){
              if ($visual === 'gradient_linear'){
                $t = ($w > 1) ? ($x / max(1,$w - 1)) : 0.0;
                [$r2,$g2,$b2] = $lighten($fr,$fg,$fb,0.35);
                return [$blend($fr,$r2,$t), $blend($fg,$g2,$t), $blend($fb,$b2,$t)];
              }
              if ($visual === 'gradient_radial'){
                $cx = ($w - 1) / 2; $cy = ($h - 1) / 2;
                $dx = $x - $cx; $dy = $y - $cy;
                $dist = sqrt($dx * $dx + $dy * $dy);
                $maxd = sqrt($cx * $cx + $cy * $cy);
                $t = $maxd > 0 ? min(1.0, $dist / $maxd) : 0.0;
                [$r2,$g2,$b2] = $lighten($fr,$fg,$fb,0.45);
                return [$blend($r2,$fr,$t), $blend($g2,$fg,$t), $blend($b2,$fb,$t)];
              }
              if ($visual === 'gradient_multi'){
                $t = ($h > 1) ? ($y / max(1,$h - 1)) : 0.0;
                [$r1,$g1,$b1] = [$fr,$fg,$fb];
                [$r2,$g2,$b2] = $lighten($fr,$fg,$fb,0.30);
                [$r3,$g3,$b3] = $darken($fr,$fg,$fb,0.20);
                if ($t < 0.5){
                  $tt = $t / 0.5;
                  return [$blend($r1,$r2,$tt), $blend($g1,$g2,$tt), $blend($b1,$b2,$tt)];
                }
                $tt = ($t - 0.5) / 0.5;
                return [$blend($r2,$r3,$tt), $blend($g2,$g3,$tt), $blend($b2,$b3,$tt)];
              }
              return [$fr,$fg,$fb];
            };

            $texture = function($x,$y) use($fr,$fg,$fb,$lighten,$darken,$visual){
              if ($visual === 'texture_crosshatch'){
                $m = (($x % 6) === 0) || (($y % 6) === 0);
                return $m ? $darken($fr,$fg,$fb,0.25) : [$fr,$fg,$fb];
              }
              if ($visual === 'texture_halftone'){
                $d = ((($x & 3) === 0) && (($y & 3) === 0)) || (((($x + 2) & 3) === 0) && ((($y + 2) & 3) === 0));
                return $d ? $lighten($fr,$fg,$fb,0.35) : [$fr,$fg,$fb];
              }
              return [$fr,$fg,$fb];
            };

            $moduleColor = function($x,$y) use($visual,$mod_color,$grad,$texture){
              if ($visual === 'gradient_linear' || $visual === 'gradient_radial' || $visual === 'gradient_multi'){
                return $grad($x,$y);
              }
              if ($visual === 'texture_crosshatch' || $visual === 'texture_halftone'){
                return $texture($x,$y);
              }
              if ($visual === 'negative'){
                return [255,255,255];
              }
              return $mod_color;
            };

            $bgColorForModule = function($visual,$bg_rgb){
              if ($visual === 'two_tone' || $visual === 'negative'){
                return $bg_rgb;
              }
              return [255,255,255];
            };

            $moduleSize = (int)($style['scale'] ?? 10);
            if ($moduleSize < 1) $moduleSize = 10;
            $moduleSize = self::detect_module_size($im, $moduleSize);
            if ($moduleSize < 1) $moduleSize = 1;

            $modulesX = (int)max(1, round($w / $moduleSize));
            $modulesY = (int)max(1, round($h / $moduleSize));

            $canvas = imagecreatetruecolor($w, $h);
            imagesavealpha($canvas, true);
            imagealphablending($canvas, true);

            $colorCache = [];
            $alloc = function($img, array $rgb, int $alpha = 0) use (&$colorCache){
              $key = implode(',', [$rgb[0],$rgb[1],$rgb[2],$alpha]);
              if (!isset($colorCache[$key])){
                $r = max(0, min(255, (int)$rgb[0]));
                $g = max(0, min(255, (int)$rgb[1]));
                $b = max(0, min(255, (int)$rgb[2]));
                $colorCache[$key] = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha)));
              }
              return $colorCache[$key];
            };

            $baseBg = $alloc($canvas, [255,255,255], 0);
            imagefilledrectangle($canvas, 0, 0, $w - 1, $h - 1, $baseBg);

            $bgDefault = $bgColorForModule($visual, $bg_rgb);

            for ($my = 0; $my < $modulesY; $my++){
              for ($mx = 0; $mx < $modulesX; $mx++){
                $left   = (int)($mx * $moduleSize);
                $top    = (int)($my * $moduleSize);
                $right  = min($w - 1, (int)(($mx + 1) * $moduleSize) - 1);
                $bottom = min($h - 1, (int)(($my + 1) * $moduleSize) - 1);
                if ($right < $left) $right = $left;
                if ($bottom < $top) $bottom = $top;

                $cx = min($w - 1, (int)round(($left + $right) / 2));
                $cy = min($h - 1, (int)round(($top + $bottom) / 2));

                $rgba = imagecolorsforindex($im, imagecolorat($im, $cx, $cy));
                $isDark = self::is_dark_rgba($rgba);

                $bgRGB = $bgDefault;
                if ($visual === 'two_tone' || $visual === 'negative'){
                  $bgRGB = $bg_rgb;
                }
                $bgCol = $alloc($canvas, $bgRGB, 0);
                imagefilledrectangle($canvas, $left, $top, $right, $bottom, $bgCol);

                if ($isDark){
                  [$mr,$mg,$mb] = $moduleColor($cx, $cy);
                  $moduleCol = $alloc($canvas, [$mr,$mg,$mb], 0);

                  $pad = 0;
                  if ($visual !== 'negative'){
                    if ($shape === 'square'){
                      $pad = (int)max(0, floor($moduleSize * 0.05));
                    } else {
                      $pad = (int)max(0, floor($moduleSize * 0.18));
                    }
                  }

                  $centerX = (int)round(($left + $right) / 2);
                  $centerY = (int)round(($top + $bottom) / 2);
                  $width   = max(1, $right - $left + 1 - $pad * 2);
                  $height  = max(1, $bottom - $top + 1 - $pad * 2);

                  switch ($shape){
                    case 'dots':
                      imagefilledellipse($canvas, $centerX, $centerY, $width, $height, $moduleCol);
                      break;
                    case 'diamond':
                      $points = [
                        $centerX, max($top + $pad, $top),
                        min($right - $pad, $right), $centerY,
                        $centerX, min($bottom - $pad, $bottom),
                        max($left + $pad, $left), $centerY,
                      ];
                      imagefilledpolygon($canvas, array_map('intval', $points), 4, $moduleCol);
                      break;
                    case 'triangles':
                      $up = (($mx + $my) % 2) === 0;
                      if ($up){
                        $points = [
                          $centerX, max($top + $pad, $top),
                          min($right - $pad, $right), min($bottom - $pad, $bottom),
                          max($left + $pad, $left), min($bottom - $pad, $bottom),
                        ];
                      } else {
                        $points = [
                          max($left + $pad, $left), max($top + $pad, $top),
                          min($right - $pad, $right), max($top + $pad, $top),
                          $centerX, min($bottom - $pad, $bottom),
                        ];
                      }
                      imagefilledpolygon($canvas, array_map('intval', $points), 3, $moduleCol);
                      break;
                    default:
                      $rectLeft = max($left, min($right, $left + $pad));
                      $rectTop  = max($top, min($bottom, $top + $pad));
                      $rectRight = min($right, max($left, $right - $pad));
                      $rectBottom = min($bottom, max($top, $bottom - $pad));
                      if ($rectRight < $rectLeft) $rectRight = $rectLeft;
                      if ($rectBottom < $rectTop) $rectBottom = $rectTop;
                      imagefilledrectangle($canvas, $rectLeft, $rectTop, $rectRight, $rectBottom, $moduleCol);
                      break;
                  }
                }
              }
            }

            imagepng($canvas, $savePath, 6);
            imagedestroy($canvas);
          }

          imagedestroy($im);
        }
      } catch (\Throwable $e) {
        if (function_exists('error_log')) error_log('[ArtaQR] recolor: '.$e->getMessage());
      }
    }

// ------- caption (existing behavior left intact) -------
// Apply frame style after QR is rendered
    if (function_exists('imagecreatefrompng') && is_readable($savePath) && $frame && $frame !== 'none'){
      self::apply_frame($savePath, $frame, $hex ?: '#000000', $style);
    }

    // ------- NEW: append caption under the QR (Arial 17 bold; fallback DejaVu Sans Bold) -------
    $caption = isset($style['caption']) ? trim((string)$style['caption']) : '';
    if ($caption !== '' && function_exists('imagecreatefrompng') && function_exists('imagettftext')){
      $caption = function_exists('mb_substr') ? mb_substr($caption, 0, 30, 'UTF-8') : substr($caption, 0, 30);
      $im = @imagecreatefrompng($savePath);
      if ($im){
        $w = imagesx($im); $h = imagesy($im);
        $extraH = 44;
        $bg = imagecreatetruecolor($w, $h + $extraH);
        imagesavealpha($bg, true);
        $white = imagecolorallocatealpha($bg, 255,255,255, 0);
        imagefilledrectangle($bg, 0, 0, $w, $h + $extraH, $white);
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        $black = imagecolorallocate($bg, 0,0,0);

        $assetsDir = dirname(__DIR__).'/assets';
        $fontCandidates = [
          $assetsDir.'/arial/arialbd.ttf',
          $assetsDir.'/arial/Arial Bold.ttf',
          $assetsDir.'/dejavu-fonts-ttf-2.37/DejaVuSans-Bold.ttf',
          $assetsDir.'/DejaVuSans-Bold.ttf',
        ];
        $fontFile = '';
        foreach ($fontCandidates as $fp){ if (file_exists($fp)) { $fontFile = $fp; break; } }

        if ($fontFile){
          $pt = 17;
          $bbox = imagettfbbox($pt, 0, $fontFile, $caption);
          $textW = abs($bbox[2] - $bbox[0]);
          $textH = abs($bbox[7] - $bbox[1]);
          $x = (int) max(0, ($w - $textW) / 2);
          $y = $h + $textH + 10;
          imagettftext($bg, $pt, 0, $x, $y, $black, $fontFile, $caption);
        } else {
          $x = (int) max(0, ($w - imagefontwidth(5)*strlen($caption)) / 2);
          $y = $h + 10;
          imagestring($bg, 5, $x, $y, $caption, $black);
        }

        imagepng($bg, $savePath, 6);
        imagedestroy($bg);
        imagedestroy($im);
      }
    }
    // -------------------------------------------------------------------------------------------

    self::log('PNG generated at: '.$savePath);
    return $savePath;
  }

  /* ---------- Frame renderer ---------- */
  protected static function apply_frame(string $path, string $frame, string $hexColor, array $style){
    $im = @imagecreatefrompng($path);
    if (!$im) return;

    $w = imagesx($im);
    $h = imagesy($im);

    $pad    = (int)($style['frame_padding'] ?? 18);
    $border = (int)($style['frame_border']  ?? 12);
    $radius = (int)($style['frame_radius']  ?? 20);

    $cw = $w + $pad*2;
    $ch = $h + $pad*2;

    $canvas = imagecreatetruecolor($cw, $ch);
    imagesavealpha($canvas, true);
    $white = imagecolorallocatealpha($canvas, 255,255,255, 0);
    imagefill($canvas, 0, 0, $white);

    [$cr,$cg,$cb] = self::hex2rgb($hexColor);
    $col = imagecolorallocatealpha($canvas, $cr, $cg, $cb, 0);

    switch ($frame){
      case 'classic':
        imagefilledrectangle($canvas, 0, 0, $cw-1, $ch-1, $col);
        imagefilledrectangle($canvas, $border, $border, $cw-1-$border, $ch-1-$border, $white);
        imagecopy($canvas, $im, $pad, $pad, 0, 0, $w, $h);
        break;

      case 'rounded':
        self::filled_rounded_rect($canvas, 0, 0, $cw-1, $ch-1, $radius, $col);
        self::filled_rounded_rect($canvas, $border, $border, $cw-1-$border, $ch-1-$border, max(4, $radius-$border), $white);
        imagecopy($canvas, $im, $pad, $pad, 0, 0, $w, $h);
        break;

      case 'badge':
        $diam = min($cw, $ch) - max(2, $border);
        $cx = (int)($cw / 2);
        $cy = (int)($ch / 2);
        imagefilledellipse($canvas, $cx, $cy, $diam, $diam, $col);
        $ring  = max(12, (int)($pad - 6));
        $inner = max(0, $diam - 2 * $ring);
        if ($inner > 0){
          imagefilledellipse($canvas, $cx, $cy, $inner, $inner, $white);
        }
        imagecopy($canvas, $im, $pad, $pad, 0, 0, $w, $h);
        break;

      case 'corner':
        $tab = max(18, (int)($pad*0.8));
        imagefilledpolygon($canvas, [0,0, $tab,0, 0,$tab], 3, $col);
        imagefilledpolygon($canvas, [$cw,0, $cw-$tab,0, $cw,$tab], 3, $col);
        imagefilledpolygon($canvas, [0,$ch, 0,$ch-$tab, $tab,$ch], 3, $col);
        imagefilledpolygon($canvas, [$cw,$ch, $cw-$tab,$ch, $cw,$ch-$tab], 3, $col);
        imagecopy($canvas, $im, $pad, $pad, 0, 0, $w, $h);
        break;

      default:
        imagecopy($canvas, $im, $pad, $pad, 0, 0, $w, $h);
        break;
    }

    imagepng($canvas, $path, 6);
    imagedestroy($canvas);
    imagedestroy($im);
  }

  protected static function filled_rounded_rect($img, $x1,$y1,$x2,$y2,$r,$color){
    if ($r === 0){
      imagefilledrectangle($img, $x1,$y1,$x2,$y2,$color);
      return;
    }
    imagefilledrectangle($img, $x1+$r, $y1, $x2-$r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1+$r, $x2, $y2-$r, $color);
    imagefilledellipse($img, $x1+$r, $y1+$r, $r*2, $r*2, $color);
    imagefilledellipse($img, $x2-$r, $y1+$r, $r*2, $r*2, $color);
    imagefilledellipse($img, $x1+$r, $y2-$r, $r*2, $r*2, $color);
    imagefilledellipse($img, $x2-$r, $y2-$r, $r*2, $r*2, $color);
  }

  protected static function is_dark_rgba(array $rgba): bool {
    $alpha = (int)($rgba['alpha'] ?? 0);
    $r = (int)($rgba['red'] ?? 255);
    $g = (int)($rgba['green'] ?? 255);
    $b = (int)($rgba['blue'] ?? 255);
    if ($alpha >= 120) return false;
    return ($r < 128) || ($g < 128) || ($b < 128);
  }

  protected static function detect_module_size($im, int $fallback = 10): int {
    $isGdImage = false;
    if (class_exists('\\GdImage', false)){
      $isGdImage = $im instanceof \GdImage;
    }

    if (!is_resource($im) && !$isGdImage){
      return max(1, $fallback);
    }

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0){
      return max(1, $fallback);
    }

    $midY = (int)floor($h / 2);
    $midY = max(0, min($h - 1, $midY));
    $firstDarkX = -1;
    for ($x = 0; $x < $w; $x++){
      $rgba = imagecolorsforindex($im, imagecolorat($im, $x, $midY));
      if (self::is_dark_rgba($rgba)){
        $firstDarkX = $x;
        break;
      }
    }

    if ($firstDarkX === -1){
      return max(1, $fallback);
    }

    for ($x = $firstDarkX + 1; $x < $w; $x++){
      $rgba = imagecolorsforindex($im, imagecolorat($im, $x, $midY));
      if (!self::is_dark_rgba($rgba)){
        $size = $x - $firstDarkX;
        if ($size > 0){
          return $size;
        }
        break;
      }
    }

    $midX = $firstDarkX;
    $firstDarkY = -1;
    for ($y = 0; $y < $h; $y++){
      $rgba = imagecolorsforindex($im, imagecolorat($im, $midX, $y));
      if (self::is_dark_rgba($rgba)){
        $firstDarkY = $y;
        break;
      }
    }

    if ($firstDarkY !== -1){
      for ($y = $firstDarkY + 1; $y < $h; $y++){
        $rgba = imagecolorsforindex($im, imagecolorat($im, $midX, $y));
        if (!self::is_dark_rgba($rgba)){
          $size = $y - $firstDarkY;
          if ($size > 0){
            return $size;
          }
          break;
        }
      }
    }

    return max(1, $fallback);
  }
}
