<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email de informe ya renderizado (asunto + cuerpo HTML salidos de una
 * PlantillaEmail). El Mailable no sabe de métricas: solo envuelve el cuerpo
 * en el layout común.
 */
class InformeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $cuerpo,
        public ?string $color = null,
    ) {}

    /**
     * Con los mailers "log" o "array" Laravel da el envío por bueno pero el
     * correo no sale: se usa para avisar en el panel antes de enviar.
     */
    public static function smtpConfigurado(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.informe');
    }
}
