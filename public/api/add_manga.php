<?php
/**
 * MangaTracker v2.1 — Add or Update Manga
 * Returns a JSON response. Requires authentication + CSRF header.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json(true);

const ALLOWED_LANGS    = ['fr', 'en', 'ja', 'es', 'de', 'it', 'pt', 'ko', 'zh', 'other'];
const ALLOWED_STATUSES = ['reading', 'completed'];

try {
    mt_ensure_schema($pdo);

    $id             = isset($_POST['id']) && !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $title          = trim($_POST['title'] ?? '');
    $readingLink    = trim($_POST['readingLink'] ?? '');
    $currentChapter = trim($_POST['currentChapter'] ?? '');
    $status         = trim($_POST['status'] ?? 'reading');
    $language       = trim($_POST['language'] ?? 'fr');
    $notes          = trim($_POST['notes'] ?? '');
    $imageUrl       = trim($_POST['imageUrl'] ?? '');
    $rating         = isset($_POST['rating']) && is_numeric($_POST['rating']) ? max(0, min(5, (int)$_POST['rating'])) : 0;
    $imagePath      = null;

    if (!$title)          throw new Exception('Le titre est requis');
    if (!$readingLink)    throw new Exception('Le lien de lecture est requis');
    if (!$currentChapter) throw new Exception('Le chapitre actuel est requis');

    if (mb_strlen($title) > 255)          throw new Exception('Titre trop long (max 255)');
    if (mb_strlen($currentChapter) > 100) throw new Exception('Chapitre trop long (max 100)');
    if (!in_array($status, ALLOWED_STATUSES, true)) $status = 'reading';
    if (!in_array($language, ALLOWED_LANGS, true))  $language = 'other';

    if (!mt_valid_http_url($readingLink)) {
        throw new Exception('Le lien de lecture doit commencer par http:// ou https://');
    }
    if ($imageUrl !== '' && !mt_valid_http_url($imageUrl)) {
        throw new Exception("L'URL de l'image doit commencer par http:// ou https://");
    }

    // ── Duplicate title? (new entries only; the client can resend with force=1) ──
    if (!$id && ($_POST['force'] ?? '') !== '1') {
        $dup = $pdo->prepare("SELECT id, title FROM mangas WHERE LOWER(TRIM(title)) = LOWER(?) LIMIT 1");
        $dup->execute([$title]);
        if ($existing = $dup->fetch()) {
            echo json_encode([
                'success' => false,
                'code'    => 'duplicate',
                'error'   => "« {$existing['title']} » existe déjà dans ta collection",
            ]);
            exit;
        }
    }

    // ── Image upload ─────────────────────────────────────────────────────────
    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        // Extension is derived from the REAL mime type, never from the client's filename.
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['imageFile']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowedTypes[$mimeType])) {
            throw new Exception('Type de fichier non autorisé');
        }
        if (@getimagesize($_FILES['imageFile']['tmp_name']) === false) {
            throw new Exception('Image invalide ou corrompue');
        }
        if ($_FILES['imageFile']['size'] > 5 * 1024 * 1024) {
            throw new Exception('Image trop grande (max 5 Mo)');
        }

        $safeTitle = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $title), 0, 60);
        $filename  = uniqid() . '_' . $safeTitle . '.' . $allowedTypes[$mimeType];
        $relDir    = 'img/manga/';
        mt_ensure_dir($relDir, MT_IMAGES_HTACCESS);   // also drops a .htaccess that forbids scripts there

        $imagePath = $relDir . $filename;
        if (!move_uploaded_file($_FILES['imageFile']['tmp_name'], mt_abs($imagePath))) {
            throw new Exception('Erreur lors du déplacement du fichier');
        }
    }

    // ── Update or insert ─────────────────────────────────────────────────────
    if ($id) {
        // Replacing the cover (upload OR new URL): remove the old local file
        if ($imagePath || $imageUrl) {
            $stmt = $pdo->prepare("SELECT image FROM mangas WHERE id = ?");
            $stmt->execute([$id]);
            $oldImage = $stmt->fetchColumn();
            if ($oldImage && strpos($oldImage, 'img/') === 0 && strpos($oldImage, '..') === false
                && is_file(mt_abs($oldImage)) && $oldImage !== $imagePath) {
                @unlink(mt_abs($oldImage));
            }
        }

        $sql    = "UPDATE mangas SET title=?, reading_link=?, current_chapter=?, status=?, language=?, notes=?, rating=?";
        $params = [$title, $readingLink, $currentChapter, $status, $language, $notes, $rating];

        if ($imagePath) {
            $sql .= ", image=?";
            $params[] = $imagePath;
        } elseif ($imageUrl) {
            $sql .= ", image=?";
            $params[] = $imageUrl;
        }

        $sql .= ", date_updated=NOW() WHERE id=?";
        $params[] = $id;

        $pdo->prepare($sql)->execute($params);
    } else {
        $finalImage = $imagePath ?: $imageUrl;
        $stmt = $pdo->prepare("
            INSERT INTO mangas (title, image, reading_link, current_chapter, status, language, notes, rating, date_added)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$title, $finalImage, $readingLink, $currentChapter, $status, $language, $notes, $rating]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
