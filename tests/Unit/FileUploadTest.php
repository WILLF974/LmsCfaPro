<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression : encodage/décodage JSON du chemin de fichier soumis.
 *
 * Reproduit le pattern de student/case_studies/view.php et
 * teacher/evaluations/grade.php pour éviter la régression
 * "Array" stocké en base (bug corrigé : uploadFile() retourne un array,
 * pas une chaîne).
 */
class FileUploadTest extends TestCase
{
    // ── Helpers reproduisant le code de view.php ─────────────────

    /**
     * Encode le résultat d'uploadFile() en JSON pour la base.
     * Source : student/case_studies/view.php, student/course/view.php
     */
    private function encodeUploadResult(array $uploadResult, string $originalName): string
    {
        return json_encode(['path' => $uploadResult['path'], 'name' => $originalName]);
    }

    /**
     * Décode le file_path stocké en base pour l'affichage.
     * Source : teacher/evaluations/grade.php, student/case_studies/view.php
     */
    private function decodeFilePath(string $rawPath): array
    {
        $data = json_decode($rawPath, true);
        $path = is_array($data) ? ($data['path'] ?? '') : $rawPath;
        $name = is_array($data) ? ($data['name'] ?? basename($path)) : basename($rawPath);
        return ['path' => $path, 'name' => $name];
    }

    // ── Encodage ─────────────────────────────────────────────────

    public function testEncodeProducesValidJson(): void
    {
        $uploadResult = [
            'success'  => true,
            'filename' => 'abc123.pdf',
            'path'     => 'submissions/abc123.pdf',
            'mime'     => 'application/pdf',
            'size'     => 204800,
        ];
        $encoded = $this->encodeUploadResult($uploadResult, 'mon_rapport.pdf');
        $this->assertJson($encoded);
    }

    public function testEncodeStoresPathAndOriginalName(): void
    {
        $uploadResult = ['success' => true, 'path' => 'submissions/abc123.pdf', 'filename' => 'abc123.pdf'];
        $encoded = $this->encodeUploadResult($uploadResult, 'rapport_stage.pdf');
        $decoded = json_decode($encoded, true);

        $this->assertSame('submissions/abc123.pdf', $decoded['path']);
        $this->assertSame('rapport_stage.pdf',      $decoded['name']);
    }

    /** Régression : avant le fix, on stockait directement l'array → "Array" en base. */
    public function testDirectArrayCastProducesArrayString(): void
    {
        $uploadResult = ['success' => true, 'path' => 'submissions/abc.pdf'];
        // Simule l'ancien bug : $uploadedPath = $uploadResult sans extraction
        $buggyValue = @((string) $uploadResult); // @ intentionnel : on documente le warning PHP, pas on le cache
        $this->assertSame('Array', $buggyValue, 'Cast d\'array en string donne "Array" — valeur invalide pour la DB');
    }

    // ── Décodage — chemin JSON valide ─────────────────────────────

    public function testDecodeJsonPathReturnsCorrectValues(): void
    {
        $stored = json_encode(['path' => 'submissions/abc123.pdf', 'name' => 'rapport.pdf']);
        $result = $this->decodeFilePath($stored);
        $this->assertSame('submissions/abc123.pdf', $result['path']);
        $this->assertSame('rapport.pdf',            $result['name']);
    }

    // ── Décodage — legacy (chemin brut non-JSON) ──────────────────

    public function testDecodeLegacyRawPathFallsBack(): void
    {
        $result = $this->decodeFilePath('submissions/old_file.pdf');
        $this->assertSame('submissions/old_file.pdf', $result['path']);
        $this->assertSame('old_file.pdf',             $result['name']);
    }

    /** Régression : "Array" stocké en base ne doit pas provoquer d'erreur, juste donner des valeurs vides/fallback. */
    public function testDecodeArrayStringFallsBackGracefully(): void
    {
        $result = $this->decodeFilePath('Array');
        $this->assertSame('Array',  $result['path']);
        $this->assertSame('Array',  $result['name']);
        // Aucune exception : pas de crash à l'affichage
    }

    // ── Roundtrip complet ─────────────────────────────────────────

    public function testRoundtripEncodeDecode(): void
    {
        $uploadResult = [
            'success'  => true,
            'path'     => 'submissions/ff3a21b0.pdf',
            'filename' => 'ff3a21b0.pdf',
        ];
        $originalName = 'Étude_de_cas_Wilfrid.pdf';

        $stored  = $this->encodeUploadResult($uploadResult, $originalName);
        $decoded = $this->decodeFilePath($stored);

        $this->assertSame($uploadResult['path'], $decoded['path']);
        $this->assertSame($originalName,         $decoded['name']);
    }

    // ── $fileOk guard ─────────────────────────────────────────────

    /** Reproduit la condition $fileOk de grade.php : empêche l'affichage si path est vide ou "Array". */
    private function fileOk(string $path): bool
    {
        return $path !== '' && $path !== 'Array';
    }

    public function testFileOkTrueForValidPath(): void
    {
        $this->assertTrue($this->fileOk('submissions/abc123.pdf'));
    }

    public function testFileOkFalseForEmptyPath(): void
    {
        $this->assertFalse($this->fileOk(''));
    }

    public function testFileOkFalseForLegacyArrayString(): void
    {
        $this->assertFalse($this->fileOk('Array'));
    }
}
