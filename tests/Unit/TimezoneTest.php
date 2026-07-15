<?php
declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Non-regression : horodatages en heure de La Reunion (Indian/Reunion, UTC+4).
 *
 * Documente et protege la correction du decalage de +2h qui survenait
 * lorsque le serveur MySQL Hostinger (Europe/Paris, UTC+2 en ete) fournissait
 * des horodatages non alignes sur le fuseau cible UTC+4.
 */
class TimezoneTest extends TestCase
{
    private string $previousTz;

    protected function setUp(): void
    {
        $this->previousTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
    }

    // ── 1. Definition du fuseau cible ─────────────────────────────────────────

    public function testIndianReunionIsUtcPlus4(): void
    {
        $tz     = new DateTimeZone('Indian/Reunion');
        $offset = $tz->getOffset(new DateTimeImmutable('now', $tz));
        $this->assertSame(4 * 3600, $offset, 'Indian/Reunion doit etre UTC+4 (14 400 s)');
    }

    public function testMysqlOffsetStringForReunion(): void
    {
        date_default_timezone_set('Indian/Reunion');
        $offset = (new DateTimeImmutable())->format('P');
        $this->assertSame('+04:00', $offset, "La chaine transmise a MySQL SET time_zone doit etre '+04:00'");
    }

    // ── 2. La Reunion n'observe pas l'heure d'ete ────────────────────────────

    public function testReunionHasNoSummerTime(): void
    {
        $tz     = new DateTimeZone('Indian/Reunion');
        $summer = new DateTimeImmutable('2024-07-15 12:00:00', $tz);
        $winter = new DateTimeImmutable('2024-01-15 12:00:00', $tz);
        $this->assertSame(
            $tz->getOffset($summer),
            $tz->getOffset($winter),
            'Indian/Reunion ne doit pas changer d\'offset entre ete et hiver'
        );
    }

    // ── 3. Conversion UTC → Reunion ──────────────────────────────────────────

    public function testUtcMidnightIsReunion4am(): void
    {
        $utc   = new DateTimeImmutable('2024-07-15 00:00:00', new DateTimeZone('UTC'));
        $local = $utc->setTimezone(new DateTimeZone('Indian/Reunion'));
        $this->assertSame('15/07/2024 04:00', $local->format('d/m/Y H:i'),
            'Minuit UTC doit correspondre a 04h00 heure Reunion');
    }

    #[DataProvider('utcToReunionProvider')]
    public function testUtcToReunionConversion(string $utc, string $expectedReunion): void
    {
        $dt    = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $local = $dt->setTimezone(new DateTimeZone('Indian/Reunion'));
        $this->assertSame($expectedReunion, $local->format('d/m/Y H:i'));
    }

    public static function utcToReunionProvider(): array
    {
        return [
            'UTC 00h00 => 04h00 Reunion'      => ['2024-07-15 00:00:00', '15/07/2024 04:00'],
            'UTC 06h00 => 10h00 Reunion'      => ['2024-07-15 06:00:00', '15/07/2024 10:00'],
            'UTC 20h00 => 00h00+1j Reunion'   => ['2024-07-15 20:00:00', '16/07/2024 00:00'],
            'Minuit Reunion = UTC 20h veille' => ['2024-07-14 20:00:00', '15/07/2024 00:00'],
        ];
    }

    // ── 4. Le bug corrige : Europe/Paris (UTC+2 ete) != Reunion (UTC+4) ──────

    public function testEuropeParisSummerIsNotReunion(): void
    {
        $paris   = new DateTimeZone('Europe/Paris');
        $reunion = new DateTimeZone('Indian/Reunion');
        $ref     = new DateTimeImmutable('2024-07-15 12:00:00', new DateTimeZone('UTC'));

        $offsetParis   = $paris->getOffset($ref);   // +7200 s (UTC+2 en ete)
        $offsetReunion = $reunion->getOffset($ref);  // +14400 s (UTC+4)

        $this->assertNotSame($offsetParis, $offsetReunion,
            'Europe/Paris (UTC+2 ete) et Indian/Reunion (UTC+4) ne doivent pas avoir le meme offset');
        $this->assertSame(2 * 3600, $offsetReunion - $offsetParis,
            "L'ecart entre Paris ete et Reunion doit etre exactement 2h — c'est le decalage qui etait observe");
    }

    // ── 5. La chaine MySQL resultante est bien formee ─────────────────────────

    public function testMysqlSetTimezoneStringIsEscapable(): void
    {
        date_default_timezone_set('Indian/Reunion');
        $offset = (new DateTimeImmutable())->format('P');
        $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', $offset);
    }

    // ── 6. formatDateTime() du LMS affiche correctement l'heure locale ────────

    public function testFormatDateTimeUsesCurrentPhpTimezone(): void
    {
        date_default_timezone_set('Indian/Reunion');
        // Simule ce que MySQL renvoie quand sa session est alignee sur UTC+4 :
        // un enregistrement insere a 10h Reunion revient comme '2024-07-15 10:00:00'
        $result = formatDateTime('2024-07-15 10:00:00');
        $this->assertSame('15/07/2024 10:00', $result);
    }

    public function testFormatDateTimeWithEuropeParisTzShowsWrongTime(): void
    {
        // Reproduit le bug : PHP en Europe/Paris, MySQL en Europe/Paris.
        // Un enregistrement insere a 10h Reunion (= 08h Paris) est stocke '08:00'.
        // PHP en Paris l'affiche '08:00' alors que l'utilisateur attendait '10:00'.
        date_default_timezone_set('Europe/Paris');
        $mysqlValueStoredInParisTz = '2024-07-15 08:00:00';
        $displayed = formatDateTime($mysqlValueStoredInParisTz);
        $this->assertSame('15/07/2024 08:00', $displayed);
        $this->assertNotSame('15/07/2024 10:00', $displayed,
            'Sans correction, heure Paris (08:00) != heure Reunion (10:00)');
    }

    public function testFormatDateTimeWithReunionTzShowsCorrectTime(): void
    {
        // Apres correction : PHP en Indian/Reunion, MySQL en Indian/Reunion.
        // Un enregistrement insere a 10h Reunion est stocke '10:00', affiche '10:00'.
        date_default_timezone_set('Indian/Reunion');
        $mysqlValueStoredInReunionTz = '2024-07-15 10:00:00';
        $displayed = formatDateTime($mysqlValueStoredInReunionTz);
        $this->assertSame('15/07/2024 10:00', $displayed,
            'Apres correction, l\'heure Reunion doit s\'afficher correctement');
    }
}
