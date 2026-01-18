<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'odoo_database',
        'odoo_host',
        'odoo_username',
        'odoo_password',
        'timezone',
        'whatsapp_webhook_url',
        'assigned_phone_number',
        'appointment_status',
    ];

    /**
     * Usar el campo slug para la resolución de rutas (Route Model Binding).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
