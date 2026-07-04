<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';

// Contrôle d'accès : admin/pédagogie toujours, enseignant uniquement s'il est le tuteur
$viewer = currentUser();
if (!$viewer) { redirect(url('index.php')); }

$pdo    = getDB();
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { setFlash('error', 'Utilisateur introuvable.'); redirect(url('admin/users/index.php')); }

$viewerRole = $viewer['role'];
if (!in_array($viewerRole, ['admin', 'pedagogy'])) {
    if ($viewerRole === 'teacher') {
        try {
            $tc = $pdo->prepare("SELECT id FROM tutor_assignments WHERE teacher_id=? AND student_id=? AND revoked_at IS NULL LIMIT 1");
            $tc->execute([$viewer['id'], $userId]);
            if (!$tc->fetch()) {
                setFlash('error', "Vous n'êtes pas le tuteur de cet apprenant.");
                redirect(url('teacher/students/index.php'));
            }
        } catch (Exception $e) {
            redirect(url('teacher/index.php'));
        }
    } else {
        redirect(url('index.php'));
    }
}

// Charger l'étudiant
$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$student = $userStmt->fetch();
if (!$student) { setFlash('error', 'Utilisateur introuvable.'); redirect(url($viewerRole === 'teacher' ? 'teacher/students/index.php' : 'admin/users/index.php')); }

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action     = $_POST['action'] ?? '';
    $formationId = (int)($_POST['formation_id'] ?? 0);

    if ($action === 'reset_formation' && $formationId) {
        // IDs des séances (modules) de cette formation (direct ou via séquence)
        $moduleIdsStmt = $pdo->prepare("
            SELECT m.id FROM modules m
            LEFT JOIN sequences s ON m.sequence_id=s.id
            WHERE m.formation_id=? OR s.formation_id=?
        ");
        $moduleIdsStmt->execute([$formationId, $formationId]);
        $moduleIds = array_column($moduleIdsStmt->fetchAll(), 'id');

        // IDs des quiz de cette formation
        $quizIdsStmt = $pdo->prepare("
            SELECT id FROM quizzes WHERE formation_id=?
            OR module_id IN (SELECT id FROM modules m2 LEFT JOIN sequences s2 ON m2.sequence_id=s2.id WHERE m2.formation_id=? OR s2.formation_id=?)
        ");
        $quizIdsStmt->execute([$formationId, $formationId, $formationId]);
        $quizIds = array_column($quizIdsStmt->fetchAll(), 'id');

        // Progression séances
        if (!empty($moduleIds)) {
            $ph = implode(',', array_fill(0, count($moduleIds), '?'));
            $pdo->prepare("DELETE FROM module_progress WHERE user_id=? AND module_id IN ($ph)")
                ->execute(array_merge([$userId], $moduleIds));
        }

        // Réponses + tentatives quiz
        $pdo->prepare("DELETE qaa FROM quiz_attempt_answers qaa JOIN quiz_attempts qa ON qaa.attempt_id=qa.id JOIN quizzes q ON qa.quiz_id=q.id WHERE q.formation_id=? AND qa.user_id=?")->execute([$formationId, $userId]);
        $pdo->prepare("DELETE qa FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id=q.id WHERE q.formation_id=? AND qa.user_id=?")->execute([$formationId, $userId]);

        // XP lié à cette formation
        $xpToRemove = 0;
        if (!empty($moduleIds)) {
            $ph = implode(',', array_fill(0, count($moduleIds), '?'));
            $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='module' AND reference_id IN ($ph)");
            $xpStmt->execute(array_merge([$userId], $moduleIds));
            $xpToRemove += (int)$xpStmt->fetchColumn();
            $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='module' AND reference_id IN ($ph)")->execute(array_merge([$userId], $moduleIds));
        }
        if (!empty($quizIds)) {
            $ph = implode(',', array_fill(0, count($quizIds), '?'));
            $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='quiz' AND reference_id IN ($ph)");
            $xpStmt->execute(array_merge([$userId], $quizIds));
            $xpToRemove += (int)$xpStmt->fetchColumn();
            $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='quiz' AND reference_id IN ($ph)")->execute(array_merge([$userId], $quizIds));
        }
        // XP formation
        $xpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='formation' AND reference_id=?");
        $xpStmt->execute([$userId, $formationId]);
        $xpToRemove += (int)$xpStmt->fetchColumn();
        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='formation' AND reference_id=?")->execute([$userId, $formationId]);

        // XP badges liés à cette formation (badges dont l'XP est tracé)
        // On retire aussi les badges obtenus grâce à cette formation uniquement si liés
        $badgeXpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_transactions WHERE user_id=? AND reference_type='badge' AND reference_id IN (SELECT badge_id FROM user_badges WHERE user_id=? AND context LIKE ?)");
        $badgeXpStmt->execute([$userId, $userId, '%formation:'.$formationId.'%']);
        $xpToRemove += (int)$badgeXpStmt->fetchColumn();

        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=? AND reference_type='badge' AND reference_id IN (SELECT badge_id FROM user_badges WHERE user_id=? AND context LIKE ?)")->execute([$userId, $userId, '%formation:'.$formationId.'%']);
        $pdo->prepare("DELETE FROM user_badges WHERE user_id=? AND context LIKE ?")->execute([$userId, '%formation:'.$formationId.'%']);

        // Déduire l'XP et recalculer le niveau
        if ($xpToRemove > 0) {
            $newXp = max(0, $student['xp_points'] - $xpToRemove);
            $newLevel = max(1, floor($newXp / 100) + 1);
            $pdo->prepare("UPDATE users SET xp_points=?, level=? WHERE id=?")->execute([$newXp, $newLevel, $userId]);
        }

        // Remettre l'enrollment à 0
        $pdo->prepare("UPDATE enrollments SET progress_percent=0, status='active' WHERE user_id=? AND formation_id=?")->execute([$userId, $formationId]);

        auditLog('student_progress_reset', 'enrollment', $formationId);
        setFlash('success', 'Parcours réinitialisé (progression, quiz, XP et badges liés supprimés).');
        redirect(url("admin/users/progress.php?id=$userId"));
    }

    if ($action === 'reset_all') {
        // Réponses quiz
        $pdo->prepare("DELETE qaa FROM quiz_attempt_answers qaa JOIN quiz_attempts qa ON qaa.attempt_id=qa.id WHERE qa.user_id=?")->execute([$userId]);
        // Tentatives quiz
        $pdo->prepare("DELETE FROM quiz_attempts WHERE user_id=?")->execute([$userId]);
        // Progression séances
        $pdo->prepare("DELETE FROM module_progress WHERE user_id=?")->execute([$userId]);
        // Enrollments remis à 0
        $pdo->prepare("UPDATE enrollments SET progress_percent=0, status='active' WHERE user_id=?")->execute([$userId]);
        // Toutes les transactions XP
        $pdo->prepare("DELETE FROM xp_transactions WHERE user_id=?")->execute([$userId]);
        // Tous les badges
        $pdo->prepare("DELETE FROM user_badges WHERE user_id=?")->execute([$userId]);
        // XP et niveau remis à 0
        $pdo->prepare("UPDATE users SET xp_points=0, level=1 WHERE id=?")->execute([$userId]);

        auditLog('student_all_progress_reset', 'user', $userId);
        setFlash('success', 'Tout le parcours a été réinitialisé (progression, quiz, XP et badges supprimés).');
        redirect(url("admin/users/progress.php?id=$userId"));
    }
}

// ── Inscriptions + formations ────────────────────────────────
$enrStmt = $pdo->prepare("
    SELECT e.*, f.id as f_id, f.title as f_title, f.duration_hours,
           r.rncp_code, r.title as rncp_title, r.level as rncp_level,
           (SELECT COUNT(*) FROM sequences WHERE formation_id=f.id) as total_sequences,
           (SELECT COUNT(*) FROM modules m2 LEFT JOIN sequences s2 ON m2.sequence_id=s2.id WHERE m2.formation_id=f.id OR s2.formation_id=f.id) as total_modules
    FROM enrollments e
    JOIN formations f ON e.formation_id=f.id
    LEFT JOIN rncp_titles r ON f.rncp_title_id=r.id
    WHERE e.user_id=?
    ORDER BY e.enrolled_at DESC
");
$enrStmt->execute([$userId]);
$enrollments = $enrStmt->fetchAll();

// ── Stats globales ───────────────────────────────────────────
$statsStmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='active')           as active_formations,
      (SELECT COUNT(*) FROM enrollments WHERE user_id=? AND status='completed')         as done_formations,
      (SELECT COUNT(*) FROM module_progress WHERE user_id=? AND status='completed')    as done_modules,
      (SELECT COUNT(*) FROM module_progress WHERE user_id=? AND status='in_progress')  as in_progress_modules,
      (SELECT COUNT(*) FROM quiz_attempts WHERE user_id=? AND passed=1)                as passed_quizzes,
      (SELECT COUNT(*) FROM quiz_attempts WHERE user_id=? AND status='completed')      as total_quiz_attempts,
      (SELECT SUM(time_spent_seconds) FROM module_progress WHERE user_id=?)            as total_time_sec,
      (SELECT SUM(time_spent_seconds) FROM quiz_attempts WHERE user_id=? AND status='completed') as quiz_time_sec
");
$statsStmt->execute(array_fill(0, 8, $userId));
$stats = $statsStmt->fetch();

// ── Pour chaque formation : séquences + séances (modules) + quiz ─
$formationsData = [];
foreach ($enrollments as $enr) {
    $fId = $enr['f_id'];

    // Séquences de la formation avec info compétence
    $seqStmt = $pdo->prepare("
        SELECT seq.*, c.code as comp_code, c.title as comp_title,
               at.code as at_code, at.title as at_title, at.id as at_id
        FROM sequences seq
        LEFT JOIN competencies c ON seq.competency_id=c.id
        LEFT JOIN activity_types at ON c.activity_type_id=at.id
        WHERE seq.formation_id=?
        ORDER BY seq.order_num
    ");
    $seqStmt->execute([$fId]);
    $sequences = $seqStmt->fetchAll();

    // Séances (modules) avec progression, trouvées via formation_id direct OU via séquence
    $modStmt = $pdo->prepare("
        SELECT m.id, m.title, m.content_type, m.duration_hours, m.xp_reward, m.is_mandatory,
               m.sequence_id, m.order_num,
               mp.status as prog_status, mp.started_at, mp.completed_at, mp.time_spent_seconds
        FROM modules m
        LEFT JOIN sequences seq_f ON m.sequence_id=seq_f.id
        LEFT JOIN module_progress mp ON mp.module_id=m.id AND mp.user_id=?
        WHERE (m.formation_id=? OR seq_f.formation_id=?)
        ORDER BY m.sequence_id, m.order_num
    ");
    $modStmt->execute([$userId, $fId, $fId]);
    $modulesBySeq = []; $freeModules = [];
    foreach ($modStmt->fetchAll() as $m) {
        if ($m['sequence_id']) $modulesBySeq[$m['sequence_id']][] = $m;
        else                   $freeModules[] = $m;
    }

    // Quiz de la formation (niveau formation + niveau séance)
    $qzStmt = $pdo->prepare("
        SELECT q.id, q.title, q.quiz_type, q.passing_score, q.max_attempts, q.xp_reward,
               q.module_id, q.formation_id, q.activity_type_id, q.competency_id,
               at.code as at_code, at.title as at_title,
               c.code as comp_code, c.title as comp_title,
               (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND status='completed') as used_attempts,
               (SELECT MAX(score) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND status='completed') as best_score,
               (SELECT passed FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? AND passed=1 LIMIT 1) as is_passed,
               (SELECT completed_at FROM quiz_attempts WHERE quiz_id=q.id AND user_id=? ORDER BY id DESC LIMIT 1) as last_attempt_date
        FROM quizzes q
        LEFT JOIN activity_types at ON q.activity_type_id=at.id
        LEFT JOIN competencies c ON q.competency_id=c.id
        WHERE q.formation_id=? OR q.module_id IN (
            SELECT id FROM modules m2 LEFT JOIN sequences s2 ON m2.sequence_id=s2.id
            WHERE m2.formation_id=? OR s2.formation_id=?
        )
        ORDER BY q.module_id, q.id
    ");
    $qzStmt->execute([$userId, $userId, $userId, $userId, $fId, $fId, $fId]);
    $quizzesByMod = []; $standaloneQuizzes = [];
    foreach ($qzStmt->fetchAll() as $qz) {
        if ($qz['module_id']) $quizzesByMod[$qz['module_id']][] = $qz;
        else $standaloneQuizzes[] = $qz;
    }

    // Blocs RNCP
    $blocsStmt = $pdo->prepare("
        SELECT at.id, at.code, at.title,
               (SELECT COUNT(*) FROM competencies WHERE activity_type_id=at.id) as comp_count
        FROM activity_types at
        JOIN formations f ON at.rncp_title_id=f.rncp_title_id
        WHERE f.id=? ORDER BY at.order_num
    ");
    $blocsStmt->execute([$fId]);
    $blocs = $blocsStmt->fetchAll();

    $compsStmt = $pdo->prepare("
        SELECT c.*, at.code as at_code
        FROM competencies c
        JOIN activity_types at ON c.activity_type_id=at.id
        WHERE at.id IN (SELECT id FROM activity_types WHERE rncp_title_id=(SELECT rncp_title_id FROM formations WHERE id=?))
        ORDER BY at.order_num, c.order_num
    ");
    $compsStmt->execute([$fId]);
    $compsByBloc = [];
    foreach ($compsStmt->fetchAll() as $c) {
        $compsByBloc[$c['activity_type_id']][] = $c;
    }

    // Stats par séquence (progression des séances dedans)
    $seqStats = [];
    foreach ($sequences as $seq) {
        $mods  = $modulesBySeq[$seq['id']] ?? [];
        $total = count($mods);
        $done  = count(array_filter($mods, fn($m) => $m['prog_status'] === 'completed'));
        $seqStats[$seq['id']] = ['total'=>$total,'done'=>$done,'pct'=>$total>0?round($done/$total*100):0];
    }

    $formationsData[$fId] = compact('sequences','modulesBySeq','freeModules','quizzesByMod','standaloneQuizzes','blocs','compsByBloc','seqStats');

    // ── Arborescence RNCP ────────────────────────────────────────
    $rncpTree = [];
    try {
    // Séquences avec chaîne RNCP complète
    $chainStmt = $pdo->prepare("
        SELECT seq.id as seq_id, seq.title as seq_title, seq.order_num as seq_order,
               c.id as comp_id, c.code as comp_code, c.title as comp_title, c.order_num as comp_order,
               at.id as at_id, at.code as at_code, at.title as at_title, at.order_num as at_order,
               rt.id as rncp_id, rt.rncp_code, rt.title as rncp_title
        FROM sequences seq
        LEFT JOIN competencies c ON seq.competency_id = c.id
        LEFT JOIN activity_types at ON c.activity_type_id = at.id
        LEFT JOIN rncp_titles rt ON at.rncp_title_id = rt.id
        WHERE seq.formation_id = ?
        ORDER BY at.order_num, c.order_num, seq.order_num
    ");
    $chainStmt->execute([$fId]);

    // Quiz avec dernière tentative réussie (ou en cours)
    $qzByMod = []; $qzByComp = [];
    $qzRncpStmt = $pdo->prepare("
        SELECT q.id as qid, q.title as qtitle, q.passing_score, q.xp_reward,
               q.module_id, q.competency_id,
               qa.started_at, qa.completed_at, qa.time_spent_seconds,
               qa.score, qa.passed, qa.teacher_score, qa.review_status
        FROM quizzes q
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = ?
            AND qa.id = (SELECT MAX(id) FROM quiz_attempts
                         WHERE quiz_id = q.id AND user_id = ? AND status = 'completed')
        WHERE q.formation_id = ?
           OR q.module_id IN (
               SELECT m2.id FROM modules m2
               LEFT JOIN sequences s2 ON m2.sequence_id = s2.id
               WHERE m2.formation_id = ? OR s2.formation_id = ?
           )
    ");
    $qzRncpStmt->execute([$userId, $userId, $fId, $fId, $fId]);
    foreach ($qzRncpStmt->fetchAll() as $qz) {
        if ($qz['module_id'])     $qzByMod[$qz['module_id']][]     = $qz;
        elseif ($qz['competency_id']) $qzByComp[$qz['competency_id']][] = $qz;
    }

    // Études de cas soumises
    $csByComp = [];
    try {
        $csRncpStmt = $pdo->prepare("
            SELECT cs.id as cs_id, cs.title as cs_title, cs.competency_id,
                   css.submitted_at, css.graded_at, css.score, css.max_score, css.status, css.grade
            FROM case_study_submissions css
            JOIN case_studies cs ON css.case_study_id = cs.id
            WHERE css.user_id = ? AND cs.competency_id IN (
                SELECT c2.id FROM competencies c2
                JOIN activity_types at2 ON c2.activity_type_id = at2.id
                WHERE at2.rncp_title_id = (SELECT rncp_title_id FROM formations WHERE id = ?)
            )
        ");
        $csRncpStmt->execute([$userId, $fId]);
        foreach ($csRncpStmt->fetchAll() as $cs) {
            if ($cs['competency_id']) $csByComp[$cs['competency_id']][] = $cs;
        }
    } catch (Exception $e) {}

    // Construire l'arbre RNCP → AT → Comp → Séquence → Séance
    $rncpTree = [];
    foreach ($chainStmt->fetchAll() as $row) {
        $rId  = $row['rncp_id']  ?? 0;
        $atId = $row['at_id']    ?? 0;
        $cId  = $row['comp_id']  ?? 0;
        $sId  = $row['seq_id'];

        if (!isset($rncpTree[$rId])) {
            $rncpTree[$rId] = ['code'=>$row['rncp_code'], 'title'=>$row['rncp_title'], 'ats'=>[]];
        }
        if (!isset($rncpTree[$rId]['ats'][$atId])) {
            $rncpTree[$rId]['ats'][$atId] = ['code'=>$row['at_code'], 'title'=>$row['at_title'], 'order'=>(int)$row['at_order'], 'comps'=>[]];
        }
        if (!isset($rncpTree[$rId]['ats'][$atId]['comps'][$cId])) {
            $rncpTree[$rId]['ats'][$atId]['comps'][$cId] = [
                'code' => $row['comp_code'], 'title' => $row['comp_title'], 'order' => (int)$row['comp_order'],
                'seqs' => [],
                'quizzes' => $qzByComp[$cId] ?? [],
                'case_studies' => $csByComp[$cId] ?? [],
            ];
        }

        // Séances de la séquence, avec leurs quiz
        $seqMods = $modulesBySeq[$sId] ?? [];
        foreach ($seqMods as &$sm) {
            $sm['quizzes'] = $qzByMod[$sm['id']] ?? [];
        }
        unset($sm);

        $rncpTree[$rId]['ats'][$atId]['comps'][$cId]['seqs'][$sId] = [
            'id'=>$sId, 'title'=>$row['seq_title'], 'order'=>(int)$row['seq_order'],
            'modules'=>$seqMods,
        ];
    }

    $formationsData[$fId]['rncpTree'] = $rncpTree;
    } catch (Exception $e) { $formationsData[$fId]['rncpTree'] = []; }
}

// ── Fallback : arborescence sans inscription (depuis module_progress) ──
$freeProgress = [];
if (empty($enrollments)) {
    try {
        $freeModStmt = $pdo->prepare("
            SELECT m.id, m.title, m.content_type, m.duration_hours, m.xp_reward, m.is_mandatory,
                   m.sequence_id, m.order_num,
                   mp.status as prog_status, mp.started_at, mp.completed_at, mp.time_spent_seconds,
                   seq.id as seq_id, seq.title as seq_title, seq.order_num as seq_order,
                   c.id as comp_id, c.code as comp_code, c.title as comp_title, c.order_num as comp_order,
                   at.id as at_id, at.code as at_code, at.title as at_title, at.order_num as at_order,
                   rt.id as rncp_id, rt.rncp_code, rt.title as rncp_title
            FROM module_progress mp
            JOIN modules m ON mp.module_id = m.id
            LEFT JOIN sequences seq ON m.sequence_id = seq.id
            LEFT JOIN competencies c ON seq.competency_id = c.id
            LEFT JOIN activity_types at ON c.activity_type_id = at.id
            LEFT JOIN rncp_titles rt ON at.rncp_title_id = rt.id
            WHERE mp.user_id = ?
            ORDER BY at.order_num, c.order_num, seq.order_num, m.order_num
        ");
        $freeModStmt->execute([$userId]);

        $freeQzStmt = $pdo->prepare("
            SELECT q.id as qid, q.title as qtitle, q.passing_score, q.xp_reward,
                   q.module_id, q.competency_id,
                   qa.started_at, qa.completed_at, qa.time_spent_seconds,
                   qa.score, qa.passed, qa.teacher_score, qa.review_status
            FROM quiz_attempts qa
            JOIN quizzes q ON qa.quiz_id = q.id
            WHERE qa.user_id = ?
              AND qa.id = (SELECT MAX(qa2.id) FROM quiz_attempts qa2
                           WHERE qa2.quiz_id = q.id AND qa2.user_id = ? AND qa2.status = 'completed')
        ");
        $freeQzStmt->execute([$userId, $userId]);
        $freeQzByMod = []; $freeQzByComp = [];
        foreach ($freeQzStmt->fetchAll() as $qz) {
            if ($qz['module_id'])         $freeQzByMod[$qz['module_id']][]       = $qz;
            elseif ($qz['competency_id']) $freeQzByComp[$qz['competency_id']][]  = $qz;
        }

        $freeCsByComp = [];
        try {
            $freeCsStmt = $pdo->prepare("
                SELECT cs.id as cs_id, cs.title as cs_title, cs.competency_id,
                       css.submitted_at, css.graded_at, css.score, css.max_score, css.status, css.grade
                FROM case_study_submissions css
                JOIN case_studies cs ON css.case_study_id = cs.id
                WHERE css.user_id = ?
            ");
            $freeCsStmt->execute([$userId]);
            foreach ($freeCsStmt->fetchAll() as $cs) {
                if ($cs['competency_id']) $freeCsByComp[$cs['competency_id']][] = $cs;
            }
        } catch (Exception $e) {}

        foreach ($freeModStmt->fetchAll() as $row) {
            $rId  = $row['rncp_id']  ?? 0;
            $atId = $row['at_id']    ?? 0;
            $cId  = $row['comp_id']  ?? 0;
            $sId  = $row['seq_id']   ?? 0;
            $mId  = $row['id'];
            if (!isset($freeProgress[$rId])) {
                $freeProgress[$rId] = ['code'=>$row['rncp_code'],'title'=>$row['rncp_title'],'ats'=>[]];
            }
            if (!isset($freeProgress[$rId]['ats'][$atId])) {
                $freeProgress[$rId]['ats'][$atId] = ['code'=>$row['at_code'],'title'=>$row['at_title'],'order'=>(int)$row['at_order'],'comps'=>[]];
            }
            if (!isset($freeProgress[$rId]['ats'][$atId]['comps'][$cId])) {
                $freeProgress[$rId]['ats'][$atId]['comps'][$cId] = [
                    'code'=>$row['comp_code'],'title'=>$row['comp_title'],'order'=>(int)$row['comp_order'],
                    'seqs'=>[],'quizzes'=>$freeQzByComp[$cId]??[],'case_studies'=>$freeCsByComp[$cId]??[],
                ];
            }
            if ($sId && !isset($freeProgress[$rId]['ats'][$atId]['comps'][$cId]['seqs'][$sId])) {
                $freeProgress[$rId]['ats'][$atId]['comps'][$cId]['seqs'][$sId] = [
                    'id'=>$sId,'title'=>$row['seq_title'],'order'=>(int)$row['seq_order'],'modules'=>[],
                ];
            }
            $modEntry = [
                'id'=>$mId,'title'=>$row['title'],'content_type'=>$row['content_type'],
                'duration_hours'=>$row['duration_hours'],'xp_reward'=>$row['xp_reward'],
                'is_mandatory'=>$row['is_mandatory'],'order_num'=>$row['order_num'],
                'prog_status'=>$row['prog_status'],'started_at'=>$row['started_at'],
                'completed_at'=>$row['completed_at'],'time_spent_seconds'=>$row['time_spent_seconds'],
                'quizzes'=>$freeQzByMod[$mId]??[],
            ];
            if ($sId) {
                $freeProgress[$rId]['ats'][$atId]['comps'][$cId]['seqs'][$sId]['modules'][] = $modEntry;
            }
        }
    } catch (Exception $e) {}
}

// ── Render ───────────────────────────────────────────────────
$name = e($student['first_name'] . ' ' . $student['last_name']);
renderHead('Suivi pédagogique — ' . $name);
if ($viewerRole === 'teacher') {
    renderSidebar('teacher');
    renderTopbar('Suivi pédagogique', [
        ['Enseignant', url('teacher/index.php')],
        ['Mes filleuls', url('teacher/students/index.php')],
        [$name, ''],
    ]);
} elseif ($viewerRole === 'pedagogy') {
    renderSidebar('pedagogy');
    renderTopbar('Suivi pédagogique', [
        ['Pédagogie', url('pedagogy/index.php')],
        ['Apprenants', url('admin/users/index.php')],
        [$name, ''],
    ]);
} else {
    renderSidebar('admin');
    renderTopbar('Suivi pédagogique', [
        ['Administration', url('admin/index.php')],
        ['Apprenants', url('admin/users/index.php')],
        [$name, ''],
    ]);
}
?>
<div class="page-content fade-in">

  <!-- En-tête étudiant -->
  <div class="card" style="margin-bottom:24px">
    <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:20px 24px">
      <div class="avatar" style="width:64px;height:64px;font-size:22px;flex-shrink:0;background:<?= getAvatarColor($student['first_name'].$student['last_name']) ?>">
        <?php if ($student['avatar'] && file_exists(UPLOADS_PATH.'/'.$student['avatar'])): ?>
          <img src="<?= e(uploadUrl($student['avatar'])) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
        <?php else: ?>
          <?= getAvatarInitials($student['first_name'], $student['last_name']) ?>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">
          <?= getStatusBadge($student['status']) ?>
          <span class="badge badge-secondary"><?= ucfirst($student['role']) ?></span>
          <span class="badge badge-warning"><i class="fas fa-bolt"></i> <?= number_format($student['xp_points']) ?> XP · Niv.<?= $student['level'] ?></span>
        </div>
        <h1 style="font-size:20px;margin-bottom:2px"><?= $name ?></h1>
        <div style="font-size:13px;color:var(--text-muted)"><?= e($student['email']) ?><?= $student['phone'] ? ' · '.e($student['phone']) : '' ?></div>
      </div>
      <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap">
        <?php if ($viewerRole !== 'teacher'): ?>
        <a href="<?= url('admin/users/edit.php?id='.$userId) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> Modifier</a>
        <?php if (!empty($enrollments)): ?>
        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:rgba(239,68,68,.3)"
          onclick="document.getElementById('modal-reset-all').style.display='flex'">
          <i class="fas fa-redo"></i> Réinitialiser tout
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <a href="<?= url($viewerRole === 'teacher' ? 'teacher/students/index.php' : 'admin/users/index.php') ?>" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
      </div>
    </div>
  </div>

  <!-- KPIs globaux -->
  <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px">
    <?php
    $totalTimeSec = ($stats['total_time_sec'] ?? 0) + ($stats['quiz_time_sec'] ?? 0);
    $timeLabel = $totalTimeSec > 3600 ? round($totalTimeSec/3600,1).'h' : round($totalTimeSec/60).' min';
    $kpis = [
      ['label'=>'Formations actives',  'val'=>$stats['active_formations'],    'color'=>'var(--primary-light)', 'icon'=>'book-open'],
      ['label'=>'Formations terminées','val'=>$stats['done_formations'],       'color'=>'var(--success)',       'icon'=>'graduation-cap'],
      ['label'=>'Séances validées',    'val'=>$stats['done_modules'],          'color'=>'var(--info)',          'icon'=>'check-circle'],
      ['label'=>'En cours',            'val'=>$stats['in_progress_modules'],   'color'=>'var(--warning)',       'icon'=>'spinner'],
      ['label'=>'Quiz réussis',        'val'=>$stats['passed_quizzes'].'/'.$stats['total_quiz_attempts'], 'color'=>'var(--success)', 'icon'=>'clipboard-check'],
      ['label'=>'Temps de formation',  'val'=>$timeLabel,                      'color'=>'var(--text-muted)',    'icon'=>'clock'],
    ];
    foreach ($kpis as $k): ?>
    <div class="card">
      <div class="card-body" style="padding:14px;text-align:center">
        <i class="fas fa-<?= $k['icon'] ?>" style="color:<?= $k['color'] ?>;font-size:18px;margin-bottom:6px;display:block"></i>
        <div style="font-size:20px;font-weight:800;color:white"><?= $k['val'] ?></div>
        <div style="font-size:11px;color:var(--text-muted);line-height:1.3"><?= $k['label'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($enrollments) && empty($freeProgress)): ?>
  <div class="empty-state">
    <div class="icon">📚</div>
    <h3>Aucune inscription</h3>
    <p>Cet étudiant n'est inscrit à aucune formation et n'a aucune progression enregistrée.</p>
  </div>
  <?php endif; ?>

  <!-- ═══ ARBORESCENCE RNCP (sans inscription formelle) ═══ -->
  <?php if (!empty($freeProgress)): $fpId = 'fp'; ?>
  <div class="card" style="margin-bottom:28px">
    <div class="card-header" style="background:rgba(99,102,241,.07);padding:16px 20px">
      <div style="font-size:17px;font-weight:800;color:white"><i class="fas fa-sitemap" style="margin-right:8px"></i>Progression RNCP</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Séances consultées (accès hors inscription formation)</div>
    </div>
    <div style="padding:20px">
    <?php foreach ($freeProgress as $rId => $rncp):
      $rTotal=0; $rDone=0;
      foreach ($rncp['ats'] as $at2) foreach ($at2['comps'] as $c2) foreach ($c2['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $rTotal++; if ($m2['prog_status']==='completed') $rDone++; }
      $rPct = $rTotal>0?round($rDone/$rTotal*100):0;
    ?>
    <div style="margin-bottom:14px;border:1px solid rgba(99,102,241,.3);border-radius:var(--radius-lg);overflow:hidden">
      <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:rgba(99,102,241,.12);cursor:pointer" onclick="toggleSection('rncp-<?=$fpId?>-<?=$rId?>')">
        <i class="fas fa-graduation-cap" style="color:var(--primary-light);font-size:15px;flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <?php if ($rncp['code']): ?><span class="badge badge-primary" style="font-size:10px;margin-bottom:4px"><?= e($rncp['code']) ?></span> <?php endif; ?>
          <span style="font-size:14px;font-weight:800;color:white"><?= e($rncp['title'] ?? 'Titre RNCP') ?></span>
        </div>
        <div style="flex-shrink:0;display:flex;align-items:center;gap:10px">
          <?php if ($rTotal>0): ?>
          <div style="text-align:right">
            <div style="font-size:15px;font-weight:800;color:<?= $rPct===100?'var(--success)':'var(--primary-light)' ?>"><?= $rPct ?>%</div>
            <div style="font-size:10px;color:var(--text-faint)"><?= $rDone ?>/<?= $rTotal ?> séances</div>
          </div>
          <?php endif; ?>
          <i class="fas fa-chevron-down" id="chev-rncp-<?=$fpId?>-<?=$rId?>" style="color:var(--text-muted);transition:.2s"></i>
        </div>
      </div>
      <div id="rncp-<?=$fpId?>-<?=$rId?>">
        <?php foreach ($rncp['ats'] as $atId => $at):
          $atTotal=0; $atDone=0;
          foreach ($at['comps'] as $c2) foreach ($c2['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $atTotal++; if ($m2['prog_status']==='completed') $atDone++; }
          $atPct = $atTotal>0?round($atDone/$atTotal*100):0;
        ?>
        <div style="border-top:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:12px;padding:12px 18px 12px 30px;background:rgba(139,92,246,.07);cursor:pointer" onclick="toggleSection('at-<?=$fpId?>-<?=$atId?>')">
            <i class="fas fa-layer-group" style="color:#a78bfa;font-size:13px;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
              <?php if ($at['code']): ?><span class="badge badge-secondary" style="font-size:10px"><?= e($at['code']) ?></span> <?php endif; ?>
              <span style="font-size:13px;font-weight:700;color:white"><?= e($at['title'] ?? 'Activité Type') ?></span>
            </div>
            <div style="flex-shrink:0;display:flex;align-items:center;gap:8px">
              <?php if ($atTotal>0): ?>
              <span style="font-size:13px;font-weight:700;color:<?= $atPct===100?'var(--success)':'#a78bfa' ?>"><?= $atPct ?>%</span>
              <span style="font-size:10px;color:var(--text-faint)"><?= $atDone ?>/<?= $atTotal ?></span>
              <?php endif; ?>
              <i class="fas fa-chevron-down" id="chev-at-<?=$fpId?>-<?=$atId?>" style="color:var(--text-muted);transition:.2s"></i>
            </div>
          </div>
          <div id="at-<?=$fpId?>-<?=$atId?>">
            <?php foreach ($at['comps'] as $cId => $comp):
              $cTotal=0; $cDone=0;
              foreach ($comp['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $cTotal++; if ($m2['prog_status']==='completed') $cDone++; }
              $cPct = $cTotal>0?round($cDone/$cTotal*100):0;
            ?>
            <div style="border-top:1px solid rgba(255,255,255,.04)">
              <div style="display:flex;align-items:center;gap:10px;padding:10px 18px 10px 44px;background:rgba(59,130,246,.04);cursor:pointer" onclick="toggleSection('comp-<?=$fpId?>-<?=$cId?>')">
                <i class="fas fa-bullseye" style="color:#60a5fa;font-size:12px;flex-shrink:0"></i>
                <div style="flex:1;min-width:0">
                  <?php if ($comp['code']): ?><span style="font-size:10px;padding:1px 6px;background:rgba(59,130,246,.15);border-radius:99px;color:#60a5fa;border:1px solid rgba(59,130,246,.3)"><?= e($comp['code']) ?></span> <?php endif; ?>
                  <span style="font-size:13px;font-weight:600;color:white"><?= e($comp['title'] ?? 'Compétence') ?></span>
                </div>
                <div style="flex-shrink:0;display:flex;align-items:center;gap:8px">
                  <?php if ($cTotal>0): ?>
                  <span style="font-size:12px;font-weight:700;color:<?= $cPct===100?'var(--success)':'#60a5fa' ?>"><?= $cPct ?>%</span>
                  <span style="font-size:10px;color:var(--text-faint)"><?= $cDone ?>/<?= $cTotal ?></span>
                  <?php endif; ?>
                  <i class="fas fa-chevron-down" id="chev-comp-<?=$fpId?>-<?=$cId?>" style="color:var(--text-muted);transition:.2s;transform:rotate(-90deg)"></i>
                </div>
              </div>
              <div id="comp-<?=$fpId?>-<?=$cId?>" style="display:none">
                <?php foreach ($comp['seqs'] as $sId => $seq):
                  $sTotal2 = count($seq['modules']);
                  $sDone2  = count(array_filter($seq['modules'], fn($m) => $m['prog_status']==='completed'));
                  $sPct2   = $sTotal2>0?round($sDone2/$sTotal2*100):0;
                ?>
                <div style="border-top:1px solid rgba(255,255,255,.03)">
                  <div style="display:flex;align-items:center;gap:10px;padding:9px 18px 9px 56px;background:var(--bg-elevated);cursor:pointer" onclick="toggleSection('cseq-<?=$fpId?>-<?=$sId?>')">
                    <i class="fas fa-stream" style="color:var(--text-faint);font-size:11px;flex-shrink:0"></i>
                    <span style="flex:1;font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($seq['title']) ?></span>
                    <div style="flex-shrink:0;display:flex;align-items:center;gap:6px">
                      <?php if ($sTotal2>0): ?>
                      <span style="font-size:11px;font-weight:700;color:<?= $sPct2===100?'var(--success)':'var(--text-muted)' ?>"><?= $sPct2 ?>%</span>
                      <span style="font-size:10px;color:var(--text-faint)"><?= $sDone2 ?>/<?= $sTotal2 ?></span>
                      <?php endif; ?>
                      <i class="fas fa-chevron-down" id="chev-cseq-<?=$fpId?>-<?=$sId?>" style="color:var(--text-faint);font-size:10px;transition:.2s;transform:rotate(-90deg)"></i>
                    </div>
                  </div>
                  <div id="cseq-<?=$fpId?>-<?=$sId?>" style="display:none">
                    <?php if (!empty($seq['modules'])): ?>
                    <div style="overflow-x:auto;border-top:1px solid rgba(255,255,255,.03)">
                      <table style="width:100%;border-collapse:collapse;font-size:12px">
                        <thead>
                          <tr style="background:var(--bg-surface)">
                            <th style="padding:6px 12px 6px 68px;text-align:left;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase">Séance / Évaluation</th>
                            <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Statut</th>
                            <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Début</th>
                            <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Fin</th>
                            <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Durée</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($seq['modules'] as $m):
                            $mst = $m['prog_status'] ?? 'not_started';
                          ?>
                          <tr style="border-top:1px solid rgba(255,255,255,.03);background:<?= $mst==='completed'?'rgba(16,185,129,.03)':($mst==='in_progress'?'rgba(99,102,241,.03)':'') ?>">
                            <td style="padding:8px 12px 8px 68px">
                              <div style="display:flex;align-items:center;gap:8px">
                                <i class="<?= getContentTypeIcon($m['content_type']) ?>" style="color:var(--text-faint);font-size:11px;width:12px;flex-shrink:0"></i>
                                <span style="color:<?= $mst==='completed'?'var(--text-muted)':'var(--text)' ?>"><?= e($m['title']) ?></span>
                                <?php if (!$m['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:9px">Opt.</span><?php endif; ?>
                              </div>
                            </td>
                            <td style="padding:8px 12px;text-align:center">
                              <?php if ($mst==='completed'): ?><span class="badge badge-success" style="font-size:10px"><i class="fas fa-check"></i> Validée</span>
                              <?php elseif ($mst==='in_progress'): ?><span class="badge badge-primary" style="font-size:10px"><i class="fas fa-play"></i> En cours</span>
                              <?php else: ?><span class="badge badge-secondary" style="font-size:10px">À faire</span><?php endif; ?>
                            </td>
                            <td style="padding:8px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= $m['started_at'] ? formatDateTime($m['started_at']) : '—' ?></td>
                            <td style="padding:8px 12px;text-align:center;color:<?= $m['completed_at']?'var(--success)':'var(--text-muted)' ?>;white-space:nowrap;font-size:11px"><?= $m['completed_at'] ? formatDateTime($m['completed_at']) : '—' ?></td>
                            <td style="padding:8px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= fmtSeconds((int)($m['time_spent_seconds'] ?? 0)) ?></td>
                          </tr>
                          <?php foreach ($m['quizzes'] as $qz):
                            $qPassed = (bool)$qz['passed'];
                            $qScore  = $qz['teacher_score'] ?? $qz['score'];
                          ?>
                          <tr style="border-top:1px solid rgba(255,255,255,.02);background:rgba(251,191,36,.04)">
                            <td style="padding:7px 12px 7px 80px">
                              <div style="display:flex;align-items:center;gap:8px">
                                <i class="fas fa-question-circle" style="color:var(--warning);font-size:11px;flex-shrink:0"></i>
                                <span style="color:var(--text-muted);font-style:italic"><?= e($qz['qtitle']) ?></span>
                                <?php if ($qPassed): ?><i class="fas fa-trophy" style="color:var(--warning);font-size:10px"></i><?php endif; ?>
                              </div>
                            </td>
                            <td style="padding:7px 12px;text-align:center">
                              <?php if (!$qz['started_at']): ?><span class="badge badge-secondary" style="font-size:10px">Non démarré</span>
                              <?php elseif ($qPassed): ?><span class="badge badge-success" style="font-size:10px">Réussi<?= $qScore!==null?' — '.round($qScore).'%':'' ?></span>
                              <?php elseif ($qz['review_status']==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                              <?php elseif ($qz['completed_at']): ?><span class="badge badge-danger" style="font-size:10px">Échoué<?= $qScore!==null?' — '.round($qScore).'%':'' ?></span>
                              <?php else: ?><span class="badge badge-primary" style="font-size:10px">En cours</span><?php endif; ?>
                            </td>
                            <td style="padding:7px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= $qz['started_at'] ? formatDateTime($qz['started_at']) : '—' ?></td>
                            <td style="padding:7px 12px;text-align:center;color:<?= $qz['completed_at']?($qPassed?'var(--success)':'var(--danger)'):'var(--text-muted)' ?>;white-space:nowrap;font-size:11px"><?= $qz['completed_at'] ? formatDateTime($qz['completed_at']) : '—' ?></td>
                            <td style="padding:7px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= fmtSeconds((int)($qz['time_spent_seconds'] ?? 0)) ?></td>
                          </tr>
                          <?php endforeach; ?>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php else: ?>
                    <div style="padding:10px 14px 10px 68px;color:var(--text-faint);font-size:12px;border-top:1px solid rgba(255,255,255,.03)">Aucune séance dans cette séquence.</div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php foreach ($comp['quizzes'] as $qz):
                  $qPassed2 = (bool)$qz['passed'];
                  $qScore2  = $qz['teacher_score'] ?? $qz['score'];
                ?>
                <div style="border-top:1px solid rgba(255,255,255,.03);padding:9px 18px 9px 56px;display:flex;align-items:center;gap:10px;background:rgba(251,191,36,.03)">
                  <i class="fas fa-question-circle" style="color:var(--warning);font-size:12px;flex-shrink:0"></i>
                  <div style="flex:1;min-width:0">
                    <span style="font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($qz['qtitle']) ?></span>
                    <?php if ($qz['started_at']): ?>
                    <div style="font-size:10px;color:var(--text-faint);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap">
                      <span>Début : <?= formatDateTime($qz['started_at']) ?></span>
                      <?php if ($qz['completed_at']): ?><span>Fin : <?= formatDateTime($qz['completed_at']) ?></span><?php endif; ?>
                      <?php if ($qz['time_spent_seconds']): ?><span>Durée : <?= fmtSeconds((int)$qz['time_spent_seconds']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div style="flex-shrink:0">
                    <?php if (!$qz['started_at']): ?><span class="badge badge-secondary" style="font-size:10px">Non démarré</span>
                    <?php elseif ($qPassed2): ?><span class="badge badge-success" style="font-size:10px"><i class="fas fa-trophy"></i> <?= $qScore2!==null?round($qScore2).'%':'' ?></span>
                    <?php elseif ($qz['review_status']==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                    <?php elseif ($qz['completed_at']): ?><span class="badge badge-danger" style="font-size:10px"><?= $qScore2!==null?round($qScore2).'%':'Échoué' ?></span>
                    <?php else: ?><span class="badge badge-primary" style="font-size:10px">En cours</span><?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
                <?php foreach ($comp['case_studies'] as $cs): $csStatus = $cs['status'] ?? 'pending'; ?>
                <div style="border-top:1px solid rgba(255,255,255,.03);padding:9px 18px 9px 56px;display:flex;align-items:center;gap:10px;background:rgba(16,185,129,.02)">
                  <i class="fas fa-file-alt" style="color:var(--success);font-size:12px;flex-shrink:0"></i>
                  <div style="flex:1;min-width:0">
                    <span style="font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($cs['cs_title']) ?></span>
                    <?php if ($cs['submitted_at']): ?>
                    <div style="font-size:10px;color:var(--text-faint);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap">
                      <span>Soumis : <?= formatDateTime($cs['submitted_at']) ?></span>
                      <?php if ($cs['graded_at']): ?><span>Corrigé : <?= formatDateTime($cs['graded_at']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div style="flex-shrink:0">
                    <?php if ($csStatus==='graded'): ?><span class="badge badge-success" style="font-size:10px"><?= $cs['score']!==null?$cs['score'].'/'.$cs['max_score']:'Corrigé' ?></span>
                    <?php elseif ($csStatus==='submitted'): ?><span class="badge badge-primary" style="font-size:10px">Soumis</span>
                    <?php elseif ($csStatus==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                    <?php else: ?><span class="badge badge-secondary" style="font-size:10px">Non soumis</span><?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div><!-- /rncp block -->
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($enrollments as $enr):
    $fId   = $enr['f_id'];
    $fData = $formationsData[$fId];
    $prog  = $enr['progress_percent'];
    $isDone = $enr['status'] === 'completed';
  ?>
  <!-- ═══════════ FORMATION ═══════════ -->
  <div class="card" style="margin-bottom:28px">

    <!-- Header formation -->
    <div class="card-header" style="background:rgba(99,102,241,.07);padding:16px 20px">
      <div style="display:flex;align-items:flex-start;gap:16px;flex:1;min-width:0">
        <div style="flex:1">
          <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap">
            <?php if ($enr['rncp_code']): ?><span class="badge badge-primary"><?= e($enr['rncp_code']) ?></span><?php endif; ?>
            <?php if ($enr['rncp_level']): ?><span class="badge badge-secondary">Niv.<?= e($enr['rncp_level']) ?></span><?php endif; ?>
            <?= getStatusBadge($enr['status']) ?>
          </div>
          <div style="font-size:17px;font-weight:800;color:white"><?= e($enr['f_title']) ?></div>
          <?php if ($enr['rncp_title']): ?><div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e($enr['rncp_title']) ?></div><?php endif; ?>
        </div>
        <div style="flex-shrink:0;text-align:right;min-width:120px">
          <div style="font-size:26px;font-weight:900;color:<?= $isDone?'var(--success)':'var(--primary-light)' ?>"><?= $prog ?>%</div>
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:8px">Inscrit le <?= formatDate($enr['enrolled_at']) ?></div>
          <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:rgba(239,68,68,.3);font-size:11px"
            onclick="document.getElementById('modal-reset-<?= $fId ?>').style.display='flex'">
            <i class="fas fa-redo"></i> Réinitialiser
          </button>
        </div>
      </div>
    </div>

    <!-- Barre progression -->
    <div style="height:6px;background:var(--bg-elevated)">
      <div style="height:100%;width:<?= $prog ?>%;background:<?= $isDone?'var(--success)':'var(--primary)' ?>;transition:width .4s"></div>
    </div>

    <div style="padding:20px;display:flex;flex-direction:column;gap:24px">

      <!-- ── BLOCS RNCP ── -->
      <?php if (!empty($fData['blocs'])): ?>
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-layer-group" style="margin-right:6px"></i>Blocs RNCP & Compétences</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
          <?php foreach ($fData['blocs'] as $bloc):
            $bComps = $fData['compsByBloc'][$bloc['id']] ?? [];
            // Calculer les modules rattachés à ce bloc et leur progression
            $blocModules = array_filter($fData['modules'], fn($m) => $m['activity_type_id'] == $bloc['id']);
            $blocDone = 0; $blocTotal = 0;
            foreach ($blocModules as $bm) {
              $s = $fData['modStats'][$bm['id']] ?? ['done'=>0,'total'=>0];
              $blocDone += $s['done']; $blocTotal += $s['total'];
            }
            $blocPct = $blocTotal > 0 ? round($blocDone/$blocTotal*100) : 0;
          ?>
          <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:14px;border:1px solid var(--border)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
              <div>
                <span class="badge badge-primary" style="font-size:10px"><?= e($bloc['code']) ?></span>
                <div style="font-size:13px;font-weight:700;margin-top:4px;color:white"><?= e(mb_substr($bloc['title'],0,45)) ?><?= strlen($bloc['title'])>45?'…':'' ?></div>
              </div>
              <div style="font-size:16px;font-weight:800;color:<?= $blocPct===100?'var(--success)':'var(--primary-light)' ?>;flex-shrink:0;margin-left:8px"><?= $blocTotal>0?$blocPct.'%':'—' ?></div>
            </div>
            <?php if ($blocTotal > 0): ?>
            <div style="height:4px;background:var(--bg-hover);border-radius:99px;margin-bottom:8px">
              <div style="height:100%;width:<?= $blocPct ?>%;background:<?= $blocPct===100?'var(--success)':'var(--primary)' ?>;border-radius:99px"></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($bComps)): ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
              <?php foreach ($bComps as $bc): ?>
              <span style="font-size:10px;padding:2px 8px;background:rgba(99,102,241,.12);border-radius:99px;color:var(--primary-light);border:1px solid rgba(99,102,241,.2)"><?= e($bc['code']) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ── ARBORESCENCE RNCP ── -->
      <?php if (!empty($fData['rncpTree'])): ?>
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-sitemap" style="margin-right:6px"></i>Arborescence RNCP</div>

        <?php foreach ($fData['rncpTree'] as $rId => $rncp):
          $rTotal=0; $rDone=0;
          foreach ($rncp['ats'] as $at2) foreach ($at2['comps'] as $c2) foreach ($c2['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $rTotal++; if ($m2['prog_status']==='completed') $rDone++; }
          $rPct = $rTotal>0?round($rDone/$rTotal*100):0;
        ?>
        <div style="margin-bottom:14px;border:1px solid rgba(99,102,241,.3);border-radius:var(--radius-lg);overflow:hidden">

          <!-- Niveau RNCP -->
          <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:rgba(99,102,241,.12);cursor:pointer" onclick="toggleSection('rncp-<?=$fId?>-<?=$rId?>')">
            <i class="fas fa-graduation-cap" style="color:var(--primary-light);font-size:15px;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
              <?php if ($rncp['code']): ?><span class="badge badge-primary" style="font-size:10px;margin-bottom:4px"><?= e($rncp['code']) ?></span> <?php endif; ?>
              <span style="font-size:14px;font-weight:800;color:white"><?= e($rncp['title'] ?? 'Titre RNCP') ?></span>
            </div>
            <div style="flex-shrink:0;display:flex;align-items:center;gap:10px">
              <?php if ($rTotal>0): ?>
              <div style="text-align:right">
                <div style="font-size:15px;font-weight:800;color:<?= $rPct===100?'var(--success)':'var(--primary-light)' ?>"><?= $rPct ?>%</div>
                <div style="font-size:10px;color:var(--text-faint)"><?= $rDone ?>/<?= $rTotal ?> séances</div>
              </div>
              <?php endif; ?>
              <i class="fas fa-chevron-down" id="chev-rncp-<?=$fId?>-<?=$rId?>" style="color:var(--text-muted);transition:.2s"></i>
            </div>
          </div>

          <div id="rncp-<?=$fId?>-<?=$rId?>">
            <?php foreach ($rncp['ats'] as $atId => $at):
              $atTotal=0; $atDone=0;
              foreach ($at['comps'] as $c2) foreach ($c2['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $atTotal++; if ($m2['prog_status']==='completed') $atDone++; }
              $atPct = $atTotal>0?round($atDone/$atTotal*100):0;
            ?>
            <!-- Niveau AT -->
            <div style="border-top:1px solid var(--border)">
              <div style="display:flex;align-items:center;gap:12px;padding:12px 18px 12px 30px;background:rgba(139,92,246,.07);cursor:pointer" onclick="toggleSection('at-<?=$fId?>-<?=$atId?>')">
                <i class="fas fa-layer-group" style="color:#a78bfa;font-size:13px;flex-shrink:0"></i>
                <div style="flex:1;min-width:0">
                  <?php if ($at['code']): ?><span class="badge badge-secondary" style="font-size:10px"><?= e($at['code']) ?></span> <?php endif; ?>
                  <span style="font-size:13px;font-weight:700;color:white"><?= e($at['title'] ?? 'Activité Type') ?></span>
                </div>
                <div style="flex-shrink:0;display:flex;align-items:center;gap:8px">
                  <?php if ($atTotal>0): ?>
                  <span style="font-size:13px;font-weight:700;color:<?= $atPct===100?'var(--success)':'#a78bfa' ?>"><?= $atPct ?>%</span>
                  <span style="font-size:10px;color:var(--text-faint)"><?= $atDone ?>/<?= $atTotal ?></span>
                  <?php endif; ?>
                  <i class="fas fa-chevron-down" id="chev-at-<?=$fId?>-<?=$atId?>" style="color:var(--text-muted);transition:.2s"></i>
                </div>
              </div>

              <div id="at-<?=$fId?>-<?=$atId?>">
                <?php foreach ($at['comps'] as $cId => $comp):
                  $cTotal=0; $cDone=0;
                  foreach ($comp['seqs'] as $s2) foreach ($s2['modules'] as $m2) { $cTotal++; if ($m2['prog_status']==='completed') $cDone++; }
                  $cPct = $cTotal>0?round($cDone/$cTotal*100):0;
                ?>
                <!-- Niveau Compétence -->
                <div style="border-top:1px solid rgba(255,255,255,.04)">
                  <div style="display:flex;align-items:center;gap:10px;padding:10px 18px 10px 44px;background:rgba(59,130,246,.04);cursor:pointer" onclick="toggleSection('comp-<?=$fId?>-<?=$cId?>')">
                    <i class="fas fa-bullseye" style="color:#60a5fa;font-size:12px;flex-shrink:0"></i>
                    <div style="flex:1;min-width:0">
                      <?php if ($comp['code']): ?><span style="font-size:10px;padding:1px 6px;background:rgba(59,130,246,.15);border-radius:99px;color:#60a5fa;border:1px solid rgba(59,130,246,.3)"><?= e($comp['code']) ?></span> <?php endif; ?>
                      <span style="font-size:13px;font-weight:600;color:white"><?= e($comp['title'] ?? 'Compétence') ?></span>
                    </div>
                    <div style="flex-shrink:0;display:flex;align-items:center;gap:8px">
                      <?php if ($cTotal>0): ?>
                      <span style="font-size:12px;font-weight:700;color:<?= $cPct===100?'var(--success)':'#60a5fa' ?>"><?= $cPct ?>%</span>
                      <span style="font-size:10px;color:var(--text-faint)"><?= $cDone ?>/<?= $cTotal ?></span>
                      <?php endif; ?>
                      <?php if (!empty($comp['case_studies'])): ?><span class="badge badge-secondary" style="font-size:9px"><?= count($comp['case_studies']) ?> ét. de cas</span><?php endif; ?>
                      <?php if (!empty($comp['quizzes'])): ?><span class="badge badge-secondary" style="font-size:9px"><?= count($comp['quizzes']) ?> quiz</span><?php endif; ?>
                      <i class="fas fa-chevron-down" id="chev-comp-<?=$fId?>-<?=$cId?>" style="color:var(--text-muted);transition:.2s;transform:rotate(-90deg)"></i>
                    </div>
                  </div>

                  <div id="comp-<?=$fId?>-<?=$cId?>" style="display:none">
                    <?php foreach ($comp['seqs'] as $sId => $seq):
                      $sTotal2 = count($seq['modules']);
                      $sDone2  = count(array_filter($seq['modules'], fn($m) => $m['prog_status']==='completed'));
                      $sPct2   = $sTotal2>0?round($sDone2/$sTotal2*100):0;
                    ?>
                    <!-- Niveau Séquence -->
                    <div style="border-top:1px solid rgba(255,255,255,.03)">
                      <div style="display:flex;align-items:center;gap:10px;padding:9px 18px 9px 56px;background:var(--bg-elevated);cursor:pointer" onclick="toggleSection('cseq-<?=$fId?>-<?=$sId?>')">
                        <i class="fas fa-stream" style="color:var(--text-faint);font-size:11px;flex-shrink:0"></i>
                        <span style="flex:1;font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($seq['title']) ?></span>
                        <div style="flex-shrink:0;display:flex;align-items:center;gap:6px">
                          <?php if ($sTotal2>0): ?>
                          <span style="font-size:11px;font-weight:700;color:<?= $sPct2===100?'var(--success)':'var(--text-muted)' ?>"><?= $sPct2 ?>%</span>
                          <span style="font-size:10px;color:var(--text-faint)"><?= $sDone2 ?>/<?= $sTotal2 ?></span>
                          <?php endif; ?>
                          <i class="fas fa-chevron-down" id="chev-cseq-<?=$fId?>-<?=$sId?>" style="color:var(--text-faint);font-size:10px;transition:.2s;transform:rotate(-90deg)"></i>
                        </div>
                      </div>

                      <div id="cseq-<?=$fId?>-<?=$sId?>" style="display:none">
                        <?php if (!empty($seq['modules'])): ?>
                        <div style="overflow-x:auto;border-top:1px solid rgba(255,255,255,.03)">
                          <table style="width:100%;border-collapse:collapse;font-size:12px">
                            <thead>
                              <tr style="background:var(--bg-surface)">
                                <th style="padding:6px 12px 6px 68px;text-align:left;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;letter-spacing:.05em">Séance / Évaluation</th>
                                <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Statut</th>
                                <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Début</th>
                                <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Fin</th>
                                <th style="padding:6px 12px;text-align:center;font-size:10px;font-weight:700;color:var(--text-faint);text-transform:uppercase;white-space:nowrap">Durée</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($seq['modules'] as $m):
                                $mst = $m['prog_status'] ?? 'not_started';
                              ?>
                              <tr style="border-top:1px solid rgba(255,255,255,.03);background:<?= $mst==='completed'?'rgba(16,185,129,.03)':($mst==='in_progress'?'rgba(99,102,241,.03)':'') ?>">
                                <td style="padding:8px 12px 8px 68px">
                                  <div style="display:flex;align-items:center;gap:8px">
                                    <i class="<?= getContentTypeIcon($m['content_type']) ?>" style="color:var(--text-faint);font-size:11px;width:12px;flex-shrink:0"></i>
                                    <span style="color:<?= $mst==='completed'?'var(--text-muted)':'var(--text)' ?>"><?= e($m['title']) ?></span>
                                    <?php if (!$m['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:9px">Opt.</span><?php endif; ?>
                                  </div>
                                </td>
                                <td style="padding:8px 12px;text-align:center">
                                  <?php if ($mst==='completed'): ?><span class="badge badge-success" style="font-size:10px"><i class="fas fa-check"></i> Validée</span>
                                  <?php elseif ($mst==='in_progress'): ?><span class="badge badge-primary" style="font-size:10px"><i class="fas fa-play"></i> En cours</span>
                                  <?php else: ?><span class="badge badge-secondary" style="font-size:10px">À faire</span><?php endif; ?>
                                </td>
                                <td style="padding:8px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= $m['started_at'] ? formatDateTime($m['started_at']) : '—' ?></td>
                                <td style="padding:8px 12px;text-align:center;color:<?= $m['completed_at']?'var(--success)':'var(--text-muted)' ?>;white-space:nowrap;font-size:11px"><?= $m['completed_at'] ? formatDateTime($m['completed_at']) : '—' ?></td>
                                <td style="padding:8px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= fmtSeconds((int)($m['time_spent_seconds'] ?? 0)) ?></td>
                              </tr>
                              <?php foreach ($m['quizzes'] as $qz):
                                $qPassed = (bool)$qz['passed'];
                                $qScore  = $qz['teacher_score'] ?? $qz['score'];
                              ?>
                              <tr style="border-top:1px solid rgba(255,255,255,.02);background:rgba(251,191,36,.04)">
                                <td style="padding:7px 12px 7px 80px">
                                  <div style="display:flex;align-items:center;gap:8px">
                                    <i class="fas fa-question-circle" style="color:var(--warning);font-size:11px;flex-shrink:0"></i>
                                    <span style="color:var(--text-muted);font-style:italic"><?= e($qz['qtitle']) ?></span>
                                    <?php if ($qPassed): ?><i class="fas fa-trophy" style="color:var(--warning);font-size:10px"></i><?php endif; ?>
                                  </div>
                                </td>
                                <td style="padding:7px 12px;text-align:center">
                                  <?php if (!$qz['started_at']): ?><span class="badge badge-secondary" style="font-size:10px">Non démarré</span>
                                  <?php elseif ($qPassed): ?><span class="badge badge-success" style="font-size:10px">Réussi<?= $qScore!==null?' — '.round($qScore).'%':'' ?></span>
                                  <?php elseif ($qz['review_status']==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                                  <?php elseif ($qz['completed_at']): ?><span class="badge badge-danger" style="font-size:10px">Échoué<?= $qScore!==null?' — '.round($qScore).'%':'' ?></span>
                                  <?php else: ?><span class="badge badge-primary" style="font-size:10px">En cours</span><?php endif; ?>
                                </td>
                                <td style="padding:7px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= $qz['started_at'] ? formatDateTime($qz['started_at']) : '—' ?></td>
                                <td style="padding:7px 12px;text-align:center;color:<?= $qz['completed_at']?($qPassed?'var(--success)':'var(--danger)'):'var(--text-muted)' ?>;white-space:nowrap;font-size:11px"><?= $qz['completed_at'] ? formatDateTime($qz['completed_at']) : '—' ?></td>
                                <td style="padding:7px 12px;text-align:center;color:var(--text-muted);white-space:nowrap;font-size:11px"><?= fmtSeconds((int)($qz['time_spent_seconds'] ?? 0)) ?></td>
                              </tr>
                              <?php endforeach; ?>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                        <?php else: ?>
                        <div style="padding:10px 14px 10px 68px;color:var(--text-faint);font-size:12px;border-top:1px solid rgba(255,255,255,.03)">Aucune séance dans cette séquence.</div>
                        <?php endif; ?>
                      </div><!-- /cseq body -->
                    </div><!-- /séquence -->
                    <?php endforeach; ?>

                    <!-- Quiz de compétence -->
                    <?php foreach ($comp['quizzes'] as $qz):
                      $qPassed2 = (bool)$qz['passed'];
                      $qScore2  = $qz['teacher_score'] ?? $qz['score'];
                    ?>
                    <div style="border-top:1px solid rgba(255,255,255,.03);padding:9px 18px 9px 56px;display:flex;align-items:center;gap:10px;background:rgba(251,191,36,.03)">
                      <i class="fas fa-question-circle" style="color:var(--warning);font-size:12px;flex-shrink:0"></i>
                      <div style="flex:1;min-width:0">
                        <span style="font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($qz['qtitle']) ?></span>
                        <?php if ($qz['started_at']): ?>
                        <div style="font-size:10px;color:var(--text-faint);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap">
                          <span>Début : <?= formatDateTime($qz['started_at']) ?></span>
                          <?php if ($qz['completed_at']): ?><span>Fin : <?= formatDateTime($qz['completed_at']) ?></span><?php endif; ?>
                          <?php if ($qz['time_spent_seconds']): ?><span>Durée : <?= fmtSeconds((int)$qz['time_spent_seconds']) ?></span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div style="flex-shrink:0">
                        <?php if (!$qz['started_at']): ?><span class="badge badge-secondary" style="font-size:10px">Non démarré</span>
                        <?php elseif ($qPassed2): ?><span class="badge badge-success" style="font-size:10px"><i class="fas fa-trophy"></i> <?= $qScore2!==null?round($qScore2).'%':'' ?></span>
                        <?php elseif ($qz['review_status']==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                        <?php elseif ($qz['completed_at']): ?><span class="badge badge-danger" style="font-size:10px"><?= $qScore2!==null?round($qScore2).'%':'Échoué' ?></span>
                        <?php else: ?><span class="badge badge-primary" style="font-size:10px">En cours</span><?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Études de cas -->
                    <?php foreach ($comp['case_studies'] as $cs):
                      $csStatus = $cs['status'] ?? 'pending';
                    ?>
                    <div style="border-top:1px solid rgba(255,255,255,.03);padding:9px 18px 9px 56px;display:flex;align-items:center;gap:10px;background:rgba(16,185,129,.02)">
                      <i class="fas fa-file-alt" style="color:var(--success);font-size:12px;flex-shrink:0"></i>
                      <div style="flex:1;min-width:0">
                        <span style="font-size:12px;font-weight:600;color:var(--text-muted)"><?= e($cs['cs_title']) ?></span>
                        <?php if ($cs['submitted_at']): ?>
                        <div style="font-size:10px;color:var(--text-faint);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap">
                          <span>Soumis : <?= formatDateTime($cs['submitted_at']) ?></span>
                          <?php if ($cs['graded_at']): ?><span>Corrigé : <?= formatDateTime($cs['graded_at']) ?></span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div style="flex-shrink:0">
                        <?php if ($csStatus==='graded'): ?><span class="badge badge-success" style="font-size:10px"><?= $cs['score']!==null?$cs['score'].'/'.$cs['max_score']:'Corrigé' ?></span>
                        <?php elseif ($csStatus==='submitted'): ?><span class="badge badge-primary" style="font-size:10px">Soumis</span>
                        <?php elseif ($csStatus==='returned'): ?><span class="badge badge-warning" style="font-size:10px">Retourné</span>
                        <?php else: ?><span class="badge badge-secondary" style="font-size:10px">Non soumis</span><?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>

                  </div><!-- /comp body -->
                </div><!-- /comp block -->
                <?php endforeach; ?><!-- /comps -->
              </div><!-- /at body -->
            </div><!-- /at block -->
            <?php endforeach; ?><!-- /ats -->
          </div><!-- /rncp body -->
        </div><!-- /rncp block -->
        <?php endforeach; ?><!-- /rncpTree -->
      </div>
      <?php endif; ?>

      <!-- ── SÉQUENCES & SÉANCES ── -->
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-cubes" style="margin-right:6px"></i>Séquences & Séances</div>

        <?php if (empty($fData['sequences']) && empty($fData['freeModules'])): ?>
        <div style="color:var(--text-faint);font-size:13px;padding:16px">Aucune séquence ni séance dans cette formation.</div>
        <?php endif; ?>

        <?php foreach ($fData['sequences'] as $i => $seq):
          $seqModules = $fData['modulesBySeq'][$seq['id']] ?? [];
          $seqQuizzes = $fData['quizzesByMod'][$seq['id']] ?? [];
          $sStats     = $fData['seqStats'][$seq['id']] ?? ['pct'=>0,'done'=>0,'total'=>0];
          $sDone      = $sStats['pct'] === 100 && $sStats['total'] > 0;
        ?>
        <div style="margin-bottom:12px;border:1px solid <?= $sDone?'rgba(16,185,129,.3)':'var(--border)' ?>;border-radius:var(--radius-lg);overflow:hidden">

          <!-- En-tête séquence -->
          <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:<?= $sDone?'rgba(16,185,129,.06)':'var(--bg-elevated)' ?>;cursor:pointer" onclick="toggleSection('seq-<?= $fId ?>-<?= $seq['id'] ?>')">
            <div style="width:30px;height:30px;border-radius:50%;background:<?= $sDone?'var(--success)':'var(--primary)' ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0">
              <?= $sDone ? '<i class="fas fa-check" style="font-size:11px"></i>' : ($i+1) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:700"><?= e($seq['title']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap;margin-top:2px">
                <?php if ($seq['at_title']): ?><span><i class="fas fa-layer-group"></i> <?= e($seq['at_code']) ?> — <?= e(mb_substr($seq['at_title'],0,35)) ?></span><?php endif; ?>
                <?php if ($seq['comp_title']): ?><span><i class="fas fa-bullseye"></i> <?= e($seq['comp_code']) ?> <?= e(mb_substr($seq['comp_title'],0,30)) ?></span><?php endif; ?>
              </div>
            </div>
            <div style="flex-shrink:0;display:flex;align-items:center;gap:10px">
              <div style="text-align:right">
                <div style="font-size:14px;font-weight:800;color:<?= $sDone?'var(--success)':'var(--primary-light)' ?>"><?= $sStats['pct'] ?>%</div>
                <div style="font-size:10px;color:var(--text-faint)"><?= $sStats['done'] ?>/<?= $sStats['total'] ?> séance(s)</div>
              </div>
              <i class="fas fa-chevron-down" id="chev-<?= $fId ?>-seq-<?= $seq['id'] ?>" style="color:var(--text-muted);transition:.2s"></i>
            </div>
          </div>

          <!-- Corps séquence : séances -->
          <div id="seq-<?= $fId ?>-<?= $seq['id'] ?>">
            <?php if (!empty($seqModules)): ?>
            <div style="overflow-x:auto;border-top:1px solid var(--border)">
              <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                  <tr style="background:var(--bg-surface)">
                    <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">#</th>
                    <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Séance</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Type</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Durée</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Statut</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Date validation</th>
                    <th style="padding:8px 14px;text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">XP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($seqModules as $j => $m):
                    $st = $m['prog_status'] ?? 'not_started';
                    $durLabel = $m['duration_hours'] ? round($m['duration_hours'] * 60) . ' min' : '—';
                  ?>
                  <tr style="border-top:1px solid rgba(255,255,255,.04);background:<?= $st==='completed'?'rgba(16,185,129,.03)':($st==='in_progress'?'rgba(99,102,241,.03)':'') ?>">
                    <td style="padding:9px 14px;color:var(--text-faint)"><?= $j+1 ?></td>
                    <td style="padding:9px 14px">
                      <div style="display:flex;align-items:center;gap:8px">
                        <i class="<?= getContentTypeIcon($m['content_type']) ?>" style="color:var(--text-muted);width:14px;flex-shrink:0"></i>
                        <span style="font-weight:<?= $st==='completed'?'500':'600' ?>;color:<?= $st==='completed'?'var(--text-muted)':'var(--text)' ?>"><?= e($m['title']) ?></span>
                        <?php if (!$m['is_mandatory']): ?><span class="badge badge-secondary" style="font-size:10px">Opt.</span><?php endif; ?>
                      </div>
                    </td>
                    <td style="padding:9px 14px;text-align:center"><span class="badge badge-secondary" style="font-size:10px"><?= ucfirst($m['content_type'] ?? 'N/A') ?></span></td>
                    <td style="padding:9px 14px;text-align:center;color:var(--text-muted)"><?= $durLabel ?></td>
                    <td style="padding:9px 14px;text-align:center">
                      <?php if ($st==='completed'): ?>
                        <span class="badge badge-success"><i class="fas fa-check"></i> Validée</span>
                      <?php elseif ($st==='in_progress'): ?>
                        <span class="badge badge-primary"><i class="fas fa-play"></i> En cours</span>
                      <?php else: ?>
                        <span class="badge badge-secondary">À faire</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:9px 14px;text-align:center;color:var(--text-muted)">
                      <?= $m['completed_at'] ? '<span style="color:var(--success)">'.formatDate($m['completed_at']).'</span>' : ($m['started_at'] ? formatDate($m['started_at']) : '—') ?>
                    </td>
                    <td style="padding:9px 14px;text-align:center">
                      <?php if ($st==='completed'): ?><span style="color:var(--warning);font-weight:700">+<?= $m['xp_reward'] ?></span><?php else: ?><span style="color:var(--text-faint)"><?= $m['xp_reward'] ?></span><?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <?php if (empty($seqModules)): ?>
            <div style="padding:16px;text-align:center;color:var(--text-faint);font-size:13px;border-top:1px solid var(--border)">Aucune séance dans cette séquence.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($fData['freeModules'])): ?>
        <div style="margin-bottom:12px;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
          <div style="padding:10px 16px;background:var(--bg-elevated);font-size:13px;font-weight:600;color:var(--text-muted)"><i class="fas fa-layer-group"></i> Séances sans séquence</div>
          <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
              <tbody>
                <?php foreach ($fData['freeModules'] as $j => $m):
                  $st = $m['prog_status'] ?? 'not_started';
                ?>
                <tr style="border-top:1px solid rgba(255,255,255,.04);background:<?= $st==='completed'?'rgba(16,185,129,.03)':'' ?>">
                  <td style="padding:9px 14px;width:30px;color:var(--text-faint)"><?= $j+1 ?></td>
                  <td style="padding:9px 14px"><span style="font-weight:600"><?= e($m['title']) ?></span></td>
                  <td style="padding:9px 14px;text-align:center">
                    <?php if ($st==='completed'): ?><span class="badge badge-success"><i class="fas fa-check"></i> Validée</span>
                    <?php elseif ($st==='in_progress'): ?><span class="badge badge-primary"><i class="fas fa-play"></i> En cours</span>
                    <?php else: ?><span class="badge badge-secondary">À faire</span><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Quiz de formation (standalone) ── -->
      <?php if (!empty($fData['standaloneQuizzes'])): ?>
      <div>
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:10px"><i class="fas fa-file-alt" style="margin-right:6px"></i>Quiz de la formation</div>
        <?php foreach ($fData['standaloneQuizzes'] as $qz): ?>
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:var(--radius-lg);background:<?= $qz['is_passed']?'rgba(16,185,129,.08)':($qz['used_attempts']>0?'rgba(239,68,68,.06)':'var(--bg-elevated)') ?>;border:1px solid <?= $qz['is_passed']?'rgba(16,185,129,.3)':($qz['used_attempts']>0?'rgba(239,68,68,.2)':'var(--border)') ?>;margin-bottom:8px">
          <div style="font-size:28px"><?= $qz['is_passed'] ? '🏆' : ($qz['used_attempts'] > 0 ? '🔄' : '📋') ?></div>
          <div style="flex:1">
            <div style="font-size:15px;font-weight:700"><?= e($qz['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap;margin-top:3px">
              <span>Seuil : <?= $qz['passing_score'] ?>%</span>
              <?php if ($qz['best_score'] !== null): ?><span>Meilleur : <strong style="color:<?= $qz['is_passed']?'var(--success)':'var(--danger)' ?>"><?= round($qz['best_score']) ?>%</strong></span><?php endif; ?>
              <span><?= $qz['used_attempts'] ?>/<?= $qz['max_attempts'] ?> tentative(s)</span>
              <?php if ($qz['last_attempt_date']): ?><span>Dernier : <?= formatDate($qz['last_attempt_date']) ?></span><?php endif; ?>
              <span style="color:var(--warning)"><i class="fas fa-bolt"></i> <?= $qz['xp_reward'] ?> XP</span>
            </div>
          </div>
          <?php if ($qz['used_attempts'] === 0): ?>
            <span class="badge badge-secondary">Non démarré</span>
          <?php elseif ($qz['is_passed']): ?>
            <span class="badge badge-success"><i class="fas fa-trophy"></i> Réussi</span>
          <?php elseif ($qz['used_attempts'] >= $qz['max_attempts']): ?>
            <span class="badge badge-danger">Tentatives épuisées</span>
          <?php else: ?>
            <span class="badge badge-warning">Échoué — <?= $qz['max_attempts']-$qz['used_attempts'] ?> restante(s)</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /padding formation -->
  </div><!-- /card formation -->
  <?php endforeach; ?>

</div>

<!-- Modal : réinitialiser tout -->
<?php if (!empty($enrollments)): ?>
<div id="modal-reset-all" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:460px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title" style="color:var(--danger)"><i class="fas fa-exclamation-triangle"></i> Réinitialiser tout le parcours</h3>
      <button onclick="document.getElementById('modal-reset-all').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <p style="color:var(--text-muted);margin-bottom:12px">
        Cette action va <strong style="color:var(--danger)">tout supprimer</strong> pour <strong><?= $name ?></strong> :
      </p>
      <ul style="font-size:13px;color:var(--text-muted);margin:0 0 16px 18px;line-height:1.8">
        <li>Progressions de capsules (toutes formations)</li>
        <li>Tentatives de quiz et réponses</li>
        <li>Tous les points XP (remis à 0)</li>
        <li>Tous les badges obtenus</li>
        <li>Niveau remis à 1</li>
      </ul>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--danger);margin-bottom:20px">
        <i class="fas fa-exclamation-circle"></i> Action irréversible — à utiliser uniquement en test/debug.
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-reset-all').style.display='none'">Annuler</button>
        <form method="POST" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset_all">
          <button type="submit" class="btn" style="background:var(--danger);color:white"><i class="fas fa-redo"></i> Tout réinitialiser</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modals : réinitialiser par formation -->
<?php foreach ($enrollments as $enr): ?>
<div id="modal-reset-<?= $enr['f_id'] ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="width:460px;max-width:95vw">
    <div class="card-header">
      <h3 class="card-title" style="color:var(--danger)"><i class="fas fa-redo"></i> Réinitialiser le parcours</h3>
      <button onclick="document.getElementById('modal-reset-<?= $enr['f_id'] ?>').style.display='none'" class="btn-icon"><i class="fas fa-times"></i></button>
    </div>
    <div class="card-body">
      <p style="color:var(--text-muted);margin-bottom:12px">
        Réinitialiser la progression de <strong><?= $name ?></strong> sur la formation :
      </p>
      <div style="background:var(--bg-elevated);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-weight:700">
        <?= e($enr['f_title']) ?>
      </div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Seront supprimés :</p>
      <ul style="font-size:13px;color:var(--text-muted);margin:0 0 16px 18px;line-height:1.8">
        <li>Toutes les progressions de capsules</li>
        <li>Toutes les tentatives de quiz et leurs réponses</li>
        <li>Les points XP gagnés sur cette formation</li>
        <li>Les badges obtenus via cette formation</li>
        <li>Le pourcentage de progression (remis à 0)</li>
      </ul>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--danger);margin-bottom:20px">
        <i class="fas fa-exclamation-circle"></i> Action irréversible — à utiliser uniquement en test/debug.
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-reset-<?= $enr['f_id'] ?>').style.display='none'">Annuler</button>
        <form method="POST" style="margin:0">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reset_formation">
          <input type="hidden" name="formation_id" value="<?= $enr['f_id'] ?>">
          <button type="submit" class="btn" style="background:var(--danger);color:white"><i class="fas fa-redo"></i> Réinitialiser</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
function toggleSection(id) {
  const el = document.getElementById(id);
  const chevId = 'chev-' + id.replace('seq-','');
  const chev = document.getElementById(chevId);
  if (!el) return;
  const open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (chev) chev.style.transform = open ? 'rotate(-90deg)' : '';
}

// Fermer les modales en cliquant à l'extérieur
document.querySelectorAll('[id^="modal-reset"]').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
</script>
<?php renderFooter(); ?>
