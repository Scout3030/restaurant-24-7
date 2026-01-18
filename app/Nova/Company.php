<?php

namespace App\Nova;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Select;
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

            Text::make('Nombre', 'name')
                ->sortable()
                ->rules('required', 'max:255'),

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

            Select::make('Timezone', 'timezone')
                ->options([
                    'Europe/Madrid'    => 'Europa/Madrid',
                    'Atlantic/Canary'  => 'Atlántico/Canarias',
                ])
                ->displayUsingLabels()
                ->rules('required')
                ->default('Atlantic/Canary'),

            Text::make('WhatsApp Webhook URL', 'whatsapp_webhook_url')
                ->hideFromIndex(),

            Text::make('Assigned Phone Number', 'assigned_phone_number')
                ->nullable()
                ->hideFromIndex(),

            Select::make('Estado por defecto de la reserva', 'appointment_status')
                ->options([
                    'request' => 'Crear reserva en estado solicitado',
                    'booked'  => 'Reservado',
                ])
                ->displayUsingLabels()
                ->rules('required')
                ->default('request'),
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
