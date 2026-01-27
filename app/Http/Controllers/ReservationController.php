<?php

namespace App\Http\Controllers;

use App\Gpt\HumanDateAction;
use App\Models\Company;
use App\Services\OdooService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use MalteKuhr\LaravelGpt\Implementations\Parts\InputText;


class ReservationController extends Controller
{
    /* =====================================================
     * AVAILABILITY
     * ===================================================== */
    public function checkAvailability(Company $company, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'     => 'required|date|after_or_equal:today',
            'time'     => 'required|date_format:H:i',
            'capacity' => 'required|integer|min:1',
        ]);

        logger()->info('checkAvailability called', [
            'company_id' => $company->id,
            'company_name' => $company->name ?? null,
            'input' => $data,
        ]);

        $timezone = $company->timezone ?? config('app.timezone', 'UTC');

        $start = Carbon::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['time']}", $timezone);
        $end   = (clone $start)->addHours(2);
        $limit = (clone $start)->addDay()->setTime(3, 0);

        $odoo = (new OdooService($company))->client();

        $tables   = $this->getTables($odoo);
        $occupied = $this->getOccupiedResources($odoo, $start, $end, $limit, $timezone);

        $availableCapacity = $this->calculateAvailableCapacity($tables, $occupied);

        return response()->json([
            'available' => $availableCapacity >= (int) $data['capacity'],
        ]);
    }

    /* =====================================================
     * CURRENT TIME
     * ===================================================== */
    public function currentTime(Company $company): JsonResponse
    {
        logger()->info('currentTime called', [
            'company_id' => $company->id,
            'company_name' => $company->name ?? null,
        ]);

        $timezone = $company->timezone ?? config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);

        return response()->json([
            'referencia_tiempo'      => $now->toIso8601String(),
            'referencia_tiempo_dia'  => $now->toDateString(),
            'referencia_tiempo_hora' => $now->format('H:i'),
        ]);
    }

    /* =====================================================
     * HUMAN DATE
     * ===================================================== */
    public function humanDate(Company $company, Request $request): JsonResponse
    {
        $data = $request->validate([
            'dia'               => 'required|string',
            'referencia_tiempo' => 'nullable|string',
        ]);

        logger()->info('humanDate called', [
            'company_id' => $company->id,
            'company_name' => $company->name ?? null,
            'input' => $data,
        ]);

        $timezone = $company->timezone ?? config('app.timezone', 'UTC');

        $now = isset($data['referencia_tiempo'])
            ? Carbon::parse($data['referencia_tiempo'])->setTimezone($timezone)
            : Carbon::now($timezone);

        $prompt = <<<TXT
Contexto temporal actual:
{$now->toIso8601String()}

Expresión temporal proporcionada por el usuario:
{$data['dia']}
TXT;

        $action = new HumanDateAction([
            new InputText($prompt),
        ]);

        $action->run();

        $fecha = $action->output()['fecha'] ?? null;

        if (!is_string($fecha) || trim($fecha) === '') {
            return response()->json([
                'valido' => false,
                'fecha'  => null,
            ], 422);
        }

        return response()->json([
            'valido' => $fecha >= $now->toDateString(),
            'fecha'  => $fecha,
        ]);
    }

    /**
     * Create a reservation based on provided data and Odoo logic.
     */
    public function createReservation(Company $company, Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'         => 'required|date|after_or_equal:today',
            'time'         => 'required|date_format:H:i',
            'capacity'     => 'required|integer|min:1',
            'full_name'    => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
        ]);

        logger()->info('createReservation called', [
            'company_id' => $company->id,
            'company_name' => $company->name ?? null,
            'input' => $data,
        ]);

        $timezone = $company->timezone ?? config('app.timezone', 'UTC');
        $capacity = (int) $data['capacity'];

        $odoo = (new OdooService($company))->client();

        // 1️⃣ Obtener mesas activas
        $tables = $this->normalizeOdooRows(
            $odoo->searchRead(
                'appointment.resource',
                null,
                ['id', 'resource_id', 'capacity', 'active']
            )
        );

        $tables = array_values(array_filter($tables, fn ($t) =>
            ($t['active'] ?? true) && (int) ($t['capacity'] ?? 0) > 0
        ));


        $start = Carbon::createFromFormat(
            'Y-m-d H:i',
            "{$data['date']} {$data['time']}",
            $timezone
        );

        $stop = (clone $start)->addMinutes(90);

        $startStr = $start->format('Y-m-d H:i:s');
        $stopStr  = $stop->format('Y-m-d H:i:s');

        // 3️⃣ Selección de mesas (lógica n8n)
        $selectedTables = [];

        // Exacta
        foreach ($tables as $t) {
            if ((int) $t['capacity'] === $capacity) {
                $selectedTables = [$t];
                break;
            }
        }

        // Mesa única mayor
        if (empty($selectedTables)) {
            $bigger = array_filter($tables, fn ($t) => (int) $t['capacity'] > $capacity);
            usort($bigger, fn ($a, $b) => (int) $a['capacity'] <=> (int) $b['capacity']);

            if (!empty($bigger)) {
                $selectedTables = [reset($bigger)];
            }
        }

        // Combinar mesas
        if (empty($selectedTables)) {
            $smaller = array_filter($tables, fn ($t) => (int) $t['capacity'] < $capacity);
            usort($smaller, fn ($a, $b) => (int) $b['capacity'] <=> (int) $a['capacity']);

            $sum = 0;
            foreach ($smaller as $t) {
                if ($sum >= $capacity) {
                    break;
                }

                $selectedTables[] = $t;
                $sum += (int) $t['capacity'];
            }

            if ($sum < $capacity) {
                return response()->json([
                    'status'       => 'no_available_tables',
                    'message'      => 'No hay mesas disponibles para la capacidad solicitada',
                    'reservations' => [],
                ], 422);
            }
        }

        // 4️⃣ Asignar capacidad exacta
        $remaining = $capacity;
        $reservations = [];

        foreach ($selectedTables as $t) {
            $reserved = min((int) $t['capacity'], $remaining);
            $remaining -= $reserved;

            $reservations[] = [
                'table_id'          => $t['id'],
                'table_capacity'    => (int) $t['capacity'],
                'reserved_capacity' => $reserved,
                'start'             => $startStr,
                'stop'              => $stopStr,
            ];
        }

        if ($remaining > 0) {
            return response()->json([
                'status'       => 'no_available_tables',
                'message'      => 'No se pudo asignar la capacidad completa',
                'reservations' => $reservations,
            ], 422);
        }

        // 5️⃣ Crear evento
        try {
            $notes = 'Teléfono ' . $data['phone_number'];

            if (count($reservations) > 1) {
                $notes .= "\n\nEs parte de una reserva de {$capacity} personas";
            }

            $eventId = $odoo->create('calendar.event', [
                'name'                => 'Reserva ' . $data['full_name'],
                'start'               => $startStr,
                'stop'                => $stopStr,
                'appointment_type_id' => 1,
                'appointment_status'  => $company->appointment_status ?? 'request',
                'notes'               => $notes,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error_creating_event',
                'message' => $e->getMessage(),
            ], 500);
        }

        // 6️⃣ Crear booking lines
        try {
            foreach ($reservations as &$r) {
                $bookingId = $odoo->create('appointment.booking.line', [
                    'appointment_resource_id' => $r['table_id'],
                    'appointment_type_id'     => 2,
                    'capacity_reserved'       => $r['reserved_capacity'],
                    'calendar_event_id'       => $eventId,
                ]);

                $r['booking_id'] = $bookingId;
            }
            unset($r);
        } catch (\Throwable $e) {
            return response()->json([
                'status'       => 'error_creating_booking',
                'message'      => $e->getMessage(),
                'event_id'     => $eventId,
                'reservations' => $reservations,
            ], 500);
        }

        // 7️⃣ Enviar plantilla de WhatsApp de confirmación (no bloqueante)
        try {
            $this->sendWhatsAppConfirmation($company, $data, $start, $capacity);
        } catch (\Throwable $e) {
            logger()->warning('WhatsApp template send failed', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status'       => 'ok',
            'message'      => 'Reserva creada correctamente',
            'event_id'     => $eventId,
            'reservations' => $reservations,
        ]);
    }

    /* =====================================================
     * PRIVATE HELPERS
     * ===================================================== */

    private function getTables($odoo): array
    {
        $rows = $odoo->searchRead(
            'appointment.resource',
            null,
            ['id', 'resource_id', 'capacity', 'active']
        );

        return $this->normalizeOdooRows($rows);
    }

    private function getOccupiedResources($odoo, Carbon $start, Carbon $end, Carbon $limit, string $timezone): array
    {
        $domain = (new \Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\Domain())
            ->where('active', '=', true)
            ->where('event_stop', '>', $start->format('Y-m-d H:i:s'))
            ->where('event_stop', '<=', $limit->format('Y-m-d H:i:s'));

        $reservations = $this->normalizeOdooRows(
            $odoo->searchRead(
                'appointment.booking.line',
                $domain,
                ['appointment_resource_id', 'event_start', 'event_stop']
            )
        );

        $occupied = [];

        foreach ($reservations as $r) {
            if (
                empty($r['appointment_resource_id']) ||
                empty($r['event_start']) ||
                empty($r['event_stop'])
            ) {
                continue;
            }

            if (
                $this->overlaps(
                    $start,
                    $end,
                    Carbon::parse($r['event_start'], $timezone),
                    Carbon::parse($r['event_stop'], $timezone)
                )
            ) {
                $occupied[$r['appointment_resource_id'][0]] = true;
            }
        }

        return $occupied;
    }

    private function calculateAvailableCapacity(array $tables, array $occupied): int
    {
        $available = array_filter($tables, function ($t) use ($occupied) {
            $resourceId = $t['resource_id'][0] ?? null;

            return ($t['active'] ?? true)
                && $resourceId
                && !isset($occupied[$resourceId]);
        });

        usort($available, function ($a, $b) {
            return (int) ($b['capacity'] ?? 0) <=> (int) ($a['capacity'] ?? 0);
        });

        return array_reduce(
            $available,
            fn ($sum, $t) => $sum + (int) ($t['capacity'] ?? 0),
            0
        );
    }

    private function overlaps(
        Carbon $aStart,
        Carbon $aEnd,
        Carbon $bStart,
        Carbon $bEnd
    ): bool {
        return $aStart->lt($bEnd) && $aEnd->gt($bStart);
    }

    private function normalizeOdooRows(array $rows): array
    {
        return array_map(fn ($row) => (array) $row, $rows);
    }

    /**
     * Enviar datos al webhook de WhatsApp (n8n) para la confirmación de reserva.
     *
     * Replica la intención del nodo "Enviar mensaje a usuario" en n8n:
     *   full_name, phone_number, date, time, capacity.
     * La URL base se configura por empresa y se le añade el sufijo "-test"
     * automáticamente si el entorno no es producción.
     */
    private function sendWhatsAppConfirmation(Company $company, array $data, Carbon $start, int $capacity): void
    {
        $url = rtrim((string) $company->whatsapp_webhook_url, '/');

        $phone = preg_replace('/\s+/', '', $data['phone_number']);
        $defaultCc = '+34';
        if (!str_starts_with($phone, '+')) {
            $phone = $defaultCc . $phone;
        }

        $payload = [
            'full_name'    => $data['full_name'],
            'phone_number' => $phone,
            'date'         => $start->format('Y-m-d'),
            'time'         => $start->format('H:i'),
            'capacity'     => $capacity,
        ];

        logger()->info('Enviando datos a webhook - reserva', [
            'url' => $url,
            'company_id'   => $company->id,
            'company_name' => $company->name ?? null,
            ...$payload,
        ]);

        Http::post($url, $payload);
    }
}
