<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\OdooService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    /**
     * Verificar si hay disponibilidad para crear una reserva.
     *
     * Espera:
     * - company: id de la empresa en la ruta (Route Model Binding)
     * - date: fecha YYYY-MM-DD
     * - time: hora HH:MM
     * - capacity: número de personas
     *
     * Respuesta:
     * - { available: bool }
     */
    public function checkAvailability(Company $company, Request $request)
    {
        $data = $request->validate([
            'date'       => 'required|date_format:Y-m-d',
            'time'       => 'required|date_format:H:i',
            'capacity'   => 'required|integer|min:1',
        ]);

        // Instanciar servicio Odoo con credenciales de la empresa
        $odooService = new OdooService($company);
        $odoo = $odooService->client();

        $dateTimeString = $data['date'] . ' ' . $data['time'];

        // Rango solicitado: 2 horas a partir de la hora de inicio
        $start = Carbon::createFromFormat('Y-m-d H:i', $dateTimeString);
        $end = (clone $start)->addHours(2);

        // Límite superior para las búsquedas (día + 1 hasta las 03:00, como en el flujo n8n)
        $limit = (clone $start)->addDay()->setTime(3, 0, 0);

        $startStr = $start->format('Y-m-d H:i:s');
        $limitStr = $limit->format('Y-m-d H:i:s');

        // 1) Obtener mesas (appointment.resource)
        $tables = $odoo->searchRead(
            'appointment.resource',
            null,
            [
                'id',
                'display_name',
                'resource_calendar_id',
                'resource_id',
                'name',
                'active',
                'shareable',
                'capacity',
            ]
        );

        // 2) Obtener reservas en curso (event_stop > start, event_stop <= limit, active = true)
        $domainActive = new \Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\Domain();
        $domainActive
            ->where('event_stop', '>', $startStr)
            ->where('active', '=', true)
            ->where('event_stop', '<=', $limitStr);

        $activeReservations = $odoo->searchRead(
            'appointment.booking.line',
            $domainActive,
            [
                'id',
                'display_name',
                'active',
                'appointment_resource_id',
                'capacity_reserved',
                'capacity_used',
                'event_start',
                'event_stop',
            ]
        );

        // 3) Obtener reservas futuras/pedientes (event_start >= start, event_stop <= limit, active = true)
        $domainPending = new \Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\Domain();
        $domainPending
            ->where('event_start', '>=', $startStr)
            ->where('event_stop', '<=', $limitStr)
            ->where('active', '=', true);

        $futureReservations = $odoo->searchRead(
            'appointment.booking.line',
            $domainPending,
            [
                'id',
                'display_name',
                'active',
                'appointment_resource_id',
                'capacity_reserved',
                'capacity_used',
                'event_start',
                'event_stop',
            ]
        );

        // --- Lógica de verificación de disponibilidad (replicada del flujo n8n) ---

        $occupied = [];

        // Helper para comprobar solapamiento de rangos
        $overlaps = function (Carbon $a1, Carbon $a2, Carbon $b1, Carbon $b2): bool {
            return $a1->lt($b2) && $a2->gt($b1);
        };

        // Reservas futuras
        foreach ($futureReservations as $r) {
            if (!empty($r['event_start']) && !empty($r['event_stop']) && !empty($r['appointment_resource_id'])) {
                $rStart = Carbon::parse($r['event_start']);
                $rEnd = Carbon::parse($r['event_stop']);

                if ($overlaps($start, $end, $rStart, $rEnd)) {
                    $resourceId = $r['appointment_resource_id'][0] ?? null;
                    if ($resourceId !== null) {
                        $occupied[$resourceId] = true;
                    }
                }
            }
        }

        // Reservas en curso
        foreach ($activeReservations as $r) {
            if (!empty($r['event_start']) && !empty($r['event_stop']) && !empty($r['appointment_resource_id'])) {
                $rStart = Carbon::parse($r['event_start']);
                $rEnd = Carbon::parse($r['event_stop']);

                if ($overlaps($start, $end, $rStart, $rEnd)) {
                    $resourceId = $r['appointment_resource_id'][0] ?? null;
                    if ($resourceId !== null) {
                        $occupied[$resourceId] = true;
                    }
                }
            }
        }

        // Mesas disponibles (las que no están en occupied)
        $availableTables = [];
        foreach ($tables as $t) {
            // resource_id es un many2one -> [id, name]
            if (empty($t['resource_id']) || !is_array($t['resource_id'])) {
                continue;
            }

            $resourceId = $t['resource_id'][0] ?? null;
            if ($resourceId === null) {
                continue;
            }

            if (!isset($occupied[$resourceId])) {
                $availableTables[] = $t;
            }
        }

        // Ordenar por capacidad descendente
        usort($availableTables, function (array $a, array $b) {
            return (int)($b['capacity'] ?? 0) <=> (int)($a['capacity'] ?? 0);
        });

        // Sumar capacidad hasta cubrir la solicitada
        $totalCapacity = 0;
        foreach ($availableTables as $t) {
            $totalCapacity += (int)($t['capacity'] ?? 0);
            if ($totalCapacity >= $data['capacity']) {
                break;
            }
        }

        return response()->json([
            'available' => $totalCapacity >= $data['capacity'],
        ]);
    }
}
