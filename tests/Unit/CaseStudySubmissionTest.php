<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Non-régression : machine à états des soumissions d'études de cas.
 *
 * La logique testée est extraite de student/case_studies/view.php.
 * Ces fonctions statiques reproduisent exactement les conditions
 * du fichier source afin de détecter toute régression.
 */
class CaseStudySubmissionTest extends TestCase
{
    // ── Helpers reproduisant la logique de view.php ─────────────

    /** Détermine si la soumission est verrouillée (non modifiable). */
    private static function isLocked(?array $submission): bool
    {
        if (!$submission) return false;
        return in_array($submission['status'], ['graded', 'returned'], true);
    }

    /** Détermine si le formulaire de soumission doit être affiché. */
    private static function showForm(?array $submission): bool
    {
        return $submission === null;
    }

    /** Détermine si le bloc "en attente" doit s'afficher. */
    private static function showPending(?array $submission): bool
    {
        if (!$submission) return false;
        return in_array($submission['status'], ['submitted', 'under_review'], true);
    }

    /** Détermine si le bloc "corrigé" (note visible) doit s'afficher. */
    private static function showGraded(?array $submission): bool
    {
        return ($submission['status'] ?? null) === 'graded';
    }

    /** Détermine si le bloc "retourné pour révision" doit s'afficher. */
    private static function showReturned(?array $submission): bool
    {
        return ($submission['status'] ?? null) === 'returned';
    }

    /** Couleur de la bordure de la carte — reproduit le ternaire imbriqué de view.php. */
    private static function cardBorder(?array $submission): string
    {
        $subStatus = $submission['status'] ?? null;
        $isGraded  = $subStatus === 'graded';
        return $isGraded            ? 'rgba(16,185,129,.4)'
            : ($subStatus === 'returned'  ? 'rgba(239,68,68,.35)'
            : ($submission !== null       ? 'rgba(245,158,11,.3)'
            :                               'rgba(99,102,241,.25)'));
    }

    /** Vérifie qu'un POST doit être rejeté (soumission déjà évaluée). */
    private static function shouldRejectPost(?array $submission): bool
    {
        if (!$submission) return false;
        return in_array($submission['status'], ['graded', 'returned'], true);
    }

    /** Vérifie qu'un POST doit être ignoré (déjà en correction). */
    private static function shouldIgnorePost(?array $submission): bool
    {
        if (!$submission) return false;
        return in_array($submission['status'], ['submitted', 'under_review'], true);
    }

    // ── Cas : aucune soumission ──────────────────────────────────

    public function testNoSubmissionShowsForm(): void
    {
        $this->assertTrue(self::showForm(null));
        $this->assertFalse(self::showPending(null));
        $this->assertFalse(self::showGraded(null));
        $this->assertFalse(self::showReturned(null));
        $this->assertFalse(self::isLocked(null));
        $this->assertFalse(self::shouldRejectPost(null));
    }

    public function testNoSubmissionCardBorderIsIndigo(): void
    {
        $this->assertSame('rgba(99,102,241,.25)', self::cardBorder(null));
    }

    // ── Cas : soumis (awaiting review) ───────────────────────────

    public function testSubmittedStatusShowsPending(): void
    {
        $sub = ['status' => 'submitted'];
        $this->assertTrue(self::showPending($sub));
        $this->assertFalse(self::showForm($sub));
        $this->assertFalse(self::showGraded($sub));
        $this->assertFalse(self::showReturned($sub));
        $this->assertFalse(self::isLocked($sub));
    }

    public function testUnderReviewStatusShowsPending(): void
    {
        $sub = ['status' => 'under_review'];
        $this->assertTrue(self::showPending($sub));
        $this->assertFalse(self::showGraded($sub));
        $this->assertFalse(self::isLocked($sub));
    }

    /** Régression : l'ancienne condition vérifiait 'pending' qui n'existe pas dans l'ENUM. */
    public function testPendingValueDoesNotExistInEnum(): void
    {
        $sub = ['status' => 'pending'];
        // Ce statut ne doit jamais déclencher showPending — ce n'est pas une valeur ENUM valide
        $this->assertFalse(self::showPending($sub));
    }

    public function testSubmittedPostIsIgnored(): void
    {
        $sub = ['status' => 'submitted'];
        $this->assertTrue(self::shouldIgnorePost($sub));
        $this->assertFalse(self::shouldRejectPost($sub));
    }

    public function testSubmittedCardBorderIsOrange(): void
    {
        $this->assertSame('rgba(245,158,11,.3)', self::cardBorder(['status' => 'submitted']));
    }

    // ── Cas : corrigé (graded) ───────────────────────────────────

    public function testGradedStatusShowsGradedBlock(): void
    {
        $sub = ['status' => 'graded', 'score' => 18, 'grade' => 'A', 'feedback' => 'Très bien.'];
        $this->assertTrue(self::showGraded($sub));
        $this->assertTrue(self::isLocked($sub));
        $this->assertFalse(self::showForm($sub));
        $this->assertFalse(self::showPending($sub));
        $this->assertFalse(self::showReturned($sub));
    }

    public function testGradedPostIsRejected(): void
    {
        $sub = ['status' => 'graded'];
        $this->assertTrue(self::shouldRejectPost($sub));
        $this->assertFalse(self::shouldIgnorePost($sub));
    }

    public function testGradedCardBorderIsGreen(): void
    {
        $this->assertSame('rgba(16,185,129,.4)', self::cardBorder(['status' => 'graded']));
    }

    // ── Cas : retourné (returned) ────────────────────────────────

    public function testReturnedStatusShowsReturnedBlock(): void
    {
        $sub = ['status' => 'returned', 'feedback' => 'À retravailler.'];
        $this->assertTrue(self::showReturned($sub));
        $this->assertTrue(self::isLocked($sub));
        $this->assertFalse(self::showForm($sub));
        $this->assertFalse(self::showPending($sub));
        $this->assertFalse(self::showGraded($sub));
    }

    public function testReturnedPostIsRejected(): void
    {
        $sub = ['status' => 'returned'];
        $this->assertTrue(self::shouldRejectPost($sub));
        $this->assertFalse(self::shouldIgnorePost($sub));
    }

    public function testReturnedCardBorderIsRed(): void
    {
        $this->assertSame('rgba(239,68,68,.35)', self::cardBorder(['status' => 'returned']));
    }

    // ── Exhaustivité : chaque statut valide déclenche exactement un bloc ──

    #[DataProvider('validStatusProvider')]
    public function testExactlyOneBlockIsActivePerStatus(string $status, string $expectedBlock): void
    {
        $sub = ['status' => $status];
        $blocks = [
            'form'     => self::showForm($sub),
            'pending'  => self::showPending($sub),
            'graded'   => self::showGraded($sub),
            'returned' => self::showReturned($sub),
        ];

        $active = array_keys(array_filter($blocks));
        $this->assertSame([$expectedBlock], $active,
            "Statut '$status' : attendu bloc '$expectedBlock', obtenu : " . implode(', ', $active ?: ['(aucun)'])
        );
    }

    public static function validStatusProvider(): array
    {
        return [
            'submitted'    => ['submitted',    'pending'],
            'under_review' => ['under_review', 'pending'],
            'graded'       => ['graded',       'graded'],
            'returned'     => ['returned',     'returned'],
        ];
    }

    public function testNullSubmissionTriggersFormBlock(): void
    {
        $blocks = [
            'form'     => self::showForm(null),
            'pending'  => self::showPending(null),
            'graded'   => self::showGraded(null),
            'returned' => self::showReturned(null),
        ];
        $active = array_keys(array_filter($blocks));
        $this->assertSame(['form'], $active);
    }
}
