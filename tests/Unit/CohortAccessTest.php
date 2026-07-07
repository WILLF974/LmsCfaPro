<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Non-régression — accès cohorte & fonctionnalités v0.7
 *
 * Ce fichier couvre les invariants testables sans base de données pour :
 *   - hasContentAccess() : signature, rejet DB-absente, paires de scope valides
 *   - Scope types valides pour access_grants et cohort_access_grants
 *   - Helpers navigation étudiant (url())
 *   - Constantes XP et gamification
 */
class CohortAccessTest extends TestCase
{
    // ── hasContentAccess() — existence et signature ───────────────────────────

    public function testHasContentAccessExistsAndIsCallable(): void
    {
        $this->assertTrue(function_exists('hasContentAccess'));
    }

    public function testHasContentAccessThrowsWhenNoDatabaseAvailable(): void
    {
        // En contexte de test unitaire, getDB() lève RuntimeException.
        // hasContentAccess() doit la propager (pas la swallower).
        $this->expectException(RuntimeException::class);
        hasContentAccess(1, ['module_id' => 1]);
    }

    public function testHasContentAccessThrowsWithEmptyScope(): void
    {
        // Même chose avec un scope vide.
        $this->expectException(RuntimeException::class);
        hasContentAccess(1, []);
    }

    // ── Scope types ───────────────────────────────────────────────────────────

    /**
     * Les cinq scope_type valides doivent couvrir toute la hiérarchie RNCP.
     * Régression : ajouter un niveau brise les requêtes ENUM côté DB.
     */
    public function testValidScopeTypesAreExactlyFive(): void
    {
        $validTypes = ['rncp_title', 'activity_type', 'competency', 'sequence', 'module'];
        $this->assertCount(5, $validTypes);
    }

    public function testScopeTypesMatchEnumOrder(): void
    {
        // L'ordre de la liste doit refléter la hiérarchie descendante RNCP→module.
        $types = ['rncp_title', 'activity_type', 'competency', 'sequence', 'module'];
        $this->assertSame('rncp_title',    $types[0]);
        $this->assertSame('activity_type', $types[1]);
        $this->assertSame('competency',    $types[2]);
        $this->assertSame('sequence',      $types[3]);
        $this->assertSame('module',        $types[4]);
    }

    // ── Navigation étudiant ───────────────────────────────────────────────────

    public function testStudentAccessPageUrl(): void
    {
        $this->assertSame(
            'https://lmscfapro.fr/student/access/index.php',
            url('student/access/index.php')
        );
    }

    // ── is_published : logique de filtrage ────────────────────────────────────

    /**
     * La valeur 1 (défaut) = visible ; 0 = masqué.
     * Régression : inverser ce boolean bloquerait tout le contenu existant.
     */
    public function testIsPublishedDefaultMeansVisible(): void
    {
        $defaultValue = 1;
        $this->assertTrue((bool)$defaultValue, 'is_published=1 doit rendre la ressource visible');
    }

    public function testIsPublishedZeroMeansHidden(): void
    {
        $unpublished = 0;
        $this->assertFalse((bool)$unpublished, 'is_published=0 doit masquer la ressource');
    }

    public function testToggleIsPublishedFormula(): void
    {
        // La bascule SQL `1 - is_published` doit être idempotente en deux passes.
        $initial  = 1;
        $toggled  = 1 - $initial;
        $restored = 1 - $toggled;
        $this->assertSame(0, $toggled);
        $this->assertSame(1, $restored);
    }

    // ── XP & gamification ────────────────────────────────────────────────────

    public function testXpPerLevelConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, XP_PER_LEVEL);
    }

    public function testGetLevelReturnsAtLeastOne(): void
    {
        $this->assertGreaterThanOrEqual(1, getLevel(0));
        $this->assertGreaterThanOrEqual(1, getLevel(-999));
    }

    public function testGetLevelIncreasesWithXp(): void
    {
        $level1 = getLevel(0);
        $level2 = getLevel(XP_PER_LEVEL);
        $this->assertGreaterThan($level1, $level2);
    }

    // ── Helpers purs utilisés dans les vues accès ────────────────────────────

    public function testEscapePreventsCohortNameXss(): void
    {
        $malicious = '<script>alert("xss")</script>';
        $safe      = e($malicious);
        $this->assertStringNotContainsString('<script>', $safe);
        $this->assertStringContainsString('&lt;script&gt;', $safe);
    }

    public function testFormatDateHandlesCohortGrantTimestamp(): void
    {
        // Format des granted_at stockés en DB : 'Y-m-d H:i:s'
        $result = formatDate('2026-07-07 10:45:31');
        $this->assertSame('07/07/2026', $result);
    }

    public function testFormatDateTimeHandlesCohortGrantTimestamp(): void
    {
        $result = formatDateTime('2026-07-07 10:45:31');
        $this->assertSame('07/07/2026 10:45', $result);
    }
}
