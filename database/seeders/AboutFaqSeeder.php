<?php

namespace Database\Seeders;

use App\Models\CompanyRecord;
use Illuminate\Database\Seeder;

class AboutFaqSeeder extends Seeder
{
    public function run(): void
    {
        if (CompanyRecord::where('kind', 'about_faq')->exists()) {
            return;
        }

        $questions = [
            ['Who is Security Financial Online?', 'Security Financial Online is the public brand of Insurance Financial Solutions. We have served families since 1997.'],
            ['What can an SFO agent help me with?', 'An agent can explain coverage options, answer your questions and help you understand the next steps.'],
            ['How can I speak to an agent?', 'Use the Speak to an agent button to call our office, or send a message through the Contact page.'],
        ];

        foreach ($questions as $order => [$name, $answer]) {
            CompanyRecord::create([
                'kind' => 'about_faq',
                'data' => ['name' => $name, 'answer' => $answer, 'order' => $order, 'published' => true],
            ]);
        }
    }
}
