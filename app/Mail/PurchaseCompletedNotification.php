<?php

namespace App\Mail;

use App\Models\StripeOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseCompletedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $pack;

    /**
     * Create a new message instance.
     */
    public function __construct(StripeOrder $order, array $pack)
    {
        $this->order = $order;
        $this->pack = $pack;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【ヴァルゼリア】ご購入ありがとうございます',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase_completed',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
