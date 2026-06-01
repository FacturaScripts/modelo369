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

use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Lib\Modelo369;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
final class Modelo369Test extends TestCase
{
    use LogErrorsTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
        self::removeTaxRegularization();
    }

    public function testGetEuCountryCodesNotEmpty(): void
    {
        $codes = Modelo369::getEuCountryCodes();
        $this->assertNotEmpty($codes);
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

    public function testGetPeriodDatesMonthly(): void
    {
        $dates = Modelo369::getPeriodDates('2026', '01');
        $this->assertEquals('01-01-2026', $dates['from']);
        $this->assertEquals('31-01-2026', $dates['to']);

        $dates = Modelo369::getPeriodDates('2026', '06');
        $this->assertEquals('01-06-2026', $dates['from']);
        $this->assertEquals('30-06-2026', $dates['to']);
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

    public function testGetPeriodOptionsIoss(): void
    {
        $options = Modelo369::getPeriodOptions('ioss');
        $this->assertArrayHasKey('01', $options);
        $this->assertArrayHasKey('12', $options);
        $this->assertCount(12, $options);
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

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
