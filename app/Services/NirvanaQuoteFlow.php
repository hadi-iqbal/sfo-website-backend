<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class NirvanaQuoteFlow
{
    private const FIELDS = ['age', 'gender', 'face_amount', 'term'];
    private const EXAM_INFO = <<<'COPY'
**Completing an insurance exam could help you save up to 30-40% on insurance premiums** by allowing the insurance company to accurately assess your overall health.

As part of your life insurance or mortgage life insurance application, your carrier may require a brief paramedical exam to help complete the underwriting process. Our exam team will coordinate your appointment through APPS Paramedical or ExamOne Quest Diagnostics at a time and location that's most convenient for you, whether at your home, workplace, or a nearby exam center. The exam typically takes 20-40 minutes and may include your height, weight, blood pressure, and the collection of blood and urine samples. Depending on your age, health history, and the coverage amount, additional requirements such as an EKG or a medical interview may be requested.

Taking the insurance exam is free, confidential, and carries no obligation. It simply gives the insurance company the information needed to make you a formal coverage offer and determine your best available rate. After your exam, your licensed agent will contact you to guide you through the next steps.
COPY;
    private const DISCLAIMER = <<<'COPY'
**Important Notes:**
- These rate guides are based on health, product availability, state, and qualifying medically.
- By continuing, you authorize Securityfinancialonline.com to contact you by phone, email, or text regarding insurance products or services. You may opt out at any time.
- Product availability varies by state.
- Insurance underwritten by Corebridge Financial.
COPY;

    public function respond(Conversation $conversation, string $message): ?string
    {
        $text = mb_strtolower(trim($message));
        $state = $conversation->nirvana_quote_state ? json_decode($conversation->nirvana_quote_state, true) : null;
        if (preg_match('/^(cancel|stop|exit) (?:quote|rate guide)$/i', $text)) {
            $conversation->forceFill(['nirvana_quote_state' => null])->save();
            return 'Rate guide cancelled. Ask for another rate guide whenever you are ready.';
        }
        // Keep an unfinished guide available, but let Nirvana answer a new question or concern first.
        if ($state !== null && preg_match('/[?¿؟]|\b(?:worried|scared|confused|frustrated|anxious|upset|afraid|overwhelmed|don.t understand|not sure)\b/iu', $text)
            && !preg_match('/^(?:get|show|give|calculate|check|estimate|find)\b.*\b(?:quote|rate guide|rates?|premium|price)\b/i', $text)) {
            return null;
        }
        if (preg_match('/^(reset|restart|new|another|different) (?:quote|rate guide)$/i', $text)) $state = [];
        if ($state === null && !preg_match('/\b(?:get|show|give|need|want|calculate|check|estimate|find)\b.*\b(?:quote|rate guide|rates?|premium|price)\b|^(?:quote|rate guide|rates?)(?:\b|:)/i', $text)) return null;
        $state ??= [];
        if (preg_match('/\b(?:age\s*[:\-]?\s*|i am\s+|i.m\s+)(\d{2})\b|\b(\d{2})\s*(?:years? old|yrs? old|yo)\b|^(\d{2})$/i', $text, $match)) {
            $candidate = (int) (($match[1] ?? '') ?: (($match[2] ?? '') ?: ($match[3] ?? '')));
            if ($candidate >= 25 && $candidate <= 70) $state['age'] = $candidate;
        }
        if (preg_match('/\b(female|woman|male|man)\b/i', $text, $match)) $state['gender'] = in_array($match[1], ['female', 'woman']) ? 'Female' : 'Male';
        if (preg_match_all('/\$?\s*([\d,]+(?:\.\d+)?)\s*(million|m|thousand|k)?\b/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $amount = (float) str_replace(',', '', $match[1]);
                $unit = $match[2] ?? '';
                if (in_array($unit, ['million', 'm'])) $amount *= 1000000;
                if (in_array($unit, ['thousand', 'k'])) $amount *= 1000;
                if ($amount >= 100000 && $amount <= 2000000 && (int) $amount % 50000 === 0) $state['face_amount'] = (int) $amount;
            }
        }
        if (preg_match('/\b(10|15|20|25|30)\s*(?:-?\s*years?|yrs?)\b|\bterm\s*[:\-]?\s*(10|15|20|25|30)\b/i', $text, $match)) $state['term'] = (int) (($match[1] ?? '') ?: ($match[2] ?? ''));
        $conversation->forceFill(['nirvana_quote_state' => json_encode($state)])->save();
        foreach (self::FIELDS as $field) {
            if (empty($state[$field])) {
                return match ($field) {
                    'age' => 'To prepare your rate guide, what is your age? This guide covers ages 25 through 70.',
                    'gender' => 'Thank you. Which gender should I use for this rate guide: Male or Female?',
                    'face_amount' => 'How much coverage would you like, from $100,000 to $2,000,000 in $50,000 steps?',
                    'term' => 'Which term would you like: 10, 15, 20, 25, or 30 years?',
                };
            }
        }
        $row = DB::table('nirvana_rates')->where([
            'age' => $state['age'], 'gender' => $state['gender'], 'face_amount' => $state['face_amount'],
            'term' => $state['term'], 'version' => config('nirvana.rate_version'),
        ])->first();
        $conversation->forceFill(['nirvana_quote_state' => null])->save();
        if (!$row) return 'I could not find a matching rate in the guide. A licensed SFO agent can review your options with you.';
        $labels = ['preferred_plus' => 'Preferred Plus', 'preferred_non_tobacco' => 'Preferred Non Tobacco', 'standard_plus' => 'Standard Plus', 'standard_non_tobacco' => 'Standard Non Tobacco', 'preferred_tobacco' => 'Preferred Tobacco', 'standard_tobacco' => 'Standard Tobacco'];
        $lines = ["Here are your rate guide results:", "Age: {$state['age']}", "Gender: {$state['gender']}", 'Face amount: $'.number_format($state['face_amount']), "Product term: {$state['term']} years"];
        foreach (json_decode($row->premiums, true) as $tier => $premium) if (isset($labels[$tier])) $lines[] = $labels[$tier].': $'.number_format((float) $premium, 2).'/month';
        $lines[] = self::EXAM_INFO;
        $lines[] = self::DISCLAIMER;
        $result = implode("\n", $lines);
        $answers = "Age: {$state['age']}\nGender: {$state['gender']}\nFace amount: $".number_format($state['face_amount'])."\nProduct term: {$state['term']} years";
        $rateId = DB::table('nirvana_rate_requests')->insertGetId([
            'conversation_id' => $conversation->id, 'answers' => Crypt::encryptString($answers),
            'result' => $result, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(NirvanaCorrespondence::class)->rate($conversation, $rateId);
        return $result;
    }
}
