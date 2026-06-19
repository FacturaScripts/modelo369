<?php
/**
 * This file is part of Modelo369 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Lib\Modelo369;
use FacturaScripts\Dinamic\Lib\Modelo369Txt;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
final class Modelo369Test extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testGetDataEmptyForSpanishClient(): void
    {
        $customer = new Cliente();
        $customer->cifnif = 'B' . mt_rand(1, 999999);
        $customer->nombre = 'Cliente ESP ' . mt_rand(1, 99999);
        $customer->razonsocial = 'Empresa ' . mt_rand(1, 99999);
        $this->assertTrue($customer->save());

        $invoice = new FacturaCliente();
        $invoice->setSubject($customer);
        $this->assertTrue($invoice->save());

        $linea = $invoice->getNewLine();
        $linea->descripcion = 'Servicio test';
        $linea->cantidad = 1;
        $linea->pvpunitario = 100;
        $linea->codimpuesto = 'IVA21';
        $this->assertTrue($linea->save());

        // clientes españoles no deben aparecer en el modelo 369
        $data = Modelo369::getData((string)date('Y'), 'T' . ceil(date('n') / 3));
        $found = false;
        foreach ($data as $row) {
            if ($row['codpais'] === 'ESP') {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'ESP no debe aparecer en el modelo 369');

        $this->assertTrue($invoice->delete());
        $this->assertTrue($customer->getDefaultAddress()->delete());
        $this->assertTrue($customer->delete());
    }

    public function testGetDataWithEuClient(): void
    {
        $customer = new Cliente();
        $customer->cifnif = 'FR' . mt_rand(10000000000, 99999999999);
        $customer->nombre = 'Cliente FR ' . mt_rand(1, 99999);
        $customer->razonsocial = 'Empresa FR ' . mt_rand(1, 99999);
        $customer->tipoidfiscal = 'VAT';
        $this->assertTrue($customer->save());

        // actualizar el contacto de facturación con el país francés
        $contact = new Contacto();
        $this->assertTrue($contact->load($customer->idcontactofact));
        $contact->codpais = 'FRA';
        $this->assertTrue($contact->save());

        $invoice = new FacturaCliente();
        $invoice->setSubject($customer);
        $this->assertTrue($invoice->save());

        $linea = $invoice->getNewLine();
        $linea->descripcion = 'Servicio digital';
        $linea->cantidad = 1;
        $linea->pvpunitario = 500;
        $linea->codimpuesto = 'IVA21';
        $this->assertTrue($linea->save());

        $lines = $invoice->getLines();
        $this->assertTrue(Calculator::calculate($invoice, $lines, true));

        $year = (string)date('Y');
        $month = (int)date('n');
        $quarter = 'T' . (string)ceil($month / 3);

        $data = Modelo369::getData($year, $quarter);
        $found = false;
        foreach ($data as $row) {
            if ($row['codpais'] === 'FRA' && (float)$row['iva'] === 21.0) {
                $found = true;
                $this->assertGreaterThan(0, $row['baseimponible']);
                $this->assertGreaterThan(0, $row['cuotaiva']);
                break;
            }
        }
        $this->assertTrue($found, 'FRA con IVA21 debe aparecer en el modelo 369');

        $this->assertTrue($invoice->delete());
        $this->assertTrue($contact->delete());
        $this->assertTrue($customer->delete());
    }

    public function testGetEuCountryCodesDoesNotIncludeSpain(): void
    {
        $codes = Modelo369::getEuCountryCodes();
        $this->assertNotContains('ESP', $codes);
    }

    public function testGetEuCountryCodesIncludesMainCountries(): void
    {
        $codes = Modelo369::getEuCountryCodes();
        $this->assertContains('FRA', $codes);
        $this->assertContains('DEU', $codes);
        $this->assertContains('ITA', $codes);
    }

    public function testGetEuCountryCodesNotEmpty(): void
    {
        $codes = Modelo369::getEuCountryCodes();
        $this->assertNotEmpty($codes);
    }

    public function testGetHeaderPeriod(): void
    {
        $this->assertEquals('1T', Modelo369::getHeaderPeriod('T1'));
        $this->assertEquals('4T', Modelo369::getHeaderPeriod('T4'));
        // los periodos mensuales se mantienen igual
        $this->assertEquals('01', Modelo369::getHeaderPeriod('01'));
        $this->assertEquals('12', Modelo369::getHeaderPeriod('12'));
    }

    public function testGetPeriodDatesMonthly(): void
    {
        $dates = Modelo369::getPeriodDates('2026', '01');
        $this->assertEquals('01-01-2026', $dates['from']);
        $this->assertEquals('31-01-2026', $dates['to']);

        $dates = Modelo369::getPeriodDates('2026', '06');
        $this->assertEquals('01-06-2026', $dates['from']);
        $this->assertEquals('30-06-2026', $dates['to']);
    }

    public function testGetPeriodDatesT1(): void
    {
        $dates = Modelo369::getPeriodDates('2026', 'T1');
        $this->assertEquals('01-01-2026', $dates['from']);
        $this->assertEquals('31-03-2026', $dates['to']);
    }

    public function testGetPeriodDatesT2(): void
    {
        $dates = Modelo369::getPeriodDates('2026', 'T2');
        $this->assertEquals('01-04-2026', $dates['from']);
        $this->assertEquals('30-06-2026', $dates['to']);
    }

    public function testGetPeriodDatesT3(): void
    {
        $dates = Modelo369::getPeriodDates('2026', 'T3');
        $this->assertEquals('01-07-2026', $dates['from']);
        $this->assertEquals('30-09-2026', $dates['to']);
    }

    public function testGetPeriodDatesT4(): void
    {
        $dates = Modelo369::getPeriodDates('2026', 'T4');
        $this->assertEquals('01-10-2026', $dates['from']);
        $this->assertEquals('31-12-2026', $dates['to']);
    }

    public function testGetPeriodNumber(): void
    {
        $this->assertEquals('01', Modelo369::getPeriodNumber('T1'));
        $this->assertEquals('04', Modelo369::getPeriodNumber('T4'));
        $this->assertEquals('06', Modelo369::getPeriodNumber('06'));
    }

    public function testGetPeriodOptionsIoss(): void
    {
        $options = Modelo369::getPeriodOptions('ioss');
        $this->assertArrayHasKey('01', $options);
        $this->assertArrayHasKey('12', $options);
        $this->assertCount(12, $options);
    }

    public function testGetPeriodOptionsOss(): void
    {
        $options = Modelo369::getPeriodOptions('oss');
        $this->assertArrayHasKey('T1', $options);
        $this->assertArrayHasKey('T2', $options);
        $this->assertArrayHasKey('T3', $options);
        $this->assertArrayHasKey('T4', $options);
        $this->assertCount(4, $options);
    }

    public function testGetPeriodType(): void
    {
        $this->assertEquals('T', Modelo369::getPeriodType('oss'));
        $this->assertEquals('T', Modelo369::getPeriodType('external-oss'));
        $this->assertEquals('M', Modelo369::getPeriodType('ioss'));
    }

    public function testGetRegimeCode(): void
    {
        $this->assertEquals('MOSS', Modelo369::getRegimeCode('oss'));
        $this->assertEquals('VOES', Modelo369::getRegimeCode('external-oss'));
        $this->assertEquals('IMPO', Modelo369::getRegimeCode('ioss'));
    }

    public function testTxtExternalOssEmptyHasFixedLength(): void
    {
        // Exterior sin datos: cabecera (346) + T36900 (110) + T36901 (1422)
        // + T36902 (763) + T36903 (2947)
        $txt = Modelo369Txt::build('2026', 'T1', 'external-oss', []);
        $this->assertEquals(5588, strlen($txt));
        $this->assertStringContainsString('<T36901>', $txt);
        $this->assertStringContainsString('</T36903>', $txt);
    }

    public function testTxtIossEmptyHasFixedLength(): void
    {
        // Importación sin datos: cabecera (346) + T36900 (110) + T36910 (1454)
        // + T36911 (763) + T36912 (2947)
        $txt = Modelo369Txt::build('2026', '06', 'ioss', []);
        $this->assertEquals(5620, strlen($txt));
        $this->assertStringContainsString('<T36910>', $txt);
        $this->assertStringContainsString('</T36912>', $txt);
    }

    public function testTxtOssEmptyHasFixedLength(): void
    {
        // OSS sin datos: cabecera (328+18) + T36900 (110) + T36904 (1423)
        // + T36906 (1687) + T36907 (1687) + T36908 (763) + T36909 (5803)
        $txt = Modelo369Txt::build('2026', 'T1', 'oss', []);
        $this->assertEquals(11819, strlen($txt));
        $this->assertStringStartsWith('<T3690', $txt);
        $this->assertStringEndsWith('0000>', $txt);
        $this->assertStringContainsString('<T36900>', $txt);
        $this->assertStringContainsString('</T36900>', $txt);
        $this->assertStringContainsString('<T36904>', $txt);
        $this->assertStringContainsString('</T36904>', $txt);
        // periodo de cabecera convertido a "1T"
        $this->assertStringContainsString('<T369020261T0000>', $txt);
    }

    public function testTxtOssWithGoodsAndServices(): void
    {
        $data = [
            ['codpais' => 'FRA', 'iva' => 21.0, 'kind' => 'services', 'baseimponible' => 2800.20, 'cuotaiva' => 588.04],
            ['codpais' => 'DEU', 'iva' => 19.0, 'kind' => 'goods', 'baseimponible' => 1000.00, 'cuotaiva' => 190.00],
        ];

        $txt = Modelo369Txt::build('2026', 'T1', 'oss', $data);

        // se añade la página T36905 (entregas de bienes), longitud total +1211
        $this->assertEquals(13030, strlen($txt));
        $this->assertStringContainsString('<T36905>', $txt);
        $this->assertStringContainsString('MOSS', $txt);

        // base imponible 2.800,20 € -> 17 posiciones sin separador
        $this->assertStringContainsString('00000000000280020', $txt);
        // tipo de IVA 21,00 % -> 5 posiciones
        $this->assertStringContainsString('02100', $txt);
        // códigos de país en formato ISO alfa-2
        $this->assertStringContainsString('FR', $txt);
        $this->assertStringContainsString('DE', $txt);
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
