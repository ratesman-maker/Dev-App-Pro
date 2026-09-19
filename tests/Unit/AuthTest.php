<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\UnitTestCase;

/**
 * Unit testy pro hesla (bcrypt).
  * @group auth
 */
class AuthTest extends UnitTestCase
{
    /**
     * Test: password_hash generuje bcrypt hash s cost 12.
     */
    public function test_password_hash_bcrypt(): void
    {
        $password = 'test123';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->assertNotEmpty($hash);
        $this->assertStringStartsWith('$2y$12$', $hash);
    }

    /**
     * Test: password_verify potvrdí správné heslo.
     */
    public function test_password_verify_spravne_heslo(): void
    {
        $password = 'test123';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->assertTrue(password_verify($password, $hash));
    }

    /**
     * Test: password_verify zamítne špatné heslo.
     */
    public function test_password_verify_spatne_heslo(): void
    {
        $password = 'test123';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->assertFalse(password_verify('spatneHeslo', $hash));
    }
}
