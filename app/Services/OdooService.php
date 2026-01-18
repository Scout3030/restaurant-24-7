<?php

namespace App\Services;

use App\Models\Company;
use Obuchmann\OdooJsonRpc\Odoo;
use Obuchmann\OdooJsonRpc\Odoo\Config;

class OdooService
{
    protected Odoo $odoo;

    /**
     * Crear una instancia de Odoo usando las credenciales de una empresa.
     */
    public function __construct(Company $company)
    {
        $config = new Config(
            $company->odoo_database,
            $company->odoo_host,
            $company->odoo_username,
            $company->odoo_password
        );

        $this->odoo = new Odoo($config);
    }

    /**
     * Obtener el cliente Odoo subyacente.
     */
    public function client(): Odoo
    {
        return $this->odoo;
    }
}
