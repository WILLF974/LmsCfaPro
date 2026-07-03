/* ============================================================
   LMS CFA Pro — JavaScript principal
   ============================================================ */

'use strict';

// ── Utilitaires ─────────────────────────────────────────────
const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function debounce(fn, delay = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

function fetchJSON(url, options = {}) {
  const defaults = {
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
    }
  };
  return fetch(url, { ...defaults, ...options }).then(r => r.json());
}

// ── Toast Notifications ──────────────────────────────────────
const Toast = {
  container: null,

  init() {
    this.container = document.createElement('div');
    this.container.className = 'toast-container';
    document.body.appendChild(this.container);
  },

  show(title, message = '', type = 'info', duration = 4000) {
    const icons = { success:'check-circle', error:'times-circle', warning:'exclamation-triangle', info:'info-circle', badge:'trophy' };
    const icon = icons[type] || 'info-circle';
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
      <i class="fas fa-${icon} toast-icon"></i>
      <div><div class="toast-title">${title}</div>${message ? `<div class="toast-msg">${message}</div>` : ''}</div>
      <button onclick="this.closest('.toast').remove()" style="margin-left:auto;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:0 0 0 8px;">×</button>
    `;
    this.container.appendChild(toast);
    if (duration > 0) setTimeout(() => { toast.classList.add('hide'); setTimeout(() => toast.remove(), 300); }, duration);
    return toast;
  },

  success(title, msg) { return this.show(title, msg, 'success'); },
  error(title, msg)   { return this.show(title, msg, 'error'); },
  warning(title, msg) { return this.show(title, msg, 'warning'); },
  badge(title, msg)   { return this.show(title, msg, 'badge'); },
};

// ── Modals ───────────────────────────────────────────────────
const Modal = {
  open(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    modal.addEventListener('click', e => { if (e.target === modal) Modal.close(id); }, { once: true });
  },
  close(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
  },
  closeAll() {
    $$('.modal-overlay.open').forEach(m => { m.classList.remove('open'); });
    document.body.style.overflow = '';
  }
};

// ── Dropdowns ────────────────────────────────────────────────
function initDropdowns() {
  document.addEventListener('click', e => {
    const trigger = e.target.closest('[data-dropdown]');
    if (trigger) {
      e.stopPropagation();
      const menuId = trigger.dataset.dropdown;
      const menu = document.getElementById(menuId);
      if (!menu) return;
      const isOpen = menu.classList.contains('open');
      $$('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
      if (!isOpen) menu.classList.add('open');
    } else {
      $$('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
  });
}

// ── Tabs ─────────────────────────────────────────────────────
function initTabs() {
  $$('[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      const container = btn.closest('[data-tabs-container]') || document;
      $$('[data-tab]', container).forEach(b => b.classList.remove('active'));
      $$('.tab-pane', container).forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const pane = document.getElementById(target);
      if (pane) pane.classList.add('active');
    });
  });
}

// ── Sidebar Toggle ───────────────────────────────────────────
function initSidebar() {
  const toggle = $('#sidebar-toggle');
  const sidebar = $('.sidebar');
  const overlay = $('#sidebar-overlay');
  if (!toggle || !sidebar) return;

  toggle.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('open');
  });
  if (overlay) overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  });
}

// ── Notifications Panel ──────────────────────────────────────
function initNotifications() {
  const btn = $('#notif-btn');
  const panel = $('#notif-panel');
  if (!btn || !panel) return;

  btn.addEventListener('click', e => {
    e.stopPropagation();
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
      markNotificationsRead();
    }
  });
  document.addEventListener('click', () => panel.classList.remove('open'));
}

function markNotificationsRead() {
  fetchJSON('/api/notifications.php', { method: 'POST', body: JSON.stringify({ action: 'mark_read' }) })
    .then(() => {
      const dot = $('.notif-count', $('#notif-btn'));
      if (dot) dot.remove();
      const badge = $('.nav-badge[data-notif]');
      if (badge) badge.remove();
    }).catch(() => {});
}

// ── Progress tracking ────────────────────────────────────────
function trackLessonProgress(lessonId, formationId) {
  fetchJSON('/api/progress.php', {
    method: 'POST',
    body: JSON.stringify({ action: 'start', lesson_id: lessonId, formation_id: formationId })
  }).catch(() => {});
}

function completeLessonProgress(lessonId, formationId, score = null) {
  return fetchJSON('/api/progress.php', {
    method: 'POST',
    body: JSON.stringify({ action: 'complete', lesson_id: lessonId, formation_id: formationId, score })
  }).then(data => {
    if (data.badge) {
      Toast.badge('Badge débloqué !', data.badge.name);
      animateBadge(data.badge);
    }
    if (data.xp) {
      showXpGain(data.xp);
    }
    if (data.level_up) {
      showLevelUp(data.new_level);
    }
    return data;
  });
}

// ── Gamification Animations ──────────────────────────────────
function showXpGain(amount) {
  const el = document.createElement('div');
  el.textContent = `+${amount} XP`;
  el.style.cssText = `
    position: fixed; bottom: 80px; right: 24px;
    background: linear-gradient(135deg, #f59e0b, #f97316);
    color: #0d1117; font-weight: 800; font-size: 18px;
    padding: 10px 20px; border-radius: 99px;
    animation: xpFloat 2s ease forwards; z-index: 9999;
    box-shadow: 0 4px 16px rgba(245,158,11,.5);
  `;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 2000);
}

function showLevelUp(level) {
  const modal = document.createElement('div');
  modal.className = 'modal-overlay open';
  modal.innerHTML = `
    <div class="modal" style="text-align:center;max-width:400px">
      <div class="modal-body" style="padding:40px">
        <div style="font-size:64px;margin-bottom:16px">🎉</div>
        <h2 style="font-size:28px;margin-bottom:8px">Niveau ${level} !</h2>
        <p style="color:var(--text-muted);margin-bottom:24px">Félicitations, vous avez atteint un nouveau niveau !</p>
        <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#0d1117;margin:0 auto 24px;box-shadow:0 0 32px rgba(245,158,11,.6)">${level}</div>
        <button class="btn btn-primary" onclick="this.closest('.modal-overlay').remove()">Continuer</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';
  modal.addEventListener('click', e => { if (e.target === modal) { modal.remove(); document.body.style.overflow = ''; } });
}

function animateBadge(badge) {
  const el = document.createElement('div');
  el.style.cssText = `
    position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(0);
    background:var(--bg-elevated);border:2px solid ${badge.color||'#f59e0b'};
    border-radius:var(--radius-xl);padding:32px 40px;text-align:center;
    z-index:9998;transition:transform .4s cubic-bezier(.34,1.56,.64,1);
    box-shadow:0 0 40px rgba(245,158,11,.4);min-width:280px;
  `;
  el.innerHTML = `
    <div style="font-size:48px;margin-bottom:12px">🏆</div>
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--accent);font-weight:700;margin-bottom:8px">Badge obtenu !</div>
    <div style="font-size:20px;font-weight:800;color:white;margin-bottom:8px">${badge.name}</div>
    <div style="font-size:13px;color:var(--text-muted)">${badge.description}</div>
  `;
  document.body.appendChild(el);
  requestAnimationFrame(() => { el.style.transform = 'translate(-50%,-50%) scale(1)'; });
  setTimeout(() => {
    el.style.transform = 'translate(-50%,-50%) scale(0)';
    setTimeout(() => el.remove(), 400);
  }, 3000);
}

// ── XP CSS animation ─────────────────────────────────────────
const xpFloatStyle = document.createElement('style');
xpFloatStyle.textContent = `
@keyframes xpFloat {
  0%   { opacity:0; transform:translateY(0) scale(.8); }
  20%  { opacity:1; transform:translateY(-10px) scale(1.1); }
  80%  { opacity:1; transform:translateY(-40px) scale(1); }
  100% { opacity:0; transform:translateY(-70px) scale(.9); }
}`;
document.head.appendChild(xpFloatStyle);

// ── Confirm dialog ───────────────────────────────────────────
function confirmAction(message, callback) {
  const modal = document.createElement('div');
  modal.className = 'modal-overlay open';
  modal.innerHTML = `
    <div class="modal" style="max-width:400px">
      <div class="modal-header"><h3 class="modal-title">Confirmation</h3></div>
      <div class="modal-body"><p>${message}</p></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" id="confirm-cancel">Annuler</button>
        <button class="btn btn-danger" id="confirm-ok">Confirmer</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';
  modal.querySelector('#confirm-cancel').addEventListener('click', () => { modal.remove(); document.body.style.overflow = ''; });
  modal.querySelector('#confirm-ok').addEventListener('click', () => { modal.remove(); document.body.style.overflow = ''; callback(); });
}

// ── File upload drag & drop ──────────────────────────────────
function initFileUploads() {
  $$('.drop-zone').forEach(zone => {
    const input = zone.querySelector('input[type="file"]');
    if (!input) return;

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        updateFileLabel(zone, e.dataTransfer.files[0].name);
      }
    });
    zone.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
      if (input.files[0]) updateFileLabel(zone, input.files[0].name);
    });
  });
}

function updateFileLabel(zone, name) {
  const label = zone.querySelector('.drop-zone-label');
  if (label) label.textContent = name;
}

// ── Rich text (simple) ───────────────────────────────────────
function initRichText() {
  $$('[data-rich-text]').forEach(area => {
    area.contentEditable = true;
    area.style.minHeight = '120px';
    area.style.outline = 'none';
    area.style.padding = '12px';
  });
}

// ── Auto-save forms ──────────────────────────────────────────
function initAutoSave() {
  $$('[data-autosave]').forEach(form => {
    const key = form.dataset.autosave;
    // Restore (jamais les champs hidden : CSRF, ids techniques...)
    const saved = localStorage.getItem('autosave_' + key);
    if (saved) {
      try {
        const data = JSON.parse(saved);
        Object.entries(data).forEach(([name, value]) => {
          const field = form.querySelector(`[name="${name}"]`);
          if (field && field.type !== 'file' && field.type !== 'hidden') field.value = value;
        });
      } catch (e) {}
    }
    // Save on change (exclure les champs hidden)
    form.addEventListener('input', debounce(() => {
      const data = {};
      new FormData(form).forEach((v, k) => {
        const field = form.querySelector(`[name="${k}"]`);
        if (field && field.type !== 'hidden') data[k] = v;
      });
      localStorage.setItem('autosave_' + key, JSON.stringify(data));
    }, 1000));
    // Clear on submit
    form.addEventListener('submit', () => localStorage.removeItem('autosave_' + key));
  });
}

// ── Search live ──────────────────────────────────────────────
function initLiveSearch() {
  $$('[data-live-search]').forEach(input => {
    const target = input.dataset.liveSearch;
    const rows = $$(target);
    input.addEventListener('input', debounce(() => {
      const q = input.value.toLowerCase().trim();
      rows.forEach(row => {
        row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    }, 200));
  });
}

// ── Charts helpers ───────────────────────────────────────────
function drawProgressRing(canvas, percent, color = '#6366f1') {
  const ctx = canvas.getContext('2d');
  const s = canvas.width;
  const cx = s / 2, cy = s / 2, r = s / 2 - 8;
  const start = -Math.PI / 2;
  const end = start + (Math.PI * 2 * percent / 100);
  ctx.clearRect(0, 0, s, s);
  ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.strokeStyle = 'rgba(255,255,255,.05)'; ctx.lineWidth = 8; ctx.stroke();
  ctx.beginPath(); ctx.arc(cx, cy, r, start, end);
  ctx.strokeStyle = color; ctx.lineWidth = 8;
  ctx.lineCap = 'round'; ctx.stroke();
  ctx.fillStyle = 'white'; ctx.font = `bold ${s * 0.22}px Inter`;
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText(percent + '%', cx, cy);
}

// ── Video Player tracking ────────────────────────────────────
function initVideoTracking(videoEl, lessonId, formationId) {
  if (!videoEl) return;
  let tracked = false;
  trackLessonProgress(lessonId, formationId);

  videoEl.addEventListener('timeupdate', () => {
    if (!tracked && videoEl.currentTime / videoEl.duration > 0.8) {
      tracked = true;
      completeLessonProgress(lessonId, formationId).then(() => {
        Toast.success('Capsule terminée !', '+10 XP');
        markLessonComplete(lessonId);
      });
    }
    // Save position
    localStorage.setItem(`video_pos_${lessonId}`, Math.floor(videoEl.currentTime));
  });

  // Restore position
  const savedPos = localStorage.getItem(`video_pos_${lessonId}`);
  if (savedPos && parseInt(savedPos) > 5) {
    videoEl.currentTime = parseInt(savedPos);
  }
}

function markLessonComplete(lessonId) {
  const item = document.querySelector(`.lesson-item[data-lesson="${lessonId}"] .lesson-status`);
  if (item) {
    item.classList.add('done');
    item.innerHTML = '<i class="fas fa-check"></i>';
    item.closest('.lesson-item').classList.add('completed');
  }
  updateModuleProgress();
}

function updateModuleProgress() {
  $$('.module-progress').forEach(el => {
    const total = parseInt(el.dataset.total || 0);
    const done = el.closest('.module-block')?.querySelectorAll('.lesson-item.completed').length || 0;
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const bar = el.querySelector('.progress-fill');
    if (bar) bar.style.width = pct + '%';
    const text = el.querySelector('.progress-text');
    if (text) text.textContent = `${done}/${total} capsules`;
  });
}

// ── PDF Viewer ───────────────────────────────────────────────
function initPdfViewer(container, url, lessonId, formationId) {
  if (!container) return;
  trackLessonProgress(lessonId, formationId);
  container.innerHTML = `<iframe src="${url}#toolbar=1" style="width:100%;height:600px;border:none;border-radius:8px"></iframe>`;
  // Mark complete after 5s of viewing
  setTimeout(() => {
    completeLessonProgress(lessonId, formationId).then(() => {
      Toast.success('Document consulté !', '+10 XP');
      markLessonComplete(lessonId);
    });
  }, 5000);
}

// ── Delete confirmation ──────────────────────────────────────
function initDeleteButtons() {
  $$('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const msg = btn.dataset.confirm || 'Voulez-vous vraiment supprimer cet élément ?';
      confirmAction(msg, () => {
        const form = btn.closest('form');
        if (form) form.submit();
        else window.location.href = btn.href;
      });
    });
  });
}

// ── Character counter ────────────────────────────────────────
function initCharCounters() {
  $$('[maxlength]').forEach(el => {
    const counter = el.nextElementSibling;
    if (!counter?.classList.contains('char-count')) return;
    const update = () => counter.textContent = `${el.value.length}/${el.maxLength}`;
    el.addEventListener('input', update);
    update();
  });
}

// ── Smooth number animation ──────────────────────────────────
function animateNumber(el, target, duration = 1000) {
  const start = parseInt(el.textContent) || 0;
  const step = (target - start) / (duration / 16);
  let current = start;
  const interval = setInterval(() => {
    current += step;
    if ((step > 0 && current >= target) || (step < 0 && current <= target)) {
      el.textContent = target.toLocaleString('fr-FR');
      clearInterval(interval);
    } else {
      el.textContent = Math.round(current).toLocaleString('fr-FR');
    }
  }, 16);
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  Toast.init();
  initDropdowns();
  initTabs();
  initSidebar();
  initNotifications();
  initFileUploads();
  initAutoSave();
  initLiveSearch();
  initDeleteButtons();
  initCharCounters();

  // Animate stat numbers
  $$('.stat-value[data-count]').forEach(el => {
    const target = parseInt(el.dataset.count);
    if (!isNaN(target)) animateNumber(el, target);
  });

  // Progress rings
  $$('canvas.progress-ring').forEach(canvas => {
    const pct = parseInt(canvas.dataset.percent || 0);
    const color = canvas.dataset.color || '#6366f1';
    drawProgressRing(canvas, pct, color);
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') Modal.closeAll();
  });

  // Flash messages auto-hide
  $$('.alert').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .5s';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 500);
    }, 5000);
  });

  console.log('%c LMS CFA Pro ✨', 'color:#6366f1;font-size:18px;font-weight:bold;');
});

// Exports globaux
window.Toast = Toast;
window.Modal = Modal;
window.confirmAction = confirmAction;
window.completeLessonProgress = completeLessonProgress;
window.trackLessonProgress = trackLessonProgress;
window.initVideoTracking = initVideoTracking;
window.initPdfViewer = initPdfViewer;
window.drawProgressRing = drawProgressRing;
