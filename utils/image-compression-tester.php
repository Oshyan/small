<?php
/**
 * image-capability-check.php
 *
 * Drop this file on your server and open it in a browser.
 * It will detect server-side WebP/AVIF support via:
 * - PHP GD
 * - PHP Imagick (ImageMagick)
 * - CLI tools: magick/convert, cwebp, avifenc, vips
 *
 * Security notes:
 * - Remove this file after testing.
 * - Do not leave it publicly accessible long-term.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$TITLE = 'Image Compression Capability Checker (WebP + AVIF)';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function can_exec(): bool {
  $disabled = ini_get('disable_functions') ?: '';
  $disabledList = array_filter(array_map('trim', explode(',', $disabled)));
  if (in_array('exec', $disabledList, true) || in_array('shell_exec', $disabledList, true) || in_array('proc_open', $disabledList, true)) {
    // even if one is disabled, we can still attempt exec-based checks if exec exists
  }
  return function_exists('exec');
}

function run_cmd(string $cmd, ?int &$exitCode = null): string {
  $out = [];
  $code = 0;
  @exec($cmd . ' 2>&1', $out, $code);
  $exitCode = $code;
  return implode("\n", $out);
}

function which(string $bin): ?string {
  if (!can_exec()) return null;
  $code = 0;
  $out = run_cmd('command -v ' . escapeshellarg($bin), $code);
  if ($code !== 0) return null;
  $path = trim($out);
  return $path !== '' ? $path : null;
}

function format_rw_from_magick_list(string $text, string $formatName): array {
  // Parse `magick -list format` lines like:
  // "AVIF* HEIC      rw+   AV1 Image File Format (1.0.0)"
  // "WEBP* WEBP      rw-   WebP Image Format (libwebp ...)"
  $rw = ['r' => false, 'w' => false, 'raw' => 'unknown'];
  $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
  foreach ($lines as $line) {
    $lineTrim = trim($line);
    if ($lineTrim === '') continue;

    // Match beginning token as the format.
    // The format token might have * or + markers.
    if (preg_match('/^' . preg_quote($formatName, '/') . '\S*\s+\S+\s+([r-][w-][+-]?)\s+/i', $lineTrim, $m)) {
      $flags = $m[1];
      $rw['raw'] = $flags;
      $rw['r'] = (strpos($flags, 'r') !== false);
      $rw['w'] = (strpos($flags, 'w') !== false);
      return $rw;
    }
  }
  return $rw;
}

function img_ext_from_mime(string $mime): ?string {
  $mime = strtolower($mime);
  return match ($mime) {
    'image/jpeg', 'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    default => null,
  };
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

function bytes(int $n): string {
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  $val = (float)$n;
  while ($val >= 1024 && $i < count($units)-1) { $val /= 1024; $i++; }
  return ($i === 0 ? (string)$n : number_format($val, 2)) . ' ' . $units[$i];
}

function load_gd_image(string $path): array {
  // Returns [resource|GdImage|null, string error]
  $info = @getimagesize($path);
  if (!$info || empty($info['mime'])) return [null, 'Could not read image info'];
  $mime = strtolower($info['mime']);

  if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
    $im = @imagecreatefromjpeg($path);
    return [$im ?: null, $im ? '' : 'imagecreatefromjpeg failed'];
  }
  if ($mime === 'image/png') {
    $im = @imagecreatefrompng($path);
    if ($im) {
      // Preserve alpha in case of PNG
      imagealphablending($im, true);
      imagesavealpha($im, true);
    }
    return [$im ?: null, $im ? '' : 'imagecreatefrompng failed'];
  }
  if ($mime === 'image/gif') {
    $im = @imagecreatefromgif($path);
    return [$im ?: null, $im ? '' : 'imagecreatefromgif failed'];
  }
  if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
    $im = @imagecreatefromwebp($path);
    return [$im ?: null, $im ? '' : 'imagecreatefromwebp failed'];
  }
  if ($mime === 'image/avif' && function_exists('imagecreatefromavif')) {
    $im = @imagecreatefromavif($path);
    return [$im ?: null, $im ? '' : 'imagecreatefromavif failed'];
  }

  return [null, 'Unsupported input format for GD loader: ' . $mime];
}

function gd_save_webp($im, string $outPath, int $quality): array {
  if (!function_exists('imagewebp')) return [false, 'imagewebp() not available'];
  $ok = @imagewebp($im, $outPath, $quality);
  return [$ok, $ok ? '' : 'imagewebp() failed'];
}

function gd_save_avif($im, string $outPath, int $quality): array {
  if (!function_exists('imageavif')) return [false, 'imageavif() not available'];
  // imageavif quality range is 0-100 as of PHP docs
  $ok = @imageavif($im, $outPath, $quality);
  return [$ok, $ok ? '' : 'imageavif() failed'];
}

function imagick_formats(): array {
  if (!extension_loaded('imagick')) return [];
  try {
    $formats = \Imagick::queryFormats();
    sort($formats);
    return $formats;
  } catch (\Throwable $e) {
    return [];
  }
}

function imagick_can(string $fmt): array {
  // fmt like 'WEBP' or 'AVIF'
  $res = ['loaded' => extension_loaded('imagick'), 'r' => false, 'w' => false, 'note' => ''];
  if (!$res['loaded']) { $res['note'] = 'Imagick extension not loaded'; return $res; }
  try {
    $fmt = strtoupper($fmt);
    $res['r'] = \Imagick::queryFormats($fmt) ? true : false;
    // queryFormats only tells it is known, not strictly write-capable.
    // We'll attempt a tiny write test later in the test section.
    $res['w'] = $res['r'];
    return $res;
  } catch (\Throwable $e) {
    $res['note'] = $e->getMessage();
    return $res;
  }
}

function imagick_convert(string $inPath, string $outPath, string $format, int $quality): array {
  if (!extension_loaded('imagick')) return [false, 'Imagick extension not loaded'];
  try {
    $im = new \Imagick();
    $im->readImage($inPath);
    $im->setImageFormat($format);
    $im->setImageCompressionQuality($quality);
    // Strip metadata if possible
    try { $im->stripImage(); } catch (\Throwable $e) {}
    $ok = $im->writeImage($outPath);
    $im->clear();
    $im->destroy();
    return [$ok, $ok ? '' : 'Imagick writeImage returned false'];
  } catch (\Throwable $e) {
    return [false, $e->getMessage()];
  }
}

function cli_cwebp(string $bin, string $inPath, string $outPath, int $quality): array {
  if (!can_exec()) return [false, 'exec() not available'];
  $cmd = escapeshellcmd($bin) . ' -q ' . (int)$quality . ' ' . escapeshellarg($inPath) . ' -o ' . escapeshellarg($outPath);
  $code = 0;
  $out = run_cmd($cmd, $code);
  return [$code === 0 && is_file($outPath), $code === 0 ? '' : $out];
}

function cli_avifenc(string $bin, string $inPath, string $outPath, int $quality, int $speed): array {
  if (!can_exec()) return [false, 'exec() not available'];
  // libavif uses quantizers, but supports -q / --quality in many builds.
  // We'll use --min/--max quantizer if --quality is missing on your version.
  // First attempt: --quality
  $cmd = escapeshellcmd($bin) . ' --quality ' . (int)$quality . ' --speed ' . (int)$speed . ' ' . escapeshellarg($inPath) . ' -o ' . escapeshellarg($outPath);
  $code = 0;
  $out = run_cmd($cmd, $code);
  if ($code === 0 && is_file($outPath)) return [true, ''];

  // Fallback: quantizer mapping (approx). Lower quantizer is higher quality.
  // Map quality 0..100 to quantizer 63..0
  $q = max(0, min(63, (int)round((100 - $quality) * 63 / 100)));
  $cmd2 = escapeshellcmd($bin) . ' --min ' . $q . ' --max ' . $q . ' --speed ' . (int)$speed . ' ' . escapeshellarg($inPath) . ' -o ' . escapeshellarg($outPath);
  $code2 = 0;
  $out2 = run_cmd($cmd2, $code2);
  return [$code2 === 0 && is_file($outPath), $code2 === 0 ? '' : ($out . "\n\n" . $out2)];
}

function cli_magick_convert(string $bin, string $inPath, string $outPath, int $quality): array {
  if (!can_exec()) return [false, 'exec() not available'];
  // If $bin is "magick", syntax: magick input -quality 80 output
  // If $bin is "convert", syntax: convert input -quality 80 output
  $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($inPath) . ' -quality ' . (int)$quality . ' ' . escapeshellarg($outPath);
  $code = 0;
  $out = run_cmd($cmd, $code);
  return [$code === 0 && is_file($outPath), $code === 0 ? '' : $out];
}

function cli_vips_save(string $bin, string $inPath, string $outPath, string $format, int $quality): array {
  if (!can_exec()) return [false, 'exec() not available'];
  // vips copy input output[Q=80] or vips copy input output --Q 80 depending on version.
  // We will use the bracket syntax which is common.
  $format = strtolower($format);
  if ($format === 'webp') {
    $target = $outPath . '[Q=' . (int)$quality . ']';
  } elseif ($format === 'avif') {
    $target = $outPath . '[Q=' . (int)$quality . ']';
  } else {
    return [false, 'Unsupported format for vips_save: ' . $format];
  }
  $cmd = escapeshellcmd($bin) . ' copy ' . escapeshellarg($inPath) . ' ' . escapeshellarg($target);
  $code = 0;
  $out = run_cmd($cmd, $code);
  return [$code === 0 && is_file($outPath), $code === 0 ? '' : $out];
}

$gdLoaded = extension_loaded('gd');
$gdInfo = $gdLoaded ? gd_info() : [];
$gdHasWebP = $gdLoaded && function_exists('imagewebp') && !empty(($gdInfo['WebP Support'] ?? false));
$gdHasAVIF = $gdLoaded && function_exists('imageavif') && !empty(($gdInfo['AVIF Support'] ?? false));

$imagickLoaded = extension_loaded('imagick');
$imagickFormats = $imagickLoaded ? imagick_formats() : [];
$imagickHasWebP = $imagickLoaded ? in_array('WEBP', $imagickFormats, true) : false;
$imagickHasAVIF = $imagickLoaded ? in_array('AVIF', $imagickFormats, true) : false;

$execOk = can_exec();
$binMagick = $execOk ? (which('magick') ?? null) : null;
$binConvert = $execOk ? (which('convert') ?? null) : null;
$binCwebp = $execOk ? (which('cwebp') ?? null) : null;
$binAvifenc = $execOk ? (which('avifenc') ?? null) : null;
$binVips = $execOk ? (which('vips') ?? null) : null;

$magickList = '';
$magickWebP = ['r' => false, 'w' => false, 'raw' => 'unknown'];
$magickAVIF = ['r' => false, 'w' => false, 'raw' => 'unknown'];
if ($execOk && ($binMagick || $binConvert)) {
  // Prefer magick -list format if available. Older installs might only have convert.
  if ($binMagick) {
    $code = 0;
    $magickList = run_cmd(escapeshellcmd($binMagick) . ' -list format', $code);
    if ($code === 0) {
      $magickWebP = format_rw_from_magick_list($magickList, 'WEBP');
      $magickAVIF = format_rw_from_magick_list($magickList, 'AVIF');
    }
  } elseif ($binConvert) {
    // convert -list format also works
    $code = 0;
    $magickList = run_cmd(escapeshellcmd($binConvert) . ' -list format', $code);
    if ($code === 0) {
      $magickWebP = format_rw_from_magick_list($magickList, 'WEBP');
      $magickAVIF = format_rw_from_magick_list($magickList, 'AVIF');
    }
  }
}

$testResult = null;
$errors = [];
$uploadsDir = __DIR__ . '/_imgcap_uploads';
ensure_dir($uploadsDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $backend = $_POST['backend'] ?? '';
  $targetFormat = strtolower((string)($_POST['format'] ?? 'webp'));
  $quality = (int)($_POST['quality'] ?? 80);
  $speed = (int)($_POST['speed'] ?? 6);

  if (!in_array($targetFormat, ['webp', 'avif'], true)) $errors[] = 'Invalid target format.';
  $quality = max(0, min(100, $quality));
  $speed = max(0, min(10, $speed));

  if (!isset($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errors[] = 'Upload failed. Try a smaller file or check PHP upload limits.';
  }

  if (!$errors) {
    $tmp = $_FILES['image']['tmp_name'];
    $origName = (string)($_FILES['image']['name'] ?? 'upload');
    $mime = (string)($_FILES['image']['type'] ?? '');
    $realMime = '';
    if (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $realMime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);
      }
    }
    $useMime = $realMime ?: $mime;
    $ext = img_ext_from_mime($useMime);

    if (!$ext) {
      $errors[] = 'Unsupported uploaded file type. Use JPEG, PNG, GIF, WebP, or AVIF.';
    } else {
      $id = bin2hex(random_bytes(8));
      $inPath = $uploadsDir . '/' . $id . '_in.' . $ext;
      if (!@move_uploaded_file($tmp, $inPath)) {
        $errors[] = 'Could not move uploaded file to a temp folder.';
      } else {
        $outPath = $uploadsDir . '/' . $id . '_out.' . $targetFormat;

        $t0 = microtime(true);
        $ok = false;
        $msg = '';

        if ($backend === 'gd') {
          if (!$gdLoaded) { $ok = false; $msg = 'GD not loaded.'; }
          else {
            [$im, $err] = load_gd_image($inPath);
            if (!$im) { $ok = false; $msg = $err; }
            else {
              if ($targetFormat === 'webp') {
                [$ok, $msg] = gd_save_webp($im, $outPath, $quality);
              } else {
                [$ok, $msg] = gd_save_avif($im, $outPath, $quality);
              }
              imagedestroy($im);
            }
          }
        } elseif ($backend === 'imagick') {
          if (!$imagickLoaded) { $ok = false; $msg = 'Imagick not loaded.'; }
          else {
            $fmt = strtoupper($targetFormat);
            [$ok, $msg] = imagick_convert($inPath, $outPath, $fmt, $quality);
          }
        } elseif ($backend === 'cwebp') {
          if (!$binCwebp) { $ok = false; $msg = 'cwebp not found.'; }
          else {
            if ($targetFormat !== 'webp') { $ok = false; $msg = 'cwebp only outputs WebP.'; }
            else [$ok, $msg] = cli_cwebp($binCwebp, $inPath, $outPath, $quality);
          }
        } elseif ($backend === 'avifenc') {
          if (!$binAvifenc) { $ok = false; $msg = 'avifenc not found.'; }
          else {
            if ($targetFormat !== 'avif') { $ok = false; $msg = 'avifenc only outputs AVIF.'; }
            else [$ok, $msg] = cli_avifenc($binAvifenc, $inPath, $outPath, $quality, $speed);
          }
        } elseif ($backend === 'magick' || $backend === 'convert') {
          $bin = ($backend === 'magick') ? $binMagick : $binConvert;
          if (!$bin) { $ok = false; $msg = $backend . ' binary not found.'; }
          else {
            [$ok, $msg] = cli_magick_convert($bin, $inPath, $outPath, $quality);
          }
        } elseif ($backend === 'vips') {
          if (!$binVips) { $ok = false; $msg = 'vips not found.'; }
          else {
            [$ok, $msg] = cli_vips_save($binVips, $inPath, $outPath, $targetFormat, $quality);
          }
        } else {
          $ok = false;
          $msg = 'Unknown backend selected.';
        }

        $t1 = microtime(true);
        $inSize = is_file($inPath) ? filesize($inPath) : 0;
        $outSize = is_file($outPath) ? filesize($outPath) : 0;

        $testResult = [
          'ok' => $ok,
          'backend' => $backend,
          'format' => $targetFormat,
          'quality' => $quality,
          'speed' => $speed,
          'inPath' => $inPath,
          'outPath' => $outPath,
          'inSize' => $inSize,
          'outSize' => $outSize,
          'timeMs' => (int)round(($t1 - $t0) * 1000),
          'msg' => $msg,
          'inUrl' => basename($inPath),
          'outUrl' => basename($outPath),
        ];
      }
    }
  }
}

function base_url_dir(): string {
  // best-effort base URL for links to saved files
  $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $dir = rtrim(str_replace(basename($script), '', $script), '/');
  return $proto . '://' . $host . $dir;
}

$baseUrl = base_url_dir();
$uploadsUrl = $baseUrl . '/_imgcap_uploads';

$backendOptions = [];
if ($gdLoaded) $backendOptions['gd'] = 'GD (PHP)';
if ($imagickLoaded) $backendOptions['imagick'] = 'Imagick (PHP)';
if ($binCwebp) $backendOptions['cwebp'] = 'cwebp (CLI)';
if ($binAvifenc) $backendOptions['avifenc'] = 'avifenc (CLI)';
if ($binMagick) $backendOptions['magick'] = 'magick (CLI, ImageMagick)';
if ($binConvert) $backendOptions['convert'] = 'convert (CLI, ImageMagick)';
if ($binVips) $backendOptions['vips'] = 'vips (CLI, libvips)';

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?=h($TITLE)?></title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 20px; line-height: 1.35; }
    .card { border: 1px solid #ddd; border-radius: 12px; padding: 14px; margin: 12px 0; }
    code, pre { background: #f7f7f7; padding: 2px 5px; border-radius: 6px; }
    pre { padding: 10px; overflow: auto; }
    .ok { color: #0a7a2f; font-weight: 600; }
    .bad { color: #b00020; font-weight: 600; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
    @media (min-width: 900px) { .grid { grid-template-columns: 1fr 1fr; } }
    .kv { display: grid; grid-template-columns: 220px 1fr; gap: 6px 12px; }
    .small { color: #555; font-size: 0.92em; }
    input[type="number"] { width: 90px; }
    select, input[type="file"], input[type="number"] { padding: 6px; }
    button { padding: 8px 12px; border-radius: 10px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
    button:hover { background: #fafafa; }
    .warn { background: #fff7e6; border: 1px solid #ffd9a3; }
  </style>
</head>
<body>
  <h1><?=h($TITLE)?></h1>
  <p class="small">Remove this file after testing.</p>

  <div class="grid">
    <div class="card">
      <h2>PHP Environment</h2>
      <div class="kv">
        <div>PHP Version</div><div><code><?=h(PHP_VERSION)?></code></div>
        <div>SAPI</div><div><code><?=h(PHP_SAPI)?></code></div>
        <div>exec() available</div><div><?= $execOk ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
        <div>disable_functions</div><div><code><?=h((string)ini_get('disable_functions'))?></code></div>
        <div>memory_limit</div><div><code><?=h((string)ini_get('memory_limit'))?></code></div>
        <div>upload_max_filesize</div><div><code><?=h((string)ini_get('upload_max_filesize'))?></code></div>
        <div>post_max_size</div><div><code><?=h((string)ini_get('post_max_size'))?></code></div>
      </div>
    </div>

    <div class="card">
      <h2>Binaries Found (CLI)</h2>
      <?php if (!$execOk): ?>
        <div class="card warn">CLI checks disabled because <code>exec()</code> is not available.</div>
      <?php endif; ?>
      <div class="kv">
        <div>magick</div><div><?= $binMagick ? '<span class="ok">' . h($binMagick) . '</span>' : '<span class="bad">Not found</span>' ?></div>
        <div>convert</div><div><?= $binConvert ? '<span class="ok">' . h($binConvert) . '</span>' : '<span class="bad">Not found</span>' ?></div>
        <div>cwebp</div><div><?= $binCwebp ? '<span class="ok">' . h($binCwebp) . '</span>' : '<span class="bad">Not found</span>' ?></div>
        <div>avifenc</div><div><?= $binAvifenc ? '<span class="ok">' . h($binAvifenc) . '</span>' : '<span class="bad">Not found</span>' ?></div>
        <div>vips</div><div><?= $binVips ? '<span class="ok">' . h($binVips) . '</span>' : '<span class="bad">Not found</span>' ?></div>
      </div>

      <?php if ($magickList !== ''): ?>
        <h3>ImageMagick format flags (from <code>-list format</code>)</h3>
        <div class="kv">
          <div>WEBP</div><div><code><?=h($magickWebP['raw'])?></code> (read: <?= $magickWebP['r'] ? 'yes' : 'no' ?>, write: <?= $magickWebP['w'] ? 'yes' : 'no' ?>)</div>
          <div>AVIF</div><div><code><?=h($magickAVIF['raw'])?></code> (read: <?= $magickAVIF['r'] ? 'yes' : 'no' ?>, write: <?= $magickAVIF['w'] ? 'yes' : 'no' ?>)</div>
        </div>
        <details>
          <summary>Show full <code>-list format</code> output</summary>
          <pre><?=h($magickList)?></pre>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>GD (PHP)</h2>
      <div class="kv">
        <div>Extension loaded</div><div><?= $gdLoaded ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
        <div>WebP</div><div><?= $gdHasWebP ? '<span class="ok">Supported</span>' : '<span class="bad">Not supported</span>' ?></div>
        <div>AVIF</div><div><?= $gdHasAVIF ? '<span class="ok">Supported</span>' : '<span class="bad">Not supported</span>' ?></div>
        <div>imagewebp()</div><div><?= function_exists('imagewebp') ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
        <div>imageavif()</div><div><?= function_exists('imageavif') ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
      </div>
      <?php if ($gdLoaded): ?>
        <details>
          <summary>Show <code>gd_info()</code></summary>
          <pre><?=h(print_r($gdInfo, true))?></pre>
        </details>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Imagick (PHP)</h2>
      <div class="kv">
        <div>Extension loaded</div><div><?= $imagickLoaded ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
        <div>WEBP in formats</div><div><?= $imagickHasWebP ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
        <div>AVIF in formats</div><div><?= $imagickHasAVIF ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>' ?></div>
      </div>
      <?php if ($imagickLoaded): ?>
        <details>
          <summary>Show Imagick formats list</summary>
          <pre><?=h(implode(", ", $imagickFormats))?></pre>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Test compression</h2>

    <?php if ($errors): ?>
      <div class="card warn">
        <div class="bad">Errors:</div>
        <ul>
          <?php foreach ($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <div class="kv">
        <div>Upload image</div>
        <div><input type="file" name="image" accept="image/*" required></div>

        <div>Backend</div>
        <div>
          <select name="backend" required>
            <?php foreach ($backendOptions as $key => $label): ?>
              <option value="<?=h($key)?>" <?= (isset($_POST['backend']) && $_POST['backend'] === $key) ? 'selected' : '' ?>>
                <?=h($label)?>
              </option>
            <?php endforeach; ?>
            <?php if (!$backendOptions): ?>
              <option value="" selected>No backends detected</option>
            <?php endif; ?>
          </select>
        </div>

        <div>Output format</div>
        <div>
          <select name="format">
            <option value="webp" <?= (($_POST['format'] ?? '') === 'webp') ? 'selected' : '' ?>>WebP</option>
            <option value="avif" <?= (($_POST['format'] ?? '') === 'avif') ? 'selected' : '' ?>>AVIF</option>
          </select>
        </div>

        <div>Quality (0-100)</div>
        <div><input type="number" name="quality" min="0" max="100" value="<?=h((string)($_POST['quality'] ?? 80))?>"></div>

        <div>AVIF speed (0-10)</div>
        <div><input type="number" name="speed" min="0" max="10" value="<?=h((string)($_POST['speed'] ?? 6))?>"> <span class="small">(only used for avifenc)</span></div>
      </div>

      <p><button type="submit">Run test</button></p>
    </form>

    <?php if (is_array($testResult)): ?>
      <div class="card <?= $testResult['ok'] ? '' : 'warn' ?>">
        <h3>Result</h3>
        <div class="kv">
          <div>Status</div><div><?= $testResult['ok'] ? '<span class="ok">Success</span>' : '<span class="bad">Failed</span>' ?></div>
          <div>Backend</div><div><code><?=h($testResult['backend'])?></code></div>
          <div>Format</div><div><code><?=h($testResult['format'])?></code></div>
          <div>Quality</div><div><code><?=h((string)$testResult['quality'])?></code></div>
          <div>Time</div><div><code><?=h((string)$testResult['timeMs'])?> ms</code></div>
          <div>Input size</div><div><code><?=h(bytes((int)$testResult['inSize']))?></code></div>
          <div>Output size</div><div><code><?=h(bytes((int)$testResult['outSize']))?></code></div>
          <div>Reduction</div>
          <div>
            <?php
              $in = (int)$testResult['inSize'];
              $out = (int)$testResult['outSize'];
              if ($in > 0 && $out > 0) {
                $pct = (1 - ($out / $in)) * 100;
                echo '<code>' . h(number_format($pct, 2)) . '%</code>';
              } else echo '<code>n/a</code>';
            ?>
          </div>
          <div>Message</div><div><pre><?=h($testResult['msg'])?></pre></div>
        </div>

        <?php if ($testResult['ok']): ?>
          <p>
            <a href="<?=h($uploadsUrl . '/' . $testResult['inUrl'])?>" target="_blank" rel="noopener">View input</a>
            &nbsp;|&nbsp;
            <a href="<?=h($uploadsUrl . '/' . $testResult['outUrl'])?>" target="_blank" rel="noopener">View output</a>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p class="small">
      Tip: if <code>Imagick</code> is loaded but AVIF/WebP fails, it usually means ImageMagick was built without the needed delegates.
      The CLI <code>magick -list format</code> section above is often the clearest clue. :contentReference[oaicite:2]{index=2}
    </p>
  </div>
</body>
</html>
