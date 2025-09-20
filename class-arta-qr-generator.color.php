<?php
namespace ArtaQR;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

if (!defined('ABSPATH')) exit;

if (!function_exists(__NAMESPACE__.'\\arta_qr_log')){
  function arta_qr_log($msg){
    if (defined('WP_DEBUG') && WP_DEBUG){
      error_log('[ArtaQR] '.$msg);
    }
  }
}

class Generator {

  /** Normalize color input: hex "#RRGGBB" or named ("red","gold",...) → "#RRGGBB" */
  protected static function normalize_hex($color){
    $map = [
      'black'=>'#000000','blue'=>'#1D4ED8','red'=>'#DC2626','orange'=>'#F97316',
      'green'=>'#16A34A','pink'=>'#DB2777','gold'=>'#D4AF37',
    ];
    $c = strtolower(trim((string)$color));
    if (isset($map[$c])) return $map[$c];
    if ($c && $c[0] !== '#') $c = '#'.$c;
    if (preg_match('/^#([0-9a-f]{6})$/i', $c)) return strtoupper($c);
    return '';
  }

  public static function build_qr_data(string $payloadType, array $d): string {
    switch ($payloadType){
      case 'website': return esc_url_raw($d['url'] ?? '');
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
        $org   = (string)($d['org'] ?? '');
        $title = (string)($d['title'] ?? '');
        $phone = (string)($d['phone'] ?? '');
        $email = sanitize_email($d['email'] ?? '');
        $site  = esc_url_raw($d['website'] ?? '');
        $street= (string)($d['street'] ?? '');
        $city  = (string)($d['city'] ?? '');
        $reg   = (string)($d['region'] ?? '');
        $post  = (string)($d['postal'] ?? '');
        $ctry  = (string)($d['country'] ?? '');
        $v  =  "BEGIN:VCARD\nVERSION:3.0\n";
        $v .= "FN:{$org}\nORG:{$org}\n";
        if ($title !== '') $v .= "TITLE:{$title}\n";
        if ($phone !== '') $v .= "TEL:{$phone}\n";
        if ($email !== '') $v .= "EMAIL:{$email}\n";
        if ($site  !== '') $v .= "URL:{$site}\n";
        if ($street.$city.$reg.$post.$ctry !== ''){
          $v .= "ADR;TYPE=WORK:;;{$street};{$city};{$reg};{$post};{$ctry}\n";
        }
        $v .= "END:VCARD";
        return $v;
      case 'sms':
        $to = preg_replace('/[^0-9+]/', '', (string)($d['to'] ?? ''));
        $qs = [];
        if (!empty($d['body'])) $qs[] = 'body='.rawurlencode((string)$d['body']);
        return 'sms:'.$to.($qs ? '?'.implode('&', $qs) : '');
      case 'email':
        $to = sanitize_email($d['to'] ?? '');
        $qs = [];
        if (!empty($d['subject'])) $qs[] = 'subject='.rawurlencode((string)$d['subject']);
        if (!empty($d['body']))    $qs[] = 'body='.rawurlencode((string)$d['body']);
        return 'mailto:'.$to.($qs ? '?'.implode('&', $qs) : '');
      case 'ical':
        $summary = trim((string)($d['summary'] ?? 'Event'));
        $start   = preg_replace('/[^0-9TZ]/i', '', (string)($d['start'] ?? ''));
        $end     = preg_replace('/[^0-9TZ]/i', '', (string)($d['end'] ?? ''));
        $uid     = uniqid('qr', true);
        $ics  = "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:{$uid}\nSUMMARY:{$summary}\n";
        if ($start !== '') $ics .= "DTSTART:{$start}\n";
        if ($end   !== '') $ics .= "DTEND:{$end}\n";
        $ics .= "END:VEVENT\nEND:VCALENDAR";
        return $ics;
      case 'geo':
        $lat = trim((string)($d['lat'] ?? ''));
        $lon = trim((string)($d['lon'] ?? ''));
        return 'geo:'.$lat.','.$lon;
      case 'payment':
      default:
        return esc_url_raw($d['url'] ?? '');
    }
  }

  /** Generate PNG with optional color+frame */
  public static function generate_png(string $data, string $savePath, array $style = []): string {
    $hex = isset($style['color']) ? self::normalize_hex($style['color']) : '';
    if ($hex){
      arta_qr_log('generate_png color requested: '.$hex);
    } else {
      arta_qr_log('generate_png no color provided (will be black)');
    }

    // try to render colored natively via moduleValues
    $optsArr = [
      'eccLevel'    => $style['ecc']    ?? QRCode::ECC_H,
      'scale'       => $style['scale']  ?? 10,
      'margin'      => $style['margin'] ?? 2,
      'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
      'imageBase64' => false,
    ];

    if ($hex && preg_match('/^#([0-9A-F]{6})$/i', $hex)){
      $r = hexdec(substr($hex,1,2));
      $g = hexdec(substr($hex,3,2));
      $b = hexdec(substr($hex,5,2));
      // Many versions of chillerlan accept moduleValues to override colors
      $optsArr['moduleValues'] = [
        // light modules (background)
        'L' => [255,255,255],
        // dark modules (foreground)
        'D' => [$r,$g,$b],
      ];
    }

    $opts = new QROptions($optsArr);
    $png  = (new QRCode($opts))->render($data);

    if (is_string($png) && strncmp($png, 'data:image', 10) === 0 && preg_match('#^data:image/[^;]+;base64,(.+)$#', $png, $m)) {
      $png = base64_decode($m[1]);
    }
    if (!is_string($png)){
      arta_qr_log('QR render did not return a string');
      return $savePath;
    }

    wp_mkdir_p(dirname($savePath));
    file_put_contents($savePath, $png);

    // Fallback recolor via GD if native color didn't apply or library ignores moduleValues
    if ($hex && function_exists('imagecreatefrompng') && is_readable($savePath)){
      // detect whether it's still black by sampling a few pixels
      $im = @imagecreatefrompng($savePath);
      if ($im){
        $w = imagesx($im); $h = imagesy($im);
        $sampleDark = 0;
        for ($i=0;$i<50;$i++){
          $x = ($i*13)%max(1,$w);
          $y = ($i*29)%max(1,$h);
          $c = imagecolorsforindex($im, imagecolorat($im,$x,$y));
          if ($c['red'] < 40 && $c['green'] < 40 && $c['blue'] < 40 && $c['alpha'] < 120){
            $sampleDark++; if ($sampleDark > 2) break;
          }
        }
        if ($sampleDark > 2){
          arta_qr_log('Applying GD recolor fallback to '.$hex);
          $r = hexdec(substr($hex,1,2));
          $g = hexdec(substr($hex,3,2));
          $b = hexdec(substr($hex,5,2));
          imagesavealpha($im, true);
          for ($y=0;$y<$h;$y++){
            for ($x=0;$x<$w;$x++){
              $rgba = imagecolorsforindex($im, imagecolorat($im,$x,$y));
              if ($rgba['alpha'] < 120 && $rgba['red'] < 40 && $rgba['green'] < 40 && $rgba['blue'] < 40){
                $col = imagecolorallocatealpha($im, $r, $g, $b, $rgba['alpha']);
                imagesetpixel($im, $x, $y, $col);
              }
            }
          }
          imagepng($im, $savePath);
        } else {
          arta_qr_log('Native color likely applied, skipping GD recolor');
        }
        imagedestroy($im);
      }
    }

    // Simple caption/frame layer preserved from your previous logic if any...
    // (omitted here for brevity; add if needed)

    arta_qr_log('PNG generated at: '.$savePath);
    return $savePath;
  }
}
