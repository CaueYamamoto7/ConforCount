<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Cnpj;
use PHPUnit\Framework\TestCase;

final class CnpjTest extends TestCase
{
    public function testValidaCnpjNumericoEAlfanumerico(): void
    {
        self::assertTrue(Cnpj::valido('11.222.333/0001-81'));
        self::assertTrue(Cnpj::valido('12.ABC.345/01DE-35'));
        self::assertFalse(Cnpj::valido('12.ABC.345/01DE-36'));
        self::assertFalse(Cnpj::valido('11.111.111/1111-11'));
        self::assertSame('12.ABC.345/01DE-35', Cnpj::formatar('12abc34501de35'));
    }
}
