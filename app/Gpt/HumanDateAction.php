<?php

namespace App\Gpt;

use MalteKuhr\LaravelGpt\GptAction;

class HumanDateAction extends GptAction
{
    /**
     * Mensaje de sistema que define el comportamiento del modelo.
     */
    public function systemMessage(): ?string
    {
        return <<<'TXT'
Eres un asistente que interpreta expresiones temporales en español y devuelve una fecha calendario concreta.

Debes seguir estas instrucciones obligatorias:

1. Resuelve una fecha calendario a partir de la expresión temporal del usuario.
2. Prioriza siempre la información explícita presente en la expresión del usuario.
3. Si faltan datos (mes, año), complétalos usando el contexto temporal actual proporcionado.
4. Si se menciona un número entre 1 y 31 sin aclaración adicional, interprétalo como día del mes, no como número de semana del año.
5. “La semana del X” se define como la semana que contiene el día X del mes asumido.
6. “El lunes de la semana del X” corresponde al lunes de esa semana.
7. La semana comienza en lunes.
8. No utilices semanas ISO del año.
9. No proyectes a meses futuros ni pasados si existe una interpretación válida en el mes actual.
10. No inventes ni completes información que no sea estrictamente necesaria.

Devuelve SIEMPRE y EXCLUSIVAMENTE un JSON con esta estructura exacta:
{
  "fecha": "YYYY-MM-DD"
}
TXT;
    }

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

