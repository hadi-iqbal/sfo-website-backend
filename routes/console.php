<?php

require __DIR__.'/nirvana-console.php';

\Illuminate\Support\Facades\Artisan::command('nirvana:test-email {recipient?}', function (?string $recipient = null) {
    try {
        $recipient ??= (string) config('nirvana.graph_sender');
        app(\App\Services\MicrosoftGraphMail::class)->send($recipient, 'SFO Nirvana Graph test',
            '<p>Microsoft Graph accepted this Nirvana AI test email.</p>');
        $this->info('Microsoft Graph accepted the test email for the configured recipient.');
    } catch (\Throwable $exception) {
        $this->error('Microsoft Graph test failed: '.$exception->getMessage());
        return 1;
    }
})->purpose('Verify the configured Nirvana Microsoft Graph sender');

\Illuminate\Support\Facades\Artisan::command('nirvana:finalize-departed', function () {
    $cutoff = now()->subSeconds(max(30, (int) config('nirvana.end_grace_seconds')));
    $stale = now()->subMinutes(max(10, (int) config('nirvana.stale_minutes')));
    \App\Models\Conversation::query()->whereNotNull('visitor_token_hash')
        ->whereNull('nirvana_summary_sent_at')->where(function ($query) use ($cutoff, $stale) {
            $query->where('nirvana_end_requested_at', '<=', $cutoff)
                ->orWhere('nirvana_last_seen_at', '<=', $stale);
        })
        ->chunkById(50, function ($conversations) {
            foreach ($conversations as $conversation) app(\App\Services\NirvanaCorrespondence::class)->finalize($conversation);
        });
})->purpose('Send Nirvana visitor and staff recaps after a tab closes');

\Illuminate\Support\Facades\Schedule::command('nirvana:finalize-departed')->everyMinute()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
