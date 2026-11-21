<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CompanySeeder::class, TeamProfileDemoSeeder::class, AboutNarrativeSeeder::class, AboutFaqSeeder::class]);
        foreach (config('sfo.permissions') as $permission) {
            Permission::updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@securityfinancialonline.com')],
            [
                'name' => 'SFO Admin',
                'password' => env('ADMIN_PASSWORD', 'ChangeMe!2026'),
                'is_super_admin' => true,
                'is_active' => true,
            ]
        );

        $pages = [
            ['slug' => 'home', 'path' => '/', 'label' => 'Home', 'title' => 'Security Financial Online | Mortgage Life & Term Life Insurance', 'meta_description' => 'Get affordable mortgage life and term life insurance quotes from top A-rated carriers. Compare rates, get expert advice, and secure your coverage online with Security Financial Online.'],
            ['slug' => 'plans', 'path' => '/plans', 'label' => 'Insurance Plans', 'title' => 'Insurance Plans | Security Financial Online', 'meta_description' => 'Compare mortgage life, term life, chronic illness, indexed universal life, final expense, and annuity options with Security Financial Online.'],
            ['slug' => 'mortgage-life', 'path' => '/mortgage-life', 'label' => 'Mortgage Life Insurance', 'title' => 'Mortgage Life Insurance | Security Financial Online', 'meta_description' => 'Mortgage life insurance that pays your family, stays level, and can include terminal illness, disability, and return of premium riders.'],
            ['slug' => 'term-life', 'path' => '/term-life', 'label' => 'Term Life', 'title' => 'Term Life Insurance | Security Financial Online', 'meta_description' => 'Level term life insurance with stable premiums, competitive pricing, and the option to renew or convert.'],
            ['slug' => 'chronic-illness', 'path' => '/chronic-illness', 'label' => 'Chronic Illness', 'title' => 'Chronic Illness Protection | Security Financial Online', 'meta_description' => 'Access life insurance benefits while living with a chronic illness rider, including monthly tax-free payments for qualifying conditions.'],
            ['slug' => 'indexed-universal-life', 'path' => '/indexed-universal-life', 'label' => 'Indexed Universal Life', 'title' => 'Indexed Universal Life Insurance | Security Financial Online', 'meta_description' => 'Permanent life insurance with flexible premiums and cash value linked to a market index, with a guaranteed minimum credit.'],
            ['slug' => 'final-expense', 'path' => '/final-expense', 'label' => 'Final Expense', 'title' => 'Final Expense Insurance | Security Financial Online', 'meta_description' => 'Affordable final expense coverage for funeral costs, medical bills, and debts, paid directly to the person you choose.'],
            ['slug' => 'annuities', 'path' => '/annuities', 'label' => 'Annuities', 'title' => 'Annuities | Security Financial Online', 'meta_description' => 'Turn retirement savings into predictable income with fixed, variable, immediate, and deferred annuities.'],
            ['slug' => 'free-quote', 'path' => '/free-quote', 'label' => 'Free Quote', 'title' => 'Free Insurance Quote | Security Financial Online', 'meta_description' => 'Request a free mortgage life or life insurance quote. A licensed agent follows up by phone, email, or text.'],
            ['slug' => 'rate', 'path' => '/rate', 'label' => 'Instant Rate', 'title' => 'Instant Rate | Security Financial Online', 'meta_description' => 'Request an instant insurance rate and compare A-rated carriers with Security Financial Online.'],
            ['slug' => 'schedule', 'path' => '/schedule', 'label' => 'Schedule a Call', 'title' => 'Schedule a Conference Call | Security Financial Online', 'meta_description' => 'Schedule a phone or Zoom rate conference with a licensed Security Financial Online agent.'],
            ['slug' => 'about', 'path' => '/about', 'label' => 'About', 'title' => 'About Security Financial Online', 'meta_description' => 'Insurance Financial Solutions has protected families since 1997. Meet the leadership team behind Security Financial Online.'],
            ['slug' => 'contact', 'path' => '/contact', 'label' => 'Contact', 'title' => 'Contact Security Financial Online', 'meta_description' => 'Reach Insurance Financial Solutions in Granite Bay, California at (866) 674-5527 or info@securityfinancialonline.com.'],
            ['slug' => 'directory', 'path' => '/directory', 'label' => 'Directory', 'title' => 'Licensed Agent Directory | Security Financial Online', 'meta_description' => 'Find an approved Security Financial Online licensed agent or property and casualty professional.'],
            ['slug' => 'directory-apply', 'path' => '/directory/apply', 'label' => 'Join the Directory', 'title' => 'Join the SFO Directory', 'meta_description' => 'Apply to be listed in the Security Financial Online licensed agent or property and casualty directory.'],
        ];

        foreach ($pages as $page) {
            $record = SeoPage::updateOrCreate(['slug' => $page['slug']], $page);
            if ($record->faqs()->count() === 0 && $page['slug'] === 'home') {
                $record->faqs()->createMany([
                    ['question' => 'What does Security Financial Online offer?', 'answer' => 'Mortgage life insurance, term life, chronic illness protection, indexed universal life, final expense, and annuities, compared across A-rated carriers.', 'sort_order' => 0],
                    ['question' => 'Who underwrites the coverage?', 'answer' => 'Products are underwritten by carriers such as Corebridge Financial. Availability varies by state, and a licensed agent confirms eligibility.', 'sort_order' => 1],
                    ['question' => 'How do I get a quote?', 'answer' => 'Submit the free quote, instant rate, or schedule form, or send a message in chat. A licensed agent follows up by phone, email, or text.', 'sort_order' => 2],
                ]);
            }
        }
    }
}
