<?php

namespace App\Mail;

use App\Models\ClientIssue;
use App\Models\ClientIssueUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientIssueUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ClientIssue $issue, public ClientIssueUpdate $update)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[' . $this->issue->reference . '] Support issue ' . str_replace('_', ' ', $this->update->status));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.client-issue-update');
    }
}
