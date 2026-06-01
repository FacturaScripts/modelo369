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

namespace FacturaScripts\Plugins\Modelo369\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\DataSrc\Paises;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Modelo369 as Modelo369Lib;

/**
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
class Modelo369 extends Controller
{
    /** @var string */
    public $codejercicio;

    /** @var array */
    public $data = [];

    /** @var string */
    public $periodo;

    /** @var string */
    public $regime = 'oss';

    /** @var bool */
    public $searched = false;

    /** @var float */
    public $totalBase = 0.0;

    /** @var float */
    public $totalCuota = 0.0;

    public function getEjercicios(): array
    {
        $list = [];
        $year = (int)date('Y');
        for ($i = 0; $i < 5; $i++) {
            $value = (string)($year - $i);
            $list[$value] = $value;
        }
        return $list;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'model-369';
        $data['icon'] = 'fa-solid fa-globe';
        return $data;
    }

    public function getPeriodOptions(): array
    {
        return Modelo369Lib::getPeriodOptions($this->regime);
    }

    public function getRegimeOptions(): array
    {
        return [
            'oss' => Tools::trans('oss-regime'),
            'external-oss' => Tools::trans('external-oss-regime'),
            'ioss' => Tools::trans('ioss-regime'),
        ];
    }

    public function getCountryName(string $codpais): string
    {
        $pais = Paises::get($codpais);
        return $pais->nombre ?? $codpais;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->setTemplate('Modelo369/index');

        $this->codejercicio = $this->request->input('codejercicio', date('Y'));

        $this->regime = $this->request->input('regime', 'oss');
        if (false === in_array($this->regime, ['oss', 'external-oss', 'ioss'], true)) {
            $this->regime = 'oss';
        }

        $defaultPeriod = Modelo369Lib::getDefaultPeriod($this->regime);
        $this->periodo = $this->request->input('periodo', $defaultPeriod);

        $action = $this->request->input('action', '');
        if ($action === 'download-csv') {
            $this->downloadCsv();
            return;
        }

        // Solo cargamos datos cuando el formulario ha sido enviado (POST)
        if ($this->request->method() === 'POST') {
            $this->loadData();
        }
    }

    protected function loadData(): void
    {
        $this->searched = true;
        $this->data = Modelo369Lib::getData($this->codejercicio, $this->periodo);

        $this->totalBase = 0.0;
        $this->totalCuota = 0.0;
        foreach ($this->data as $row) {
            $this->totalBase += $row['baseimponible'];
            $this->totalCuota += $row['cuotaiva'];
        }
    }

    protected function downloadCsv(): void
    {
        $this->setTemplate(false);

        $this->loadData();

        $fileName = 'modelo_369_' . $this->codejercicio . '_' . $this->periodo . '.csv';

        // BOM UTF-8 para compatibilidad con Excel en español
        $content = "\xEF\xBB\xBF";
        $content .= implode(';', [
            Tools::trans('destination-country'),
            Tools::trans('vat-rate') . ' (%)',
            Tools::trans('tax-base'),
            Tools::trans('vat-amount'),
        ]) . "\r\n";

        foreach ($this->data as $row) {
            $content .= implode(';', [
                $this->csvField($this->getCountryName($row['codpais'])),
                $this->csvField(Tools::number($row['iva'])),
                $this->csvField(Tools::number($row['baseimponible'])),
                $this->csvField(Tools::number($row['cuotaiva'])),
            ]) . "\r\n";
        }

        $content .= implode(';', [
            $this->csvField(Tools::trans('total')),
            '',
            $this->csvField(Tools::number($this->totalBase)),
            $this->csvField(Tools::number($this->totalCuota)),
        ]) . "\r\n";

        $this->response
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->setContent($content);
    }

    protected function csvField(string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
