<?php

namespace App\Mail;

use App\Models\StripeOrder;
use App\Models\Character;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPurchaseNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $pack;
    public $character;

    public function __construct(StripeOrder $order, array $pack, Character $character)
    {
        $this->order = $order;
        $this->pack = $pack;
        $this->character = $character;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '【通知】アイテムが購入されました（' . $this->pack['name'] . '）',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_purchase_notification',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
