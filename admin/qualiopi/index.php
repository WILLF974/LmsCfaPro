<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
requirePedagogy();

$pdo = getDB();

// Get all criteria with evidence counts
$criteria = $pdo->query("
    SELECT qc.*,
           (SELECT COUNT(*) FROM qualiopi_evidences qe WHERE qe.criteria_id=qc.id) as total_evidences,
           (SELECT COUNT(*) FROM qualiopi_evidences qe WHERE qe.criteria_id=qc.id AND qe.status='validated') as validated_evidences
    FROM qualiopi_criteria qc ORDER BY qc.criterion_number, qc.indicator_number
")->fetchAll();

// Group by criterion
$grouped = [];
foreach ($criteria as $c) {
    $grouped[$c['criterion_number']][] = $c;
}

$criterionLabels = [
    1 => ['label'=>'Information', 'icon'=>'info-circle', 'color'=>'#6366f1', 'desc'=>'Conditions, délais, financement'],
    2 => ['label'=>'Compétences', 'icon'=>'graduation-cap', 'color'=>'#8b5cf6', 'desc'=>'Identification & adaptation'],
    3 => ['label'=>'Moyens', 'icon'=>'tools', 'color'=>'#0ea5e9', 'desc'=>'Techniques et humains'],
    4 => ['label'=>'Adaptation', 'icon'=>'sync', 'color'=>'#10b981', 'desc'=>'Suivi exécution et évaluation'],
    5 => ['label'=>'Amélioration', 'icon'=>'chart-line', 'color'=>'#f59e0b', 'desc'=>'Démarche qualité continue'],
    6 => ['label'=>'Réclamations', 'icon'=>'comment-alt', 'color'=>'#f97316', 'desc'=>'Gestion des aléas'],
    7 => ['label'=>'Résultats', 'icon'=>'trophy', 'color'=>'#22c55e', 'desc'=>'Indicateurs de performance'],
];

// Global compliance score
$totalIndicators = count($criteria);
$validatedTotal = array_sum(array_column($criteria, 'validated_evidences')) > 0 ? count(array_filter($criteria, fn($c) => $c['validated_evidences'] > 0)) : 0;
$complianceScore = $totalIndicators > 0 ? round(($validatedTotal / $totalIndicators) * 100) : 0;

renderHead('Tableau Qualiopi');
renderSidebar(isAdmin() ? 'admin' : 'pedagogy');
renderTopbar('Conformité Qualiopi', [['Admin', url('admin/index.php')], ['Qualiopi', '']]);
?>
<div class="page-content fade-in">
  <?= renderFlash() ?>

  <!-- Qualiopi Header -->
  <div style="background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(34,197,94,.06));border:1px solid rgba(16,185,129,.25);border-radius:var(--radius-xl);padding:28px;margin-bottom:28px;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:28px">🛡️</span>
        <div>
          <h2 style="font-size:22px;font-weight:800;margin-bottom:2px">Certification Qualiopi</h2>
          <p style="color:var(--text-muted);font-size:13px">Référentiel National Qualité — 7 critères · 32 indicateurs</p>
        </div>
      </div>
      <p style="color:var(--text-muted);font-size:14px;max-width:600px">
        Suivez la conformité de votre organisme de formation aux indicateurs Qualiopi. Ajoutez des preuves documentaires pour chaque indicateur.
      </p>
    </div>
    <div style="text-align:center">
      <canvas class="progress-ring" data-percent="<?= $complianceScore ?>" data-color="#10b981" width="100" height="100" style="display:block;margin:0 auto 8px"></canvas>
      <div style="font-size:13px;color:var(--text-muted)">Conformité globale</div>
      <div style="font-size:11px;color:var(--success);margin-top:2px"><?= $validatedTotal ?>/<?= $totalIndicators ?> indicateurs validés</div>
    </div>
  </div>

  <!-- Criteria Grid -->
  <?php foreach ($grouped as $num => $indicators): ?>
  <?php $info = $criterionLabels[$num] ?? ['label'=>"Critère $num",'icon'=>'check','color'=>'#6366f1','desc'=>'']; ?>
  <div class="card" style="margin-bottom:24px">
    <div class="card-header" style="background:rgba(<?= implode(',',sscanf($info['color'],'#%02x%02x%02x')) ?>,.08)">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:40px;height:40px;border-radius:var(--radius);background:<?= $info['color'] ?>22;border:1px solid <?= $info['color'] ?>44;display:flex;align-items:center;justify-content:center;color:<?= $info['color'] ?>">
          <i class="fas fa-<?= $info['icon'] ?>"></i>
        </div>
        <div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:700;letter-spacing:.08em;text-transform:uppercase">Critère <?= $num ?></div>
          <div style="font-size:16px;font-weight:800;color:white"><?= $info['label'] ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:12px">
        <?php
          $critTotal = count($indicators);
          $critValid = count(array_filter($indicators, fn($i) => $i['validated_evidences'] > 0));
          $critPct = $critTotal > 0 ? round(($critValid/$critTotal)*100) : 0;
        ?>
        <div style="text-align:right">
          <div style="font-size:18px;font-weight:800;color:<?= $critPct===100?'var(--success)':($critPct>0?'var(--warning)':'var(--danger)') ?>"><?= $critPct ?>%</div>
          <div style="font-size:11px;color:var(--text-muted)"><?= $critValid ?>/<?= $critTotal ?></div>
        </div>
      </div>
    </div>

    <div style="padding:0">
      <?php foreach ($indicators as $i => $ind): ?>
      <div style="display:grid;grid-template-columns:auto 1fr auto auto;align-items:start;gap:16px;padding:16px 20px;<?= $i<count($indicators)-1?'border-bottom:1px solid var(--border-light)':'' ?>">
        <!-- Status indicator -->
        <div style="width:32px;height:32px;border-radius:50%;background:<?= $ind['validated_evidences']>0?'rgba(16,185,129,.2)':($ind['total_evidences']>0?'rgba(245,158,11,.2)':'rgba(239,68,68,.1)') ?>;display:flex;align-items:center;justify-content:center;border:2px solid <?= $ind['validated_evidences']>0?'var(--success)':($ind['total_evidences']>0?'var(--warning)':'var(--border)') ?>;flex-shrink:0;margin-top:2px">
          <i class="fas fa-<?= $ind['validated_evidences']>0?'check text-green':($ind['total_evidences']>0?'clock text-yellow':'times text-gray') ?>" style="font-size:11px;color:<?= $ind['validated_evidences']>0?'var(--success)':($ind['total_evidences']>0?'var(--warning)':'var(--text-faint)') ?>"></i>
        </div>

        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span style="font-size:10px;font-weight:800;background:<?= $info['color'] ?>22;color:<?= $info['color'] ?>;padding:2px 8px;border-radius:var(--radius-full)"><?= e($ind['indicator_number']) ?></span>
          </div>
          <div style="font-size:14px;font-weight:600;color:white;margin-bottom:3px"><?= e($ind['title']) ?></div>
          <div style="font-size:12px;color:var(--text-muted)"><?= e(mb_strimwidth($ind['description'],0,120,'…')) ?></div>
        </div>

        <div style="text-align:center;white-space:nowrap">
          <?php if ($ind['total_evidences'] > 0): ?>
          <div style="font-size:14px;font-weight:700;color:white"><?= $ind['validated_evidences'] ?>/<?= $ind['total_evidences'] ?></div>
          <div style="font-size:10px;color:var(--text-muted)">preuves</div>
          <?php else: ?>
          <div style="font-size:12px;color:var(--text-faint)">Aucune preuve</div>
          <?php endif; ?>
        </div>

        <div>
          <button class="btn btn-secondary btn-sm" onclick="openEvidenceModal(<?= $ind['id'] ?>, '<?= e(addslashes($ind['title'])) ?>')">
            <i class="fas fa-plus"></i> Preuve
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Modal Add Evidence -->
<div class="modal-overlay" id="modal-evidence">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title">Ajouter une preuve</h3>
      <button class="modal-close" onclick="Modal.close('modal-evidence')">×</button>
    </div>
    <form method="POST" action="<?= url('admin/qualiopi/evidence.php') ?>" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="criteria_id" id="evidence-criteria-id">
      <div class="modal-body">
        <div id="evidence-criteria-name" style="font-size:13px;color:var(--text-muted);padding:8px 12px;background:var(--bg-elevated);border-radius:var(--radius);margin-bottom:16px"></div>
        <div class="form-group">
          <label class="form-label">Titre de la preuve <span class="required">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="Ex: Programme de formation 2024..." required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Description de la preuve..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Fichier justificatif (PDF, Word...)</label>
          <input type="file" name="evidence_file" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx">
        </div>
        <div class="form-group">
          <label class="form-label">URL (si ressource en ligne)</label>
          <input type="url" name="url" class="form-control" placeholder="https://...">
        </div>
        <div class="form-group">
          <label class="form-label">Statut</label>
          <select name="status" class="form-control">
            <option value="draft">Brouillon</option>
            <option value="submitted">Soumis</option>
            <option value="validated">Validé</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="Modal.close('modal-evidence')">Annuler</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= asset('js/main.js') ?>"></script>
<script>
function openEvidenceModal(id, title) {
  document.getElementById('evidence-criteria-id').value = id;
  document.getElementById('evidence-criteria-name').innerHTML = '<i class="fas fa-clipboard-check" style="margin-right:6px;color:var(--primary-light)"></i>' + title;
  Modal.open('modal-evidence');
}
</script>
<?php renderFooter(['']); ?>
