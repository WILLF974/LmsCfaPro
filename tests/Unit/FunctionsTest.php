<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    // ─── e() ───────────────────────────────────────────────────

    public function testEscapesHtmlEntities(): void
    {
        // ENT_HTML5 encode les apostrophes en &apos; (et non &#039;)
        $this->assertSame('&lt;script&gt;alert(&apos;xss&apos;)&lt;/script&gt;', e("<script>alert('xss')</script>"));
    }

    public function testEDoesNotAlterSafeString(): void
    {
        $this->assertSame('Bonjour le monde', e('Bonjour le monde'));
    }

    // ─── url() ─────────────────────────────────────────────────

    public function testUrlWithPath(): void
    {
        $this->assertSame('https://lmscfapro.fr/student/dashboard', url('student/dashboard'));
    }

    public function testUrlStripsLeadingSlash(): void
    {
        $this->assertSame('https://lmscfapro.fr/student/dashboard', url('/student/dashboard'));
    }

    public function testUrlEmptyPath(): void
    {
        $this->assertSame('https://lmscfapro.fr/', url(''));
    }

    // ─── uploadUrl() ───────────────────────────────────────────

    public function testUploadUrl(): void
    {
        $this->assertSame('https://lmscfapro.fr/uploads/submissions/abc.pdf', uploadUrl('submissions/abc.pdf'));
    }

    public function testUploadUrlStripsLeadingSlash(): void
    {
        $this->assertSame('https://lmscfapro.fr/uploads/submissions/abc.pdf', uploadUrl('/submissions/abc.pdf'));
    }

    // ─── formatDate() ──────────────────────────────────────────

    public function testFormatDate(): void
    {
        $this->assertSame('25/12/2024', formatDate('2024-12-25'));
    }

    public function testFormatDateCustomFormat(): void
    {
        $this->assertSame('2024-12-25', formatDate('2024-12-25', 'Y-m-d'));
    }

    public function testFormatDateEmptyReturnsPlaceholder(): void
    {
        $this->assertSame('-', formatDate(''));
    }

    // ─── formatDateTime() ──────────────────────────────────────

    public function testFormatDateTime(): void
    {
        $this->assertSame('25/12/2024 14:30', formatDateTime('2024-12-25 14:30:00'));
    }

    public function testFormatDateTimeEmptyReturnsPlaceholder(): void
    {
        $this->assertSame('-', formatDateTime(''));
    }

    // ─── formatDuration() ──────────────────────────────────────

    public function testFormatDurationMinutesOnly(): void
    {
        $this->assertSame('45 min', formatDuration(45));
    }

    public function testFormatDurationHoursNoMinutes(): void
    {
        $this->assertSame('2h', formatDuration(120));
    }

    public function testFormatDurationHoursAndMinutes(): void
    {
        $this->assertSame('1h30', formatDuration(90));
    }

    public function testFormatDurationHoursAndSingleDigitMinutes(): void
    {
        $this->assertSame('1h05', formatDuration(65));
    }

    // ─── formatFileSize() ──────────────────────────────────────

    public function testFormatFileSizeBytes(): void
    {
        $this->assertSame('500 o', formatFileSize(500));
    }

    public function testFormatFileSizeKilobytes(): void
    {
        $this->assertSame('1.5 Ko', formatFileSize(1536));
    }

    public function testFormatFileSizeMegabytes(): void
    {
        $this->assertSame('2 Mo', formatFileSize(2 * 1048576));
    }

    public function testFormatFileSizeGigabytes(): void
    {
        $this->assertSame('1.5 Go', formatFileSize((int)(1.5 * 1073741824)));
    }

    // ─── slugify() ─────────────────────────────────────────────

    public function testSlugifyBasic(): void
    {
        $this->assertSame('bonjour-le-monde', slugify('Bonjour le monde'));
    }

    public function testSlugifyAccents(): void
    {
        $this->assertSame('etude-de-cas-gerer-les-stocks', slugify('Étude de cas – Gérer les stocks'));
    }

    public function testSlugifySpecialChars(): void
    {
        $this->assertSame('introduction-a-la-comptabilite', slugify('Introduction à la Comptabilité !'));
    }

    // ─── paginate() ────────────────────────────────────────────

    public function testPaginateBasic(): void
    {
        $p = paginate(100, 20, 1);
        $this->assertSame(5,  $p['totalPages']);
        $this->assertSame(1,  $p['currentPage']);
        $this->assertSame(0,  $p['offset']);
    }

    public function testPaginatePage3(): void
    {
        $p = paginate(100, 20, 3);
        $this->assertSame(40, $p['offset']);
    }

    public function testPaginateClampsBelowOne(): void
    {
        $p = paginate(50, 10, 0);
        $this->assertSame(1, $p['currentPage']);
    }

    public function testPaginateClampsAboveTotal(): void
    {
        $p = paginate(50, 10, 99);
        $this->assertSame(5, $p['currentPage']);
    }

    public function testPaginateZeroTotalGivesOnePage(): void
    {
        $p = paginate(0, 20, 1);
        $this->assertSame(1, $p['totalPages']);
    }

    // ─── validateEmail() ───────────────────────────────────────

    public function testValidateEmailValid(): void
    {
        $this->assertTrue(validateEmail('user@example.com'));
    }

    public function testValidateEmailInvalid(): void
    {
        $this->assertFalse(validateEmail('not-an-email'));
    }

    public function testValidateEmailMissingDomain(): void
    {
        $this->assertFalse(validateEmail('user@'));
    }

    // ─── validatePassword() ────────────────────────────────────

    public function testValidatePasswordMinLength(): void
    {
        $this->assertTrue(validatePassword('12345678'));
    }

    public function testValidatePasswordTooShort(): void
    {
        $this->assertFalse(validatePassword('1234567'));
    }

    // ─── getContentTypeIcon() ──────────────────────────────────

    public function testGetContentTypeIconVideo(): void
    {
        $this->assertSame('fas fa-play-circle text-red-400', getContentTypeIcon('video'));
    }

    public function testGetContentTypeIconPdf(): void
    {
        $this->assertSame('fas fa-file-pdf text-red-500', getContentTypeIcon('pdf'));
    }

    public function testGetContentTypeIconQuiz(): void
    {
        $this->assertSame('fas fa-question-circle text-purple-500', getContentTypeIcon('quiz'));
    }

    public function testGetContentTypeIconUnknownReturnsDefault(): void
    {
        $this->assertSame('fas fa-file text-gray-400', getContentTypeIcon('unknown_type'));
    }

    // ─── getLevel() / getLevelName() ───────────────────────────

    public function testGetLevelStartsAt1(): void
    {
        $this->assertSame(1, getLevel(0));
    }

    public function testGetLevelIncreasesAtThreshold(): void
    {
        $this->assertSame(2, getLevel(XP_PER_LEVEL));
    }

    public function testGetLevelName(): void
    {
        $this->assertSame('Débutant', getLevelName(1));
        $this->assertSame('Expert',   getLevelName(6));
    }

    // ─── getAvatarInitials() / getAvatarColor() ────────────────

    public function testGetAvatarInitials(): void
    {
        $this->assertSame('WR', getAvatarInitials('Wilfrid', 'Rivière'));
    }

    public function testGetAvatarColorReturnsCssColor(): void
    {
        $color = getAvatarColor('Wilfrid');
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color);
    }

    public function testGetAvatarColorIsDeterministic(): void
    {
        $this->assertSame(getAvatarColor('test'), getAvatarColor('test'));
    }

    // ─── Flash messages ────────────────────────────────────────

    public function testFlashSetAndGet(): void
    {
        setFlash('success', 'Travail soumis.');
        $flash = getFlash();
        $this->assertCount(1, $flash);
        $this->assertSame('success', $flash[0]['type']);
        $this->assertSame('Travail soumis.', $flash[0]['message']);
    }

    public function testFlashIsConsumedAfterGet(): void
    {
        setFlash('info', 'Message temporaire');
        getFlash();
        $this->assertEmpty(getFlash());
    }

    public function testFlashMultipleMessages(): void
    {
        setFlash('error', 'Erreur 1');
        setFlash('warning', 'Avertissement');
        $flash = getFlash();
        $this->assertCount(2, $flash);
    }

    // ─── getRoleLabel() ────────────────────────────────────────

    public function testGetRoleLabel(): void
    {
        $this->assertSame('Étudiant',     getRoleLabel('student'));
        $this->assertSame('Enseignant',   getRoleLabel('teacher'));
        $this->assertSame('Administrateur', getRoleLabel('admin'));
    }

    public function testGetRoleLabelUnknownReturnsSelf(): void
    {
        $this->assertSame('superuser', getRoleLabel('superuser'));
    }
}
