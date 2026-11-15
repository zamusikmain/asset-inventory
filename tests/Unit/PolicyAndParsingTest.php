<?php

namespace App\Tests\Unit;

use App\Entity\Asset;
use App\Entity\AttributeDefinition;
use App\Security\PasswordPolicy;
use App\Service\Import\ExcelImportService;
use App\Service\SecretVault;
use PHPUnit\Framework\TestCase;

class PolicyAndParsingTest extends TestCase
{
    public function testPasswordPolicy(): void
    {
        $this->assertNotEmpty(PasswordPolicy::violations('short'));
        $this->assertNotEmpty(PasswordPolicy::violations('NoDigitsOrSpecial'));
        $this->assertNotEmpty(PasswordPolicy::violations('Ivanov!Passw0rd#1', 'ivanov'));
        $this->assertSame([], PasswordPolicy::violations('Corr3ct!Horse#Battery'));
        $this->assertSame([], PasswordPolicy::violations(PasswordPolicy::generate()));
    }

    public function testExcelDates(): void
    {
        $this->assertSame('2024-01-15', ExcelImportService::parseDate('15.01.2024')?->format('Y-m-d'));
        $this->assertSame('2024-02-01', ExcelImportService::parseDate('2024-02-01')?->format('Y-m-d'));
        $this->assertSame('2024-03-01', ExcelImportService::parseDate(45352)?->format('Y-m-d'));
        $this->assertNull(ExcelImportService::parseDate('31.02.2024'));
        $this->assertNull(ExcelImportService::parseDate('вчера'));
    }

    public function testScannerCodeNormalization(): void
    {
        $this->assertSame('IT-000001', Asset::normalizeCode(" it-000001\r\n"));
        $a = (new Asset())->setInventoryNumber('nb-1');
        $this->assertSame('NB-1', $a->getBarcode(), 'Штрихкод по умолчанию = инвентарный номер');
    }

    public function testAttributeValidation(): void
    {
        $d = (new AttributeDefinition())->setLabel('RAM')->setDataType('int');
        $this->assertSame('16', $d->normalize('16'));
        $this->expectException(\InvalidArgumentException::class);
        $d->normalize('шестнадцать');
    }

    public function testSecretMasking(): void
    {
        $this->assertSame('••••••7890', SecretVault::mask('1234567890'));
    }
}
