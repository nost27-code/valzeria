<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\StripeOrder;
use App\Models\KisekiTransaction;
use App\Models\StripePaymentAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook parse error.', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Parse error'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutSessionCompleted($event->data->object, $event->id, $event->type);
        } elseif (in_array($event->type, ['checkout.session.expired', 'payment_intent.canceled', 'charge.refunded'], true)) {
            $this->recordTerminalAudit($event->data->object, $event->id, $event->type);
        }

        return response()->json(['received' => true]);
    }

    private function handleCheckoutSessionCompleted(object $session, string $eventId, string $eventType): void
    {
        $sessionId    = $session->id;
        $metadata     = $session->metadata;
        $characterId  = (int) ($metadata->character_id ?? 0);
        $packKey      = (string) ($metadata->pack_key ?? '');
        $kisekiAmount = (int) ($metadata->kiseki_amount ?? 0);
        $audit = $this->recordAudit([
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $this->stringValue($session->payment_intent ?? null),
            'character_id' => $characterId ?: null,
            'pack_key' => $packKey ?: null,
            'kiseki_amount' => $kisekiAmount ?: null,
            'status' => 'received',
            'idempotency_key' => $sessionId,
            'webhook_received_at' => now(),
            'payload' => $this->payloadArray($session),
        ]);

        if ($audit && !$audit->wasRecentlyCreated && $audit->status !== 'received') {
            Log::info('Stripe webhook: duplicate event, skipping.', [
                'event_id' => $eventId,
                'session_id' => $sessionId,
                'audit_status' => $audit->status,
            ]);
            return;
        }

        if (!$characterId || !$packKey || $kisekiAmount <= 0) {
            Log::error('Stripe webhook: invalid metadata', ['session_id' => $sessionId]);
            $this->markAudit($audit, 'failed', ['error_message' => 'invalid metadata']);
            return;
        }

        $pack = config("kiseki.packs.{$packKey}");
        if (!$pack) {
            Log::error('Stripe webhook: unknown pack_key', ['pack_key' => $packKey]);
            $this->markAudit($audit, 'failed', ['error_message' => 'unknown pack_key']);
            return;
        }

        $order = null;
        $character = null;

        DB::transaction(function () use ($sessionId, $characterId, $packKey, $kisekiAmount, $pack, $audit, &$order, &$character) {
            // 二重付与防止: session_id が既に存在すれば何もしない
            $existingOrder = StripeOrder::where('session_id', $sessionId)->lockForUpdate()->first();
            if ($existingOrder) {
                Log::info('Stripe webhook: duplicate session, skipping.', ['session_id' => $sessionId]);
                $this->markAudit($audit, 'duplicate', [
                    'stripe_order_id' => $existingOrder->id,
                    'product_name' => $pack['name'] ?? $packKey,
                    'price_jpy' => $pack['price_jpy'] ?? null,
                ]);
                return;
            }

            $character = Character::with('user')->where('id', $characterId)->lockForUpdate()->first();
            if (!$character) {
                Log::error('Stripe webhook: character not found', ['character_id' => $characterId]);
                $this->markAudit($audit, 'failed', [
                    'product_name' => $pack['name'] ?? $packKey,
                    'price_jpy' => $pack['price_jpy'] ?? null,
                    'error_message' => 'character not found',
                ]);
                return;
            }

            // 輝石付与（kiseki = paid + free の合計として同期）
            $newPaid = ($character->paid_kiseki ?? 0) + $kisekiAmount;
            $character->paid_kiseki = $newPaid;
            $character->kiseki = $newPaid + ($character->free_kiseki ?? 0);
            $character->save();

            // 注文レコード保存
            $order = StripeOrder::create([
                'session_id'    => $sessionId,
                'character_id'  => $characterId,
                'pack_key'      => $packKey,
                'kiseki_amount' => $kisekiAmount,
                'price_jpy'     => $pack['price_jpy'],
                'status'        => 'fulfilled',
                'fulfilled_at'  => now(),
            ]);

            // 取引履歴保存（既存テーブルのカラム名に合わせる）
            KisekiTransaction::create([
                'character_id'     => $characterId,
                'kiseki_type'      => 'paid',
                'amount'           => $kisekiAmount,
                'transaction_type' => 'purchase',
                'source_type'      => 'stripe_order',
                'source_id'        => $order->id,
                'description'      => $pack['name'],
            ]);

            $this->markAudit($audit, 'fulfilled', [
                'stripe_order_id' => $order->id,
                'user_id' => $character->user_id,
                'character_id' => $character->id,
                'product_name' => $pack['name'] ?? $packKey,
                'price_jpy' => $pack['price_jpy'] ?? null,
                'kiseki_amount' => $kisekiAmount,
                'fulfilled_at' => $order->fulfilled_at,
            ]);

            Log::info('Stripe webhook: kiseki fulfilled.', [
                'character_id'  => $characterId,
                'kiseki_amount' => $kisekiAmount,
                'pack_key'      => $packKey,
                'session_id'    => $sessionId,
            ]);
        });

        if (!$order || !$character) {
            return;
        }

        $buyerEmail = $session->customer_details->email ?? null;
        try {
            $mailer = Mail::mailer(config('mail.purchase_notification_mailer'));

            if ($buyerEmail) {
                $mailer->to($buyerEmail)->send(new \App\Mail\PurchaseCompletedNotification($order, $pack));
            }

            $adminEmail = config('mail.admin_purchase_notification_address');
            if ($adminEmail) {
                $mailer->to($adminEmail)->send(new \App\Mail\AdminPurchaseNotification($order, $pack, $character));
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Stripe webhook: mail failed after kiseki fulfillment.', [
                'order_id' => $order->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordTerminalAudit(object $object, string $eventId, string $eventType): void
    {
        $status = match ($eventType) {
            'charge.refunded' => 'refunded',
            default => 'canceled',
        };

        $sessionId = $this->stringValue($object->id ?? null);
        if ($eventType !== 'checkout.session.expired') {
            $sessionId = null;
        }

        $paymentIntentId = $this->stringValue($object->payment_intent ?? null);
        if ($eventType === 'payment_intent.canceled') {
            $paymentIntentId = $this->stringValue($object->id ?? null);
        }

        $this->recordAudit([
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_charge_id' => $eventType === 'charge.refunded' ? $this->stringValue($object->id ?? null) : null,
            'status' => $status,
            'idempotency_key' => $eventId,
            'webhook_received_at' => now(),
            'payload' => $this->payloadArray($object),
        ]);
    }

    private function recordAudit(array $values): ?StripePaymentAudit
    {
        if (!Schema::hasTable('stripe_payment_audits')) {
            return null;
        }

        $eventId = $values['stripe_event_id'] ?? null;
        if ($eventId) {
            return StripePaymentAudit::firstOrCreate(
                ['stripe_event_id' => $eventId],
                $values
            );
        }

        return StripePaymentAudit::create($values);
    }

    private function markAudit(?StripePaymentAudit $audit, string $status, array $values = []): void
    {
        if (!$audit) {
            return;
        }

        $audit->fill(array_merge($values, ['status' => $status]));
        $audit->save();
    }

    private function payloadArray(object $object): array
    {
        if (method_exists($object, 'toArray')) {
            return $object->toArray();
        }

        return json_decode(json_encode($object), true) ?: [];
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
