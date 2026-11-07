<?php
/**
 * MITCH COSMETICS SHOP — Hero Video Manager (Admin)
 * Upload, replace, or remove the promotional video shown on the public homepage.
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Hero Video';
$activeMenu = 'hero_video';
$db = getDB();

// ── Ensure upload directories exist ───────────────────────────────────────
$videoDirAbs  = rtrim(VIDEO_PATH, '/\\');
$imageDirAbs  = rtrim(UPLOAD_PATH, '/\\');
if (!is_dir($videoDirAbs))  mkdir($videoDirAbs,  0755, true);
if (!is_dir($imageDirAbs))  mkdir($imageDirAbs,  0755, true);

// ── Current saved values ───────────────────────────────────────────────────
$heroVideo      = getSiteSetting('hero_video',      '');
$heroPoster     = getSiteSetting('hero_poster',     '');
$showcaseVideo  = getSiteSetting('showcase_video',  '');
$showcasePoster = getSiteSetting('showcase_poster', '');

// ── Helpers ────────────────────────────────────────────────────────────────
function deleteVideoFile(string $filename): void {
    if (empty($filename)) return;
    $path = rtrim(VIDEO_PATH, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
    if (is_file($path)) @unlink($path);
}
function deletePosterFile(string $filename): void {
    if (empty($filename)) return;
    $path = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
    if (is_file($path)) @unlink($path);
}

// ── Upload helper: video ───────────────────────────────────────────────────
function uploadHeroVideo(array $file, int $maxMB = 100): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'msg' => 'Upload error code: ' . $file['error']];
    }
    $maxBytes = $maxMB * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'msg' => "File too large (max {$maxMB} MB)."];
    }
    // MIME validation from file content
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    if (!in_array($mime, $allowed, true)) {
        return ['success' => false, 'msg' => 'Only MP4/WebM video files are allowed. Detected: ' . htmlspecialchars($mime)];
    }
    // Derive safe extension from MIME type — never trust user-supplied filename extension
    $mimeToExt = ['video/mp4'=>'mp4','video/mpeg'=>'mp4','video/quicktime'=>'mov','video/x-msvideo'=>'avi','video/webm'=>'webm'];
    $ext      = $mimeToExt[$mime] ?? 'mp4';
    $safeName = 'hero_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = rtrim(VIDEO_PATH, '/\\') . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => false, 'msg' => 'Could not save video. Check directory permissions.'];
    }
    return ['success' => true, 'filename' => $safeName, 'mime' => $mime];
}

// ── Upload helper: poster image ────────────────────────────────────────────
function uploadPosterImage(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'msg' => 'Upload error code: ' . $file['error']];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'msg' => 'Poster image too large (max 5 MB).'];
    }
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return ['success' => false, 'msg' => 'Only JPG/PNG/WebP images are allowed for the poster.'];
    }
    // Derive safe extension from MIME type — never trust user-supplied filename extension
    $mimeToExt = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $ext      = $mimeToExt[$mime] ?? 'jpg';
    $safeName = 'hero_poster_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target   = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => false, 'msg' => 'Could not save poster image. Check directory permissions.'];
    }
    return ['success' => true, 'filename' => $safeName];
}

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Upload hero video ──────────────────────────────────────────────────
    if ($action === 'upload_hero') {
        $hasVideo  = !empty($_FILES['hero_video']['name']);
        $hasPoster = !empty($_FILES['hero_poster']['name']);

        if (!$hasVideo && !$hasPoster) {
            setFlash('error', 'Please select a video or poster image to upload.');
            redirect(BASE_URL . '/admin/hero_video.php');
        }

        if ($hasVideo) {
            $up = uploadHeroVideo($_FILES['hero_video']);
            if (!$up['success']) {
                setFlash('error', $up['msg']);
                redirect(BASE_URL . '/admin/hero_video.php');
            }
            // Update DB first, THEN delete old file (prevents dangling reference on failure)
            $oldHeroVideo = $heroVideo;
            setSiteSetting('hero_video', $up['filename']);
            $heroVideo = $up['filename'];
            deleteVideoFile($oldHeroVideo);
        }

        if ($hasPoster) {
            $up = uploadPosterImage($_FILES['hero_poster']);
            if (!$up['success']) {
                setFlash('error', $up['msg']);
                redirect(BASE_URL . '/admin/hero_video.php');
            }
            $oldPoster = $heroPoster;
            setSiteSetting('hero_poster', $up['filename']);
            $heroPoster = $up['filename'];
            deletePosterFile($oldPoster);
        }

        setFlash('success', 'Hero section updated successfully.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }

    // ── Upload showcase video ──────────────────────────────────────────────
    if ($action === 'upload_showcase') {
        $hasVideo  = !empty($_FILES['showcase_video']['name']);
        $hasPoster = !empty($_FILES['showcase_poster']['name']);

        if (!$hasVideo && !$hasPoster) {
            setFlash('error', 'Please select a video or poster image to upload.');
            redirect(BASE_URL . '/admin/hero_video.php');
        }

        if ($hasVideo) {
            $up = uploadHeroVideo($_FILES['showcase_video']);
            if (!$up['success']) { setFlash('error', $up['msg']); redirect(BASE_URL . '/admin/hero_video.php'); }
            $oldShowcaseVideo = $showcaseVideo;
            setSiteSetting('showcase_video', $up['filename']);
            $showcaseVideo = $up['filename'];
            deleteVideoFile($oldShowcaseVideo);
        }
        if ($hasPoster) {
            $up = uploadPosterImage($_FILES['showcase_poster']);
            if (!$up['success']) { setFlash('error', $up['msg']); redirect(BASE_URL . '/admin/hero_video.php'); }
            $oldShowcasePoster = $showcasePoster;
            setSiteSetting('showcase_poster', $up['filename']);
            $showcasePoster = $up['filename'];
            deletePosterFile($oldShowcasePoster);
        }

        setFlash('success', 'Showcase section updated successfully.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }

    // ── Remove hero video ──────────────────────────────────────────────────
    if ($action === 'remove_hero_video') {
        deleteVideoFile($heroVideo);
        setSiteSetting('hero_video', '');
        setFlash('success', 'Hero video removed. Gradient background will show instead.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }

    // ── Remove hero poster ─────────────────────────────────────────────────
    if ($action === 'remove_hero_poster') {
        deletePosterFile($heroPoster);
        setSiteSetting('hero_poster', '');
        setFlash('success', 'Hero poster image removed.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }

    // ── Remove showcase video ──────────────────────────────────────────────
    if ($action === 'remove_showcase_video') {
        deleteVideoFile($showcaseVideo);
        setSiteSetting('showcase_video', '');
        setFlash('success', 'Showcase video removed.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }

    // ── Remove showcase poster ─────────────────────────────────────────────
    if ($action === 'remove_showcase_poster') {
        deletePosterFile($showcasePoster);
        setSiteSetting('showcase_poster', '');
        setFlash('success', 'Showcase poster image removed.');
        redirect(BASE_URL . '/admin/hero_video.php');
    }
}

// ── Build preview URLs ─────────────────────────────────────────────────────
$heroVideoUrl      = $heroVideo      ? VIDEO_URL  . $heroVideo      : '';
$heroPosterUrl     = $heroPoster     ? UPLOAD_URL . $heroPoster     : '';
$showcaseVideoUrl  = $showcaseVideo  ? VIDEO_URL  . $showcaseVideo  : '';
$showcasePosterUrl = $showcasePoster ? UPLOAD_URL . $showcasePoster : '';

// ── File size helper ───────────────────────────────────────────────────────
function fileSizeLabel(string $filename, string $dir): string {
    if (empty($filename)) return '';
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
    if (!is_file($path)) return '(file missing)';
    $bytes = filesize($path);
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 0) . ' KB';
}

include __DIR__ . '/../includes/header.php';
?>

<style>
  .video-manager-card { border-radius: 16px; overflow: hidden; }
  .upload-zone {
    border: 2px dashed rgba(192,0,122,.3);
    border-radius: 12px;
    padding: 28px 20px;
    text-align: center;
    background: var(--accent, #ffe4f3);
    cursor: pointer;
    transition: all .2s;
  }
  .upload-zone:hover { border-color: var(--primary, #c0007a); background: #ffd6ef; }
  .upload-zone input[type="file"] { display: none; }
  .upload-zone .uz-icon { font-size: 2.2rem; color: var(--primary, #c0007a); margin-bottom: 8px; }
  .upload-zone .uz-label { font-weight: 700; font-size: .9rem; color: #1a1a2e; }
  .upload-zone .uz-hint  { font-size: .75rem; color: #6c757d; margin-top: 4px; }

  .video-preview-wrap {
    border-radius: 12px; overflow: hidden;
    background: #1a0a14;
    position: relative;
  }
  .video-preview-wrap video {
    width: 100%; display: block;
    max-height: 280px; object-fit: cover;
  }
  .video-preview-wrap .no-video-placeholder {
    height: 200px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: rgba(255,255,255,.4); gap: 10px;
    font-size: .88rem;
  }
  .file-meta { font-size: .78rem; color: #6c757d; margin-top: 6px; }
  .status-badge-live { background: #00875a; color: #fff; border-radius: 99px; padding: 3px 12px; font-size: .72rem; font-weight: 700; }
  .status-badge-none { background: #6c757d; color: #fff; border-radius: 99px; padding: 3px 12px; font-size: .72rem; font-weight: 700; }
  .section-card-header {
    background: linear-gradient(135deg, #1a0a14, #3d0030);
    color: #fff; padding: 16px 20px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .tips-list li { font-size: .83rem; margin-bottom: 6px; color: #6c757d; }
</style>

<!-- Page intro -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <p class="text-muted mb-0" style="font-size:.9rem">
    Upload your promotional cosmetics video — it plays automatically on the public homepage.
  </p>
  <a href="<?= BASE_URL ?>/" target="_blank" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-eye me-1"></i>Preview Public Page
  </a>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION 1 — HERO VIDEO (top of homepage)
══════════════════════════════════════════════════════ -->
<div class="card video-manager-card mb-5">
  <div class="section-card-header">
    <div>
      <i class="bi bi-film me-2"></i>
      <span class="fw-bold">Hero Video</span>
      <small class="ms-2" style="opacity:.65">Top of the public homepage</small>
    </div>
    <?php if ($heroVideo): ?>
      <span class="status-badge-live"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Live</span>
    <?php else: ?>
      <span class="status-badge-none">No Video</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-4">

      <!-- LEFT: current video preview -->
      <div class="col-md-5">
        <label class="form-label fw-bold">Current Hero Video</label>
        <div class="video-preview-wrap">
          <?php if ($heroVideo): ?>
          <video muted loop autoplay playsinline preload="metadata"
                 poster="<?= htmlspecialchars($heroPosterUrl) ?>">
            <source src="<?= htmlspecialchars($heroVideoUrl) ?>" type="video/mp4">
          </video>
          <?php else: ?>
          <div class="no-video-placeholder">
            <i class="bi bi-film" style="font-size:2.5rem"></i>
            <span>No hero video uploaded</span>
            <small>Gradient background will show</small>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($heroVideo): ?>
        <div class="file-meta mt-2">
          <i class="bi bi-file-earmark-play me-1"></i>
          <strong><?= htmlspecialchars(basename($heroVideo)) ?></strong>
          &nbsp;·&nbsp;<?= fileSizeLabel($heroVideo, VIDEO_PATH) ?>
        </div>
        <div class="d-flex gap-2 mt-2">
          <form method="POST" class="d-inline" onsubmit="return confirm('Remove hero video?')">
            <input type="hidden" name="action" value="remove_hero_video">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-trash me-1"></i>Remove Video
            </button>
          </form>
        </div>
        <?php endif; ?>

        <!-- Poster preview -->
        <div class="mt-3">
          <label class="form-label fw-bold">Poster / Fallback Image</label>
          <?php if ($heroPoster): ?>
          <img src="<?= htmlspecialchars($heroPosterUrl) ?>"
               alt="Hero poster"
               style="width:100%;border-radius:10px;max-height:120px;object-fit:cover">
          <div class="file-meta mt-1">
            <?= htmlspecialchars(basename($heroPoster)) ?>
            &nbsp;·&nbsp;<?= fileSizeLabel($heroPoster, UPLOAD_PATH) ?>
          </div>
          <form method="POST" class="mt-1" onsubmit="return confirm('Remove poster image?')">
            <input type="hidden" name="action" value="remove_hero_poster">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-trash me-1"></i>Remove Poster
            </button>
          </form>
          <?php else: ?>
          <div class="p-3 rounded text-center text-muted" style="background:#f8f0f5;font-size:.82rem">
            <i class="bi bi-image mb-1 d-block" style="font-size:1.6rem"></i>
            No poster image — browser default will show while video loads.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: upload form -->
      <div class="col-md-7">
        <form method="POST" enctype="multipart/form-data" id="heroUploadForm">
          <input type="hidden" name="action" value="upload_hero">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

          <!-- Video upload zone -->
          <div class="mb-3">
            <label class="form-label fw-bold">
              <?= $heroVideo ? 'Replace Hero Video' : 'Upload Hero Video' ?>
              <span class="text-muted fw-normal">(MP4 recommended, max 100 MB)</span>
            </label>
            <div class="upload-zone" id="heroVideoZone" onclick="document.getElementById('heroVideoFile').click()">
              <input type="file" name="hero_video" id="heroVideoFile"
                     accept="video/mp4,video/webm,video/ogg"
                     onchange="previewNewVideo(this,'heroPreviewNew','heroVideoZoneLabel')">
              <div class="uz-icon"><i class="bi bi-cloud-arrow-up"></i></div>
              <div class="uz-label" id="heroVideoZoneLabel">Click to select video file</div>
              <div class="uz-hint">MP4 · WebM · Max 100 MB</div>
            </div>
            <!-- Inline preview of newly selected video -->
            <video id="heroPreviewNew" controls muted playsinline
                   style="display:none;width:100%;margin-top:10px;border-radius:10px;max-height:200px;object-fit:cover;background:#1a0a14">
            </video>
          </div>

          <!-- Poster upload zone -->
          <div class="mb-4">
            <label class="form-label fw-bold">
              <?= $heroPoster ? 'Replace Poster Image' : 'Upload Poster Image' ?>
              <span class="text-muted fw-normal">(shown while video loads, max 5 MB)</span>
            </label>
            <div class="upload-zone" id="heroPosterZone" onclick="document.getElementById('heroPosterFile').click()">
              <input type="file" name="hero_poster" id="heroPosterFile"
                     accept="image/jpeg,image/png,image/webp"
                     onchange="previewNewPoster(this,'heroPosterPreview','heroPosterZoneLabel')">
              <div class="uz-icon"><i class="bi bi-image"></i></div>
              <div class="uz-label" id="heroPosterZoneLabel">Click to select poster image</div>
              <div class="uz-hint">JPG · PNG · WebP · Max 5 MB</div>
            </div>
            <img id="heroPosterPreview" alt=""
                 style="display:none;width:100%;max-height:100px;object-fit:cover;border-radius:10px;margin-top:8px">
          </div>

          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-upload me-2"></i><?= $heroVideo ? 'Replace Hero Video' : 'Upload Hero Video' ?>
          </button>
        </form>

        <!-- Tips -->
        <div class="mt-4 p-3 rounded" style="background:#f8f9fa;border:1px solid #eee">
          <div class="fw-bold mb-2" style="font-size:.82rem">
            <i class="bi bi-lightbulb text-warning me-1"></i>Tips for best results
          </div>
          <ul class="tips-list mb-0 ps-3">
            <li>Use <strong>MP4 (H.264)</strong> for maximum browser compatibility</li>
            <li>Recommended resolution: <strong>1920×1080</strong> or <strong>1280×720</strong></li>
            <li>Keep the file under <strong>30–50 MB</strong> for fast loading on mobile</li>
            <li>Use a cosmetics/beauty theme — the video plays <strong>muted and looping</strong></li>
            <li>The poster image shows on mobile or while video buffers — use a <strong>beautiful still frame</strong></li>
            <li>Free stock videos: <a href="https://www.pexels.com/search/videos/cosmetics/" target="_blank" rel="noopener">pexels.com</a></li>
          </ul>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION 2 — SHOWCASE VIDEO (brand story section)
══════════════════════════════════════════════════════ -->
<div class="card video-manager-card mb-4">
  <div class="section-card-header">
    <div>
      <i class="bi bi-play-circle me-2"></i>
      <span class="fw-bold">Showcase Video</span>
      <small class="ms-2" style="opacity:.65">Brand story section (middle of page)</small>
    </div>
    <?php if ($showcaseVideo): ?>
      <span class="status-badge-live"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Live</span>
    <?php else: ?>
      <span class="status-badge-none">No Video</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-4">

      <!-- LEFT: current -->
      <div class="col-md-5">
        <label class="form-label fw-bold">Current Showcase Video</label>
        <div class="video-preview-wrap">
          <?php if ($showcaseVideo): ?>
          <video muted playsinline preload="metadata"
                 poster="<?= htmlspecialchars($showcasePosterUrl) ?>"
                 style="cursor:pointer" onclick="this.paused?this.play():this.pause()">
            <source src="<?= htmlspecialchars($showcaseVideoUrl) ?>" type="video/mp4">
          </video>
          <?php else: ?>
          <div class="no-video-placeholder">
            <i class="bi bi-play-circle" style="font-size:2.5rem"></i>
            <span>No showcase video uploaded</span>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($showcaseVideo): ?>
        <div class="file-meta mt-2">
          <i class="bi bi-file-earmark-play me-1"></i>
          <strong><?= htmlspecialchars(basename($showcaseVideo)) ?></strong>
          &nbsp;·&nbsp;<?= fileSizeLabel($showcaseVideo, VIDEO_PATH) ?>
        </div>
        <form method="POST" class="mt-2 d-inline" onsubmit="return confirm('Remove showcase video?')">
          <input type="hidden" name="action" value="remove_showcase_video">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash me-1"></i>Remove
          </button>
        </form>
        <?php endif; ?>

        <!-- Showcase poster -->
        <div class="mt-3">
          <label class="form-label fw-bold">Showcase Poster</label>
          <?php if ($showcasePoster): ?>
          <img src="<?= htmlspecialchars($showcasePosterUrl) ?>"
               style="width:100%;border-radius:10px;max-height:100px;object-fit:cover"
               alt="Showcase poster">
          <div class="file-meta mt-1">
            <?= htmlspecialchars(basename($showcasePoster)) ?>
            &nbsp;·&nbsp;<?= fileSizeLabel($showcasePoster, UPLOAD_PATH) ?>
          </div>
          <form method="POST" class="mt-1 d-inline" onsubmit="return confirm('Remove?')">
            <input type="hidden" name="action" value="remove_showcase_poster">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-trash me-1"></i>Remove
            </button>
          </form>
          <?php else: ?>
          <div class="p-3 rounded text-center text-muted" style="background:#f8f0f5;font-size:.82rem">
            <i class="bi bi-image mb-1 d-block" style="font-size:1.4rem"></i>No poster set.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: upload -->
      <div class="col-md-7">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload_showcase">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

          <div class="mb-3">
            <label class="form-label fw-bold">
              <?= $showcaseVideo ? 'Replace Showcase Video' : 'Upload Showcase Video' ?>
              <span class="text-muted fw-normal">(max 100 MB)</span>
            </label>
            <div class="upload-zone" onclick="document.getElementById('showcaseVideoFile').click()">
              <input type="file" name="showcase_video" id="showcaseVideoFile"
                     accept="video/mp4,video/webm,video/ogg"
                     onchange="previewNewVideo(this,'showcasePreviewNew','showcaseVideoZoneLabel')">
              <div class="uz-icon"><i class="bi bi-cloud-arrow-up"></i></div>
              <div class="uz-label" id="showcaseVideoZoneLabel">Click to select video file</div>
              <div class="uz-hint">MP4 · WebM · Max 100 MB</div>
            </div>
            <video id="showcasePreviewNew" controls muted playsinline
                   style="display:none;width:100%;margin-top:10px;border-radius:10px;max-height:200px;object-fit:cover;background:#1a0a14">
            </video>
          </div>

          <div class="mb-4">
            <label class="form-label fw-bold">
              <?= $showcasePoster ? 'Replace Showcase Poster' : 'Upload Showcase Poster' ?>
            </label>
            <div class="upload-zone" onclick="document.getElementById('showcasePosterFile').click()">
              <input type="file" name="showcase_poster" id="showcasePosterFile"
                     accept="image/jpeg,image/png,image/webp"
                     onchange="previewNewPoster(this,'showcasePosterPreview','showcasePosterZoneLabel')">
              <div class="uz-icon"><i class="bi bi-image"></i></div>
              <div class="uz-label" id="showcasePosterZoneLabel">Click to select poster image</div>
              <div class="uz-hint">JPG · PNG · WebP · Max 5 MB</div>
            </div>
            <img id="showcasePosterPreview"
                 style="display:none;width:100%;max-height:100px;object-fit:cover;border-radius:10px;margin-top:8px"
                 alt="">
          </div>

          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-upload me-2"></i><?= $showcaseVideo ? 'Replace Showcase Video' : 'Upload Showcase Video' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Quick status summary -->
<div class="card">
  <div class="card-header fw-bold"><i class="bi bi-info-circle me-2"></i>Current Status</div>
  <div class="card-body p-0">
    <table class="table mb-0">
      <thead><tr><th>Section</th><th>Video</th><th>Poster</th><th>Status</th></tr></thead>
      <tbody>
        <tr>
          <td class="fw-semibold">Hero (top)</td>
          <td><?= $heroVideo ? '<span class="badge bg-success">'.htmlspecialchars(basename($heroVideo)).'</span>' : '<span class="badge bg-secondary">None</span>' ?></td>
          <td><?= $heroPoster ? '<span class="badge bg-success">'.htmlspecialchars(basename($heroPoster)).'</span>' : '<span class="badge bg-secondary">None</span>' ?></td>
          <td><?= $heroVideo ? '<span class="badge bg-success">Video Playing</span>' : '<span class="badge bg-warning text-dark">Gradient Fallback</span>' ?></td>
        </tr>
        <tr>
          <td class="fw-semibold">Showcase (mid)</td>
          <td><?= $showcaseVideo ? '<span class="badge bg-success">'.htmlspecialchars(basename($showcaseVideo)).'</span>' : '<span class="badge bg-secondary">None</span>' ?></td>
          <td><?= $showcasePoster ? '<span class="badge bg-success">'.htmlspecialchars(basename($showcasePoster)).'</span>' : '<span class="badge bg-secondary">None</span>' ?></td>
          <td><?= $showcaseVideo ? '<span class="badge bg-success">Video Ready</span>' : '<span class="badge bg-secondary">Hidden</span>' ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<script>
// Preview newly selected video file in-browser before upload
function previewNewVideo(input, previewId, labelId) {
  const preview = document.getElementById(previewId);
  const label   = document.getElementById(labelId);
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const mb   = (file.size / 1048576).toFixed(1);
  label.textContent = file.name + ' (' + mb + ' MB)';
  const url  = URL.createObjectURL(file);
  preview.src = url;
  preview.style.display = 'block';
  preview.load();
}

// Preview newly selected poster image in-browser before upload
function previewNewPoster(input, previewId, labelId) {
  const preview = document.getElementById(previewId);
  const label   = document.getElementById(labelId);
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const mb   = (file.size / 1048576).toFixed(1);
  label.textContent = file.name + ' (' + mb + ' MB)';
  const reader = new FileReader();
  reader.onload = e => {
    preview.src = e.target.result;
    preview.style.display = 'block';
  };
  reader.readAsDataURL(file);
}

// Drag-and-drop support on upload zones
document.querySelectorAll('.upload-zone').forEach(zone => {
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.style.borderColor='var(--primary)'; zone.style.background='#ffd6ef'; });
  zone.addEventListener('dragleave', () => { zone.style.borderColor=''; zone.style.background=''; });
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.style.borderColor=''; zone.style.background='';
    const input = zone.querySelector('input[type="file"]');
    if (!input || !e.dataTransfer.files.length) return;
    input.files = e.dataTransfer.files;
    input.dispatchEvent(new Event('change'));
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
