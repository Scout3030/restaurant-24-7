<?php

namespace App\Http\Controllers;

use App\Gpt\HumanDateAction;
use App\Models\Company;
use App\Services\OdooService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MalteKuhr\LaravelGpt\Implementations\Parts\InputText;

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

    /**
     * Endpoint que replica el flujo "Fecha Actual" de n8n (sin empresa).
     *
     * Devuelve:
     * - referencia_tiempo: ISO completo (UTC)
     * - referencia_tiempo_dia: YYYY-MM-DD (UTC)
     * - referencia_tiempo_hora: HH:MM (UTC)
     */
    public function currentTime(Request $request): JsonResponse
    {
        $now = Carbon::now('UTC');

        return response()->json([
            'referencia_tiempo'      => $now->toIso8601String(),
            'referencia_tiempo_dia'  => $now->toDateString(),
            'referencia_tiempo_hora' => $now->format('H:i'),
        ]);
    }

    /**
     * Calcula una fecha calendario a partir de una expresión en lenguaje humano,
     * replicando el flujo "Cálculo Fecha Lenguaje Humano" de n8n.
     *
     * Entrada (JSON):
     * - dia: string con la expresión temporal del usuario (obligatorio)
     * - referencia_tiempo: ISO 8601 (opcional, si no se envía se usa ahora en UTC)
     *
     * Respuesta:
     * - { valido: bool, fecha: "YYYY-MM-DD" }
     */
    public function humanDate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dia'               => 'required|string',
            'referencia_tiempo' => 'nullable|string',
        ]);

        // Contexto temporal actual
        $now = isset($data['referencia_tiempo'])
            ? Carbon::parse($data['referencia_tiempo'])->utc()
            : Carbon::now('UTC');

        $nowIso = $now->toIso8601String();

        // Construimos el prompt igual que en n8n
        $prompt = <<<TXT
Contexto temporal actual:
{$nowIso}

Expresión temporal proporcionada por el usuario:
{$data['dia']}
TXT;

        $action = new HumanDateAction([
            new InputText($prompt),
        ]);

        // Ejecutar acción GPT
        $action->run();
        $output = $action->output() ?? [];

        $fecha = $output['fecha'] ?? null;
        if (!is_string($fecha) || trim($fecha) === '') {
            return response()->json([
                'valido' => false,
                'fecha'  => null,
            ], 422);
        }

        // En n8n se marca válido si la fecha es >= al día actual (YYYY-MM-DD)
        $today = $now->toDateString(); // YYYY-MM-DD
        $valido = $fecha >= $today;

        return response()->json([
            'valido' => $valido,
            'fecha'  => $fecha,
        ]);
    }

    /**
     * Crear una reserva en Odoo (calendar.event + appointment.booking.line),
     * replicando el flujo "Crear reserva" de n8n.
     *
     * Ruta: POST /api/companies/{company}/reservations
     *
     * Entrada (JSON):
     * - date: YYYY-MM-DD
     * - time: HH:MM
     * - capacity: int
     * - full_name: string
     * - phone_number: string
     *
     * Respuesta:
     * - status: "ok" | "no_available_tables"
     * - message: string
     * - reservations: [
     *     {
     *       table_id,
     *       table_capacity,
     *       reserved_capacity,
     *       start,
     *       stop
     *     },
     *   ]
     * - event_id: id de calendar.event creado (si aplica)
     */
    public function createReservation(Company $company, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'         => 'required|date_format:Y-m-d',
            'time'         => 'required|date_format:H:i',
            'capacity'     => 'required|integer|min:1',
            'full_name'    => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
        ]);

        $capacity = (int) $data['capacity'];

        // Instanciar servicio Odoo con credenciales de la empresa
        $odooService = new OdooService($company);
        $odoo = $odooService->client();

        // 1) Obtener mesas (appointment.resource)
        $tables = $odoo->searchRead(
            'appointment.resource',
            null,
            [
                'id',
                'display_name',
                'resource_id',
                'resource_calendar_id',
                'name',
                'active',
                'capacity',
                'shareable',
                'description',
                'appointment_type_ids',
            ]
        );

        // Filtramos sólo mesas activas con capacidad válida
        $tables = array_values(array_filter($tables, function (array $t) {
            $active = $t['active'] ?? true;
            $capacity = (int) ($t['capacity'] ?? 0);
            return $active && $capacity > 0;
        }));

        // --- Buscar recursos (mesas) replicando el código JS del flujo n8n ---

        // Duración: 30 minutos por cada 2 personas (redondeando hacia arriba)
        $minutes = (int) ceil($capacity / 2) * 30;

        // Fechas según la zona horaria de la app
        $tz = config('app.timezone', 'UTC');
        $start = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}", $tz);
        $stop = (clone $start)->addMinutes($minutes);

        // Formato YYYY-MM-DD HH:mm:ss para Odoo
        $startStr = $start->format('Y-m-d H:i:s');
        $stopStr = $stop->format('Y-m-d H:i:s');

        $selectedTables = [];
        $status = 'ok';
        $message = 'Reserva creada correctamente';

        // 1️⃣ Mesa con capacidad exacta
        $exactTable = null;
        foreach ($tables as $t) {
            if ((int) ($t['capacity'] ?? 0) === $capacity) {
                $exactTable = $t;
                break;
            }
        }

        if ($exactTable) {
            $selectedTables = [$exactTable];
        } else {
            // 2️⃣ Mesa única más grande pero cercana
            $biggerTables = array_filter($tables, function (array $t) use ($capacity) {
                return (int) ($t['capacity'] ?? 0) > $capacity;
            });

            usort($biggerTables, function (array $a, array $b) {
                return (int) ($a['capacity'] ?? 0) <=> (int) ($b['capacity'] ?? 0);
            });

            if (!empty($biggerTables)) {
                $selectedTables = [reset($biggerTables)];
            } else {
                // 3️⃣ Combinar mesas más pequeñas (último recurso)
                $smallerTables = array_filter($tables, function (array $t) use ($capacity) {
                    return (int) ($t['capacity'] ?? 0) < $capacity;
                });

                usort($smallerTables, function (array $a, array $b) {
                    return (int) ($b['capacity'] ?? 0) <=> (int) ($a['capacity'] ?? 0);
                });

                $accumulatedCapacity = 0;
                foreach ($smallerTables as $t) {
                    if ($accumulatedCapacity >= $capacity) {
                        break;
                    }

                    $selectedTables[] = $t;
                    $accumulatedCapacity += (int) ($t['capacity'] ?? 0);
                }

                if ($accumulatedCapacity < $capacity) {
                    $status = 'no_available_tables';
                    $message = 'No hay mesas disponibles para la capacidad solicitada';
                    return response()->json([
                        'status'       => $status,
                        'message'      => $message,
                        'reservations' => [],
                    ], 422);
                }
            }
        }

        // 4️⃣ Asignar capacidad reservada por mesa
        $remainingCapacity = $capacity;
        $reservations = [];

        foreach ($selectedTables as $t) {
            $tableCapacity = (int) ($t['capacity'] ?? 0);
            $reserved = min($tableCapacity, $remainingCapacity);
            $remainingCapacity -= $reserved;

            $reservations[] = [
                'table_id'          => $t['id'],
                'table_capacity'    => $tableCapacity,
                'reserved_capacity' => $reserved,
                'start'             => $startStr,
                'stop'              => $stopStr,
            ];
        }

        // Seguridad: si por alguna razón no se cubre la capacidad, devolvemos error
        if ($remainingCapacity > 0) {
            return response()->json([
                'status'       => 'no_available_tables',
                'message'      => 'No se pudo asignar toda la capacidad solicitada',
                'reservations' => $reservations,
            ], 422);
        }

        // 5️⃣ Crear evento en calendar.event
        try {
            $notesExtra = count($reservations) > 1
                ? "\n\nEs parte de una reserva de {$capacity} personas"
                : '';

            $eventId = $odoo->create('calendar.event', [
                'name'                => 'Reserva ' . $data['full_name'],
                'start'               => $startStr,
                'stop'                => $stopStr,
                'appointment_type_id' => 2,
                'notes'               => 'Teléfono ' . $data['phone_number'] . $notesExtra,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error_creating_event',
                'message' => 'Error creando el evento en Odoo: ' . $e->getMessage(),
            ], 500);
        }

        // 6️⃣ Crear booking lines (appointment.booking.line) para cada mesa
        try {
            foreach ($reservations as &$reservation) {
                $bookingId = $odoo->create('appointment.booking.line', [
                    'appointment_resource_id' => $reservation['table_id'],
                    'appointment_type_id'     => 2,
                    'capacity_reserved'       => $reservation['reserved_capacity'],
                    'calendar_event_id'       => $eventId,
                ]);

                $reservation['booking_id'] = $bookingId;
            }
            unset($reservation);
        } catch (\Throwable $e) {
            return response()->json([
                'status'       => 'error_creating_booking',
                'message'      => 'Error creando las reservas en Odoo: ' . $e->getMessage(),
                'event_id'     => $eventId,
                'reservations' => $reservations,
            ], 500);
        }

        return response()->json([
            'status'       => $status,
            'message'      => $message,
            'event_id'     => $eventId,
            'reservations' => $reservations,
        ]);
    }
}
