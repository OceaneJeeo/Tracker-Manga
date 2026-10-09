<?php
/**
 * MangaTracker v3.0 — Main Page (login + application shell)
 */

require_once __DIR__ . '/../src/security.php';

mt_session_start(false);   // writable session: login / logout / CSRF token
header('Content-Type: text/html; charset=UTF-8');
mt_html_headers();         // Content-Security-Policy (the other security headers come from mt_session_start)

$isAuthenticated = mt_is_authenticated();

// Handle login (5 failures => locked for 5 minutes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $lockedFor = mt_login_locked_seconds();
    if ($lockedFor > 0) {
        mt_log('login_blocked', 'attempt while locked');
        $error = "Trop de tentatives. Réessayez dans " . ceil($lockedFor / 60) . " min.";
    } elseif (password_verify((string)$_POST['password'], MT_PASSWORD_HASH)) {
        mt_login_clear();
        mt_login_session();
        mt_log('login_ok');
        header('Location: index.php');
        exit;
    } else {
        mt_login_record_failure();
        mt_log('login_failed');
        $error = mt_login_locked_seconds() > 0
            ? "Trop de tentatives. Réessayez dans 5 min."
            : "Mot de passe incorrect";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    if ($isAuthenticated) {
        mt_log('logout');
    }
    mt_logout_session();
    header('Location: index.php');
    exit;
}

$csrfToken = $isAuthenticated ? mt_csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MangaTracker - Ma Collection</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="assets/css/manga.css" rel="stylesheet">
    <meta name="theme-color" content="#0d0f14">
    <?php if ($isAuthenticated): ?><meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>"><?php endif; ?>
</head>
<body>

<?php if (!$isAuthenticated): ?>
<!-- ═══════════════════ LOGIN PAGE ═══════════════════ -->
<div class="login-wrap">
    <div class="login-card">
        <div class="login-logo">📚 MangaTracker</div>
        <p class="login-subtitle">Votre collection personnelle sécurisée</p>

        <?php if (isset($error)): ?>
        <div class="login-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label for="password">Mot de passe</label>
                <input type="password" name="password" id="password" required autofocus placeholder="••••••••">
            </div>
            <button type="submit" class="btn-primary">🔓 Connexion</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════ APP ═══════════════════ -->

<!-- NAVBAR -->
<nav class="navbar">
    <div class="navbar-inner">
        <a href="#" class="nav-logo">📚 MangaTracker</a>

        <!-- Search -->
        <div class="nav-search">
            <span class="nav-search-icon">🔍</span>
            <input type="text" id="navSearch" placeholder="Rechercher un manga…" oninput="handleSearch(this.value)" autocomplete="off">
            <button class="nav-search-clear" id="navSearchClear" onclick="clearSearch()">✕</button>
        </div>

        <div class="nav-spacer"></div>

        <div class="nav-actions">
            <!-- Add button -->
            <button class="nav-btn accent" onclick="openAddModal()">+ Ajouter</button>

            <!-- Import/Export dropdown -->
            <div class="dropdown">
                <button class="nav-btn" onclick="toggleDropdown('menuImportExport')">
                    ⚙️ <span style="display:none" id="menuLabel">Options</span>
                </button>
                <div class="dropdown-menu" id="menuImportExport">
                    <button class="dropdown-item" onclick="exportCollection();toggleDropdown('menuImportExport')">📤 Exporter JSON</button>
                    <button class="dropdown-item" onclick="triggerImport();toggleDropdown('menuImportExport')">📥 Importer JSON</button>
                    <button class="dropdown-item" onclick="downloadBackup();toggleDropdown('menuImportExport')">💾 Sauvegarde complète (ZIP)</button>
                    <button class="dropdown-item" onclick="triggerRestore();toggleDropdown('menuImportExport')">♻️ Restaurer une sauvegarde</button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" onclick="openStatsModal();toggleDropdown('menuImportExport')">📈 Statistiques</button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" onclick="openPasswordModal();toggleDropdown('menuImportExport')">🔑 Changer le mot de passe</button>
                    <button class="dropdown-item" onclick="openSecurityLog();toggleDropdown('menuImportExport')">🛡️ Journal de sécurité</button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item" onclick="toggleAutoChapter()">
                        <span id="autoChapterState">✅</span> Chapitre auto en fin de lecture
                    </button>
                    <div class="dropdown-divider"></div>
                    <div style="padding:0.4rem 1rem 0;font-size:0.7rem;color:var(--text3);text-transform:uppercase;letter-spacing:0.06em">Thème</div>
                    <div class="theme-options">
                        <div class="theme-dot dark"   data-theme="dark"   onclick="applyTheme('dark')"   title="Sombre"></div>
                        <div class="theme-dot light"  data-theme="light"  onclick="applyTheme('light')"  title="Clair"></div>
                        <div class="theme-dot amoled" data-theme="amoled" onclick="applyTheme('amoled')" title="AMOLED"></div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="?logout" class="dropdown-item danger">🚪 Déconnexion</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- HIDDEN inputs -->
<input type="file" id="importFileInput" accept=".json" style="display:none">
<input type="file" id="restoreFileInput" accept=".zip" style="display:none">

<!-- MAIN APP -->
<div class="app">

    <!-- Hero -->
    <div class="hero-bar">
        <h1 class="hero-title">Ma <span>Collection</span></h1>
        <div class="hero-actions">
            <!-- View toggle -->
            <div class="view-toggle">
                <button class="view-btn" data-view="grid" onclick="applyView('grid')" title="Vue grille">⊞</button>
                <button class="view-btn" data-view="list" onclick="applyView('list')" title="Vue liste">☰</button>
            </div>
            <!-- Sort -->
            <select class="sort-select" id="sortSelect" onchange="setSort(this.value)">
                <option value="date_added_desc">📅 Ajout récent</option>
                <option value="date_added_asc">📅 Ajout ancien</option>
                <option value="date_updated_desc">🔄 Mis à jour</option>
                <option value="title_asc">🔤 Titre A→Z</option>
                <option value="title_desc">🔤 Titre Z→A</option>
                <option value="current_chapter_desc">📖 Ch. ↑</option>
                <option value="rating_desc">⭐ Note ↓</option>
            </select>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card s-total">
            <div class="stat-icon">📚</div>
            <div class="stat-body">
                <div class="stat-val" id="statTotal">0</div>
                <div class="stat-lbl">Total</div>
            </div>
        </div>
        <div class="stat-card s-reading">
            <div class="stat-icon">📖</div>
            <div class="stat-body">
                <div class="stat-val" id="statReading">0</div>
                <div class="stat-lbl">En cours</div>
            </div>
        </div>
        <div class="stat-card s-done">
            <div class="stat-icon">✅</div>
            <div class="stat-body">
                <div class="stat-val" id="statCompleted">0</div>
                <div class="stat-lbl">Terminés</div>
            </div>
        </div>
        <div class="stat-card s-chapters">
            <div class="stat-icon">📦</div>
            <div class="stat-body">
                <div class="stat-val" id="statChapters">0</div>
                <div class="stat-lbl">Chapitres</div>
            </div>
        </div>
        <div class="stat-card s-score">
            <div class="stat-icon">⭐</div>
            <div class="stat-body">
                <div class="stat-val" id="statRating">—</div>
                <div class="stat-lbl">Note moy.</div>
            </div>
        </div>
    </div>

    <!-- Toolbar: Filters -->
    <div class="toolbar">
        <div class="toolbar-left">
            <!-- Status filters -->
            <button class="filter-pill fp-status active" data-status="all"       onclick="setFilterStatus('all')">📚 Tous</button>
            <button class="filter-pill fp-status"        data-status="reading"   onclick="setFilterStatus('reading')">📖 En cours</button>
            <button class="filter-pill fp-status"        data-status="completed" onclick="setFilterStatus('completed')">✅ Terminés</button>
        </div>
    </div>

    <!-- Language filters (built dynamically) -->
    <div class="toolbar" style="margin-top:-0.75rem">
        <div class="toolbar-left" id="langFilters" style="flex-wrap:wrap"></div>
    </div>

    <!-- Search info -->
    <div id="searchInfo" class="search-info" style="display:none"></div>

    <!-- Continue reading -->
    <div class="section-block" id="continueBlock" style="display:none">
        <div class="section-head">
            <div class="section-title">▶ Reprendre la lecture</div>
        </div>
        <div class="continue-strip" id="continueStrip"></div>
    </div>

    <!-- Reading section -->
    <div class="section-block" id="readingBlock">
        <div class="section-head">
            <div class="section-title">📖 En cours de lecture</div>
            <span class="section-count" id="countReading">0</span>
        </div>
        <div id="gridReading" class="manga-grid"></div>
    </div>

    <!-- Completed section -->
    <div class="section-block" id="completedBlock">
        <div class="section-head">
            <div class="section-title">✅ Terminés</div>
            <span class="section-count" id="countCompleted">0</span>
        </div>
        <div id="gridCompleted" class="manga-grid"></div>
    </div>

    <!-- Empty state -->
    <div id="emptyState" class="empty" style="display:none">
        <div class="empty-icon">📖</div>
        <h3>Aucun manga dans la collection</h3>
        <p>Commencez par ajouter votre premier manga</p>
    </div>

</div><!-- .app -->

<!-- Toast container -->
<div id="toastContainer"></div>

<!-- ═══════════════════════════════════════════
     MODAL: Add / Edit Manga
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="formTitle">Ajouter un manga</h2>
            <button class="modal-close" onclick="closeModal('addModal')">×</button>
        </div>
        <div class="modal-body">
            <form id="mangaForm" enctype="multipart/form-data">
                <input type="hidden" name="id" id="mangaId">

                <!-- Tabs -->
                <div class="tab-row">
                    <button type="button" class="tab-btn active" data-tab="t-info"    onclick="switchTab('t-info',this)">📋 Infos</button>
                    <button type="button" class="tab-btn"        data-tab="t-image"   onclick="switchTab('t-image',this)">🖼️ Image</button>
                    <button type="button" class="tab-btn"        data-tab="t-notes"   onclick="switchTab('t-notes',this)">📝 Notes</button>
                </div>

                <!-- Tab: Infos -->
                <div class="tab-panel active" id="t-info">
                    <div class="form-field">
                        <label class="form-label">Titre *</label>
                        <input type="text" name="title" id="formMangaTitle" class="form-input" required placeholder="One Piece, Naruto…">
                    </div>
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Chapitre / Volume *</label>
                            <input type="text" name="currentChapter" id="formChapter" class="form-input" required placeholder="Chap. 1, Vol. 5…">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Statut *</label>
                            <select name="status" id="formStatus" class="form-select" required>
                                <option value="reading">📖 En cours</option>
                                <option value="completed">✅ Terminé</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Langue de lecture *</label>
                            <select name="language" id="formLang" class="form-select" required>
                                <option value="fr">🇫🇷 Français</option>
                                <option value="en">🇬🇧 English</option>
                                <option value="ja">🇯🇵 日本語</option>
                                <option value="es">🇪🇸 Español</option>
                                <option value="de">🇩🇪 Deutsch</option>
                                <option value="it">🇮🇹 Italiano</option>
                                <option value="pt">🇵🇹 Português</option>
                                <option value="ko">🇰🇷 한국어</option>
                                <option value="zh">🇨🇳 中文</option>
                                <option value="other">🌐 Autre</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Note personnelle</label>
                            <div class="star-input" id="starInput"></div>
                            <input type="hidden" name="rating" id="formRating">
                        </div>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Lien de lecture *</label>
                        <input type="url" name="readingLink" id="formReadingLink" class="form-input" required placeholder="https://…">
                    </div>
                </div>

                <!-- Tab: Image -->
                <div class="tab-panel" id="t-image">
                    <div class="form-field">
                        <label class="form-label">Fichier image (max 5 MB)</label>
                        <input type="file" name="imageFile" id="formImageFile" class="form-input" accept="image/*">
                        <p class="form-hint">JPEG, PNG, GIF, WebP — ou coller une URL ci-dessous</p>
                    </div>
                    <div class="form-field">
                        <label class="form-label">URL de l'image</label>
                        <input type="url" name="imageUrl" id="formImageUrl" class="form-input" placeholder="https://…">
                    </div>
                    <div class="img-preview-wrap" id="imgPreview">
                        <div class="img-preview-placeholder">📷</div>
                    </div>
                </div>

                <!-- Tab: Notes -->
                <div class="tab-panel" id="t-notes">
                    <div class="form-field">
                        <label class="form-label">Notes personnelles</label>
                        <textarea name="notes" id="formNotes" class="form-textarea" placeholder="Avis, arcs favoris, là où je me suis arrêté…" rows="6"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="submit" form="mangaForm" id="btnSaveManga" class="btn btn-accent">Sauvegarder</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Annuler</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Reading statistics
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="statsModal">
    <div class="modal">
        <div class="modal-header">
            <h2>📈 Statistiques</h2>
            <button class="modal-close" onclick="closeModal('statsModal')">×</button>
        </div>
        <div class="modal-body" id="statsBody"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Change password
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="passwordModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2>🔑 Changer le mot de passe</h2>
            <button class="modal-close" onclick="closeModal('passwordModal')">×</button>
        </div>
        <div class="modal-body">
            <form id="passwordForm" autocomplete="off">
                <div class="form-field">
                    <label class="form-label" for="pwCurrent">Mot de passe actuel</label>
                    <input type="password" id="pwCurrent" class="form-input" autocomplete="current-password" required>
                </div>
                <div class="form-field">
                    <label class="form-label" for="pwNew">Nouveau mot de passe <span style="color:var(--text3)">(10 caractères minimum)</span></label>
                    <input type="password" id="pwNew" class="form-input" autocomplete="new-password" minlength="10" required>
                    <div class="pw-meter"><div id="pwMeterBar"></div></div>
                    <div class="pw-meter-label" id="pwMeterLabel"></div>
                </div>
                <div class="form-field">
                    <label class="form-label" for="pwConfirm">Confirmer</label>
                    <input type="password" id="pwConfirm" class="form-input" autocomplete="new-password" minlength="10" required>
                </div>
                <p class="stats-note">Les autres appareils / navigateurs connectés seront déconnectés.</p>
            </form>
        </div>
        <div class="modal-footer">
            <button type="submit" form="passwordForm" id="btnChangePassword" class="btn btn-accent">Changer</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal('passwordModal')">Annuler</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Security journal
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="securityModal">
    <div class="modal">
        <div class="modal-header">
            <h2>🛡️ Journal de sécurité</h2>
            <button class="modal-close" onclick="closeModal('securityModal')">×</button>
        </div>
        <div class="modal-body" id="securityBody"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Detail View
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="detailModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2>Détails</h2>
            <button class="modal-close" onclick="closeModal('detailModal')">×</button>
        </div>
        <div class="modal-body" id="detailBody"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Chapters
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="chaptersModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2>📦 Chapitres — <span id="chaptersMangaTitle" style="color:var(--accent2)"></span></h2>
            <button class="modal-close" onclick="closeChaptersModal()">×</button>
        </div>
        <div class="modal-body">

            <!-- Upload zone -->
            <div class="ch-upload-zone">
                <strong style="display:block;margin-bottom:1rem;font-size:0.95rem">Ajouter un chapitre</strong>
                <form id="chapterUploadForm" enctype="multipart/form-data">
                    <input type="hidden" id="chapterMangaId" name="manga_id">
                    <input type="hidden" name="action" value="upload">
                    <div class="ch-upload-grid" id="chapterSingleGrid">
                        <div class="form-field">
                            <label class="form-label">Chapitre # *</label>
                            <input type="text" id="chapterNumber" name="chapter_number" class="form-input" placeholder="1, 2.5, 10…">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Fichier(s) ZIP *</label>
                            <input type="file" id="chapterFile" name="chapterFile" class="form-input" accept=".zip" multiple required>
                        </div>
                    </div>
                    <p style="font-size:0.78rem;color:var(--text3);margin:-0.5rem 0 0.75rem">Sélectionnez plusieurs fichiers pour un envoi groupé — le numéro de chapitre de chacun est deviné depuis son nom, et reste modifiable ci-dessous.</p>

                    <!-- Per-file list, shown when 2+ files are selected -->
                    <div id="chapterFilesList" class="ch-files-list" style="display:none"></div>

                    <!-- Progress bar -->
                    <div class="progress-bar-wrap" id="uploadProgress" style="display:none;margin-bottom:0.35rem">
                        <div class="progress-bar" id="uploadProgressBar"></div>
                    </div>
                    <p id="uploadProgressLabel" style="display:none;font-size:0.78rem;color:var(--text3);margin:0 0 0.75rem"></p>

                    <button type="submit" class="btn btn-accent" style="width:100%" id="chapterUploadBtn">📤 Uploader le chapitre</button>
                </form>
            </div>

            <!-- List -->
            <strong style="display:block;margin-bottom:0.75rem;font-size:0.95rem">Chapitres disponibles</strong>
            <div id="chaptersList" class="chapters-list"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     READER (fullscreen, not a regular modal)
═══════════════════════════════════════════ -->
<div class="reader-overlay" id="readerOverlay">
    <div class="reader-progress"><div id="readerProgressBar"></div></div>
    <div class="reader-toolbar">
        <button class="reader-close" onclick="closeReader()" title="Fermer">×</button>
        <div class="reader-chapter-nav">
            <button class="reader-chapter-btn" id="readerPrevChapterBtn" onclick="readerPrevChapter()" title="Chapitre précédent">⏮</button>
            <div class="reader-title" id="readerTitle">—</div>
            <button class="reader-chapter-btn" id="readerNextChapterBtn" onclick="readerNextChapter()" title="Chapitre suivant">⏭</button>
        </div>
        <div class="reader-mode-toggle">
            <button class="reader-mode-btn active" id="readerModeScrollBtn" onclick="setReaderMode('scroll')">📜 Défilement</button>
            <button class="reader-mode-btn" id="readerModePageBtn" onclick="setReaderMode('page')">📄 Page par page</button>
        </div>
    </div>

    <div class="reader-body">
        <div class="reader-status" id="readerStatus">⏳ Chargement des pages…</div>

        <div class="reader-scroll-view" id="readerScrollView" style="display:none"></div>

        <div class="reader-page-view" id="readerPageView" style="display:none">
            <button class="reader-nav reader-nav-prev" onclick="readerPrevPage()" title="Page précédente">‹</button>
            <img id="readerPageImage" class="reader-page-image" alt="Page">
            <button class="reader-nav reader-nav-next" onclick="readerNextPage()" title="Page suivante">›</button>
        </div>
    </div>

    <div class="reader-footer" id="readerFooter" style="display:none">
        <button class="btn btn-ghost" onclick="readerPrevPage()">◀ Précédent</button>
        <span class="reader-page-indicator" id="readerPageIndicator">1 / 1</span>
        <button class="btn btn-ghost" onclick="readerNextPage()">Suivant ▶</button>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL: Delete Confirm
═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal modal-sm">
        <div class="modal-body" style="padding-top:2rem">
            <div class="del-popup-icon">🗑️</div>
            <div class="del-popup-title">Confirmer la suppression</div>
            <p class="del-popup-msg">Supprimer définitivement<br>"<strong id="deleteItemName"></strong>" ?</p>
            <p class="del-popup-warn">⚠️ Cette action est irréversible.</p>
        </div>
        <div class="modal-footer">
            <button id="btnConfirmDelete" class="btn btn-danger" onclick="executeDelete()">✔️ Supprimer</button>
            <button class="btn btn-ghost" onclick="closeDeleteModal()">✖️ Annuler</button>
        </div>
    </div>
</div>

<!-- Tab switch helper -->
<script>
function switchTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}
</script>

<script src="assets/js/manga.js"></script>

<?php endif; ?>
</body>
</html>