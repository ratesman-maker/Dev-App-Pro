<?php
declare(strict_types=1);

namespace DevAppPro\Tests\Unit;

use DevAppPro\Tests\UnitTestCase;

/**
 * Unit testy pro bezpečné hlavičky Content-Disposition (název souboru od uživatele).
 * @group security
 */
class ContentDispositionTest extends UnitTestCase
{
    /**
     * Test: uvozovky a zpětná lomítka v názvu nerozbijí filename="..." v hlavičce.
     */
    public function test_quotes_and_backslashes_are_not_injected(): void
    {
        $header = content_disposition('attachment', 'a"b\\c.pdf');

        $this->assertStringStartsWith('attachment; filename="a_b_c.pdf"; filename*=UTF-8', $header);
        $this->assertStringNotContainsString('a"b', $header);
    }

    /**
     * Test: znaky mimo ASCII jsou v fallbacku nahrazeny, plný název je v filename* (RFC 5987).
     */
    public function test_non_ascii_name_is_percent_encoded_in_extended_param(): void
    {
        $header = content_disposition('inline', 'faktura ř.pdf');

        $this->assertStringStartsWith('inline; filename="faktura _.pdf"', $header);
        $this->assertStringContainsString("filename*=UTF-8''faktura%20%C5%99.pdf", $header);
    }
}
