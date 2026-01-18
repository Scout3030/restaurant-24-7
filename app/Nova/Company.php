<?php

namespace App\Nova;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Panel;
use Laravel\Nova\Http\Requests\NovaRequest;

class Company extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<\App\Models\Company>
     */
    public static $model = \App\Models\Company::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
        'name',
        'odoo_database',
        'odoo_host',
        'odoo_username',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Text::make('API Base URL', function () {
                return rtrim(config('app.url'), '/') . '/api/reservaciones/' . $this->slug;
            })
                ->onlyOnIndex()
                ->copyable(),

            new Panel('Información general', [
                Text::make('Nombre', 'name')
                    ->sortable()
                    ->rules('required', 'max:255'),

                Text::make('Slug', 'slug')
                    ->rules('required', 'max:255')
                    ->creationRules('unique:companies,slug')
                    ->updateRules('unique:companies,slug,{{resourceId}}'),

                Text::make('API Token', 'api_token')
                    ->rules('required')
                    ->hideFromIndex(),

                Select::make('Timezone', 'timezone')
                    ->options([
                        'Europe/Madrid'   => 'Europa/Madrid',
                        'Atlantic/Canary' => 'Atlántico/Canarias',
                    ])
                    ->displayUsingLabels()
                    ->rules('required')
                    ->default('Atlantic/Canary'),

                Select::make('Estado por defecto de la reserva', 'appointment_status')
                    ->options([
                        'request' => 'Solicitado',
                        'booked'  => 'Reservado',
                    ])
                    ->displayUsingLabels()
                    ->rules('required')
                    ->default('request'),

                Text::make('WhatsApp Webhook URL', 'whatsapp_webhook_url')
                    ->rules('required', 'url')
                    ->hideFromIndex(),

                Text::make('Número de teléfono asignado', 'assigned_phone_number')
                    ->nullable()
                    ->hideFromIndex(),
            ]),

            new Panel('Conexión Odoo', [
                Text::make('Odoo Database', 'odoo_database')
                    ->rules('required', 'max:255')
                    ->hideFromIndex(),

                Text::make('Odoo Host', 'odoo_host')
                    ->rules('required', 'max:255')
                    ->hideFromIndex(),

                Text::make('Odoo Username', 'odoo_username')
                    ->rules('required', 'max:255')
                    ->hideFromIndex(),

                Text::make('Odoo Password', 'odoo_password')
                    ->rules('required', 'max:255')
                    ->onlyOnForms(),
            ]),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function cards(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function filters(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function lenses(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function actions(NovaRequest $request)
    {
        return [];
    }
}
