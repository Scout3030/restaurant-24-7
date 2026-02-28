<?php

namespace App\Services;

use MissaelAnda\Whatsapp\Exceptions\MessageRequestException;
use MissaelAnda\Whatsapp\Facade\Whatsapp;
use MissaelAnda\Whatsapp\Messages\Components\Body;
use MissaelAnda\Whatsapp\Messages\Components\Button;
use MissaelAnda\Whatsapp\Messages\Components\Parameters\Text;
use MissaelAnda\Whatsapp\Messages\TemplateMessage;
use MissaelAnda\Whatsapp\Messages\Enums\ButtonType;

// https://github.com/MissaelAnda/laravel-whatsapp
class WhatsAppBusinessCloudService
{
    /**
     * Envía una plantilla de WhatsApp a un número de teléfono.
     *
     * @param string $phoneNumber Número en formato internacional (ej: +34600000000)
     * @param string $templateName Nombre de la plantilla aprobada en Meta
     * @param string $languageCode Código de idioma (ej: es, en_US)
     * @param array $bodyParameters Parámetros para el body (ej: ['valor1', 'valor2'])
     * @param array $buttonParameters Parámetros para el body (ej: ['valor1', 'valor2'])
     * @return array{success: bool, message_id?: string, error?: string}
     * @throws MessageRequestException
     */
    public function sendTemplate(
        string $phoneNumber,
        string $templateName,
        string $languageCode = 'es',
        array $bodyParameters = [],
        array $buttonParameters = []
    ): array {
        $buttons = [];

        if (!empty($buttonParameters)) {
            foreach ($buttonParameters as $index => $value) {
                $buttons[] = Button::create(
                    ButtonType::Url,
                    $index,
                    null,
                    (string) $value
                );
            }
        }

        $parameters = array_map(
            fn ($value) => Text::create((string) $value),
            $bodyParameters
        );

        $message = TemplateMessage::create()
            ->name($templateName)
            ->language($languageCode)
            ->body(Body::create($parameters));

        if (!empty($buttons)) {
            $message->buttons($buttons);
        }

        $response = Whatsapp::send($phoneNumber, $message);

        return [
            'success' => true,
            'message_id' => $response->id,
        ];
    }
}
