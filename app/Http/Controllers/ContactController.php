<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactController extends Controller
{
    public function show()
    {
        return view('legal.contact', [
            'user' => Auth::user(),
            'character' => Auth::user()?->currentCharacter(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sender_name'  => ['nullable', 'string', 'max:100'],
            'sender_email' => ['required', 'email', 'max:255'],
            'category'     => ['required', 'string', 'in:bug,payment,account,report,other'],
            'subject'      => ['required', 'string', 'max:160'],
            'body'         => ['required', 'string', 'max:5000'],
            'attachment'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $user = Auth::user();
        $character = $user?->currentCharacter();

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $filename = uniqid('contact_', true) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('contact_images'), $filename);
            $attachmentPath = 'contact_images/' . $filename;
        }

        ContactMessage::create([
            'user_id'         => $user?->id,
            'character_id'    => $character?->id,
            'recipient_email' => 'info@valzeria.com',
            'sender_name'     => trim((string) ($validated['sender_name'] ?? '')) ?: null,
            'sender_email'    => $validated['sender_email'],
            'category'        => $validated['category'],
            'subject'         => $validated['subject'],
            'body'            => $validated['body'],
            'attachment_path' => $attachmentPath,
            'status'          => 'new',
        ]);

        return redirect()
            ->route('legal.contact')
            ->with('status', 'お問い合わせを送信しました。返信が必要な場合は、入力いただいたメールアドレス宛にご連絡します。');
    }
}
