<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Services\AclService;
use DevAppPro\Tests\UnitTestCase;

/**
 * Unit testy pro AclService::normalizeProjectTree.
 * Regrese: soubory vytvořené s mkdir 0755/chmod 0644 oříznou ACL masku
 * → www-data/ratesman ztratí zápis → selhávají WP updaty (viz docs/acl-masky-projekty.md).
 * @group projects
 */
class AclServiceTest extends UnitTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Test vyžaduje setfacl/getfacl a filesystem s podporou ACL
        // (command -v voláno per nástroj — sh vypíše jen první nález)
        foreach (['setfacl', 'getfacl'] as $bin) {
            if (trim((string) shell_exec("command -v {$bin} 2>/dev/null")) === '') {
                $this->markTestSkipped("{$bin} není k dispozici");
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/acl-test-' . uniqid();
        mkdir($this->tmpDir, 0775, true);

        // Rodič s default ACL → dědí se do nových objektů a ořezává maskou
        exec('setfacl -d -m u:www-data:rwX,u:ratesman:rwX ' . escapeshellarg($this->tmpDir) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Filesystem nepodporuje default ACL: ' . implode(' ', $out));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    private function maskOf(string $path): ?string
    {
        $out = shell_exec('getfacl -p ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($out === null || !preg_match('/^mask::(\S+)/m', $out, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * mkdir 0755 uvnitř stromu s default ACL ořízne masku na r-x;
     * normalizace ji vrátí na rwx.
     */
    public function test_normalizace_opravi_oriznutou_masku(): void
    {
        // Simulace root workera: umask 022 + mkdir 0755
        $sub = $this->tmpDir . '/projekt';
        mkdir($sub, 0755);

        $mask = $this->maskOf($sub);
        $this->assertNotNull($mask, 'Adresář nemá ACL masku (nezdědil default ACL?)');
        $this->assertNotSame('rwx', $mask, "Maska měla být oříznutá, je {$mask}");

        $result = AclService::normalizeProjectTree($this->tmpDir);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('rwx', $this->maskOf($sub), 'Maska po normalizaci musí být rwx');
    }

    /**
     * Soubor vytvořený s chmod 0644 dostane masku r--; po normalizaci rw-.
     */
    public function test_normalizace_souboru(): void
    {
        $file = $this->tmpDir . '/soubor.php';
        touch($file);
        chmod($file, 0644);

        $this->assertNotSame('rw-', $this->maskOf($file));

        $result = AclService::normalizeProjectTree($this->tmpDir);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('rw-', $this->maskOf($file), 'Soubor po normalizaci musí mít masku rw-');
    }

    /**
     * Spustitelné soubory si po normalizaci zachovají x (maska rwx),
     * obyčejné soubory x nedostanou (maska rw-).
     */
    public function test_normalizace_zachova_execute_bit(): void
    {
        $script = $this->tmpDir . '/skript.sh';
        touch($script);
        chmod($script, 0755);
        $plain = $this->tmpDir . '/plain.php';
        touch($plain);
        chmod($plain, 0644);

        $result = AclService::normalizeProjectTree($this->tmpDir);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('rwx', $this->maskOf($script), 'Spustitelný soubor musí mít masku rwx');
        $this->assertSame('rw-', $this->maskOf($plain), 'Obyčejný soubor musí mít masku rw-');
    }

    /**
     * Neexistující cesta → ok=false, žádná výjimka (worker nesmí padnout).
     */
    public function test_neexistujici_cesta_vrati_false(): void
    {
        $result = AclService::normalizeProjectTree($this->tmpDir . '/neexistuje');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }
}
