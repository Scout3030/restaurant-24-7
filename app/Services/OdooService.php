<?php

namespace App\Services;

use Obuchmann\OdooJsonRpc\Odoo;
use Obuchmann\OdooJsonRpc\Odoo\Config;

class OdooService
{
    protected Odoo $odoo;

    public function __construct()
    {
        $config = new Config(
            config('odoo.database'),
            config('odoo.host'),
            config('odoo.username'),
            config('odoo.password')
        );

        $this->odoo = new Odoo($config);
        $this->odoo->connect();
    }
}
