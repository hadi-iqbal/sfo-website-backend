<?php

namespace App\Services;

use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NirvanaCorrespondence
{
    private function escape(?string $value): string
    {
        return e($value ?? '');
    }

    private function send(string $recipient, string $subject, string $title, string $body, ?string $url = null, ?string $button = null): bool
    {
        $safeTitle = $this->escape($title);
        $safeBody = nl2br($this->escape($body), false);

        $html = '<!DOCTYPE html><html lang="en"><head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="light">'
            .'<title>'.$safeTitle.'</title></head>'
            .'<body style="margin:0;padding:0;background-color:#d5deeb;">'
            // Hidden preheader text (shown next to the subject in inbox lists)
            .'<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#d5deeb;">'.$safeTitle.'</div>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#d5deeb" style="background-color:#d5deeb;">'
            .'<tr><td align="center" style="padding:28px 12px;">'
            .'<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;">'

            // Header band
            .'<tr><td bgcolor="#0c2148" style="background-color:#0c2148;padding:30px 36px 26px 36px;border-radius:12px 12px 0 0;font-family:Arial,Helvetica,sans-serif;">'
            .'<div style="color:#ff7b00;font-size:13px;font-weight:bold;letter-spacing:2px;line-height:1.4;">SECURITY FINANCIAL ONLINE</div>'
            .'<div style="color:#ffffff;font-size:22px;font-weight:bold;line-height:1.3;padding-top:10px;">Welcome to Securityfinancialonline.com</div>'
            .'<div style="color:#d5deeb;font-size:14px;line-height:1.5;padding-top:6px;">Nationwide Insurance Protection Since 1997</div>'
            .'</td></tr>'

            // Orange accent bar
            .'<tr><td height="5" bgcolor="#ff7b00" style="background-color:#ff7b00;font-size:0;line-height:0;height:5px;">&nbsp;</td></tr>'

            // Content card
            .'<tr><td bgcolor="#ffffff" style="background-color:#ffffff;padding:36px 36px 12px 36px;font-family:Arial,Helvetica,sans-serif;color:#0c2148;">'
            .'<h1 style="margin:0 0 20px 0;font-size:26px;line-height:1.25;font-weight:bold;color:#0c2148;font-family:Arial,Helvetica,sans-serif;">'.$safeTitle.'</h1>'
            .'<div style="font-size:16px;line-height:1.65;color:#0c2148;font-family:Arial,Helvetica,sans-serif;">'.$safeBody.'</div>'
            .'</td></tr>';

        if ($url && $button) {
            $html .= '<tr><td bgcolor="#ffffff" align="left" style="background-color:#ffffff;padding:20px 36px 12px 36px;">'
                .'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                .'<td align="center" bgcolor="#0c2148" style="background-color:#0c2148;border-radius:28px;">'
                .'<a href="'.$this->escape($url).'" target="_blank" style="display:inline-block;padding:14px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:28px;">'.$this->escape($button).'</a>'
                .'</td></tr></table>'
                .'</td></tr>';
        }

        $html .= '<tr><td bgcolor="#ffffff" style="background-color:#ffffff;padding:12px 36px 0 36px;font-size:0;line-height:0;">&nbsp;</td></tr>'

            // Footer
            .'<tr><td bgcolor="#ffffff" style="background-color:#ffffff;padding:0 36px 32px 36px;border-radius:0 0 12px 12px;font-family:Arial,Helvetica,sans-serif;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td style="border-top:1px solid #d5deeb;padding-top:20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#52637d;">'
            .'<div style="color:#0c2148;font-weight:bold;">Securityfinancialonline.com</div>'
            .'<div>Nationwide Insurance Protection Since 1997</div>'
            .'<div style="padding-top:6px;">Call us: <a href="tel:+18666745527" style="color:#ff7b00;font-weight:bold;text-decoration:none;">(866) 674-5527</a></div>'
            .'</td></tr></table>'
            .'</td></tr>'

            .'</table>'
            .'</td></tr></table>'
            .'</body></html>';

        try {
            if (config('nirvana.email_transport') === 'graph') {
                app(MicrosoftGraphMail::class)->send($recipient, $subject, $html);
            } elseif (config('nirvana.email_transport') === 'laravel' && app()->environment('testing')) {
                Mail::html($html, fn ($message) => $message->to($recipient)->subject($subject));
            } else {
                throw new \RuntimeException('Nirvana email transport is not configured.');
            }
            return true;
        } catch (\Throwable $exception) {
            logger()->error('Nirvana email delivery failed', ['recipient' => $recipient, 'subject' => $subject, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            return false;
        }
    }

    public function welcome(Conversation $conversation): bool
    {
        return $this->send($conversation->email, 'Welcome to Nirvana AI', 'Welcome, '.$conversation->name,
            "Thank you for starting a conversation with Nirvana AI. We can help you understand insurance, explore a rate guide, and connect with the SFO team.\n\nInsurance Financial Solutions has served families since 1997. A licensed agent can help you review your options. Product availability and eligibility vary by state and underwriting.");
    }

    public function rate(Conversation $conversation, int $requestId): bool
    {
        $rate = DB::table('nirvana_rate_requests')->where('id', $requestId)->where('conversation_id', $conversation->id)->first();
        if (!$rate || $rate->emailed_at) return true;
        $answers = Crypt::decryptString($rate->answers);
        $sent = $this->send($conversation->email, 'Your SFO rate guide', 'Your rate guide',
            "Hi {$conversation->name},\n\nHere are the answers you submitted:\n{$answers}\n\n{$rate->result}\n\nThis is an illustrative guide, not an offer of coverage. A licensed agent can explain eligibility and available products.");
        if ($sent) DB::table('nirvana_rate_requests')->where('id', $requestId)->whereNull('emailed_at')->update(['emailed_at' => now()]);
        return $sent;
    }

    public function meeting(Conversation $conversation, int $meetingId): bool
    {
        $meeting = DB::table('nirvana_meetings')->where('id', $meetingId)->where('conversation_id', $conversation->id)->where('status', 'confirmed')->first();
        if (!$meeting || !$meeting->join_url) return false;
        if ($meeting->emailed_at) return true;
        $utc = CarbonImmutable::parse($meeting->starts_at, 'UTC');
        $zone = $meeting->visitor_timezone ?: config('nirvana.booking_timezone');
        $body = "Hi {$conversation->name},\n\nYour Zoom meeting with SFO is confirmed.\nYour time: ".$utc->setTimezone($zone)->format('M j, Y g:i A T')." ({$zone})\nCalifornia time: ".$utc->setTimezone('America/Los_Angeles')->format('M j, Y g:i A T')."\n\nYou can use the button below to join at the scheduled time.";
        $sent = $this->send($conversation->email, 'Your SFO Zoom meeting', 'Your meeting is confirmed', $body, $meeting->join_url, 'Join Zoom meeting');
        if ($sent) DB::table('nirvana_meetings')->where('id', $meetingId)->whereNull('emailed_at')->update(['emailed_at' => now()]);
        return $sent;
    }

    private function sendRecapOnce(int $conversationId, string $kind, string $recipient, string $subject,
        string $title, string $body, ?string $url): bool
    {
        $key = ['conversation_id' => $conversationId, 'kind' => $kind, 'recipient' => strtolower($recipient)];
        if (DB::table('nirvana_mail_deliveries')->where($key)->exists()) return true;
        if (!$this->send($recipient, $subject, $title, $body, $url, $url ? 'Join Zoom meeting' : null)) return false;
        DB::table('nirvana_mail_deliveries')->insertOrIgnore([...$key, 'sent_at' => now()]);
        return true;
    }

    public function finalize(Conversation $conversation): bool
    {
        $lock = Cache::lock('nirvana-recap:'.$conversation->id, 45);
        if (!$lock->get()) return false;
        try {
            return $this->finalizeLocked($conversation);
        } finally {
            $lock->release();
        }
    }

    private function finalizeLocked(Conversation $conversation): bool
    {
        $conversation = DB::table('conversations')->where('id', $conversation->id)->first();
        if (!$conversation || $conversation->nirvana_summary_sent_at) return true;
        $departed = $conversation->nirvana_end_requested_at &&
            CarbonImmutable::parse($conversation->nirvana_end_requested_at)->lte(now()->subSeconds(max(30, (int) config('nirvana.end_grace_seconds'))));
        $stale = $conversation->nirvana_last_seen_at &&
            CarbonImmutable::parse($conversation->nirvana_last_seen_at)->lte(now()->subMinutes(max(10, (int) config('nirvana.stale_minutes'))));
        if (!$departed && !$stale) return false;
        $turns = DB::table('messages')->where('conversation_id', $conversation->id)->orderBy('id')->get(['sender', 'body']);
        $rates = DB::table('nirvana_rate_requests')->where('conversation_id', $conversation->id)->orderBy('id')->get();
        $meetings = DB::table('nirvana_meetings')->where('conversation_id', $conversation->id)->orderBy('id')->get();
        $appointments = DB::table('leads')->where('type', 'schedule')->where('payload->conversation_id', $conversation->id)->orderBy('id')->get(['payload']);
        $topics = $turns->where('sender', 'visitor')->pluck('body')->map(fn ($body) => trim($body))->filter()->take(8)->implode(' | ');
        $summary = "Visitor: {$conversation->name}\nEmail: {$conversation->email}\nPhone: {$conversation->phone}\n";
        $summary .= $topics ? "Topics and requests: {$topics}\n" : "Status: Conversation started; no question submitted yet.\n";
        $summary .= 'Rate guides: '.$rates->count()."\nZoom meetings: ".$meetings->where('status', 'confirmed')->count()."\n";
        $summary .= 'Callback or exam requests: '.$appointments->count()."\n";
        $staff = $summary."\nFull conversation:\n";
        foreach ($turns as $turn) $staff .= "\n{$turn->sender}: {$turn->body}\n";
        $visitor = "Hi {$conversation->name},\n\nHere is a recap of your conversation with Nirvana AI:\n".$summary."\nConversation:\n";
        foreach ($turns as $turn) $visitor .= "\n{$turn->sender}: {$turn->body}\n";
        foreach ($rates as $rate) {
            $answers = Crypt::decryptString($rate->answers);
            $staff .= "\nRate guide answers:\n{$answers}\nQuoted result:\n{$rate->result}\n";
            $visitor .= "\nYour rate guide answers:\n{$answers}\nQuoted result:\n{$rate->result}\n";
        }
        foreach ($meetings as $meeting) {
            $zone = $meeting->visitor_timezone ?: config('nirvana.booking_timezone');
            $utc = CarbonImmutable::parse($meeting->starts_at, 'UTC');
            $details = "\nZoom status: {$meeting->status}\nVisitor time: ".$utc->setTimezone($zone)->format('M j, Y g:i A T')." ({$zone})\nCalifornia time: ".$utc->setTimezone('America/Los_Angeles')->format('M j, Y g:i A T')."\nJoin link: {$meeting->join_url}\n";
            $staff .= $details;
            $visitor .= $details;
        }
        foreach ($appointments as $appointment) {
            $data = json_decode($appointment->payload, true) ?: [];
            $details = "\nRequested follow-up: ".(($data['purpose'] ?? '') === 'exam' ? 'Insurance exam change' : 'Callback')
                ."\nPreferred time: ".($data['date'] ?? 'Not specified').' '.($data['time'] ?? '').' '.($data['timezone'] ?? 'America/Los_Angeles')."\n";
            $staff .= $details;
            $visitor .= $details;
        }
        $visitor .= "\nSecurity Financial Online has served families since 1997. Reply to this email or call (866) 674-5527 for help with your next step. Product availability and eligibility vary by state and underwriting.";
        DB::table('conversations')->where('id', $conversation->id)->update(['nirvana_summary' => $staff]);
        $staffSent = true;
        foreach (config('nirvana.staff_emails') as $address) {
            $staffSent = $this->sendRecapOnce($conversation->id, 'staff_recap', $address,
                'Nirvana conversation: '.$conversation->name, 'New Nirvana conversation', $staff,
                $meetings->firstWhere('status', 'confirmed')?->join_url) && $staffSent;
        }
        $visitorSent = $this->sendRecapOnce($conversation->id, 'visitor_recap', $conversation->email,
            'Your Nirvana AI conversation recap', 'Your conversation recap', $visitor,
            $meetings->firstWhere('status', 'confirmed')?->join_url);
        if ($staffSent && $visitorSent) DB::table('conversations')->where('id', $conversation->id)->whereNull('nirvana_summary_sent_at')->update(['nirvana_summary_sent_at' => now()]);
        return $staffSent && $visitorSent;
    }
}