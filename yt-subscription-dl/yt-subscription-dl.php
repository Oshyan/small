<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
define('BUILD_TAG', 'yt-web v2.0'); // sanity marker - AJAX-based progress
/**
 * Web GUI for YouTube → Google Sheet backfill/update (Folder ID validated, live progress)
 * - Start & End dates (interpreted in America/Los_Angeles)
 * - Spreadsheet name
 * - Drive Folder ID (validated; file created/placed there)
 * - Update only (append only new Video IDs)
 * - Rate-limit safe (batched writes + backoff)
 * - All user-facing dates shown in LOCAL_TZ
 */

require __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\YouTube;
use Google\Service\Sheets;
use Google\Service\Drive;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;

define('CREDENTIALS_PATH', __DIR__ . '/credentials.json'); // Web client JSON
define('TOKEN_PATH',       __DIR__ . '/token.json');
define('STATE_PATH',       __DIR__ . '/state.json');

const SHEET_DATA      = 'YT Backfill';
const SHEET_PROGRESS  = 'Progress';
const LOCAL_TZ        = 'America/Los_Angeles'; // display + input timezone
$HEADER = ['Channel Name','Video Name','Date (Local)','URL','Duration','Seconds','Is Short','Is Vertical'];

// Tunables
$WRITE_MIN_INTERVAL_MS = 1500;
$YT_BASE_DELAY_MS      = 150;
$MAX_BACKOFF_ATTEMPTS  = 6;
$BACKOFF_BASE_SECONDS  = 1.5;
$CHANNEL_FLUSH_CHUNK   = 400;
$CHANNELS_PER_BATCH    = 5;  // Process this many channels per AJAX call

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Validate folder ID
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'validateFolder') {
  header('Content-Type: application/json');
  $folderId = trim($_GET['folderId'] ?? '');

  if (!$folderId) {
    echo json_encode(['valid' => false, 'error' => 'No folder ID provided']);
    exit;
  }

  try {
    $client = getClientForValidation();
    $drive = new Drive($client);

    $file = $drive->files->get($folderId, ['fields' => 'id,name,mimeType']);

    if ($file->getMimeType() !== 'application/vnd.google-apps.folder') {
      echo json_encode(['valid' => false, 'error' => 'Not a folder']);
      exit;
    }

    echo json_encode(['valid' => true, 'name' => $file->getName()]);
  } catch (Google\Service\Exception $e) {
    $msg = 'Invalid folder ID';
    if ($e->getCode() === 404) $msg = 'Folder not found';
    elseif ($e->getCode() === 403) $msg = 'Access denied';
    echo json_encode(['valid' => false, 'error' => $msg]);
  } catch (Exception $e) {
    echo json_encode(['valid' => false, 'error' => 'Error checking folder']);
  }
  exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: Process a batch of channels (called repeatedly by progress page)
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'process') {
  header('Content-Type: application/json');

  $stateKey = $_GET['stateKey'] ?? '';
  if (!$stateKey) {
    echo json_encode(['error' => 'Missing stateKey']);
    exit;
  }

  $stateAll = loadState();
  if (!isset($stateAll[$stateKey])) {
    echo json_encode(['error' => 'Invalid or expired state']);
    exit;
  }

  $state = $stateAll[$stateKey];
  $subs = $state['subs'] ?? [];
  $total = $state['total'] ?? 0;
  $nextIndex = $state['nextIndex'] ?? 0;
  $spreadsheetId = $state['spreadsheetId'] ?? '';
  $sinceTsUtc = $state['sinceTsUtc'] ?? 0;
  $endTsUtc = $state['endTsUtc'] ?? null;
  $uploadsMap = $state['uploadsMap'] ?? [];
  $processedTotal = $state['processedTotal'] ?? 0;

  if (!$spreadsheetId || empty($subs)) {
    echo json_encode(['error' => 'Invalid state data']);
    exit;
  }

  // Check if already complete
  if ($nextIndex >= $total) {
    echo json_encode([
      'complete' => true,
      'channelIndex' => $total,
      'totalChannels' => $total,
      'totalVideos' => $processedTotal,
      'spreadsheetUrl' => "https://docs.google.com/spreadsheets/d/$spreadsheetId"
    ]);
    exit;
  }

  try {
    $client = getClient([
      'https://www.googleapis.com/auth/youtube.readonly',
      'https://www.googleapis.com/auth/spreadsheets',
      'https://www.googleapis.com/auth/drive'
    ]);
    $yt = new YouTube($client);
    $sh = new Sheets($client);

    // Get existing IDs for deduplication
    $existingIds = getExistingVideoIds($sh, $spreadsheetId, SHEET_DATA);

    // Process up to CHANNELS_PER_BATCH channels
    $batchEnd = min($nextIndex + $CHANNELS_PER_BATCH, $total);
    $batchResults = [];

    for ($i = $nextIndex; $i < $batchEnd; $i++) {
      $sub = $subs[$i];
      $cid = $sub['channelId'];
      $ctitle = $sub['channelTitle'] ?: $cid;

      $uploadsId = $uploadsMap[$cid] ?? null;
      if (!$uploadsId) {
        $batchResults[] = ['channel' => $ctitle, 'videos' => 0, 'skipped' => true];
        continue;
      }

      $channelVideos = processChannelVideos(
        $yt, $sh, $spreadsheetId, $uploadsId, $cid, $ctitle,
        $sinceTsUtc, $endTsUtc, $existingIds,
        $WRITE_MIN_INTERVAL_MS, $YT_BASE_DELAY_MS, $MAX_BACKOFF_ATTEMPTS, $BACKOFF_BASE_SECONDS, $CHANNEL_FLUSH_CHUNK
      );

      $processedTotal += $channelVideos;
      $batchResults[] = ['channel' => $ctitle, 'videos' => $channelVideos];

      // Update existing IDs for next channel in batch
      // (the function updates existingIds by reference via getExistingVideoIds pattern)
    }

    // Update state
    $state['nextIndex'] = $batchEnd;
    $state['processedTotal'] = $processedTotal;
    $stateAll[$stateKey] = $state;
    saveState($stateAll);

    $complete = ($batchEnd >= $total);

    if ($complete) {
      progress($sh, $spreadsheetId, 'Complete', 'All subscriptions processed', '', $WRITE_MIN_INTERVAL_MS);
    }

    echo json_encode([
      'complete' => $complete,
      'channelIndex' => $batchEnd,
      'totalChannels' => $total,
      'totalVideos' => $processedTotal,
      'batchResults' => $batchResults,
      'spreadsheetUrl' => "https://docs.google.com/spreadsheets/d/$spreadsheetId"
    ]);

  } catch (Exception $e) {
    echo json_encode(['error' => 'Processing error: ' . $e->getMessage()]);
  }
  exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Progress page (shows UI and polls process endpoint)
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'progress') {
  $stateKey = $_GET['stateKey'] ?? '';
  $stateAll = loadState();

  if (!$stateKey || !isset($stateAll[$stateKey])) {
    die('<!doctype html><html><body><h2>Error</h2><p>Invalid or expired session. <a href="?">Start over</a></p></body></html>');
  }

  $state = $stateAll[$stateKey];
  $sheetName = $state['sheetName'] ?? 'Unknown';
  $startDate = $state['startDate'] ?? '';
  $endDate = $state['endDate'] ?? '';
  $updateOnly = $state['updateOnly'] ?? false;
  $spreadsheetId = $state['spreadsheetId'] ?? '';
  $spreadsheetUrl = $spreadsheetId ? "https://docs.google.com/spreadsheets/d/$spreadsheetId" : '';
  $total = $state['total'] ?? 0;
  $nextIndex = $state['nextIndex'] ?? 0;
  $processedTotal = $state['processedTotal'] ?? 0;

  outputProgressPage($stateKey, $sheetName, $startDate, $endDate, $updateOnly, $spreadsheetUrl, $total, $nextIndex, $processedTotal);
  exit;
}

/**
 * Process videos for a single channel - extracted for batch processing
 */
function processChannelVideos(
  YouTube $yt, Sheets $sh, string $spreadsheetId, string $uploadsId, string $cid, string $ctitle,
  int $sinceTsUtc, ?int $endTsUtc, array &$existingIds,
  int $writeMinMs, int $ytPauseMs, int $maxBackoff, float $backoffBase, int $flushChunk
): int {
  global $HEADER;
  $pageToken = null;
  $monotonic = true;
  $pendingVideos = [];
  $processedRows = 0;

  do {
    try {
      $resp = callWithBackoff(function() use($yt, $uploadsId, $pageToken) {
        return $yt->playlistItems->listPlaylistItems('snippet,contentDetails', [
          'playlistId' => $uploadsId, 'maxResults' => 50, 'pageToken' => $pageToken
        ]);
      }, $maxBackoff, $backoffBase);
    } catch (Google\Service\Exception $e) {
      if ($e->getCode() === 404 && strpos($e->getMessage(), 'playlistId') !== false) {
        return 0; // Playlist not found, skip
      }
      throw $e;
    }

    if (!$resp) break;
    $items = $resp->getItems();
    if (!$items || !count($items)) break;

    usleep($ytPauseMs * 1000);

    $prevTs = null;
    $oldestTsInPage = null;

    foreach ($items as $it) {
      $sn = $it->getSnippet();
      $cd = $it->getContentDetails();
      $videoId = $cd ? $cd->getVideoId() : null;

      $publishedIso = null;
      if ($cd && $cd->getVideoPublishedAt()) $publishedIso = $cd->getVideoPublishedAt();
      elseif ($sn && $sn->getPublishedAt()) $publishedIso = $sn->getPublishedAt();
      if (!$videoId || !$publishedIso) continue;

      $pubTs = strtotime($publishedIso);
      if ($prevTs !== null && $pubTs > $prevTs) $monotonic = false;
      $prevTs = $pubTs;
      if ($oldestTsInPage === null || $pubTs < $oldestTsInPage) $oldestTsInPage = $pubTs;

      // Filter by date window
      if ($pubTs < $sinceTsUtc) continue;
      if ($endTsUtc && $pubTs > $endTsUtc) continue;

      if (!isset($existingIds[$videoId])) {
        $existingIds[$videoId] = true;
        $title = ($sn && $sn->getTitle()) ? $sn->getTitle() : 'Open';
        $channelTitle = ($sn && $sn->getChannelTitle()) ? $sn->getChannelTitle() : '';
        $pendingVideos[$videoId] = [
          'channelTitle' => $channelTitle,
          'title' => $title,
          'pubTs' => $pubTs,
          'cid' => $cid
        ];
      }
    }

    $early = $monotonic && $oldestTsInPage !== null && $oldestTsInPage < $sinceTsUtc;
    $pageToken = $early ? null : $resp->getNextPageToken();

    // Flush pending videos in batches
    if (count($pendingVideos) >= $flushChunk) {
      $videoDetails = fetchVideoDetails($yt, array_keys($pendingVideos), $ytPauseMs);
      $channelRows = buildVideoRows($pendingVideos, $videoDetails);
      appendRows($sh, $spreadsheetId, SHEET_DATA, $channelRows, $writeMinMs);
      $processedRows += count($channelRows);
      $pendingVideos = [];
    }

  } while ($pageToken);

  // Flush any remaining videos
  if (!empty($pendingVideos)) {
    $videoDetails = fetchVideoDetails($yt, array_keys($pendingVideos), $ytPauseMs);
    $channelRows = buildVideoRows($pendingVideos, $videoDetails);
    appendRows($sh, $spreadsheetId, SHEET_DATA, $channelRows, $writeMinMs);
    $processedRows += count($channelRows);
  }

  return $processedRows;
}

/**
 * Build spreadsheet rows from pending videos and their details
 */
function buildVideoRows(array $pendingVideos, array $videoDetails): array {
  $rows = [];
  foreach ($pendingVideos as $videoId => $info) {
    $url = 'https://www.youtube.com/watch?v=' . $videoId;
    $link = '=HYPERLINK("' . $url . '","' . escapeFormula($info['title']) . '")';
    $details = $videoDetails[$videoId] ?? ['duration' => '?', 'seconds' => 0, 'isShort' => '?', 'isVertical' => '?'];
    $rows[] = [
      $info['channelTitle'],
      $info['title'],
      displayLocalFromTs($info['pubTs']),
      $link,
      $details['duration'],
      $details['seconds'],
      $details['isShort'],
      $details['isVertical']
    ];
  }
  return $rows;
}

/**
 * Output the AJAX-based progress page
 */
function outputProgressPage(string $stateKey, string $sheetName, string $startDate, ?string $endDate, bool $updateOnly, string $spreadsheetUrl, int $total, int $nextIndex, int $processedTotal) {
  ?>
<!doctype html><html><head>
<meta charset="utf-8">
<title>Processing: <?=htmlspecialchars($sheetName)?></title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;background:#f8fafc}
.header{background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.header h2{margin:0 0 1rem}
.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem;font-size:.9rem;color:#666}
.meta span{background:#f1f5f9;padding:.5rem .75rem;border-radius:6px}
.progress-box{background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.progress-bar{height:24px;background:#e2e8f0;border-radius:12px;overflow:hidden;margin:1rem 0}
.progress-fill{height:100%;background:linear-gradient(90deg,#3b82f6,#2563eb);transition:width .3s;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:.8rem;min-width:40px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin:1rem 0}
.stat{text-align:center;padding:1rem;background:#f8fafc;border-radius:8px}
.stat-value{font-size:1.5rem;font-weight:700;color:#1e40af}
.stat-label{font-size:.75rem;color:#64748b;text-transform:uppercase}
.log{background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;font-family:ui-monospace,monospace;font-size:.85rem;max-height:400px;overflow-y:auto;margin-top:1rem}
.log-entry{padding:.25rem 0;border-bottom:1px solid #334155}
.log-entry:last-child{border:none}
.log-channel{color:#60a5fa}
.log-count{color:#4ade80}
.log-skip{color:#94a3b8}
.done{background:#d1fae5;color:#065f46;padding:1.5rem;border-radius:8px;margin-top:1rem;text-align:center}
.done a{color:#065f46;font-weight:600}
.error{background:#fee2e2;color:#991b1b;padding:1rem;border-radius:8px;margin-top:1rem}
.paused{background:#fef3c7;color:#92400e;padding:1rem;border-radius:8px;margin-top:1rem;text-align:center}
.btn{padding:.6rem 1.2rem;border:0;border-radius:8px;font-weight:600;cursor:pointer;margin:.25rem}
.btn-primary{background:#0b57d0;color:#fff}
.btn-secondary{background:#e2e8f0;color:#475569}
.controls{margin-top:1rem;text-align:center}
</style>
</head><body>
<div class="header">
  <h2>YT Subscription Downloader</h2>
  <div class="meta">
    <span><strong>Sheet:</strong> <?=htmlspecialchars($sheetName)?></span>
    <span><strong>Start:</strong> <?=htmlspecialchars($startDate)?></span>
    <span><strong>End:</strong> <?=htmlspecialchars($endDate ?: 'Now')?></span>
    <span><strong>Mode:</strong> <?=$updateOnly ? 'Update' : 'Backfill'?></span>
  </div>
  <?php if ($spreadsheetUrl): ?>
  <p style="margin-top:1rem"><a href="<?=htmlspecialchars($spreadsheetUrl)?>" target="_blank">Open Spreadsheet →</a></p>
  <?php endif; ?>
</div>
<div class="progress-box">
  <div id="currentChannel">Initializing...</div>
  <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%">0%</div></div>
  <div class="stats">
    <div class="stat"><div class="stat-value" id="statChannels">0/<?=$total?></div><div class="stat-label">Channels</div></div>
    <div class="stat"><div class="stat-value" id="statVideos"><?=$processedTotal?></div><div class="stat-label">Videos Added</div></div>
    <div class="stat"><div class="stat-value" id="statTime">0:00</div><div class="stat-label">Elapsed</div></div>
  </div>
  <div class="log" id="log"></div>
  <div id="statusArea"></div>
  <div class="controls" id="controls" style="display:none">
    <button class="btn btn-primary" id="btnContinue">Continue Processing</button>
    <button class="btn btn-secondary" id="btnPause">Pause</button>
  </div>
</div>

<script>
const stateKey = <?=json_encode($stateKey)?>;
const startTime = Date.now();
let isRunning = true;
let isPaused = false;
let totalChannels = <?=$total?>;
let currentIndex = <?=$nextIndex?>;
let totalVideos = <?=$processedTotal?>;

// Update elapsed time
setInterval(() => {
  const elapsed = Math.floor((Date.now() - startTime) / 1000);
  const mins = Math.floor(elapsed / 60);
  const secs = elapsed % 60;
  document.getElementById('statTime').textContent = mins + ':' + String(secs).padStart(2, '0');
}, 1000);

function updateUI(data) {
  const pct = Math.round((data.channelIndex / data.totalChannels) * 100);
  document.getElementById('progressFill').style.width = pct + '%';
  document.getElementById('progressFill').textContent = pct + '%';
  document.getElementById('statChannels').textContent = data.channelIndex + '/' + data.totalChannels;
  document.getElementById('statVideos').textContent = data.totalVideos;

  currentIndex = data.channelIndex;
  totalVideos = data.totalVideos;
  totalChannels = data.totalChannels;
}

function addLogEntries(results) {
  const log = document.getElementById('log');
  results.forEach(r => {
    const entry = document.createElement('div');
    entry.className = 'log-entry';
    if (r.skipped) {
      entry.innerHTML = '<span class="log-channel">[' + currentIndex + '/' + totalChannels + '] ' + escapeHtml(r.channel) + '</span> <span class="log-skip">(skipped)</span>';
    } else {
      entry.innerHTML = '<span class="log-channel">[' + currentIndex + '/' + totalChannels + '] ' + escapeHtml(r.channel) + '</span> <span class="log-count">+' + r.videos + ' videos</span>';
    }
    log.appendChild(entry);
  });
  log.scrollTop = log.scrollHeight;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function showComplete(data) {
  document.getElementById('currentChannel').innerHTML = '<strong>Complete!</strong>';
  document.getElementById('progressFill').style.width = '100%';
  document.getElementById('progressFill').textContent = '100%';
  document.getElementById('statusArea').innerHTML =
    '<div class="done"><strong>Done!</strong> Added ' + data.totalVideos + ' videos.<br>' +
    '<a href="' + data.spreadsheetUrl + '" target="_blank">Open Spreadsheet →</a></div>';
  document.getElementById('controls').style.display = 'none';
  isRunning = false;
}

function showError(msg) {
  document.getElementById('statusArea').innerHTML =
    '<div class="error"><strong>Error:</strong> ' + escapeHtml(msg) + '</div>';
  document.getElementById('controls').style.display = 'block';
  document.getElementById('btnPause').style.display = 'none';
  isRunning = false;
}

function showPaused() {
  document.getElementById('statusArea').innerHTML =
    '<div class="paused">Processing paused. Click Continue to resume.</div>';
  document.getElementById('controls').style.display = 'block';
  document.getElementById('btnPause').style.display = 'none';
}

async function processBatch() {
  if (!isRunning || isPaused) return;

  document.getElementById('currentChannel').innerHTML = '<strong>Processing batch...</strong>';

  try {
    const resp = await fetch('?action=process&stateKey=' + encodeURIComponent(stateKey));
    const data = await resp.json();

    if (data.error) {
      showError(data.error);
      return;
    }

    updateUI(data);

    if (data.batchResults) {
      addLogEntries(data.batchResults);
    }

    if (data.complete) {
      showComplete(data);
    } else if (isRunning && !isPaused) {
      // Small delay then process next batch
      setTimeout(processBatch, 100);
    }

  } catch (err) {
    showError('Network error: ' + err.message);
  }
}

// Button handlers
document.getElementById('btnContinue').addEventListener('click', () => {
  isRunning = true;
  isPaused = false;
  document.getElementById('statusArea').innerHTML = '';
  document.getElementById('controls').style.display = 'none';
  document.getElementById('btnPause').style.display = '';
  processBatch();
});

document.getElementById('btnPause').addEventListener('click', () => {
  isPaused = true;
  isRunning = false;
  showPaused();
});

// Start processing immediately
processBatch();
</script>
</body></html>
<?php
}

function getClientForValidation(): Client {
  if (!file_exists(CREDENTIALS_PATH) || !file_exists(TOKEN_PATH)) {
    throw new Exception('Missing credentials');
  }
  $client = new Client();
  $client->setApplicationName('YT Backfill Web');
  $client->setAuthConfig(CREDENTIALS_PATH);
  $client->setAccessType('offline');
  $client->setScopes(['https://www.googleapis.com/auth/drive.readonly']);
  $token = json_decode(file_get_contents(TOKEN_PATH), true);
  $client->setAccessToken($token);
  if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents(TOKEN_PATH, json_encode($client->getAccessToken()));
  }
  return $client;
}

function getClient(array $scopes): Client {
  if (!file_exists(CREDENTIALS_PATH)) die("Missing credentials.json<br>");
  if (!file_exists(TOKEN_PATH)) die("Missing token.json (open setup_auth.php first)<br>");

  $client = new Client();
  $client->setApplicationName('YT Backfill Web');
  $client->setAuthConfig(CREDENTIALS_PATH);
  $client->setAccessType('offline');
  $client->setScopes($scopes);

  $token = json_decode(file_get_contents(TOKEN_PATH), true);
  $client->setAccessToken($token);
  if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
      $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
      file_put_contents(TOKEN_PATH, json_encode($client->getAccessToken()));
    } else {
      die("Token expired and no refresh_token. Re-run setup_auth.php<br>");
    }
  }
  return $client;
}

function callWithBackoff(callable $fn, int $maxAttempts=6, float $base=1.5) {
  $attempt = 0;
  while (true) {
    try { return $fn(); }
    catch (Google\Service\Exception $e) {
      $code = $e->getCode(); $msg = $e->getMessage();
      $retry = ($code==429 || $code==500 || $code==503) ||
               ($code==403 && (stripos($msg,'rateLimitExceeded')!==false ||
                               stripos($msg,'userRateLimitExceeded')!==false ||
                               stripos($msg,'quotaExceeded')!==false ||
                               stripos($msg,'backendError')!==false));
      if (!$retry || $attempt >= $maxAttempts-1) throw $e;
      $sleep = $base * pow(2,$attempt);
      usleep((int)($sleep*1_000_000));
      $attempt++;
    }
  }
}

function writeLimiterSleep(int $minMs) {
  static $lastUs = 0;
  $now = (int)(microtime(true)*1_000_000);
  if ($lastUs>0) {
    $elapsed = $now - $lastUs; $need = $minMs*1000;
    if ($elapsed < $need) usleep($need-$elapsed);
  }
  $lastUs = (int)(microtime(true)*1_000_000);
}

function valuesUpdate(Sheets $sh, string $spreadsheetId, string $rangeA1, array $rows, int $minMs) {
  writeLimiterSleep($minMs);
  $body = new ValueRange(['values'=>$rows]);
  callWithBackoff(function() use($sh,$spreadsheetId,$rangeA1,$body){
    return $sh->spreadsheets_values->update($spreadsheetId,$rangeA1,$body,['valueInputOption'=>'USER_ENTERED']);
  });
}

function appendRows(Sheets $sh, string $spreadsheetId, string $sheetName, array $rows, int $minMs, int $chunk=400) {
  $range = $sheetName.'!A1';
  $total = count($rows);
  for ($i=0; $i<$total; $i+=$chunk) {
    $slice = array_slice($rows,$i,$chunk);
    writeLimiterSleep($minMs);
    $body = new ValueRange(['values'=>$slice]);
    callWithBackoff(function() use($sh,$spreadsheetId,$range,$body){
      return $sh->spreadsheets_values->append($spreadsheetId,$range,$body,[
        'valueInputOption'=>'USER_ENTERED','insertDataOption'=>'INSERT_ROWS'
      ]);
    });
  }
}

function progress(Sheets $sh,string $spreadsheetId,string $event,string $detail,string $note,int $minMs){
  $row = [[localNowIso(),$event,$detail,$note]];
  appendRows($sh,$spreadsheetId,SHEET_PROGRESS,$row,$minMs,100);
}

function localNowIso(): string {
  $dt = new DateTime('now', new DateTimeZone(LOCAL_TZ));
  return $dt->format('Y-m-d H:i:s');
}

function displayLocalFromTs(int $ts): string {
  $dt = new DateTime('@'.$ts);
  $dt->setTimezone(new DateTimeZone(LOCAL_TZ));
  return $dt->format('Y-m-d H:i');
}

function localDateToUtcTs(string $date, string $time='00:00:00'): int {
  $dtLocal = DateTime::createFromFormat('Y-m-d H:i:s', $date.' '.$time, new DateTimeZone(LOCAL_TZ));
  $dtLocal->setTimezone(new DateTimeZone('UTC'));
  return $dtLocal->getTimestamp();
}

function escapeFormula(string $s): string { return str_replace('"','""',$s); }

/**
 * Parse ISO 8601 duration (PT1H2M3S) to seconds and readable format
 * Uses regex for more reliable parsing than DateInterval
 */
function parseDuration(string $iso): array {
  $hours = 0; $minutes = 0; $seconds = 0;

  // Parse hours, minutes, seconds from ISO 8601 duration
  if (preg_match('/(\d+)H/', $iso, $m)) $hours = (int)$m[1];
  if (preg_match('/(\d+)M/', $iso, $m)) $minutes = (int)$m[1];
  if (preg_match('/(\d+)S/', $iso, $m)) $seconds = (int)$m[1];

  $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;

  // Format readable string
  if ($hours > 0) {
    $readable = sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
  } else {
    $readable = sprintf('%d:%02d', $minutes, $seconds);
  }

  return ['seconds' => $totalSeconds, 'readable' => $readable];
}

/**
 * Batch-fetch video details (duration, thumbnails) for up to 50 video IDs
 */
function fetchVideoDetails(YouTube $yt, array $videoIds, int $ytPauseMs): array {
  $details = [];
  if (empty($videoIds)) return $details;

  $chunks = array_chunk($videoIds, 50);
  foreach ($chunks as $chunk) {
    $resp = callWithBackoff(function() use($yt, $chunk) {
      return $yt->videos->listVideos('contentDetails,snippet', [
        'id' => implode(',', $chunk),
        'maxResults' => 50
      ]);
    });
    usleep($ytPauseMs * 1000);

    $items = $resp->getItems();
    if ($items) {
      foreach ($items as $video) {
        $id = $video->getId();
        $cd = $video->getContentDetails();
        $sn = $video->getSnippet();

        $durationIso = $cd ? $cd->getDuration() : 'PT0S';
        $parsed = parseDuration($durationIso);

        // Check thumbnail aspect ratio for vertical detection
        $isVertical = false;
        if ($sn && $sn->getThumbnails()) {
          $thumbs = $sn->getThumbnails();
          // Try maxres, then high, then default
          $thumb = $thumbs->getMaxres() ?? $thumbs->getHigh() ?? $thumbs->getDefault();
          if ($thumb && $thumb->getWidth() && $thumb->getHeight()) {
            $w = $thumb->getWidth();
            $h = $thumb->getHeight();
            // Vertical if height > width (portrait aspect ratio)
            $isVertical = ($h > $w);
          }
        }

        // YouTube Shorts are ≤60 seconds
        $isShort = ($parsed['seconds'] > 0 && $parsed['seconds'] <= 60);

        $details[$id] = [
          'duration' => $parsed['readable'],
          'seconds' => $parsed['seconds'],
          'isShort' => $isShort ? 'Yes' : 'No',
          'isVertical' => $isVertical ? 'Yes' : 'No'
        ];
      }
    }
  }
  return $details;
}

function fetchAllSubscriptions(YouTube $yt,int $ytPauseMs): array {
  $subs=[]; $pageToken=null;
  do {
    $resp = callWithBackoff(function() use($yt,$pageToken){
      return $yt->subscriptions->listSubscriptions('snippet,contentDetails',[
        'mine'=>true,'maxResults'=>50,'pageToken'=>$pageToken
      ]);
    });
    $items = $resp->getItems();
    if ($items) {
      foreach ($items as $it) {
        $sn = $it->getSnippet();
        $rid= $sn ? $sn->getResourceId() : null;
        $cid= $rid ? $rid->getChannelId() : null;
        if ($cid) {
          $title = ($sn && $sn->getTitle()) ? $sn->getTitle() : '';
          $subs[] = ['channelId'=>$cid,'channelTitle'=>$title];
        }
      }
    }
    $pageToken = $resp->getNextPageToken();
    usleep($ytPauseMs*1000);
  } while ($pageToken);
  return $subs;
}

function buildUploadsMap(YouTube $yt,array $channelIds,int $ytPauseMs): array {
  $map=[]; $n=count($channelIds);
  for ($i=0;$i<$n;$i+=50){
    $batch=array_slice($channelIds,$i,50);
    $resp = callWithBackoff(function() use($yt,$batch){
      return $yt->channels->listChannels('contentDetails',['id'=>implode(',',$batch),'maxResults'=>50]);
    });
    $items=$resp->getItems();
    if ($items){
      foreach ($items as $ch){
        $cd=$ch->getContentDetails();
        $rels=$cd?$cd->getRelatedPlaylists():null;
        $uploads=$rels?$rels->getUploads():null;
        if ($uploads) $map[$ch->getId()]=$uploads;
      }
    }
    usleep($ytPauseMs*1000);
  }
  return $map;
}

function resolveUploadsForChannel(YouTube $yt,string $channelId,int $ytPauseMs): ?string {
  $resp = callWithBackoff(function() use($yt,$channelId){
    return $yt->channels->listChannels('contentDetails',['id'=>$channelId,'maxResults'=>1]);
  });
  usleep($ytPauseMs*1000);
  $items=$resp->getItems(); if(!$items||!count($items)) return null;
  $cd=$items[0]->getContentDetails(); $rels=$cd?$cd->getRelatedPlaylists():null;
  return $rels ? $rels->getUploads() : null;
}

function getExistingVideoIds(Sheets $sh,string $spreadsheetId,string $sheetName): array {
  try {
    // Get URL column (D) which contains HYPERLINK formulas with video IDs
    $resp = callWithBackoff(function() use($sh,$spreadsheetId,$sheetName){
      return $sh->spreadsheets_values->get($spreadsheetId,$sheetName.'!D2:D',['valueRenderOption'=>'FORMULA']);
    });
  } catch (Exception $e) { return []; }
  $values = $resp->getValues() ?? [];
  $set=[];
  foreach ($values as $r){
    if (!empty($r[0])) {
      // Extract video ID from URL: =HYPERLINK("https://www.youtube.com/watch?v=VIDEO_ID","Title")
      // or plain URL: https://www.youtube.com/watch?v=VIDEO_ID
      if (preg_match('/watch\?v=([a-zA-Z0-9_-]{11})/', $r[0], $m)) {
        $set[$m[1]]=true;
      }
    }
  }
  return $set;
}

function validateFolderIdOrNull(Drive $drive, ?string $folderId): ?string {
  if (!$folderId) return null;
  try {
    $file = callWithBackoff(function() use($drive,$folderId){
      return $drive->files->get($folderId, ['fields'=>'id,name,mimeType']);
    });
  } catch (Google\Service\Exception $e) {
    return null; // invalid
  }
  if ($file->getMimeType() !== 'application/vnd.google-apps.folder') return null;
  return $folderId;
}

function createOrOpenSpreadsheet(
  Sheets $sh, Drive $drive, string $name, ?string $folderId, array $header, int $minMs
): array {
  $fileId = null;
  // If a folder is specified, search only inside it; else search My Drive
  if ($folderId) {
    $q = "mimeType='application/vnd.google-apps.spreadsheet' and name='".addslashes($name)."' and trashed=false and '$folderId' in parents";
  } else {
    $q = "mimeType='application/vnd.google-apps.spreadsheet' and name='".addslashes($name)."' and trashed=false";
  }
  $list = callWithBackoff(function() use($drive,$q){
    return $drive->files->listFiles(['q'=>$q,'spaces'=>'drive','pageSize'=>10,'fields'=>'files(id,name,parents)']);
  });
  $files = $list->getFiles();
  if ($files && count($files)>0) {
    $fileId = $files[0]->getId();
  }

  if (!$fileId) {
    $ss = new Spreadsheet([
      'properties'=>['title'=>$name],
      'sheets'=>[
        ['properties'=>['title'=>SHEET_DATA]],
        ['properties'=>['title'=>SHEET_PROGRESS]],
      ],
    ]);
    $created = callWithBackoff(function() use($sh,$ss){
      return $sh->spreadsheets->create($ss);
    });
    $fileId = $created->spreadsheetId;

    if ($folderId) {
      callWithBackoff(function() use($drive,$fileId,$folderId){
        return $drive->files->update($fileId,new Google\Service\Drive\DriveFile(),[
          'addParents'=>$folderId,'fields'=>'id,parents'
        ]);
      });
    }

    valuesUpdate($sh,$fileId,SHEET_DATA.'!A1:H1',[$header],$minMs);
    valuesUpdate($sh,$fileId,SHEET_PROGRESS.'!A1:D1',[[ 'When','Event','Detail','Note' ]],$minMs);
  }
  return [$fileId, $folderId];
}

function loadState(): array {
  if (!file_exists(STATE_PATH)) return [];
  $j = json_decode(file_get_contents(STATE_PATH), true);
  return is_array($j) ? $j : [];
}
function saveState(array $s): void {
  file_put_contents(STATE_PATH, json_encode($s, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

// UI or Run
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  ?>
<!doctype html><meta charset="utf-8"><title>YT Subscription Downloader</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem}
label{display:block;margin:.5rem 0 .2rem}
input[type=text],input[type=date]{width:100%;padding:.5rem;border:1px solid #ccc;border-radius:6px;box-sizing:border-box}
.row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
button{padding:.6rem 1rem;border:0;border-radius:8px;background:#0b57d0;color:#fff;font-weight:600;cursor:pointer}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:1rem;margin-top:1rem}
small{color:#666}
code{background:#f5f5f5;padding:0 .25rem;border-radius:4px}
.folder-wrap{position:relative}
.folder-wrap input{padding-right:2.2rem}
.folder-status{position:absolute;right:.6rem;top:50%;transform:translateY(-50%);font-size:1rem;line-height:1}
.folder-status.checking{color:#888}
.folder-status.valid{color:#22c55e}
.folder-status.invalid{color:#ef4444}
.folder-feedback{font-size:.8rem;margin-top:.25rem;min-height:1.2em}
.folder-feedback.valid{color:#22c55e}
.folder-feedback.invalid{color:#ef4444}
@keyframes spin{to{transform:translateY(-50%) rotate(360deg)}}
.folder-status.checking{animation:spin 1s linear infinite}
</style>
<h2>YT Subscription Downloader</h2>
<form method="post">
  <div class="row">
    <div>
      <label>Start date <small>(<?=LOCAL_TZ?>)</small></label>
      <input type="date" name="start" required>
    </div>
    <div>
      <label>End date <small>(optional; <?=LOCAL_TZ?>)</small></label>
      <input type="date" name="end">
    </div>
  </div>
  <div class="row">
    <div>
      <label>Spreadsheet name</label>
      <input type="text" name="sheetName" placeholder="YouTube Backfill (Local Time)" required>
    </div>
    <div>
      <label>Drive Folder ID <small>(optional)</small></label>
      <div class="folder-wrap">
        <input type="text" name="folderId" id="folderId" placeholder="1AbCDeFgHiJKLmNoPqrSTUvWxyz12345">
        <span class="folder-status" id="folderStatus"></span>
      </div>
      <div class="folder-feedback" id="folderFeedback"></div>
      <small>
        Open folder in Drive → copy ID from URL:
        <code>drive.google.com/drive/folders/<b>ID</b></code>
      </small>
    </div>
  </div>
  <div class="card">
    <label><input type="checkbox" name="updateOnly" value="1"> Update only (append new videos not already in sheet)</label>
  </div>
  <p><button type="submit">Run</button></p>
</form>
<script>
(function() {
  const input = document.getElementById('folderId');
  const status = document.getElementById('folderStatus');
  const feedback = document.getElementById('folderFeedback');
  let timer = null, lastChecked = '';

  function setStatus(state, msg) {
    status.className = 'folder-status ' + state;
    status.textContent = state === 'checking' ? '⟳' : state === 'valid' ? '✓' : state === 'invalid' ? '✗' : '';
    feedback.className = 'folder-feedback ' + state;
    feedback.textContent = msg || '';
  }

  function validate(id) {
    if (!id || id.length < 10) { setStatus('', ''); return; }
    if (id === lastChecked) return;
    lastChecked = id;
    setStatus('checking', '');
    fetch('?action=validateFolder&folderId=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(d => {
        if (input.value.trim() !== id) return;
        if (d.valid) setStatus('valid', d.name);
        else setStatus('invalid', d.error || 'Invalid');
      })
      .catch(() => { if (input.value.trim() === id) setStatus('invalid', 'Network error'); });
  }

  input.addEventListener('input', function() {
    clearTimeout(timer);
    const v = this.value.trim();
    if (!v) { setStatus('', ''); lastChecked = ''; return; }
    timer = setTimeout(() => validate(v), 500);
  });
  input.addEventListener('paste', function() {
    clearTimeout(timer);
    setTimeout(() => validate(this.value.trim()), 50);
  });
  if (input.value.trim()) validate(input.value.trim());
})();
</script>
<?php
  exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: Initialize state and redirect to progress page
// ─────────────────────────────────────────────────────────────────────────────
$startDate = trim($_POST['start'] ?? '');
$endDate   = trim($_POST['end'] ?? '');
$sheetName = trim($_POST['sheetName'] ?? '');
$folderId  = trim($_POST['folderId'] ?? '');
$updateOnly= !empty($_POST['updateOnly']);

// Validation
if (!$startDate) { http_response_code(400); die('<!doctype html><html><body><h2>Error</h2><p>Missing start date</p></body></html>'); }
if (!$sheetName){ http_response_code(400); die('<!doctype html><html><body><h2>Error</h2><p>Missing spreadsheet name</p></body></html>'); }

// Convert local date window → UTC timestamps for filtering
$sinceTsUtc = localDateToUtcTs($startDate, '00:00:00');
$endTsUtc   = $endDate ? localDateToUtcTs($endDate, '23:59:59') : null;

// Create clients
$client = getClient([
  'https://www.googleapis.com/auth/youtube.readonly',
  'https://www.googleapis.com/auth/spreadsheets',
  'https://www.googleapis.com/auth/drive'
]);
$yt = new YouTube($client);
$sh = new Sheets($client);
$dv = new Drive($client);

// Validate folder ID if provided
$folderId = $folderId ? $folderId : null;
if ($folderId) {
  $validFolder = validateFolderIdOrNull($dv, $folderId);
  if (!$validFolder) {
    die('<!doctype html><html><body><h2>Error</h2><p>The provided folder ID is not valid or accessible.</p></body></html>');
  }
}

// Open or create spreadsheet (in folder if provided)
list($spreadsheetId, $folderUsed) = createOrOpenSpreadsheet($sh, $dv, $sheetName, $folderId, $HEADER, $WRITE_MIN_INTERVAL_MS);

// Create unique state key for this run
$stateKey = 'run_' . $spreadsheetId . '_' . time();
$stateAll = loadState();

// Fetch subscriptions
$subs = fetchAllSubscriptions($yt, $YT_BASE_DELAY_MS);
if (!$subs) {
  die('<!doctype html><html><body><h2>Error</h2><p>No subscriptions found. Make sure you have YouTube subscriptions.</p></body></html>');
}
usort($subs, function($a, $b) { return strcmp($a['channelId'], $b['channelId']); });

// Build uploads map
$uploadsMap = buildUploadsMap($yt, array_map(function($s) { return $s['channelId']; }, $subs), $YT_BASE_DELAY_MS);

// Save state with all info needed for processing
$state = [
  'sheetName' => $sheetName,
  'startDate' => $startDate,
  'endDate' => $endDate,
  'updateOnly' => $updateOnly,
  'spreadsheetId' => $spreadsheetId,
  'sinceTsUtc' => $sinceTsUtc,
  'endTsUtc' => $endTsUtc,
  'subs' => $subs,
  'total' => count($subs),
  'nextIndex' => 0,
  'uploadsMap' => $uploadsMap,
  'processedTotal' => 0,
  'createdAt' => time()
];
$stateAll[$stateKey] = $state;
saveState($stateAll);

// Log start
progress($sh, $spreadsheetId, 'Started', 'Processing ' . count($subs) . ' channels', '', $WRITE_MIN_INTERVAL_MS);

// Redirect to progress page
header('Location: ?action=progress&stateKey=' . urlencode($stateKey));
exit;
