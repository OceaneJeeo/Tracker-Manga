/**
 * MangaTracker v2.0 — Client-Side Module
 * Features: grid/list view, sort/filter, ratings, detail modal,
 *           quick +1, import/export, toast notifications, themes
 */

'use strict';

// ── CSRF + session expiry (wraps every fetch) ────────────────────────────────

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';
const _nativeFetch = window.fetch.bind(window);
window.fetch = async (input, init = {}) => {
    if ((init.method || 'GET').toUpperCase() !== 'GET') {
        const headers = new Headers(init.headers || {});
        headers.set('X-CSRF-Token', CSRF_TOKEN);
        init = { ...init, headers };
    }
    const res = await _nativeFetch(input, init);
    if (res.status === 401) location.reload();   // session expired => back to the login page
    return res;
};

// ── Constants ─────────────────────────────────────────────────────────────────

const LANG_FLAGS = {
    fr:'🇫🇷', en:'🇬🇧', ja:'🇯🇵', es:'🇪🇸', de:'🇩🇪',
    it:'🇮🇹', pt:'🇵🇹', ko:'🇰🇷', zh:'🇨🇳', other:'🌐'
};

const LANG_NAMES = {
    fr:'Français', en:'English', ja:'日本語', es:'Español', de:'Deutsch',
    it:'Italiano', pt:'Português', ko:'한국어', zh:'中文', other:'Other'
};

// ── State ─────────────────────────────────────────────────────────────────────

let mangas        = [];
let view          = localStorage.getItem('mt_view') || 'grid';
let sortBy        = localStorage.getItem('mt_sort') || 'date_added';
let sortDir       = localStorage.getItem('mt_sort_dir') || 'desc';
let filterStatus  = 'all';
let filterLang    = 'all';
let searchQuery   = '';
let pendingDeleteId = null;
let currentChaptersMangaId = null;
let editingRating = 0;
let currentChapters   = [];
let readerChapterId   = null;
let readerChapterIndex = -1;
let readerPages       = [];
let readerCurrentPage = 0;
let readerMode        = localStorage.getItem('mt_reader_mode') || 'scroll';
let readerMangaId     = null;
let readerRestoring   = false;
let readerObserver    = null;
let readerSaveTimer   = null;
let readerPendingSave = null;
let readerLastSaved   = '';
let readerSavePromise = Promise.resolve();

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    syncAutoChapterUI();
    applyView(view);
    document.getElementById('sortSelect').value = sortBy + '_' + sortDir;
    loadMangas();
    bindGlobalEvents();
});

// ════════════════════════════════════════
// THEME
// ════════════════════════════════════════

function initTheme() {
    const saved = localStorage.getItem('mt_theme') || 'dark';
    applyTheme(saved);
}

function applyTheme(theme) {
    document.body.className = '';
    if (theme !== 'dark') document.body.classList.add(theme);
    localStorage.setItem('mt_theme', theme);
    document.querySelectorAll('.theme-dot').forEach(d => {
        d.classList.toggle('active', d.dataset.theme === theme);
    });
    // Update toggle icon in navbar
    const ico = document.getElementById('themeIcon');
    if (ico) ico.textContent = theme === 'light' ? '🌙' : theme === 'amoled' ? '⚫' : '☀️';
}

// ════════════════════════════════════════
// DATA LOADING
// ════════════════════════════════════════

async function loadMangas() {
    try {
        const res  = await fetch('api/get_mangas.php');
        const data = await res.json();
        if (data.success) {
            mangas = data.mangas;
            renderAll();
            updateStats();
        } else {
            toast('Erreur de chargement : ' + data.error, 'error');
        }
    } catch (e) {
        toast('Impossible de charger la collection', 'error');
    }
}

// ════════════════════════════════════════
// FILTERING & SORTING
// ════════════════════════════════════════

function getFiltered() {
    let list = [...mangas];

    // Status filter
    if (filterStatus !== 'all') list = list.filter(m => m.status === filterStatus);

    // Language filter
    if (filterLang !== 'all') list = list.filter(m => (m.language || 'fr') === filterLang);

    // Search
    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        list = list.filter(m =>
            m.title.toLowerCase().includes(q) ||
            (m.notes && m.notes.toLowerCase().includes(q)) ||
            m.current_chapter.toLowerCase().includes(q)
        );
    }

    // Sort
    list.sort((a, b) => {
        let va, vb;
        switch (sortBy) {
            case 'title':
                va = a.title.toLowerCase(); vb = b.title.toLowerCase();
                break;
            case 'date_updated':
                va = new Date(a.date_updated || a.date_added);
                vb = new Date(b.date_updated || b.date_added);
                break;
            case 'current_chapter':
                va = parseFloat(a.current_chapter) || 0;
                vb = parseFloat(b.current_chapter) || 0;
                break;
            case 'rating':
                va = parseInt(a.rating) || 0;
                vb = parseInt(b.rating) || 0;
                break;
            default: // date_added
                va = new Date(a.date_added);
                vb = new Date(b.date_added);
        }
        if (va < vb) return sortDir === 'asc' ? -1 : 1;
        if (va > vb) return sortDir === 'asc' ? 1 : -1;
        return 0;
    });

    return list;
}

function setSort(val) {
    const parts = val.split('_');
    sortDir = parts.pop();
    sortBy  = parts.join('_');
    localStorage.setItem('mt_sort', sortBy);
    localStorage.setItem('mt_sort_dir', sortDir);
    renderAll();
}

function setFilterStatus(status) {
    filterStatus = status;
    document.querySelectorAll('.fp-status').forEach(el => {
        el.classList.toggle('active', el.dataset.status === status);
    });
    renderAll();
}

function setFilterLang(lang) {
    filterLang = lang;
    document.querySelectorAll('.fp-lang').forEach(el => {
        el.classList.toggle('active', el.dataset.lang === lang);
    });
    renderAll();
}

function handleSearch(q) {
    searchQuery = q.trim();
    const clear = document.getElementById('navSearchClear');
    if (clear) clear.style.display = searchQuery ? 'block' : 'none';

    const info = document.getElementById('searchInfo');
    if (!searchQuery) {
        if (info) info.style.display = 'none';
        renderAll();
        return;
    }

    const filtered = getFiltered();
    if (info) {
        info.style.display = 'block';
        info.textContent = filtered.length === 0
            ? `Aucun résultat pour "${q}"`
            : `${filtered.length} manga${filtered.length > 1 ? 's' : ''} trouvé${filtered.length > 1 ? 's' : ''} pour "${q}"`;
    }
    renderAll();
}

function clearSearch() {
    const inp = document.getElementById('navSearch');
    if (inp) inp.value = '';
    handleSearch('');
    if (inp) inp.focus();
}

// ════════════════════════════════════════
// RENDERING
// ════════════════════════════════════════

function renderAll() {
    renderContinueStrip();
    const filtered  = getFiltered();
    const reading   = filtered.filter(m => m.status === 'reading');
    const completed = filtered.filter(m => m.status === 'completed');

    const showRead = filterStatus === 'all' || filterStatus === 'reading';
    const showComp = filterStatus === 'all' || filterStatus === 'completed';

    const readBlock = document.getElementById('readingBlock');
    const compBlock = document.getElementById('completedBlock');
    const empty     = document.getElementById('emptyState');

    if (filtered.length === 0) {
        readBlock.style.display = 'none';
        compBlock.style.display = 'none';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    if (showRead && reading.length > 0) {
        readBlock.style.display = 'block';
        document.getElementById('countReading').textContent = reading.length;
        renderGrid(document.getElementById('gridReading'), reading);
    } else {
        readBlock.style.display = 'none';
    }

    if (showComp && completed.length > 0) {
        compBlock.style.display = 'block';
        document.getElementById('countCompleted').textContent = completed.length;
        renderGrid(document.getElementById('gridCompleted'), completed);
    } else {
        compBlock.style.display = 'none';
    }
}

function renderGrid(container, list) {
    if (view === 'list') {
        container.className = 'manga-list';
        container.innerHTML = list.map(renderRow).join('');
    } else {
        container.className = 'manga-grid';
        container.innerHTML = list.map(renderCard).join('');
    }
}

function renderCard(m) {
    const flag    = LANG_FLAGS[m.language || 'fr'] || '🌐';
    const stars   = renderStars(m.rating);
    const title   = esc(m.title);
    // Safe: use data-id on card, never put URL/title inside onclick string
    const coverHtml = m.image
        ? `<img src="${esc(m.image)}" alt="${title}" loading="lazy" onerror="this.parentNode.innerHTML='<div class=\\'card-cover-placeholder\\'>📖</div>'">`
        : `<div class="card-cover-placeholder">📖</div>`;

    return `
    <div class="manga-card ${m.status === 'completed' ? 'completed' : ''}" data-id="${m.id}">
        <div class="card-cover">
            ${coverHtml}
            <span class="badge badge-lang">${flag}</span>
            ${m.status === 'completed' ? '<span class="badge badge-completed">✅</span>' : ''}
            ${m.chapter_count > 0 ? `<span class="badge badge-chapters">📦 ${m.chapter_count}</span>` : ''}
            ${m.rating > 0 ? `<span class="badge badge-rating">★ ${m.rating}</span>` : ''}
            <div class="card-overlay">
                ${m.notes ? `<div class="overlay-note">${esc(m.notes)}</div>` : ''}
                <div class="overlay-actions">
                    <button class="oa-btn oa-read"    onclick="event.stopPropagation();openMangaLink(${m.id})" title="Ouvrir le site de lecture">Site</button>
                    <button class="oa-btn oa-archive" onclick="event.stopPropagation();openChaptersModal(${m.id})">📦</button>
                    <button class="oa-btn oa-edit"    onclick="event.stopPropagation();openEditModal(${m.id})">✏️</button>
                    <button class="oa-btn oa-del"     onclick="event.stopPropagation();confirmDelete(${m.id})">🗑️</button>
                </div>
            </div>
        </div>
        <div class="card-info" onclick="openDetailModal(${m.id})">
            <div class="card-title" title="${title}">${title}</div>
            <div class="card-chapter">📖 ${esc(m.current_chapter)}</div>
        </div>
        <div class="card-actions">
            <button class="btn-quick btn-quick-read" onclick="event.stopPropagation();readManga(${m.id})" title="Lire">${readLabel(m)}</button>
            <button class="btn-quick" onclick="event.stopPropagation();quickNextChapter(${m.id})" title="Incrémenter le chapitre">+1 ch.</button>
        </div>
    </div>`;
}

function renderRow(m) {
    const flag    = LANG_FLAGS[m.language || 'fr'] || '🌐';
    const title   = esc(m.title);
    const coverHtml = m.image
        ? `<img src="${esc(m.image)}" alt="${title}" loading="lazy" onerror="this.style.display='none'">`
        : `<div class="row-cover-placeholder">📖</div>`;

    return `
    <div class="manga-row" data-id="${m.id}" onclick="openDetailModal(${m.id})">
        <div class="row-cover">${coverHtml}</div>
        <div class="row-main">
            <div class="row-title">${title}</div>
            <div class="row-meta">
                <span class="row-tag status-${m.status}">${m.status === 'reading' ? '📖 En cours' : '✅ Terminé'}</span>
                <span class="row-chapter">Ch. ${esc(m.current_chapter)}</span>
                ${m.notes ? `<span class="row-note">${esc(m.notes)}</span>` : ''}
            </div>
        </div>
        <div class="row-lang" title="${LANG_NAMES[m.language||'fr']||m.language}">${flag}</div>
        ${m.rating > 0 ? `<div class="row-stars">${renderStars(m.rating)}</div>` : '<div class="row-stars" style="width:4rem"></div>'}
        <div class="row-chapters-count">${m.chapter_count > 0 ? `📦 ${m.chapter_count}` : ''}</div>
        <div class="row-actions" onclick="event.stopPropagation()">
            <button class="ra-btn read"   onclick="readManga(${m.id})" title="${readLabel(m)}">▶</button>
            <button class="ra-btn"        onclick="openMangaLink(${m.id})" title="Ouvrir le site de lecture">🌐</button>
            <button class="ra-btn"        onclick="quickNextChapter(${m.id})">+1</button>
            <button class="ra-btn"        onclick="openChaptersModal(${m.id})">📦</button>
            <button class="ra-btn"        onclick="openEditModal(${m.id})">✏️</button>
            <button class="ra-btn del"    onclick="confirmDelete(${m.id})">🗑️</button>
        </div>
    </div>`;
}

function renderStars(rating) {
    if (!rating || rating <= 0) return '';
    let s = '';
    for (let i = 1; i <= 5; i++) {
        s += `<span style="color:${i <= rating ? '#f59e0b' : '#374151'}">★</span>`;
    }
    return s;
}

// ════════════════════════════════════════
// STATS
// ════════════════════════════════════════

function updateStats() {
    const reading   = mangas.filter(m => m.status === 'reading').length;
    const completed = mangas.filter(m => m.status === 'completed').length;
    const chapters  = mangas.reduce((s, m) => s + (parseInt(m.chapter_count) || 0), 0);
    const rated     = mangas.filter(m => m.rating > 0);
    const avgRating = rated.length ? (rated.reduce((s,m)=>s+parseInt(m.rating),0)/rated.length).toFixed(1) : '—';

    document.getElementById('statTotal').textContent     = mangas.length;
    document.getElementById('statReading').textContent   = reading;
    document.getElementById('statCompleted').textContent = completed;
    document.getElementById('statChapters').textContent  = chapters;
    document.getElementById('statRating').textContent    = avgRating;

    // Update last update
    if (mangas.length > 0) {
        const last = mangas.reduce((a, b) => {
            const da = new Date(a.date_updated || a.date_added);
            const db = new Date(b.date_updated || b.date_added);
            return db > da ? b : a;
        });
        const d = new Date(last.date_updated || last.date_added);
        const el = document.getElementById('statLastUpdate');
        if (el) el.textContent = relativeDate(d);
    }

    // Build language filter dynamically
    buildLangFilter();
}

function buildLangFilter() {
    const langs = [...new Set(mangas.map(m => m.language || 'fr'))];
    const wrap  = document.getElementById('langFilters');
    if (!wrap) return;
    const current = filterLang;

    let html = `<button class="filter-pill fp-lang ${current==='all'?'active':''}" data-lang="all" onclick="setFilterLang(this.dataset.lang)">🌍 Toutes</button>`;
    langs.forEach(l => {
        html += `<button class="filter-pill fp-lang ${current===l?'active':''}" data-lang="${esc(l)}" onclick="setFilterLang(this.dataset.lang)">${LANG_FLAGS[l]||'🌐'} ${esc(LANG_NAMES[l]||l)}</button>`;
    });
    wrap.innerHTML = html;
}

// ════════════════════════════════════════
// VIEW TOGGLE
// ════════════════════════════════════════

function applyView(v) {
    view = v;
    localStorage.setItem('mt_view', v);
    document.querySelectorAll('.view-btn').forEach(b => b.classList.toggle('active', b.dataset.view === v));
    renderAll();
}

// ════════════════════════════════════════
// QUICK +1 CHAPTER
// ════════════════════════════════════════

async function quickNextChapter(id) {
    const manga = mangas.find(m => +m.id === +id);
    if (!manga) return;

    // Try to increment numeric part
    const match = manga.current_chapter.match(/^(\D*)(\d+(?:\.\d+)?)(\D*)$/);
    let next;
    if (match) {
        const num = parseFloat(match[2]);
        next = match[1] + (Number.isInteger(num) ? (num + 1).toString() : (num + 0.5).toFixed(1)) + match[3];
    } else {
        // Non-numeric, just append a prompt
        const input = prompt(`Chapitre actuel : "${manga.current_chapter}"\nNouveau chapitre :`, manga.current_chapter);
        if (!input) return;
        next = input.trim();
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('title', manga.title);
    fd.append('readingLink', manga.reading_link);
    fd.append('currentChapter', next);
    fd.append('status', manga.status);
    fd.append('language', manga.language || 'fr');
    fd.append('notes', manga.notes || '');
    if (manga.image && !manga.image.startsWith('img/')) fd.append('imageUrl', manga.image);
    if (manga.rating) fd.append('rating', manga.rating);

    try {
        const res  = await fetch('api/add_manga.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            manga.current_chapter = next;
            toast(`Ch. ${next} — "${manga.title}"`, 'success');
            renderAll();
        } else {
            toast('Erreur : ' + data.error, 'error');
        }
    } catch (e) {
        toast('Erreur réseau', 'error');
    }
}

// ════════════════════════════════════════
// ADD / EDIT MODAL
// ════════════════════════════════════════

function openAddModal() {
    document.getElementById('formTitle').textContent = '➕ Ajouter un manga';
    document.getElementById('mangaForm').reset();
    document.getElementById('mangaId').value = '';
    document.getElementById('formStatus').value = 'reading';
    document.getElementById('formLang').value = 'fr';
    editingRating = 0;
    renderStarInput(0);
    updateImagePreview('');
    openModal('addModal');
}

function openEditModal(id) {
    const m = mangas.find(x => +x.id === +id);
    if (!m) return;

    document.getElementById('formTitle').textContent  = '✏️ Modifier le manga';
    document.getElementById('mangaId').value          = m.id;
    document.getElementById('formMangaTitle').value   = m.title;
    document.getElementById('formImageUrl').value     = m.image && !m.image.startsWith('img/') ? m.image : '';
    document.getElementById('formReadingLink').value  = m.reading_link;
    document.getElementById('formChapter').value      = m.current_chapter;
    document.getElementById('formStatus').value       = m.status || 'reading';
    document.getElementById('formLang').value         = m.language || 'fr';
    document.getElementById('formNotes').value        = m.notes || '';
    editingRating = parseInt(m.rating) || 0;
    renderStarInput(editingRating);
    updateImagePreview(m.image || '');
    openModal('addModal');
}

document.getElementById('mangaForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveManga');
    btn.disabled = true;
    btn.textContent = '⏳ Sauvegarde…';

    const fd = new FormData(this);
    fd.append('rating', editingRating);

    try {
        const res  = await fetch('api/add_manga.php', { method:'POST', body: fd });
        let data = await res.json();
        if (!data.success && data.code === 'duplicate') {
            if (confirm(`${data.error}.\nL'ajouter quand même ?`)) {
                fd.set('force', '1');
                data = await (await fetch('api/add_manga.php', { method:'POST', body: fd })).json();
            } else {
                data = { success: false, error: 'ajout annulé' };
            }
        }
        if (data.success) {
            closeModal('addModal');
            await loadMangas();
            toast('Manga sauvegardé !', 'success');
        } else {
            toast('Erreur : ' + data.error, 'error');
        }
    } catch (err) {
        toast('Erreur réseau', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Sauvegarder';
    }
});

// Image preview
document.getElementById('formImageFile').addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const url = URL.createObjectURL(this.files[0]);
        updateImagePreview(url);
        document.getElementById('formImageUrl').value = '';
    }
});
document.getElementById('formImageUrl').addEventListener('input', function() {
    updateImagePreview(this.value);
});

function updateImagePreview(src) {
    const wrap = document.getElementById('imgPreview');
    if (!wrap) return;
    if (src) {
        wrap.innerHTML = `<img src="${esc(src)}" onerror="this.parentNode.innerHTML='<div class=\\'img-preview-placeholder\\'>📷</div>'">`;
    } else {
        wrap.innerHTML = `<div class="img-preview-placeholder">📷</div>`;
    }
}

// Star rating
function renderStarInput(val) {
    const wrap = document.getElementById('starInput');
    if (!wrap) return;
    let html = '';
    for (let i = 1; i <= 5; i++) {
        html += `<button type="button" class="star-btn ${i <= val ? 'active' : ''}" data-val="${i}" onclick="setRating(${i})">★</button>`;
    }
    wrap.innerHTML = html;
}

function setRating(val) {
    editingRating = (editingRating === val) ? 0 : val; // toggle off if same
    renderStarInput(editingRating);
}

// ════════════════════════════════════════
// DETAIL MODAL
// ════════════════════════════════════════

function openDetailModal(id) {
    const m = mangas.find(x => +x.id === +id);
    if (!m) return;

    const flag = LANG_FLAGS[m.language||'fr'] || '🌐';
    const dateAdded = new Date(m.date_added).toLocaleDateString('fr-FR', { day:'2-digit', month:'short', year:'numeric' });
    const dateUp    = new Date(m.date_updated||m.date_added).toLocaleDateString('fr-FR', { day:'2-digit', month:'short', year:'numeric' });

    document.getElementById('detailBody').innerHTML = `
        <div class="detail-hero">
            <div class="detail-cover">
                ${m.image
                    ? `<img src="${esc(m.image)}" alt="${esc(m.title)}" loading="lazy" onerror="this.parentNode.innerHTML='<div class=\\'detail-cover-placeholder\\'>📖</div>'">`
                    : `<div class="detail-cover-placeholder">📖</div>`}
            </div>
            <div class="detail-meta">
                <div class="detail-title">${esc(m.title)}</div>
                <div class="detail-tags">
                    <span class="detail-tag ${m.status}">${m.status === 'reading' ? '📖 En cours' : '✅ Terminé'}</span>
                    <span class="detail-tag lang">${flag} ${LANG_NAMES[m.language||'fr']||m.language||'fr'}</span>
                    ${m.chapter_count > 0 ? `<span class="detail-tag" style="background:var(--green-bg);color:var(--green)">📦 ${m.chapter_count} ch. archivés</span>` : ''}
                </div>
                <div class="detail-stars">${m.rating > 0 ? renderStars(m.rating) : '<span style="color:var(--text3);font-size:0.8rem">Non noté</span>'}</div>
                <div class="detail-grid">
                    <div class="detail-item"><div class="detail-key">Chapitre actuel</div><div class="detail-val">📖 ${esc(m.current_chapter)}</div></div>
                    <div class="detail-item"><div class="detail-key">Ajouté le</div><div class="detail-val">${dateAdded}</div></div>
                    <div class="detail-item"><div class="detail-key">Mis à jour</div><div class="detail-val">${dateUp}</div></div>
                    <div class="detail-item"><div class="detail-key">Chapitres archivés</div><div class="detail-val">${m.chapter_count || 0}</div></div>
                </div>
            </div>
        </div>
        ${m.notes ? `<div class="detail-notes">${esc(m.notes)}</div>` : ''}
        <div class="detail-footer-btns">
            <button class="btn btn-accent" onclick="closeModal('detailModal');readManga(${m.id})">${readLabel(m)}</button>
            ${m.chapter_count > 0 ? `<button class="btn btn-ghost" onclick="openMangaLink(${m.id})">🌐 Site</button>` : ''}
            <button class="btn btn-ghost"  onclick="closeModal('detailModal');openEditModal(${m.id})">✏️ Modifier</button>
            <button class="btn btn-ghost"  onclick="closeModal('detailModal');openChaptersModal(${m.id})">📦 Chapitres</button>
        </div>`;

    openModal('detailModal');
}

// ════════════════════════════════════════
// CHAPTERS MODAL
// ════════════════════════════════════════

async function openChaptersModal(mangaId) {
    const m = mangas.find(x => +x.id === +mangaId);
    const mangaTitle = m ? m.title : '';
    currentChaptersMangaId = mangaId;
    document.getElementById('chaptersMangaTitle').textContent = mangaTitle;
    document.getElementById('chapterMangaId').value = mangaId;
    document.getElementById('chapterUploadForm').reset();
    document.getElementById('chapterFilesList').style.display = 'none';
    document.getElementById('chapterFilesList').innerHTML = '';
    document.getElementById('chapterSingleGrid').querySelector('.form-field').style.display = '';
    document.getElementById('chaptersList').innerHTML = '<p style="color:var(--text3);text-align:center;padding:2rem">Chargement…</p>';
    openModal('chaptersModal');
    await loadChapters(mangaId);
}

function closeChaptersModal() {
    closeModal('chaptersModal');
    currentChaptersMangaId = null;
}

async function loadChapters(mangaId) {
    try {
        const chapters = await fetchChapters(mangaId);
        if (chapters) {
            currentChapters = chapters;
            renderChapters(chapters);
        }
    } catch (e) {
        document.getElementById('chaptersList').innerHTML = '<p style="color:var(--red);text-align:center">Erreur de chargement</p>';
    }
}

function renderChapters(chapters) {
    const c = document.getElementById('chaptersList');
    if (!chapters.length) {
        c.innerHTML = '<p style="color:var(--text3);text-align:center;padding:2rem">Aucun chapitre archivé</p>';
        return;
    }
    // No value is ever put inside an onclick: buttons carry data-act / data-id (see the click handler below)
    c.innerHTML = chapters.map(ch => {
        const last  = parseInt(ch.last_page) || 0;
        const total = parseInt(ch.total_pages) || 0;
        const mid   = total > 0 && last > 0 && last < total - 1;
        let progress = '';
        if (mid) progress = `<span class="ch-progress">📍 p. ${last + 1}/${total}</span>`;
        else if (parseInt(ch.finished) === 1) progress = '<span class="ch-progress done">✓ Lu</span>';

        return `
        <div class="chapter-item">
            <div class="ch-info">
                <div class="ch-number">📖 Chapitre ${esc(ch.chapter_number)} ${progress}</div>
                <div class="ch-meta">${formatBytes(ch.file_size)} · Ajouté le ${new Date(ch.date_added).toLocaleDateString('fr-FR')}</div>
            </div>
            <div class="ch-actions">
                <button class="btn btn-accent" style="padding:0.4rem 0.75rem;font-size:0.8rem;flex:initial" data-act="read" data-id="${ch.id}">${mid ? '▶ Continuer' : '📖 Lire'}</button>
                <button class="btn btn-ghost" style="padding:0.4rem 0.75rem;font-size:0.8rem;flex:initial" data-act="download" data-id="${ch.id}">📥 Télécharger</button>
                <button class="btn btn-danger" style="padding:0.4rem 0.75rem;font-size:0.8rem;flex:initial" data-act="delete" data-id="${ch.id}">🗑️</button>
            </div>
        </div>`;
    }).join('');
}

document.getElementById('chaptersList').addEventListener('click', e => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const id = parseInt(btn.dataset.id);
    const ch = currentChapters.find(c => +c.id === id);
    if (btn.dataset.act === 'read')     openReader(id, { resume: true });
    if (btn.dataset.act === 'download') downloadChapter(id);
    if (btn.dataset.act === 'delete' && ch) deleteChapter(id, ch.chapter_number);
});

/**
 * Guesses a chapter number from a filename by taking the LAST number
 * found in it (before the extension), e.g. "MyManga - Chapitre 12.zip"
 * -> "12", "Onepiece_104.5.zip" -> "104.5". Falls back to '' if none found.
 */
function guessChapterNumberFromFilename(filename) {
    const base = filename.replace(/\.zip$/i, '');
    const matches = base.match(/\d+(?:\.\d+)?/g);
    return matches && matches.length ? matches[matches.length - 1] : '';
}

const chapterFileInput  = document.getElementById('chapterFile');
const chapterFilesList  = document.getElementById('chapterFilesList');
const chapterSingleGrid = document.getElementById('chapterSingleGrid');
const chapterNumberInput = document.getElementById('chapterNumber');

chapterFileInput.addEventListener('change', function() {
    const files = Array.from(this.files || []);

    if (files.length <= 1) {
        // Single (or no) file: plain single chapter-number field, as before.
        chapterFilesList.style.display = 'none';
        chapterFilesList.innerHTML = '';
        chapterSingleGrid.querySelector('.form-field').style.display = '';
        if (files.length === 1 && !chapterNumberInput.value) {
            chapterNumberInput.value = guessChapterNumberFromFilename(files[0].name);
        }
        return;
    }

    // Multiple files: hide the single field, show one editable row per file.
    chapterSingleGrid.querySelector('.form-field').style.display = 'none';
    chapterFilesList.style.display = 'flex';
    chapterFilesList.innerHTML = files.map((f, i) => `
        <div class="ch-file-row" data-index="${i}">
            <span class="ch-file-status"></span>
            <span class="ch-file-name" title="${esc(f.name)}">${esc(f.name)}</span>
            <input type="text" class="form-input ch-file-number" placeholder="N° chapitre" value="${esc(guessChapterNumberFromFilename(f.name))}">
        </div>`).join('');
});

document.getElementById('chapterUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('chapterUploadBtn');
    const bar = document.getElementById('uploadProgress');
    const barInner = document.getElementById('uploadProgressBar');
    const label = document.getElementById('uploadProgressLabel');
    const mangaId = document.getElementById('chapterMangaId').value;
    const files = Array.from(chapterFileInput.files || []);

    if (!files.length) return;

    // Build the (filename, chapterNumber) pairs to upload, one request each —
    // the backend only accepts one ZIP per call.
    let items;
    if (files.length === 1) {
        const num = chapterNumberInput.value.trim();
        if (!num) { toast('Merci de renseigner le numéro de chapitre', 'error'); return; }
        items = [{ file: files[0], number: num, row: null }];
    } else {
        const rows = Array.from(chapterFilesList.querySelectorAll('.ch-file-row'));
        items = rows.map((row, i) => ({
            file: files[i],
            number: row.querySelector('.ch-file-number').value.trim(),
            row
        }));
        const missing = items.some(it => !it.number);
        if (missing) { toast('Merci de renseigner un numéro pour chaque chapitre', 'error'); return; }
    }

    btn.disabled = true;
    chapterFileInput.disabled = true;
    if (bar) bar.style.display = 'block';
    if (label) label.style.display = 'block';

    let successCount = 0;
    const failed = [];

    for (let i = 0; i < items.length; i++) {
        const { file, number, row } = items[i];
        btn.textContent = `⏳ Upload ${i + 1}/${items.length}…`;
        if (label) label.textContent = `Chapitre ${number} (${file.name}) — ${i + 1}/${items.length}`;
        if (barInner) barInner.style.width = Math.round(((i) / items.length) * 100) + '%';
        if (row) row.querySelector('.ch-file-status').textContent = '⏳';

        try {
            const send = async (replace) => {
                const fd = new FormData();
                fd.append('action', 'upload');
                fd.append('manga_id', mangaId);
                fd.append('chapter_number', number);
                fd.append('chapterFile', file);
                if (replace) fd.append('replace', '1');
                const res = await fetch('api/manage_chapters.php', { method:'POST', body: fd });
                return res.json();
            };

            let data = await send(false);
            if (!data.success && data.code === 'duplicate') {
                if (confirm(`Le chapitre ${number} existe déjà. Le remplacer par ce fichier ?\n(la progression de lecture de ce chapitre sera réinitialisée)`)) {
                    if (label) label.textContent = `Remplacement du chapitre ${number}…`;
                    data = await send(true);
                } else {
                    data = { success: false, error: 'déjà présent (ignoré)' };
                }
            }

            if (data.success) {
                successCount++;
                if (row) row.querySelector('.ch-file-status').textContent = '✅';
            } else {
                failed.push(`${number} : ${data.error}`);
                if (row) row.querySelector('.ch-file-status').textContent = '❌';
            }
        } catch (err) {
            failed.push(`${number} : erreur réseau`);
            if (row) row.querySelector('.ch-file-status').textContent = '❌';
        }

        if (barInner) barInner.style.width = Math.round(((i + 1) / items.length) * 100) + '%';
    }

    if (successCount) {
        await loadChapters(currentChaptersMangaId);
        await loadMangas(); // refresh counts
    }

    if (!failed.length) {
        toast(successCount > 1 ? `${successCount} chapitres uploadés !` : 'Chapitre uploadé !', 'success');
        this.reset();
        chapterFilesList.style.display = 'none';
        chapterFilesList.innerHTML = '';
        chapterSingleGrid.querySelector('.form-field').style.display = '';
    } else if (successCount) {
        toast(`${successCount} chapitres uploadés, ${failed.length} échec(s) : ${failed.join(' · ')}`, 'error');
    } else {
        toast('Erreur : ' + failed.join(' · '), 'error');
    }

    btn.disabled = false;
    chapterFileInput.disabled = false;
    btn.textContent = '📤 Uploader';
    if (bar) setTimeout(() => { bar.style.display = 'none'; barInner.style.width = '0'; }, 600);
    if (label) setTimeout(() => { label.style.display = 'none'; }, 600);
});

function downloadChapter(chapterId) { window.location.href = `api/download_chapter.php?chapter_id=${encodeURIComponent(chapterId)}`; }

async function deleteChapter(chapterId, num) {
    if (!confirm(`Supprimer le chapitre ${num} ?`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('chapter_id', chapterId);
        const res  = await fetch('api/manage_chapters.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            await loadChapters(currentChaptersMangaId);
            await loadMangas();
            toast('Chapitre supprimé', 'info');
        } else {
            toast('Erreur : ' + data.error, 'error');
        }
    } catch (e) {
        toast('Erreur réseau', 'error');
    }
}

// ════════════════════════════════════════
// CHAPTER READER
// ════════════════════════════════════════

/**
 * Label of the main "read" button: local reader if chapters are archived,
 * external reading link otherwise.
 */
function readLabel(m) {
    return (parseInt(m.chapter_count) || 0) > 0 ? '📖 Reprendre' : '▶ Lire';
}

function readManga(id) {
    const m = mangas.find(x => +x.id === +id);
    if (!m) return;
    if ((parseInt(m.chapter_count) || 0) > 0) resumeReading(id);
    else openMangaLink(id);
}

/** Fetches the chapter list (with saved progress) of a manga. Returns null on error. */
async function fetchChapters(mangaId) {
    const fd = new FormData();
    fd.append('action', 'list');
    fd.append('manga_id', mangaId);
    const res  = await fetch('api/manage_chapters.php', { method: 'POST', body: fd });
    const data = await res.json();
    return data.success ? data.chapters : null;
}

/**
 * Resume reading: reopens the last chapter read at the saved page. If that chapter
 * was read to the end, starts the next one instead.
 */
async function resumeReading(mangaId) {
    const m = mangas.find(x => +x.id === +mangaId);
    if (!m) return;

    let chapters = null;
    try { chapters = await fetchChapters(mangaId); } catch (e) { /* handled below */ }
    if (chapters === null) { toast('Impossible de charger les chapitres', 'error'); return; }
    if (!chapters.length)  { openMangaLink(mangaId); return; }

    let idx = chapters.findIndex(c => +c.id === +m.last_read_chapter_id);
    let page = 0;

    if (idx === -1) {
        idx = 0;
    } else {
        const c = chapters[idx];
        const last = parseInt(c.last_page) || 0;
        const total = parseInt(c.total_pages) || 0;
        const reachedEnd = total > 0 && last >= total - 1;
        if (reachedEnd && idx < chapters.length - 1) idx++;   // next chapter, from its first page
        else page = last;                                      // mid-chapter (or very last page)
    }

    currentChapters = chapters;
    readerMangaId = mangaId;
    await openReaderAt(idx, page);
}

/**
 * Opens the fullscreen reader for a chapter of the chapters modal.
 * @param {number} chapterId
 * @param {{resume?: boolean}} [opts] resume = start at the saved page
 */
async function openReader(chapterId, opts = {}) {
    const idx = currentChapters.findIndex(c => +c.id === +chapterId);
    if (idx === -1) {
        toast('Chapitre introuvable', 'error');
        return;
    }
    readerMangaId = currentChaptersMangaId;

    let page = 0;
    if (opts.resume) {
        const c = currentChapters[idx];
        const last = parseInt(c.last_page) || 0;
        const total = parseInt(c.total_pages) || 0;
        if (total > 0 && last > 0 && last < total - 1) page = last;
    }
    await openReaderAt(idx, page);
}

async function openReaderAt(index, page = 0) {
    document.getElementById('readerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    await loadReaderChapter(index, { page });
}

/**
 * Closes the reader: saves progress, clears loaded images, refreshes the
 * collection (so "Reprendre" and the current chapter are up to date).
 */
async function closeReader() {
    queueProgressSave(true);
    const saving = readerSavePromise;

    teardownReaderObserver();
    document.getElementById('readerOverlay').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('readerScrollView').innerHTML = '';
    document.getElementById('readerPageImage').removeAttribute('src');
    document.getElementById('readerProgressBar').style.width = '0';
    readerChapterId     = null;
    readerChapterIndex  = -1;
    readerPages         = [];
    readerCurrentPage   = 0;

    try { await saving; } catch (e) { /* ignore */ }
    await loadMangas();
    if (currentChaptersMangaId && document.getElementById('chaptersModal').classList.contains('open')) {
        loadChapters(currentChaptersMangaId);
    }
}

/**
 * Loads the chapter at `index` of currentChapters into the reader.
 * @param {number} index
 * @param {{ page?: number, jumpToLastPage?: boolean }} [opts]
 */
async function loadReaderChapter(index, opts = {}) {
    if (index < 0 || index >= currentChapters.length) return;

    // Save where we were in the previous chapter
    if (readerPendingSave) flushProgress();

    const chapter = currentChapters[index];
    readerChapterIndex = index;
    readerChapterId    = chapter.id;
    readerPages        = [];
    readerCurrentPage  = 0;
    teardownReaderObserver();

    const m = mangas.find(x => +x.id === +readerMangaId);
    document.getElementById('readerTitle').textContent =
        (m ? m.title + ' — ' : '') + 'Chapitre ' + chapter.chapter_number;

    updateReaderChapterNavButtons();

    const status     = document.getElementById('readerStatus');
    const scrollView = document.getElementById('readerScrollView');
    const pageView   = document.getElementById('readerPageView');
    const footer     = document.getElementById('readerFooter');

    status.style.display = 'flex';
    status.textContent = '⏳ Chargement des pages…';
    scrollView.style.display = 'none';
    scrollView.innerHTML = '';
    pageView.style.display = 'none';
    footer.style.display = 'none';
    document.getElementById('readerProgressBar').style.width = '0';

    setReaderModeButtons(readerMode);
    const loadingChapterId = chapter.id;

    try {
        const res  = await fetch(`api/get_chapter_pages.php?chapter_id=${chapter.id}`);
        const raw  = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (parseErr) {
            // The server answered something that is not JSON (PHP error, HTTP error…): show it
            if (readerChapterId === loadingChapterId) {
                status.textContent = `❌ Réponse invalide du serveur (HTTP ${res.status}) : ` +
                    raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
            }
            return;
        }

        if (readerChapterId !== loadingChapterId) return;   // user switched chapter meanwhile

        if (!data.success) {
            status.textContent = '❌ ' + data.error;
            return;
        }

        readerPages = data.pages;
        const last = readerPages.length - 1;
        readerCurrentPage = opts.jumpToLastPage ? last : Math.max(0, Math.min(parseInt(opts.page) || 0, last));
        status.style.display = 'none';
        renderReaderMode();
    } catch (e) {
        status.textContent = '❌ Erreur réseau lors du chargement des pages (' + (e && e.message ? e.message : e) + ')';
    }
}

function updateReaderChapterNavButtons() {
    document.getElementById('readerPrevChapterBtn').disabled = readerChapterIndex <= 0;
    document.getElementById('readerNextChapterBtn').disabled = readerChapterIndex >= currentChapters.length - 1;
}

function readerPrevChapter() {
    if (readerChapterIndex <= 0) return;
    loadReaderChapter(readerChapterIndex - 1, { jumpToLastPage: true });
}

function readerNextChapter() {
    if (readerChapterIndex >= currentChapters.length - 1) return;
    loadReaderChapter(readerChapterIndex + 1);
}

/**
 * Switches between 'scroll' (continuous) and 'page' (one page at a time).
 * The current page is kept when switching.
 */
function setReaderMode(mode) {
    readerMode = mode;
    localStorage.setItem('mt_reader_mode', mode);
    setReaderModeButtons(mode);
    renderReaderMode();
}

function setReaderModeButtons(mode) {
    document.getElementById('readerModeScrollBtn').classList.toggle('active', mode === 'scroll');
    document.getElementById('readerModePageBtn').classList.toggle('active', mode === 'page');
}

function renderReaderMode() {
    const scrollView = document.getElementById('readerScrollView');
    const pageView   = document.getElementById('readerPageView');
    const footer     = document.getElementById('readerFooter');

    if (!readerPages.length) return;

    if (readerMode === 'scroll') {
        pageView.style.display = 'none';
        footer.style.display = 'none';
        scrollView.style.display = 'flex';

        if (!scrollView.childElementCount) {
            // Pages up to the saved one load eagerly, so we can scroll to it reliably
            const imagesHtml = readerPages.map((p, i) => `
                <img class="reader-scroll-image" ${i <= readerCurrentPage ? '' : 'loading="lazy"'}
                     data-idx="${i}"
                     src="api/get_chapter_image.php?chapter_id=${readerChapterId}&index=${p.zip_index}"
                     alt="Page ${p.page}">
            `).join('');

            const hasNext = readerChapterIndex < currentChapters.length - 1;
            const endHtml = hasNext
                ? `<button class="reader-scroll-next-btn" onclick="readerNextChapter()">Chapitre suivant ▶</button>`
                : `<div class="reader-scroll-end">🏁 Fin du chapitre</div>`;

            scrollView.innerHTML = imagesHtml + endHtml;
            setupReaderObserver();
        }
        restoreScrollPosition();
    } else {
        scrollView.style.display = 'none';
        pageView.style.display = 'flex';
        footer.style.display = 'flex';
        showReaderPage(readerCurrentPage);
    }
}

function waitForImage(img) {
    return new Promise(resolve => {
        if (img.complete) return resolve();
        img.addEventListener('load', resolve, { once: true });
        img.addEventListener('error', resolve, { once: true });
    });
}

/** Scrolls the continuous view to readerCurrentPage once the pages above it have loaded. */
async function restoreScrollPosition() {
    const sv = document.getElementById('readerScrollView');
    const imgs = Array.from(sv.querySelectorAll('.reader-scroll-image'));
    const target = readerCurrentPage;
    const chapterAtStart = readerChapterId;

    readerRestoring = true;   // the observer must not overwrite the saved page while we jump

    if (target > 0 && imgs[target]) {
        const above = imgs.slice(0, target + 1);
        above.forEach(img => { img.loading = 'eager'; });
        await Promise.race([
            Promise.all(above.map(waitForImage)),
            new Promise(r => setTimeout(r, 10000))
        ]);
        if (readerChapterId !== chapterAtStart) return;
        imgs[target].scrollIntoView({ block: 'start' });
    } else {
        sv.scrollTop = 0;
    }

    updateReaderProgressBar();
    setTimeout(() => { readerRestoring = false; }, 300);
}

/**
 * Tracks the page under the middle of the screen while scrolling
 * (an observer on a zero-height line at 50% of the viewport).
 */
function setupReaderObserver() {
    teardownReaderObserver();
    const sv = document.getElementById('readerScrollView');
    readerObserver = new IntersectionObserver(entries => {
        if (readerRestoring) return;
        for (const e of entries) {
            if (!e.isIntersecting) continue;
            const i = parseInt(e.target.dataset.idx);
            if (i !== readerCurrentPage) {
                readerCurrentPage = i;
                updateReaderProgressBar();
                queueProgressSave();
            }
        }
    }, { root: sv, rootMargin: '-50% 0px -50% 0px', threshold: 0 });
    sv.querySelectorAll('.reader-scroll-image').forEach(img => readerObserver.observe(img));
}

function teardownReaderObserver() {
    if (readerObserver) { readerObserver.disconnect(); readerObserver = null; }
}

function updateReaderProgressBar() {
    const bar = document.getElementById('readerProgressBar');
    if (!bar || !readerPages.length) return;
    bar.style.width = (((readerCurrentPage + 1) / readerPages.length) * 100) + '%';
}

function showReaderPage(index) {
    if (!readerPages.length) return;
    readerCurrentPage = Math.max(0, Math.min(index, readerPages.length - 1));
    const p = readerPages[readerCurrentPage];
    document.getElementById('readerPageImage').src =
        `api/get_chapter_image.php?chapter_id=${readerChapterId}&index=${p.zip_index}`;
    document.getElementById('readerPageIndicator').textContent =
        `${readerCurrentPage + 1} / ${readerPages.length}`;

    // Preload the next page so turning it feels instant
    const next = readerPages[readerCurrentPage + 1];
    if (next) new Image().src = `api/get_chapter_image.php?chapter_id=${readerChapterId}&index=${next.zip_index}`;

    updateReaderProgressBar();
    queueProgressSave();
}

/** Previous page, or last page of the previous chapter. */
function readerPrevPage() {
    if (readerCurrentPage > 0) {
        showReaderPage(readerCurrentPage - 1);
    } else if (readerChapterIndex > 0) {
        loadReaderChapter(readerChapterIndex - 1, { jumpToLastPage: true });
    }
}

/** Next page, or first page of the next chapter. */
function readerNextPage() {
    if (readerCurrentPage < readerPages.length - 1) {
        showReaderPage(readerCurrentPage + 1);
    } else if (readerChapterIndex < currentChapters.length - 1) {
        loadReaderChapter(readerChapterIndex + 1);
    }
}

// Swipe left = next page, swipe right = previous page (page mode, touch screens)
(function bindReaderSwipe() {
    const el = document.getElementById('readerPageView');
    let sx = 0, sy = 0;
    el.addEventListener('touchstart', e => {
        const t = e.changedTouches[0];
        sx = t.clientX; sy = t.clientY;
    }, { passive: true });
    el.addEventListener('touchend', e => {
        const t = e.changedTouches[0];
        const dx = t.clientX - sx, dy = t.clientY - sy;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            if (dx < 0) readerNextPage(); else readerPrevPage();
        }
    }, { passive: true });
})();

// ════════════════════════════════════════
// READING PROGRESS (save + auto chapter)
// ════════════════════════════════════════

function autoChapterEnabled() {
    return localStorage.getItem('mt_auto_chapter') !== '0';
}

function syncAutoChapterUI() {
    const el = document.getElementById('autoChapterState');
    if (el) el.textContent = autoChapterEnabled() ? '✅' : '⬜';
}

function toggleAutoChapter() {
    localStorage.setItem('mt_auto_chapter', autoChapterEnabled() ? '0' : '1');
    syncAutoChapterUI();
    toast(autoChapterEnabled()
        ? 'Le chapitre actuel avancera automatiquement quand vous finissez un chapitre'
        : 'Mise à jour automatique du chapitre désactivée', 'info');
}

/** Remembers the current page; the request is sent after a short pause (or at once). */
function queueProgressSave(immediate = false) {
    if (readerChapterId == null || !readerPages.length) return;
    readerPendingSave = { chapter_id: readerChapterId, page: readerCurrentPage, total: readerPages.length };
    clearTimeout(readerSaveTimer);
    if (immediate) flushProgress();
    else readerSaveTimer = setTimeout(flushProgress, 800);
}

function flushProgress() {
    clearTimeout(readerSaveTimer);
    const p = readerPendingSave;
    if (!p) return readerSavePromise;
    readerPendingSave = null;

    const key = `${p.chapter_id}:${p.page}`;
    if (key === readerLastSaved) return readerSavePromise;
    readerLastSaved = key;

    readerSavePromise = (async () => {
        try {
            const fd = new FormData();
            fd.append('chapter_id', p.chapter_id);
            fd.append('page', p.page);
            fd.append('total', p.total);
            fd.append('auto_update', autoChapterEnabled() ? '1' : '0');
            const res  = await fetch('api/save_progress.php', { method: 'POST', body: fd, keepalive: true });
            const data = await res.json();
            if (!data.success) return;

            // Keep local state in sync (chapter list badges, resume logic)
            const ch = currentChapters.find(c => +c.id === +p.chapter_id);
            if (ch) {
                ch.last_page = p.page;
                ch.total_pages = p.total;
                if (data.finished) ch.finished = 1;
            }
            const m = mangas.find(x => +x.id === +data.manga_id);
            if (m) m.last_read_chapter_id = p.chapter_id;

            if (data.current_chapter && m) {
                m.current_chapter = data.current_chapter;
                toast(`Chapitre actuel → ${data.current_chapter}`, 'info');
                renderAll();
            }
        } catch (e) { /* progress is best-effort */ }
    })();
    return readerSavePromise;
}

// Save when the tab is hidden or closed
document.addEventListener('visibilitychange', () => { if (document.hidden) queueProgressSave(true); });
window.addEventListener('pagehide', () => queueProgressSave(true));

// ════════════════════════════════════════
// DELETE CONFIRM
// ════════════════════════════════════════

function confirmDelete(id) {
    const m = mangas.find(x => +x.id === +id);
    pendingDeleteId = id;
    document.getElementById('deleteItemName').textContent = m ? m.title : id;
    openModal('deleteModal');
}

function closeDeleteModal() {
    pendingDeleteId = null;
    closeModal('deleteModal');
}

async function executeDelete() {
    if (!pendingDeleteId) return;
    const btn = document.getElementById('btnConfirmDelete');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', pendingDeleteId);
        const res  = await fetch('api/delete_manga.php', { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('deleteModal');
            pendingDeleteId = null;
            await loadMangas();
            toast('Manga supprimé', 'info');
        } else {
            toast('Erreur : ' + data.error, 'error');
        }
    } catch (e) {
        toast('Erreur réseau', 'error');
    } finally {
        btn.disabled = false;
    }
}

// ════════════════════════════════════════
// IMPORT / EXPORT
// ════════════════════════════════════════

function exportCollection() {
    const data = {
        exportedAt: new Date().toISOString(),
        version: '2.0',
        count: mangas.length,
        mangas: mangas.map(m => ({
            title: m.title,
            image: m.image,
            reading_link: m.reading_link,
            current_chapter: m.current_chapter,
            status: m.status,
            language: m.language,
            notes: m.notes,
            rating: m.rating,
            date_added: m.date_added,
        }))
    };
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = `manga-tracker-export-${new Date().toISOString().slice(0,10)}.json`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Collection exportée !', 'success');
}

function triggerImport() {
    document.getElementById('importFileInput').click();
}

document.getElementById('importFileInput').addEventListener('change', async function() {
    if (!this.files || !this.files[0]) return;
    const file = this.files[0];
    try {
        const text = await file.text();
        const json = JSON.parse(text);
        const list = json.mangas || (Array.isArray(json) ? json : null);
        if (!list || !list.length) { toast('Fichier invalide', 'error'); return; }

        if (!confirm(`Importer ${list.length} manga(s) ? Les entrées existantes ne seront pas supprimées.`)) return;

        let imported = 0, duplicates = 0;
        for (const m of list) {
            if (!m.title || !m.reading_link || !m.current_chapter) continue;
            const fd = new FormData();
            fd.append('title', m.title);
            // Only real URLs can be imported from a JSON file (local covers are in the ZIP backup)
            if (m.image && /^https?:\/\//i.test(m.image)) fd.append('imageUrl', m.image);
            fd.append('readingLink', m.reading_link);
            fd.append('currentChapter', m.current_chapter);
            fd.append('status', m.status || 'reading');
            fd.append('language', m.language || 'fr');
            fd.append('notes', m.notes || '');
            if (m.rating) fd.append('rating', m.rating);
            const res = await fetch('api/add_manga.php', { method:'POST', body: fd });
            const data = await res.json();
            if (data.success) imported++;
            else if (data.code === 'duplicate') duplicates++;
        }
        await loadMangas();
        toast(`${imported} manga(s) importé(s)` + (duplicates ? `, ${duplicates} doublon(s) ignoré(s)` : '') + ' !', 'success');
    } catch (err) {
        toast('Erreur lors de l\'import', 'error');
    }
    this.value = '';
});

// ════════════════════════════════════════
// CONTINUE READING STRIP
// ════════════════════════════════════════

/** Up to 6 manga most recently read in the built-in reader. Hidden while searching. */
function renderContinueStrip() {
    const block = document.getElementById('continueBlock');
    const box   = document.getElementById('continueStrip');
    if (!block || !box) return;

    const searching = (document.getElementById('navSearch')?.value || '').trim() !== '';
    const items = mangas
        .filter(m => m.last_read_at && (parseInt(m.chapter_count) || 0) > 0)
        .sort((a, b) => new Date(b.last_read_at) - new Date(a.last_read_at))
        .slice(0, 6);

    if (!items.length || searching) { block.style.display = 'none'; return; }
    block.style.display = 'block';

    box.innerHTML = items.map(m => {
        const last  = parseInt(m.last_read_page) || 0;
        const total = parseInt(m.last_read_total) || 0;
        const done  = total > 0 && last >= total - 1;
        const pct   = total > 0 ? Math.round(((last + 1) / total) * 100) : 0;
        const where = done
            ? `Chap. ${esc(m.last_read_chapter_number)} terminé`
            : `Chap. ${esc(m.last_read_chapter_number)} · p. ${last + 1}/${total || '?'}`;
        const thumb = m.image
            ? `<img src="${esc(m.image)}" alt="" loading="lazy" onerror="this.parentNode.textContent='📖'">`
            : '📖';
        return `
        <div class="continue-card" data-id="${m.id}" title="Reprendre la lecture">
            <div class="continue-thumb">${thumb}</div>
            <div class="continue-body">
                <div class="continue-title">${esc(m.title)}</div>
                <div class="continue-meta">${where} · ${relativeDate(new Date(m.last_read_at))}</div>
                <div class="continue-bar"><div style="width:${done ? 100 : pct}%"></div></div>
            </div>
        </div>`;
    }).join('');
}

document.getElementById('continueStrip').addEventListener('click', e => {
    const card = e.target.closest('.continue-card');
    if (card) resumeReading(parseInt(card.dataset.id));
});

// ════════════════════════════════════════
// READING STATISTICS
// ════════════════════════════════════════

async function openStatsModal() {
    const body = document.getElementById('statsBody');
    body.innerHTML = '<p style="color:var(--text3);text-align:center;padding:2rem">Chargement…</p>';
    openModal('statsModal');
    try {
        const res  = await fetch('api/get_stats.php');
        const data = await res.json();
        if (!data.success) { body.textContent = '❌ ' + data.error; return; }
        body.innerHTML = renderStats(data);
    } catch (e) {
        body.innerHTML = '<p style="color:var(--red);text-align:center">Erreur de chargement</p>';
    }
}

function renderStats(d) {
    const cell = (v, l) => `<div class="cell"><div class="v">${v}</div><div class="l">${l}</div></div>`;
    const pl   = (n, word) => word + (n > 1 ? 's' : '');
    const nf   = n => Number(n).toLocaleString('fr-FR');
    const hbar = (label, n, max) =>
        `<div class="hbar"><span class="hbar-l">${label}</span><div class="hbar-t"><div style="width:${max ? Math.round((n / max) * 100) : 0}%"></div></div><span class="hbar-n">${n}</span></div>`;
    const columns = (items, maxV) => items.map(it => {
        const h = it.v ? Math.max(8, Math.round((it.v / maxV) * 100)) : 2;
        return `<div class="daily-col" title="${esc(it.tip)}"><div class="daily-bar" style="height:${h}%;${it.v ? '' : 'opacity:.25'}${it.hl ? ';filter:brightness(1.25)' : ''}"></div><div class="daily-lbl">${esc(it.lbl)}</div></div>`;
    }).join('');

    // ── Reading tab ───────────────────────────────────────────────────────────
    const dailyMax = Math.max(1, ...d.daily.map(x => x.chapters));
    const daily = columns(d.daily.map(x => {
        const day = new Date(x.date + 'T00:00:00');
        return {
            v: x.chapters,
            lbl: day.toLocaleDateString('fr-FR', { weekday: 'narrow' }),
            tip: `${day.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} : ${x.chapters} chapitre(s), ${x.pages} page(s)`
        };
    }), dailyMax);

    const dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    const dayFull  = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    const wd       = d.habits.weekday;
    const wdMax    = Math.max(1, ...wd);
    const wdBest   = wd.indexOf(Math.max(...wd));
    const weekdayChart = columns(wd.map((v, i) => ({
        v, lbl: dayNames[i][0], hl: v === wdMax && v > 0,
        tip: `${dayFull[i]} : ${v} chapitre(s) lu(s)`
    })), wdMax);

    const top = d.top.length
        ? `<ul class="top-list">${d.top.map((t, i) => `<li><span>${i + 1}. ${esc(t.title)}</span><strong>${t.chapters} ch.</strong></li>`).join('')}</ul>`
        : '<p class="stats-empty">Termine un chapitre dans le lecteur pour voir ton classement.</p>';

    const h = d.habits;
    const firstRead = h.first_read ? new Date(h.first_read + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : null;

    const reading = `
        <div class="stats-mini">
            ${cell(d.total.chapters_finished, pl(d.total.chapters_finished, 'chapitre') + (d.total.chapters_finished > 1 ? ' terminés' : ' terminé'))}
            ${cell(nf(d.total.pages), pl(d.total.pages, 'page') + (d.total.pages > 1 ? ' lues' : ' lue'))}
            ${cell(d.streak ? '🔥 ' + d.streak : '0', d.streak > 1 ? 'jours d\'affilée' : 'jour d\'affilée')}
        </div>
        <div class="stats-sub">Cette semaine</div>
        <div class="stats-mini">
            ${cell(d.week.chapters, pl(d.week.chapters, 'chapitre'))}
            ${cell(nf(d.week.pages), pl(d.week.pages, 'page'))}
            ${cell(d.week.manga, 'manga')}
        </div>
        <div class="stats-sub">30 derniers jours</div>
        <div class="stats-mini">
            ${cell(d.month.chapters, pl(d.month.chapters, 'chapitre'))}
            ${cell(nf(d.month.pages), pl(d.month.pages, 'page'))}
            ${cell(d.month.manga, 'manga')}
        </div>
        <div class="stats-sub">Activité (14 jours)</div>
        <div class="daily-chart">${daily}</div>

        <div class="stats-sub">Habitudes</div>
        <div class="stats-mini stats-mini-4">
            ${cell('🏆 ' + h.longest_streak, 'record d\'affilée')}
            ${cell(h.active_days, pl(h.active_days, 'jour') + ' de lecture')}
            ${cell(nf(h.avg_pages_per_day), 'pages / jour actif (30 j)')}
            ${cell(h.completion_rate === null ? '—' : h.completion_rate + ' %', 'chapitres terminés')}
        </div>
        <div class="daily-chart" style="height:70px;margin-top:.75rem">${weekdayChart}</div>
        ${Math.max(...wd) > 0 ? `<p class="stats-note" style="margin-top:.4rem">Jour préféré : <strong>${dayFull[wdBest]}</strong>${firstRead ? ` · premier chapitre lu le ${firstRead}` : ''}</p>` : ''}

        <div class="stats-sub">Les plus lus</div>
        ${top}
        <p class="stats-note">Seules les lectures faites dans le lecteur intégré sont comptées. Un chapitre est daté de sa dernière lecture.</p>`;

    // ── Collection tab ────────────────────────────────────────────────────────
    const c  = d.collection;
    const st = c.storage;
    const statusN = k => (c.by_status.find(x => x.status === k) || { n: 0 }).n;
    const langMax = Math.max(1, ...c.by_language.map(x => +x.n));
    const ratingMax = Math.max(1, ...c.ratings);
    const monthMax = Math.max(1, ...c.added_per_month.map(x => x.n));
    const months = columns(c.added_per_month.map(x => {
        const dt = new Date(x.month + '-01T00:00:00');
        return { v: x.n, lbl: dt.toLocaleDateString('fr-FR', { month: 'narrow' }),
                 tip: `${dt.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })} : ${x.n} manga ajouté(s)` };
    }), monthMax);
    const heaviest = st.heaviest.length
        ? `<ul class="top-list">${st.heaviest.map((t, i) => `<li><span>${i + 1}. ${esc(t.title)}</span><strong>${formatBytes(+t.bytes)}</strong></li>`).join('')}</ul>`
        : '<p class="stats-empty">Aucun chapitre archivé pour l\'instant.</p>';

    const collection = `
        <div class="stats-mini">
            ${cell(c.total, 'manga')}
            ${cell(st.chapters, pl(st.chapters, 'chapitre') + ' archivé' + (st.chapters > 1 ? 's' : ''))}
            ${cell(formatBytes(st.bytes), 'd\'archives')}
        </div>
        <div class="stats-mini" style="margin-top:.6rem">
            ${cell(c.avg_rating === null ? '—' : c.avg_rating + ' ★', 'note moyenne')}
            ${cell(st.manga_with_chapters ? (st.chapters / st.manga_with_chapters).toFixed(1) : '—', 'chapitres / manga archivé')}
            ${cell(c.with_notes, pl(c.with_notes, 'manga') + ' avec notes')}
        </div>
        <div class="stats-sub">Statut</div>
        ${hbar('📖 En cours', +statusN('reading'), Math.max(1, c.total))}
        ${hbar('✅ Terminés', +statusN('completed'), Math.max(1, c.total))}
        <div class="stats-sub">Langues</div>
        ${c.by_language.map(x => hbar(`${LANG_FLAGS[x.language] || '🌐'} ${esc(LANG_NAMES[x.language] || x.language)}`, +x.n, langMax)).join('') || '<p class="stats-empty">—</p>'}
        <div class="stats-sub">Notes</div>
        ${[5, 4, 3, 2, 1, 0].map(r => hbar(r ? '★'.repeat(r) : 'Non noté', c.ratings[r], ratingMax)).join('')}
        <div class="stats-sub">Manga ajoutés (12 mois)</div>
        <div class="daily-chart" style="height:80px">${months}</div>
        <div class="stats-sub">Archives les plus lourdes</div>
        ${heaviest}`;

    return `
        <div class="stats-tabs">
            <button type="button" class="stats-tab active" data-tab="st-read">📖 Lecture</button>
            <button type="button" class="stats-tab" data-tab="st-coll">📚 Collection</button>
        </div>
        <div class="stats-panel active" id="st-read">${reading}</div>
        <div class="stats-panel" id="st-coll">${collection}</div>`;
}

document.getElementById('statsBody').addEventListener('click', e => {
    const tab = e.target.closest('.stats-tab');
    if (!tab) return;
    const body = document.getElementById('statsBody');
    body.querySelectorAll('.stats-tab').forEach(t => t.classList.toggle('active', t === tab));
    body.querySelectorAll('.stats-panel').forEach(p => p.classList.toggle('active', p.id === tab.dataset.tab));
});

// ════════════════════════════════════════
// PASSWORD + SECURITY JOURNAL
// ════════════════════════════════════════

function openPasswordModal() {
    document.getElementById('passwordForm').reset();
    updatePasswordMeter('');
    openModal('passwordModal');
    setTimeout(() => document.getElementById('pwCurrent').focus(), 50);
}

/** Simple strength hint: length + variety of characters. */
function updatePasswordMeter(pw) {
    let score = 0;
    if (pw.length >= 10) score++;
    if (pw.length >= 14) score++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
    if (/\d/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    if (pw.length < 10) score = Math.min(score, 1);
    const labels = ['', 'Faible', 'Moyen', 'Correct', 'Bon', 'Très bon'];
    const colors = ['transparent', 'var(--red)', 'var(--yellow)', 'var(--yellow)', 'var(--green)', 'var(--green)'];
    const bar = document.getElementById('pwMeterBar');
    bar.style.width = (pw ? score * 20 : 0) + '%';
    bar.style.background = colors[pw ? score : 0];
    document.getElementById('pwMeterLabel').textContent = !pw ? '' : (pw.length < 10 ? 'Trop court' : labels[score]);
    if (pw && pw.length < 10) { bar.style.width = '10%'; bar.style.background = 'var(--red)'; }
}

document.getElementById('pwNew').addEventListener('input', function() { updatePasswordMeter(this.value); });

document.getElementById('passwordForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const cur = document.getElementById('pwCurrent').value;
    const nw  = document.getElementById('pwNew').value;
    const cf  = document.getElementById('pwConfirm').value;
    if (nw !== cf) { toast('La confirmation ne correspond pas', 'error'); return; }

    const btn = document.getElementById('btnChangePassword');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('current', cur);
        fd.append('new', nw);
        fd.append('confirm', cf);
        const res  = await fetch('api/change_password.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('passwordModal');
            this.reset();
            toast('Mot de passe changé. Les autres appareils sont déconnectés.', 'success', 5000);
        } else {
            toast('Erreur : ' + data.error, 'error');
        }
    } catch (err) {
        toast('Erreur réseau', 'error');
    } finally {
        btn.disabled = false;
    }
});

const SECURITY_EVENTS = {
    login_ok:               ['✅', 'Connexion réussie'],
    login_failed:           ['❌', 'Mot de passe incorrect'],
    login_locked:           ['🔒', 'Connexion bloquée (trop d\'essais)'],
    login_blocked:          ['⛔', 'Tentative pendant le blocage'],
    logout:                 ['🚪', 'Déconnexion'],
    password_changed:       ['🔑', 'Mot de passe changé'],
    password_change_failed: ['⚠️', 'Changement de mot de passe refusé'],
    csrf_rejected:          ['🛑', 'Requête refusée (jeton / origine)'],
    backup_downloaded:      ['💾', 'Sauvegarde téléchargée'],
    backup_restored:        ['♻️', 'Sauvegarde restaurée'],
    manga_deleted:          ['🗑️', 'Manga supprimé'],
    chapter_uploaded:       ['📤', 'Chapitre ajouté'],
    chapter_replaced:       ['🔁', 'Chapitre remplacé'],
    chapter_deleted:        ['🗑️', 'Chapitre supprimé'],
};

async function openSecurityLog() {
    const body = document.getElementById('securityBody');
    body.innerHTML = '<p class="stats-empty" style="text-align:center;padding:2rem">Chargement…</p>';
    openModal('securityModal');
    try {
        const res  = await fetch('api/get_security_log.php');
        const data = await res.json();
        if (!data.success) { body.textContent = '❌ ' + data.error; return; }
        if (!data.entries.length) { body.innerHTML = '<p class="stats-empty" style="text-align:center;padding:2rem">Aucun événement enregistré.</p>'; return; }
        body.innerHTML = `
            <div class="log-wrap"><table class="log-table">
                <thead><tr><th>Date</th><th>Événement</th><th>Adresse IP</th><th>Détail</th></tr></thead>
                <tbody>${data.entries.map(en => {
                    const [ico, label] = SECURITY_EVENTS[en.event] || ['•', en.event];
                    const bad = /failed|locked|blocked|rejected/.test(en.event);
                    return `<tr class="${bad ? 'log-bad' : ''}">
                        <td>${esc(new Date(en.time).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }))}</td>
                        <td>${ico} ${esc(label)}</td><td>${esc(en.ip)}</td><td>${esc(en.detail)}</td></tr>`;
                }).join('')}</tbody>
            </table></div>
            <p class="stats-note">Les 100 derniers événements. Fichier : logs/security.log</p>`;
    } catch (e) {
        body.innerHTML = '<p style="color:var(--red);text-align:center">Erreur de chargement</p>';
    }
}

// ════════════════════════════════════════
// FULL BACKUP / RESTORE (ZIP)
// ════════════════════════════════════════

function downloadBackup() {
    window.location.href = 'api/backup.php';
    toast('Sauvegarde en cours de téléchargement…', 'info');
}

function triggerRestore() {
    document.getElementById('restoreFileInput').click();
}

document.getElementById('restoreFileInput').addEventListener('change', async function() {
    if (!this.files || !this.files[0]) return;
    const file = this.files[0];
    this.value = '';
    if (!confirm('Restaurer cette sauvegarde ?\nLes manga déjà présents (même titre) sont ignorés : rien n\'est écrasé ni supprimé.')) return;

    const fd = new FormData();
    fd.append('backup', file);
    try {
        const res  = await fetch('api/restore_backup.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { toast('Erreur : ' + data.error, 'error'); return; }
        await loadMangas();
        toast(`${data.imported} manga(s) restauré(s)` +
              (data.skipped ? `, ${data.skipped} déjà présent(s)` : '') +
              (data.invalid ? `, ${data.invalid} invalide(s)` : '') + ' !', 'success');
    } catch (e) {
        toast('Erreur lors de la restauration', 'error');
    }
});

// ════════════════════════════════════════
// MODAL HELPERS
// ════════════════════════════════════════

function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    // Re-enable scroll only if no other modal open
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.style.overflow = '';
    }
}

// Close on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            if (!document.querySelector('.modal-backdrop.open')) {
                document.body.style.overflow = '';
            }
        }
    });
});

// ════════════════════════════════════════
// DROPDOWN MENU
// ════════════════════════════════════════

function toggleDropdown(id) {
    const menu = document.getElementById(id);
    const isOpen = menu.classList.contains('open');
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('open'));
    if (!isOpen) menu.classList.add('open');
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('open'));
    }
});

// ════════════════════════════════════════
// LINK HELPER
// ════════════════════════════════════════

function openLink(url) { window.open(url, '_blank', 'noopener'); }

// Safe link opener: reads URL from in-memory mangas array, never from inline HTML attr
function openMangaLink(id) {
    const m = mangas.find(x => +x.id === +id);
    if (m && m.reading_link) window.open(m.reading_link, '_blank', 'noopener');
}

// ════════════════════════════════════════
// TOAST NOTIFICATIONS
// ════════════════════════════════════════

function toast(msg, type = 'info', duration = 3500) {
    const icons = { success: '✅', error: '❌', info: 'ℹ️' };
    const container = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    const ico = document.createElement('span');
    ico.textContent = icons[type] || 'ℹ️';
    const txt = document.createElement('span');
    txt.textContent = msg;               // textContent: manga titles can't inject HTML
    el.append(ico, txt);
    container.appendChild(el);
    setTimeout(() => {
        el.classList.add('out');
        el.addEventListener('animationend', () => el.remove());
    }, duration);
}

// ════════════════════════════════════════
// UTILS
// ════════════════════════════════════════

function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes/1048576).toFixed(1) + ' MB';
    return (bytes/1073741824).toFixed(1) + ' GB';
}

function relativeDate(date) {
    const diff = Math.floor((Date.now() - date) / 1000);
    if (diff < 60) return 'à l\'instant';
    if (diff < 3600) return `il y a ${Math.floor(diff/60)} min`;
    if (diff < 86400) return `il y a ${Math.floor(diff/3600)} h`;
    if (diff < 604800) return `il y a ${Math.floor(diff/86400)} j`;
    return date.toLocaleDateString('fr-FR', { day:'numeric', month:'short' });
}

// ════════════════════════════════════════
// GLOBAL EVENTS
// ════════════════════════════════════════

function bindGlobalEvents() {
    // Escape key closes modals / reader; arrow keys navigate the reader in page mode
    document.addEventListener('keydown', (e) => {
        const readerOpen = document.getElementById('readerOverlay').classList.contains('open');

        if (e.key === 'Escape') {
            if (readerOpen) {
                closeReader();
                return;
            }
            const open = document.querySelector('.modal-backdrop.open');
            if (open) {
                open.classList.remove('open');
                if (!document.querySelector('.modal-backdrop.open')) document.body.style.overflow = '';
            }
            return;
        }

        if (readerOpen && readerMode === 'page') {
            if (e.key === 'ArrowLeft')  readerPrevPage();
            if (e.key === 'ArrowRight') readerNextPage();
        }
    });
}