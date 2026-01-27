<?php

namespace App\Gpt;

use MalteKuhr\LaravelGpt\GptAction;

class HumanDateAction extends GptAction
{
    /**
     * Reglas de validación / esquema de salida.
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'string'],
        ];
    }
}

