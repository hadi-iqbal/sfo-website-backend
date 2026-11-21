<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Services\NirvanaCorrespondence;
use App\Support\NirvanaServiceArea;

class NirvanaMeetingController extends Controller
{
    private function host(): ?string
    {
        return config('nirvana.zoom_account') && config('nirvana.zoom_client') && config('nirvana.zoom_secret')
            ? config('nirvana.zoom_host') : null;
    }

    private function available(string $host): array
    {
        $timezone = config('nirvana.booking_timezone');
        $now = CarbonImmutable::now($timezone);
        $reserved = DB::table('nirvana_meetings')->where('host', $host)
            ->where('starts_at', '>=', $now->utc()->toDateTimeString())->pluck('starts_at')->flip();
        $slots = [];
        for ($day = 0; $day < 15; $day++) {
            $date = $now->addDays($day);
            if (!$date->isWeekday()) {
                continue;
            }
            foreach (array_unique(explode(',', config('nirvana.booking_hours'))) as $hour) {
                $hour = filter_var(trim($hour), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
                if ($hour === false) {
                    continue;
                }
                $slot = $date->setTime($hour, 0);
                if ($slot->gt($now->addHour()) && !$reserved->has($slot->utc()->toDateTimeString())) {
                    $slots[] = $slot->utc()->toIso8601String();
                }
            }
        }
        sort($slots);
        return $slots;
    }

    private function reserveStart(string $raw, string $timezone, string $host): CarbonImmutable
    {
        if (in_array($raw, $this->available($host), true)) {
            return CarbonImmutable::parse($raw)->utc();
        }
        try {
            $start = CarbonImmutable::parse($raw)->utc();
        } catch (\Throwable) {
            abort(422, 'Choose a valid time.');
        }
        abort_unless($start->greaterThan(now()->addHour()) && $start->lessThan(now()->addDays(21)), 409, 'Choose a time at least an hour from now and within the next three weeks.');
        $local = $start->setTimezone($timezone);
        $clock = $local->format('H:i');
        abort_unless($local->isWeekday() && $clock >= '06:00' && $clock < '19:00', 422, 'Choose a weekday between 6:00 AM and 7:00 PM in your time zone.');
        $conflict = DB::table('nirvana_meetings')->where('host', $host)
            ->whereIn('status', ['pending', 'confirmed', 'needs_review'])
            ->where('starts_at', '>', $start->subMinutes(30)->toDateTimeString())
            ->where('starts_at', '<', $start->addMinutes(30)->toDateTimeString())
            ->exists();
        abort_if($conflict, 409, 'That time is no longer available. Choose another time.');

        return $start;
    }

    public function slots(Request $request)
    {
        NirvanaController::visitor($request);
        $host = $this->host();
        return ['enabled' => (bool) $host, 'slots' => $host ? $this->available($host) : []];
    }

    public function book(Request $request)
    {
        $conversation = NirvanaController::visitor($request);
        $input = $request->validate([
            'starts_at' => 'required|string|max:40', 'timezone' => 'required|timezone',
            'state' => 'required|string|size:2', 'zip' => 'required|string',
        ]);
        NirvanaServiceArea::validateLocation($input['state'], $input['zip'], $input['timezone']);
        $host = $this->host();
        abort_unless($host, 503, 'Online Zoom booking is unavailable. Please call SFO at (866) 674-5527.');

        try {
            return Cache::lock('nirvana-booking:'.$conversation->id, 40)->block(1, function () use ($conversation, $input, $host) {
                $existing = DB::table('nirvana_meetings')->where('conversation_id', $conversation->id)
                    ->where('starts_at', '>', now('UTC'))->where('status', 'confirmed')->first();
                if ($existing) {
                    return NirvanaController::snapshot($conversation);
                }
                abort_if(DB::table('nirvana_meetings')->where('conversation_id', $conversation->id)
                    ->where('starts_at', '>', now('UTC'))->whereIn('status', ['pending', 'needs_review'])->exists(),
                    409, 'A booking is pending review. Please contact SFO.');
                $start = $this->reserveStart($input['starts_at'], $input['timezone'], $host);
                $reservation = DB::table('nirvana_meetings')->insertOrIgnore([
                    'conversation_id' => $conversation->id, 'host' => $host, 'starts_at' => $start->toDateTimeString(),
                    'status' => 'pending', 'visitor_timezone' => $input['timezone'],
                    'visitor_state' => $input['state'], 'visitor_zip' => $input['zip'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                abort_unless($reservation, 409, 'That time was just booked.');
                $meeting = DB::table('nirvana_meetings')->where('host', $host)->where('starts_at', $start->toDateTimeString());
                try {
                    $token = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
                        ->withBasicAuth(config('nirvana.zoom_client'), config('nirvana.zoom_secret'))
                        ->asForm()->connectTimeout(3)->timeout(7)->post('https://zoom.us/oauth/token', [
                            'grant_type' => 'account_credentials', 'account_id' => config('nirvana.zoom_account'),
                        ])->throw()->json('access_token');
                } catch (\Illuminate\Http\Client\HttpClientException $exception) {
                    $meeting->delete();
                    abort(502, 'Zoom sign-in is temporarily unavailable. Please try again.');
                }

                try {
                    $result = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
                        ->withToken($token)->connectTimeout(3)->timeout(12)
                        ->post('https://api.zoom.us/v2/users/'.rawurlencode($host).'/meetings', [
                            'topic' => 'SFO insurance consultation', 'type' => 2,
                            'start_time' => $start->format('Y-m-d\TH:i:s\Z'), 'duration' => 30,
                            'settings' => ['waiting_room' => true, 'join_before_host' => false],
                        ])->throw()->json();
                } catch (\Illuminate\Http\Client\HttpClientException $exception) {
                    $definitive = $exception instanceof \Illuminate\Http\Client\RequestException
                        && in_array($exception->response->status(), [400, 401, 403, 404, 429], true);
                    if ($definitive) {
                        $meeting->delete();
                    } else {
                        // A timeout can occur after Zoom creates the call. Keep the slot until staff reconciles it.
                        $meeting->update(['status' => 'needs_review', 'updated_at' => now()]);
                    }
                    abort(502, $definitive ? 'Zoom could not confirm the meeting. Please try again.' : 'Booking confirmation is pending review. Please contact SFO before booking again.');
                }

                $joinUrl = $result['join_url'] ?? '';
                if (empty($result['id']) || !is_string($joinUrl) || !preg_match('~^https://(?:[a-z0-9-]+\.)*zoom\.us/[^\s]+$~iD', $joinUrl)) {
                    $meeting->update(['status' => 'needs_review', 'updated_at' => now()]);
                    abort(502, 'Booking confirmation is pending review. Please contact SFO.');
                }
                DB::transaction(function () use ($meeting, $conversation, $result, $joinUrl, $start, $input) {
                    $meeting->update(['status' => 'confirmed', 'zoom_id' => (string) $result['id'], 'join_url' => $joinUrl, 'updated_at' => now()]);
                    $conversation->messages()->create(['sender' => 'visitor', 'body' => 'Book this Zoom time.']);
                    $conversation->forceFill(['last_message_at' => now()])->save();
                });
                $emailSent = app(NirvanaCorrespondence::class)->meeting($conversation, (int) $meeting->first()->id);
                $when = $start->setTimezone($input['timezone'])->format('M j, Y g:i A T').' ('.$input['timezone'].'). California time: '.$start->setTimezone('America/Los_Angeles')->format('M j, Y g:i A T').'.';
                $conversation->messages()->create([
                    'sender' => 'Nirvana',
                    'body' => $emailSent
                        ? 'Your Zoom meeting is confirmed for '.$when.' I emailed the Zoom details to you just now.'
                        : 'Your Zoom meeting is confirmed for '.$when.' I could not send the email just now. Please call SFO at (866) 674-5527 and we will resend the meeting details.',
                    'actions' => ['rates'],
                ]);
                return response()->json([...NirvanaController::snapshot($conversation), 'email_sent' => $emailSent], 201);
            });
        } catch (LockTimeoutException) {
            abort(409, 'A booking is already being processed.');
        }
    }

    public function webhook(Request $request)
    {
        $secret = config('nirvana.zoom_webhook_secret');
        abort_unless($secret, 401);
        if ($request->input('event') === 'endpoint.url_validation') {
            $plainToken = $request->validate(['payload.plainToken' => ['required', 'string', 'max:255', 'not_regex:/:/']])['payload']['plainToken'];
            return ['plainToken' => $plainToken, 'encryptedToken' => hash_hmac('sha256', $plainToken, $secret)];
        }

        $timestamp = $request->header('x-zm-request-timestamp', '');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401);
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$request->getContent(), $secret);
        abort_unless(hash_equals($signature, $request->header('x-zm-signature', '')), 401);

        if ($request->input('event') === 'meeting.summary_completed') {
            $meeting = $request->input('payload.object', []);
            $recap = $meeting['summary_content'] ?? $meeting['summary_overview'] ?? null;
            if (isset($meeting['meeting_id']) && is_string($recap) && $recap !== '') {
                DB::table('nirvana_meetings')->where('zoom_id', (string) $meeting['meeting_id'])
                    ->update(['recap' => mb_substr($recap, 0, 30000), 'updated_at' => now()]);
            }
        }
        return response()->noContent();
    }
}
