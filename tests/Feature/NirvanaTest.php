<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\MicrosoftGraphMail;
use App\Services\NirvanaCorrespondence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NirvanaTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_mail_uses_app_credentials_caches_the_token_and_retries_after_unauthorized(): void
    {
        config(['nirvana.email_transport' => 'graph', 'nirvana.graph_tenant' => 'test-tenant',
            'nirvana.graph_client' => 'test-client', 'nirvana.graph_secret' => 'test-secret',
            'nirvana.graph_sender' => 'sender@example.test']);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::sequence()->push(['access_token' => 'first', 'expires_in' => 3600])
                ->push(['access_token' => 'second', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::sequence()->push([], 202)->push([], 401)->push([], 202),
        ]);

        $graph = app(MicrosoftGraphMail::class);
        $graph->send('visitor@example.test', 'First', '<p>First email</p>');
        $graph->send('visitor@example.test', 'Second', '<p>Second email</p>');
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/users/sender%40example.test/sendMail')
            && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'visitor@example.test'
            && $request['message']['body']['contentType'] === 'HTML');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/v2.0/token')
            && str_contains($request->body(), 'grant_type=client_credentials')
            && str_contains($request->body(), 'scope=https%3A%2F%2Fgraph.microsoft.com%2F.default'));
    }

    public function test_graph_configuration_is_required_before_correspondence_reports_delivery(): void
    {
        config(['nirvana.email_transport' => 'graph', 'nirvana.graph_tenant' => null,
            'nirvana.graph_client' => null, 'nirvana.graph_secret' => null,
            'nirvana.graph_sender' => 'sender@example.test']);
        Http::preventStrayRequests();
        $this->assertFalse(app(NirvanaCorrespondence::class)->welcome(new Conversation([
            'name' => 'Visitor', 'email' => 'visitor@example.test', 'phone' => '555-0100',
        ])));
        Http::assertNothingSent();
    }

    public function test_quote_and_partial_recap_are_separate_graph_messages_with_all_answers(): void
    {
        config(['nirvana.email_transport' => 'graph', 'nirvana.graph_tenant' => 'tenant-integration',
            'nirvana.graph_client' => 'client-integration', 'nirvana.graph_secret' => 'secret-integration',
            'nirvana.graph_sender' => 'sender@example.test', 'nirvana.staff_emails' => ['staff@example.test'],
            'nirvana.end_grace_seconds' => 30]);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response([], 202),
        ]);
        DB::table('nirvana_rates')->insert([
            'version' => config('nirvana.rate_version'), 'age' => 35, 'gender' => 'Female',
            'face_amount' => 250000, 'term' => 20, 'premiums' => '{"preferred_plus":23.75}',
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'Can I get a quote? I am 35, female, seeking $250k for a 20 year term.'], $headers)
            ->assertOk()->assertJsonPath('messages.2.actions', ['rates']);
        $this->postJson('/api/nirvana/rates', ['age' => 35, 'gender' => 'Female', 'face_amount' => 250000, 'term' => 20], $headers)
            ->assertOk();
        $this->postJson('/api/nirvana/depart', [], $headers)->assertNoContent();
        $this->travel(31)->seconds();
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $sent = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMail'));
        $this->assertCount(4, $sent);
        $subjects = $sent->map(fn ($entry) => $entry[0]['message']['subject'])->all();
        $this->assertContains('Welcome to Nirvana AI', $subjects);
        $this->assertContains('Your SFO rate guide', $subjects);
        $this->assertContains('Nirvana conversation: Test Visitor', $subjects);
        $this->assertContains('Your Nirvana AI conversation recap', $subjects);
        $rateMail = $sent->first(fn ($entry) => $entry[0]['message']['subject'] === 'Your SFO rate guide')[0];
        $this->assertStringContainsString('Age: 35', $rateMail['message']['body']['content']);
        $this->assertStringContainsString('Preferred Plus: $23.75/month', $rateMail['message']['body']['content']);
        $this->assertDatabaseCount('nirvana_mail_deliveries', 2);
    }

    public function test_partial_graph_failure_retries_only_the_recipient_that_failed(): void
    {
        config(['nirvana.email_transport' => 'graph', 'nirvana.graph_tenant' => 'tenant-retry',
            'nirvana.graph_client' => 'client-retry', 'nirvana.graph_secret' => 'secret-retry',
            'nirvana.graph_sender' => 'sender@example.test',
            'nirvana.staff_emails' => ['first@example.test', 'second@example.test'],
            'nirvana.end_grace_seconds' => 30]);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::sequence()->push([], 202)->push([], 202)
                ->push([], 503)->push([], 202)->push([], 202),
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/depart', [], $headers)->assertNoContent();
        $this->travel(31)->seconds();
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $this->assertNull(Conversation::sole()->nirvana_summary_sent_at);
        $this->assertDatabaseCount('nirvana_mail_deliveries', 2);
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $this->assertNotNull(Conversation::sole()->fresh()->nirvana_summary_sent_at);
        $this->assertDatabaseCount('nirvana_mail_deliveries', 3);
        $sent = Http::recorded(fn ($request) => str_contains($request->url(), '/sendMail'));
        $this->assertCount(5, $sent);
        $this->assertSame(['visitor@example.test', 'first@example.test', 'second@example.test',
            'visitor@example.test', 'second@example.test'], $sent->map(fn ($entry) =>
                $entry[0]['message']['toRecipients'][0]['emailAddress']['address'])->values()->all());
    }

    public function test_google_intake_verifies_tokens_for_the_configured_client_before_prefilling(): void
    {
        config(['nirvana.google_client_id' => 'nirvana-client']);
        $openssl = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($bundledConfig) && !getenv('OPENSSL_CONF')) {
            $openssl['config'] = $bundledConfig;
        }
        $key = openssl_pkey_new($openssl);
        $pem = openssl_pkey_get_details($key)['key'];
        openssl_pkey_export($key, $privateKey, null, $openssl);
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs' => Http::response(
            ['test-key' => $pem], 200, ['Cache-Control' => 'public, max-age=300']
        )]);
        $claims = ['aud' => 'nirvana-client', 'iss' => 'https://accounts.google.com',
            'sub' => '123', 'exp' => time() + 300, 'email_verified' => true,
            'name' => 'Test Visitor', 'email' => 'visitor@example.test'];

        $this->postJson('/api/nirvana/google', [
            'credential' => \Firebase\JWT\JWT::encode($claims, $privateKey, 'RS256', 'test-key'),
        ])->assertOk()->assertExactJson(['name' => 'Test Visitor', 'email' => 'visitor@example.test']);
        $this->postJson('/api/nirvana/google', [
            'credential' => \Firebase\JWT\JWT::encode([...$claims, 'aud' => 'other-client'], $privateKey, 'RS256', 'test-key'),
        ])->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_default_knowledge_publish_uses_the_original_pdf(): void
    {
        $pdf = config('nirvana.knowledge_pdf');
        if (!is_file($pdf)) {
            $this->markTestSkipped('Legacy PDF is not present.');
        }
        $digest = hash_file('sha256', $pdf);
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_approved']);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['id' => 'file-approved'])
            ->push(['id' => 'vs_approved'])
            ->push(['status' => 'in_progress']);
        $this->artisan('nirvana:publish-knowledge')
            ->expectsOutput("Uploaded RADON AI.pdf (SHA-256 {$digest}).")->assertSuccessful();
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/files'
            && $request->hasFile('file', file_get_contents($pdf), 'RADON AI.pdf'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/vector_stores'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['metadata']['source_sha256'] === $digest);
    }

    public function test_website_pdf_attaches_to_the_original_store_without_replacing_it(): void
    {
        $original = config('nirvana.knowledge_pdf');
        $website = base_path('../output/pdf/sfo-public-website-knowledge-2026-11.pdf');
        if (!is_file($original) || !is_file($website)) {
            $this->markTestSkipped('Both local PDFs are required.');
        }
        $originalDigest = hash_file('sha256', $original);
        $websiteDigest = hash_file('sha256', $website);
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_approved', 'nirvana.website_pdf' => $website]);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['id' => 'vs_approved', 'status' => 'completed', 'file_counts' => ['total' => 1],
                'metadata' => ['source_sha256' => $originalDigest, 'source_filename' => 'RADON AI.pdf']])
            ->push(['data' => [['status' => 'completed', 'attributes' => ['source_sha256' => $originalDigest]]]])
            ->push(['id' => 'file-website'])
            ->push(['status' => 'in_progress'])
            ->push(['id' => 'vs_approved', 'status' => 'completed', 'file_counts' => ['total' => 2],
                'metadata' => ['source_sha256' => $originalDigest, 'source_filename' => 'RADON AI.pdf']])
            ->push(['data' => [
                ['status' => 'completed', 'attributes' => ['source_sha256' => $originalDigest]],
                ['status' => 'completed', 'attributes' => ['source_sha256' => $websiteDigest]],
            ]]);
        $this->artisan('nirvana:attach-website-knowledge')->assertSuccessful();
        $this->artisan('nirvana:knowledge-status')
            ->expectsOutput('Ready: RADON AI.pdf and sfo-public-website-knowledge-2026-11.pdf in the existing store.')
            ->assertSuccessful();
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/vector_stores/vs_approved/files'
            && $request['attributes']['source_sha256'] === $websiteDigest);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/vector_stores'
            && $request->method() === 'POST');
    }

    private function visitor(): string
    {
        return $this->postJson('/api/nirvana/session', [
            'name' => 'Test Visitor', 'email' => 'visitor@example.test', 'phone' => '555-0100', 'track' => 'life',
        ])->assertCreated()->json('token');
    }

    public function test_session_reuses_the_lead_and_returns_rates_and_grounded_answers(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"Term life covers a defined term.","brief":"Explain term life.","actions":["rates","zoom"]}',
                'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']]],
        ]]]])]);
        $token = $this->visitor();
        $this->getJson('/api/nirvana/session')->assertUnauthorized();
        DB::table('nirvana_rates')->insert([
            'version' => config('nirvana.rate_version'), 'age' => 25, 'gender' => 'Male',
            'face_amount' => 100000, 'term' => 10, 'premiums' => '{"preferred_plus":8.45}',
        ]);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/nirvana/rates', ['age' => 25, 'gender' => 'Male', 'face_amount' => 100000, 'term' => 10], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'Preferred Plus: $8.45/month')
                && str_contains($body, 'These are sample rates'));
        $this->postJson('/api/nirvana/message', ['message' => 'What is term life?'], $headers)
            ->assertOk()->assertJsonFragment(['body' => 'Term life covers a defined term.'])
            ->assertJsonPath('messages.4.actions', ['rates', 'zoom']);
        $this->getJson('/api/nirvana/session', $headers)->assertOk()->assertJsonCount(5, 'messages');
        $this->assertDatabaseCount('leads', 1);
        $this->assertSame('Explain term life.', Conversation::sole()->chat_brief);
    }

    public function test_all_of_them_uses_the_prior_coverage_list_and_primary_pdf_first(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => 'SFO offers term life, mortgage protection, chronic illness coverage, final expense, and annuities.',
                    'brief' => 'Listed coverage categories.', 'actions' => [], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge',
                    'answer' => "## Coverage options\n\n- **Term life:** Protection for a chosen period.\n- **Mortgage protection:** A benefit your family can use toward a mortgage.\n- **Chronic illness:** Access to benefits while living, subject to the policy.\n- **Final expense:** Help with end-of-life costs.\n- **Annuities:** Retirement income planning.",
                    'brief' => 'Explained all five categories.', 'actions' => ['rates'], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'What types of insurance coverage can I explore with SFO?'], $headers)->assertOk();
        $this->postJson('/api/nirvana/message', ['message' => 'all of them'], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', fn (string $body) =>
                str_contains($body, '## Coverage options') && str_contains($body, '- **Annuities:**')
                && !str_contains($body, 'Source:'));
        Http::assertSentCount(2);
        $digest = hash_file('sha256', config('nirvana.knowledge_pdf'));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(['type' => 'eq', 'key' => 'source_sha256', 'value' => $digest], $request['tools'][0]['filters']);
        }
        $followUpInput = Http::recorded()[1][0]['input'];
        $followUp = $followUpInput[array_key_last($followUpInput)]['content'];
        $this->assertStringContainsString('Explain every coverage category in your immediately preceding answer', $followUp);
        $this->assertStringContainsString('SFO offers term life, mortgage protection', $followUp);
        $this->assertStringContainsString('350 to 550 words', Http::recorded()[1][0]['instructions']);
        $this->assertSame('all of them', Conversation::sole()->messages()->where('sender', 'visitor')->latest('id')->first()->body);
    }

    public function test_detail_and_repeated_questions_keep_the_previous_answer(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        $first = 'SFO offers term life, mortgage protection, final expense coverage, and annuities.';
        Http::fakeSequence('api.openai.com/*')
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => $first,
                    'brief' => 'Listed products.', 'actions' => [], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => "## What SFO offers\n\n".$first,
                    'brief' => 'Repeated product question.', 'actions' => ['rates'], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => "## Coverage in plain language\n\n**Term life:** Protection for a set period.",
                    'brief' => 'Detail follow-up.', 'actions' => ['rates'], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'what do you sell?'], $headers)->assertOk();
        $this->postJson('/api/nirvana/message', ['message' => 'what do you sell?'], $headers)->assertOk();
        $this->postJson('/api/nirvana/message', ['message' => 'tell me about it in details so i can understand'], $headers)
            ->assertOk()->assertJsonPath('messages.6.body', fn (string $body) => str_contains($body, '## Coverage in plain language'));
        $repeated = Http::recorded()[1][0]['input'];
        $detail = Http::recorded()[2][0]['input'];
        $this->assertStringContainsString("Previous Nirvana answer:\n".$first, $repeated[array_key_last($repeated)]['content']);
        $this->assertStringContainsString('tell me about it in details', $detail[array_key_last($detail)]['content']);
        $this->assertStringContainsString('What SFO offers', $detail[array_key_last($detail)]['content']);
        $this->assertSame(3500, Http::recorded()[0][0]['max_output_tokens']);
    }

    public function test_verification_hedges_are_removed_from_the_visitor_reply(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode([
                'kind' => 'knowledge',
                'answer' => 'I can confirm these product categories, but I can\'t verify which specific policies are currently available. An SFO agent can walk through them with you.',
                'brief' => 'Product question.', 'actions' => ['callback'], 'fallback' => '', 'language' => 'English',
            ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
        ]]]]])]);
        $this->postJson('/api/nirvana/message', ['message' => 'what do you sell?'],
            ['Authorization' => 'Bearer '.$this->visitor()])
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) =>
                str_contains($body, 'I can confirm these product categories.')
                && str_contains($body, 'An SFO agent can walk through them with you.')
                && !str_contains($body, "can't verify")
                && !str_contains($body, 'About page'));
    }

    public function test_website_pdf_is_used_only_after_primary_has_no_grounded_answer(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => 'Unsupported.', 'brief' => '', 'actions' => [],
                    'fallback' => 'I do not have this detail.', 'language' => 'English',
                ]), 'annotations' => [],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode([
                    'kind' => 'knowledge', 'answer' => 'SFO offers online coverage guidance.', 'brief' => '',
                    'actions' => [], 'fallback' => '', 'language' => 'English',
                ]), 'annotations' => [['type' => 'file_citation', 'filename' => basename(config('nirvana.website_pdf'))]],
            ]]]]]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'How does the website describe its guidance?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) =>
                str_contains($body, 'SFO offers online coverage guidance.')
                && !str_contains($body, 'Source:'));
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame('source_sha256', $requests[0][0]['tools'][0]['filters']['key']);
        $this->assertSame(['type' => 'eq', 'key' => 'source_kind', 'value' => 'public_website_snapshot'],
            $requests[1][0]['tools'][0]['filters']);
    }

    public function test_published_agent_roster_answers_without_a_pdf_citation_and_uses_current_count(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        $group = \App\Models\CompanyRecord::create(['kind' => 'group', 'data' => ['name' => 'Licensed Agents', 'published' => true]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => ['name' => 'Agent One', 'group_id' => $group->id, 'published' => true]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => ['name' => 'Draft Agent', 'group_id' => $group->id, 'published' => false]]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => '{"kind":"roster","answer":"The public About page lists 1 licensed agent profile. This is not a verified company-wide total.","brief":"Asked about public roster.","actions":[]}',
        ]]]]])]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'How many licensed agents does this company have?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, '**Agent One**')
                && !str_contains($body, 'Draft Agent') && !str_contains($body, 'Source:'));
        Http::assertNothingSent();
    }

    public function test_published_team_role_answers_from_the_about_page_without_a_pdf_citation(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        $person = \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => [
            'name' => 'Jordan Example', 'title' => 'Operations Manager', 'published' => true,
            'bio' => 'Jordan helps clients find their next step.', 'email' => 'jordan@example.test',
            'phone' => '(555) 010-0200', 'links' => [['type' => 'linkedin', 'label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/jordan-example', 'visible' => true]],
        ]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => [
            'name' => 'Draft Person', 'title' => 'Operations Manager', 'published' => false,
        ]]);
        Http::preventStrayRequests();
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'Who is the operations manager?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'Jordan Example')
                && str_contains($body, 'Operations Manager') && str_contains($body, 'jordan@example.test')
                && str_contains($body, 'https://linkedin.com/in/jordan-example') && !str_contains($body, 'Draft Person'));
        $person->update(['data' => [...$person->data, 'title' => 'Client Services Director', 'phone' => '(555) 010-0300']]);
        $this->postJson('/api/nirvana/message', ['message' => 'Tell me about Jordan Example'], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', fn (string $body) => str_contains($body, 'Client Services Director')
                && str_contains($body, '(555) 010-0300') && !str_contains($body, '(555) 010-0200'));
        Http::assertNothingSent();
    }

    public function test_team_list_and_first_name_follow_up_use_current_published_profiles_without_model_guesswork(): void
    {
        $group = \App\Models\CompanyRecord::create(['kind' => 'group', 'data' => ['name' => 'Leadership', 'published' => true]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => [
            'name' => 'Marlon Mendoza', 'title' => 'Founder & Chief Visionary Officer',
            'group_id' => $group->id, 'published' => true,
        ]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => [
            'name' => 'Jordan Example', 'title' => 'Operations Manager',
            'group_id' => $group->id, 'published' => true,
        ]]);
        \App\Models\CompanyRecord::create(['kind' => 'person', 'data' => [
            'name' => 'Draft Person', 'title' => 'Agent', 'group_id' => $group->id, 'published' => false,
        ]]);
        Http::preventStrayRequests();
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'who works in sfo?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) =>
                str_contains($body, '- **Marlon Mendoza** — Founder & Chief Visionary Officer')
                && str_contains($body, '- **Jordan Example** — Operations Manager')
                && !str_contains($body, 'Draft Person') && !str_contains($body, 'verified total'));
        $this->postJson('/api/nirvana/message', ['message' => 'why Marlon'], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', fn (string $body) =>
                str_contains($body, 'Marlon Mendoza is our Founder & Chief Visionary Officer')
                && !str_contains($body, 'can’t verify'));
        Http::assertNothingSent();
    }

    public function test_company_reply_removes_internal_citation_markers_and_repeated_identity_disclaimer(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        \App\Models\CompanyRecord::create(['kind' => 'content', 'data' => [
            'name' => 'Our approach', 'intro' => 'We explain options clearly.', 'published' => true,
        ]]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode([
                'kind' => 'company', 'answer' => "We explain coverage clearly. fileciteturn0file0 I’m Nirvana AI, not a human or licensed agent.",
                'brief' => 'Asked why SFO.', 'actions' => [], 'fallback' => '', 'language' => 'English',
            ]),
        ]]]]])]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'why sfo?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) =>
                str_contains($body, 'We explain coverage clearly.')
                && !str_contains($body, 'filecite') && !str_contains($body, 'not a human'));
    }

    public function test_long_plain_insurance_answer_becomes_readable_without_exposing_sources(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        $plain = 'Mortgage life insurance can provide a level benefit to the beneficiary you choose if you die during the policy term. Your beneficiary can decide how to use the payment, including toward a mortgage, subject to the policy. Coverage amounts and optional benefits vary by plan and eligibility, so an SFO agent can help you compare what fits your family.';
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode([
                'kind' => 'knowledge', 'answer' => $plain.' Source: RADON AI.pdf',
                'brief' => 'Mortgage life question.', 'actions' => [], 'fallback' => '', 'language' => 'English',
            ]), 'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
        ]]]]])]);
        $this->postJson('/api/nirvana/message', ['message' => 'Tell me about mortgage life insurance'],
            ['Authorization' => 'Bearer '.$this->visitor()])
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) =>
                str_contains($body, '### Mortgage life insurance')
                && str_contains($body, '- Your beneficiary can decide')
                && !str_contains($body, 'Source:'));
    }

    public function test_non_english_reply_and_handoff_are_not_replaced_with_english_copy(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"El seguro de vida a término cubre un período definido.","brief":"Term life question.","actions":["rates"]}',
                'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"handoff","answer":"No puedo verificar el total actual. Un agente de SFO puede ayudarle.","brief":"Unverified count.","actions":[]}',
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"Respuesta no respaldada.","fallback":"No encuentro una cifra verificada para ese dato en la información de SFO.","brief":"Unverified figure.","actions":[]}',
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"Respuesta no respaldada.","fallback":"No encuentro una cifra verificada para ese dato en la información de SFO.","brief":"Unverified figure.","actions":[]}',
            ]]]]]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => '¿Qué es el seguro de vida a término?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_starts_with($body, 'El seguro'));
        $this->postJson('/api/nirvana/message', ['message' => '¿Cuántos empleados tienen?'], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', 'No puedo verificar el total actual. Un agente de SFO puede ayudarle.');
        $this->postJson('/api/nirvana/message', ['message' => '¿Cuál es la tasa exacta de aprobación?'], $headers)
            ->assertOk()->assertJsonPath('messages.6.body', 'No encuentro una cifra verificada para ese dato en la información de SFO.');
    }

    public function test_language_requests_switch_the_session_without_searching_the_pdf(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fakeSequence('api.openai.com/*')
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"Term life coverage lasts for a defined period.","brief":"Asked about term life.","actions":[],"fallback":"","language":""}',
                'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]])
            ->push(['output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => '{"kind":"knowledge","answer":"El seguro a término dura un período definido.","brief":"Asked about term life.","actions":[],"fallback":"","language":""}',
                'annotations' => [['type' => 'file_citation', 'filename' => 'RADON AI.pdf']],
            ]]]]]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'habla espaneol?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_starts_with($body, 'Claro.'))
            ->assertJsonPath('messages.2.actions', []);
        $this->postJson('/api/nirvana/message', ['message' => 'english please'], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', fn (string $body) => str_contains($body, 'continue in English'))
            ->assertJsonPath('messages.4.actions', []);
        $this->assertSame('English', Conversation::sole()->nirvana_language);
        Http::assertNothingSent();
        $this->getJson('/api/nirvana/session', $headers)->assertOk()->assertJsonCount(5, 'messages');
        $this->postJson('/api/nirvana/message', ['message' => 'What is term life?'], $headers)
            ->assertOk()->assertJsonPath('messages.6.body', fn (string $body) => str_starts_with($body, 'Term life'));
        $this->postJson('/api/nirvana/message', ['message' => 'en español por favor'], $headers)
            ->assertOk()->assertJsonPath('messages.8.body', fn (string $body) => str_starts_with($body, 'Claro.'));
        $this->postJson('/api/nirvana/message', ['message' => 'What is term life?'], $headers)
            ->assertOk()->assertJsonPath('messages.10.body', fn (string $body) => str_starts_with($body, 'El seguro'));
        Http::assertSentCount(2);
        $this->assertSame('Spanish', Conversation::sole()->fresh()->nirvana_language);
        Http::assertSent(fn ($request) => str_contains($request['instructions'], 'Preferred response language: Spanish')
            && $request['model'] === config('nirvana.model'));
    }

    public function test_internal_document_lookup_language_never_reaches_a_visitor(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode([
                'kind' => 'handoff',
                'answer' => 'I could not verify a translation or summary from the uploaded document.',
                'fallback' => 'I could not verify a translation or summary from the uploaded document.',
                'brief' => 'Visitor asked a company question.', 'actions' => [], 'language' => 'English',
            ]),
        ]]]]])]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'How many offices does SFO have?'], $headers)
            ->assertOk()
            ->assertJsonPath('messages.2.body', 'I don’t have a confirmed answer to that specific detail. I can help you explore coverage or connect you with the SFO team to verify it.');
        $this->assertStringNotContainsString('uploaded document', Conversation::sole()->messages()->latest('id')->first()->body);
    }

    public function test_rate_request_offers_the_in_chat_form_without_asking_for_age(): void
    {
        Http::preventStrayRequests();
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'I want to get a rate'], $headers)
            ->assertOk()
            ->assertJsonPath('messages.2.actions', ['rates'])
            ->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'rate quote')
                && str_contains($body, 'right here in our conversation')
                && !str_contains($body, 'what is your age'));
        Http::assertNothingSent();
        $this->assertNull(Conversation::sole()->nirvana_quote_state);
    }

    public function test_rate_offer_respects_the_visitors_language_and_a_decline(): void
    {
        Http::preventStrayRequests();
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'en español'], $headers)
            ->assertOk();
        $this->postJson('/api/nirvana/message', ['message' => 'Quiero una cotización'], $headers)
            ->assertOk()
            ->assertJsonPath('messages.4.actions', ['rates'])
            ->assertJsonPath('messages.4.body', fn (string $body) => str_contains($body, 'Con gusto'));

        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => '{"kind":"courtesy","answer":"Entendido. ¿En qué más puedo ayudarte?","brief":"Declined quote.","actions":[],"fallback":"","language":"Spanish"}',
        ]]]]])]);
        $this->postJson('/api/nirvana/message', ['message' => "I don't want a quote"], $headers)
            ->assertOk()
            ->assertJsonPath('messages.6.actions', [])
            ->assertJsonPath('messages.6.body', 'Entendido. ¿En qué más puedo ayudarte?');
        Http::assertSentCount(1);
    }

    public function test_rate_form_reports_missing_encryption_configuration_without_exposing_laravel_error(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        config(['app.key' => null]);
        $this->postJson('/api/nirvana/rates', ['age' => 35, 'gender' => 'Female', 'face_amount' => 250000, 'term' => 20], $headers)
            ->assertStatus(503)
            ->assertJsonPath('message', 'The rate guide is temporarily unavailable. Please call SFO at (866) 674-5527.');
        $this->assertDatabaseCount('nirvana_rate_requests', 0);
    }

    public function test_rate_guide_derives_age_from_birth_date_and_omits_health_details_from_the_chat(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        DB::table('nirvana_rates')->insert([
            'version' => config('nirvana.rate_version'), 'age' => 50, 'gender' => 'Female',
            'face_amount' => 100000, 'term' => 10, 'premiums' => '{"preferred_plus":14.36,"preferred_tobacco":50.54}',
        ]);
        $birthday = now('America/Los_Angeles')->subYears(50);
        $form = [
            'date_of_birth' => $birthday->toDateString(),
            'gender' => 'Female', 'face_amount' => 100000, 'term' => 10,
            'height_feet' => 5, 'height_inches' => 0, 'weight' => 160, 'tobacco' => 'yes',
            'medications' => 'yes', 'medications_details' => ['Blood pressure'],
            'health_history' => 'no', 'other_health' => 'Synthetic health note',
        ];
        $this->postJson('/api/nirvana/rates', [...$form, 'date_of_birth' => $birthday->addYears(26)->toDateString()], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
        $this->postJson('/api/nirvana/rates', $form, $headers)
            ->assertOk()
            ->assertJsonPath('messages.1.body', fn (string $body) => str_contains($body, 'age 50'))
            ->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'Preferred Tobacco: $50.54')
                && !str_contains($body, 'Preferred Plus'));
        $snapshot = $this->getJson('/api/nirvana/session', $headers)->assertOk()->getContent();
        $this->assertStringNotContainsString($form['date_of_birth'], $snapshot);
        $this->assertStringNotContainsString($form['other_health'], $snapshot);
    }

    public function test_follow_up_uses_previous_reply_and_offers_a_workflow_without_a_pdf_citation(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => '{"kind":"zoom","answer":"It is booked.","brief":"Wants a Zoom appointment.","actions":[]}',
        ]]]]])]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->getJson('/api/nirvana/session', $headers)
            ->assertJsonPath('messages.0.actions', ['rates', 'zoom', 'callback', 'coverage'])
            ->assertJsonPath('messages.0.body', fn (string $body) => str_starts_with($body, '👋 Welcome to Nirvana AI Preparing today for tomorrow'));
        Conversation::sole()->messages()->create(['sender' => 'Nirvana', 'body' => 'Would you like to book a meeting?', 'actions' => ['zoom']]);
        $this->postJson('/api/nirvana/message', ['message' => 'Yes, please.'], $headers)
            ->assertOk()->assertJsonPath('messages.3.actions', ['zoom'])
            ->assertJsonPath('messages.3.body', fn (string $body) => str_contains($body, 'available Zoom appointments'));
        Http::assertSent(fn ($request) => $request['input'][1]['content'] === 'Would you like to book a meeting?'
            && str_contains($request['instructions'], 'most recent assistant reply: zoom'));
        $this->assertDatabaseCount('nirvana_meetings', 0);
    }

    public function test_rate_form_offer_does_not_block_a_worried_visitor_question(): void
    {
        config(['nirvana.openai_key' => 'test', 'nirvana.knowledge_store' => 'vs_test']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => '{"kind":"courtesy","answer":"I understand this feels like a lot. We can take it one step at a time. What is worrying you most?","brief":"Visitor expressed concern during rate guide.","actions":[],"fallback":""}',
        ]]]]])]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $this->postJson('/api/nirvana/message', ['message' => 'Can I get a quote?'], $headers)
            ->assertOk()->assertJsonPath('messages.2.actions', ['rates']);
        $this->postJson('/api/nirvana/message', ['message' => "I'm worried I won't qualify."], $headers)
            ->assertOk()->assertJsonPath('messages.4.body', fn (string $body) => str_starts_with($body, 'I understand'));
        Http::assertSent(fn ($request) => str_contains($request['instructions'], 'Rate requests use the in-chat rate guide form'));
    }

    public function test_callback_uses_the_visitor_contact_and_deduplicates_retries(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $date = now('America/Los_Angeles')->addDay()->toDateString();
        $payload = ['date' => $date, 'time' => '14:00'];
        $this->postJson('/api/nirvana/callback', $payload)->assertUnauthorized();
        $this->postJson('/api/nirvana/callback', ['date' => '2001-01-01', 'time' => '14:00'], $headers)->assertUnprocessable();
        $this->postJson('/api/nirvana/callback', $payload, $headers)->assertOk()->assertJsonCount(3, 'messages');
        $this->postJson('/api/nirvana/callback', $payload, $headers)->assertOk()->assertJsonCount(3, 'messages');
        $this->assertDatabaseHas('leads', ['type' => 'schedule', 'email' => 'visitor@example.test', 'track' => 'life']);
        $this->assertSame(1, DB::table('leads')->where('type', 'schedule')->count());
    }

    public function test_exam_handoff_creates_a_distinct_staff_request(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $payload = ['date' => now('America/Los_Angeles')->addDay()->toDateString(), 'time' => '14:00'];
        $this->postJson('/api/nirvana/callback', $payload, $headers)->assertOk();
        $this->postJson('/api/nirvana/message', ['message' => 'I need to reschedule my exam.'], $headers)
            ->assertOk()->assertJsonPath('messages.4.actions', ['exam']);
        $this->postJson('/api/nirvana/callback', [...$payload, 'purpose' => 'exam'], $headers)
            ->assertOk()->assertJsonPath('messages.6.body', fn (string $body) => str_contains($body, 'keep it until the team confirms'));
        $this->postJson('/api/nirvana/callback', [...$payload, 'purpose' => 'exam'], $headers)
            ->assertOk()->assertJsonCount(7, 'messages');
        $this->assertDatabaseHas('leads', ['type' => 'schedule', 'message' => 'Insurance exam appointment change requested.', 'track' => 'life']);
    }

    public function test_zoom_booking_is_idempotent_and_accepts_a_signed_recap(): void
    {
        config(['nirvana.zoom_account' => 'test', 'nirvana.zoom_client' => 'test', 'nirvana.zoom_secret' => 'test',
            'nirvana.zoom_host' => 'host@example.test', 'nirvana.zoom_webhook_secret' => 'webhook', 'nirvana.send_invites' => false]);
        Http::preventStrayRequests();
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => 'test']),
            'api.zoom.us/*' => Http::response(['id' => 123456, 'join_url' => 'https://zoom.us/j/123456']),
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $slot = $this->getJson('/api/nirvana/slots', $headers)->assertOk()->json('slots.0');
        $this->postJson('/api/nirvana/bookings', ['starts_at' => $slot, 'timezone' => 'America/Los_Angeles', 'state' => 'CA', 'zip' => '95746'], $headers)->assertCreated()
            ->assertJsonPath('meeting.join_url', 'https://zoom.us/j/123456')
            ->assertJsonPath('messages.2.actions', ['rates'])
            ->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'emailed the Zoom details'));
        $this->postJson('/api/nirvana/bookings', ['starts_at' => $slot, 'timezone' => 'America/Los_Angeles', 'state' => 'CA', 'zip' => '95746'], $headers)->assertOk();
        Http::assertSentCount(2);
        $timestamp = (string) time();
        $body = json_encode(['event' => 'meeting.summary_completed', 'payload' => ['object' => ['meeting_id' => 123456, 'summary_content' => 'Follow up on options.']]]);
        $this->postJson('/api/nirvana/zoom/webhook', [])->assertUnauthorized();
        $this->call('POST', '/api/nirvana/zoom/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'webhook'),
        ], $body)->assertNoContent();
        $this->assertDatabaseHas('nirvana_meetings', ['zoom_id' => '123456', 'recap' => 'Follow up on options.', 'visitor_state' => 'CA', 'visitor_zip' => '95746']);
    }

    public function test_scheduling_location_and_time_zone_are_limited_to_served_states(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        $date = now('America/Los_Angeles')->addDays(3)->toDateString();
        $request = ['date' => $date, 'time' => '13:00', 'timezone' => 'America/Los_Angeles', 'state' => 'CA', 'zip' => '95746'];
        $this->postJson('/api/nirvana/callback', [...$request, 'state' => 'NV'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->postJson('/api/nirvana/callback', [...$request, 'zip' => 'bad'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('zip');
        $this->postJson('/api/nirvana/callback', [...$request, 'timezone' => 'America/New_York'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->postJson('/api/nirvana/callback', $request, $headers)->assertOk()
            ->assertJsonPath('timeline.0.kind', 'chat')
            ->assertJsonPath('timeline.1.status', 'pending')
            ->assertJsonPath('timeline.1.detail', fn (string $detail) => str_contains($detail, 'CA 95746') && str_contains($detail, 'SFO will confirm it'));
        $this->assertDatabaseHas('leads', ['type' => 'schedule']);
        $this->assertSame('CA', \App\Models\Lead::where('type', 'schedule')->first()->payload['state']);
    }

    public function test_the_supplied_sqlite_snapshot_can_be_imported_with_exact_rates(): void
    {
        $source = base_path('../sfo_backend-main/sfo_backend-main/rapid_rater.db');
        if (!is_file($source)) {
            $this->markTestSkipped('Legacy SQLite snapshot is not present.');
        }
        $this->artisan('nirvana:import-rates', ['sqlite' => $source, 'version' => 'test-snapshot'])
            ->expectsOutput('Imported 16319 rows for test-snapshot.')->assertSuccessful();
        $this->assertDatabaseCount('nirvana_rates', 16319);
        $sample = DB::table('nirvana_rates')->where(['age' => 25, 'gender' => 'Male', 'term' => 10, 'face_amount' => 100000])->first();
        $this->assertEquals(8.45, json_decode($sample->premiums, true)['preferred_plus']);
    }

    public function test_rate_form_uses_the_local_rate_table_and_sends_its_own_rate_email(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->visitor()];
        DB::table('nirvana_rates')->insert([
            'version' => config('nirvana.rate_version'), 'age' => 35, 'gender' => 'Female',
            'face_amount' => 250000, 'term' => 20, 'premiums' => '{"preferred_plus":23.75}',
        ]);
        $this->postJson('/api/nirvana/rates', ['age' => 35, 'gender' => 'Female', 'face_amount' => 250000, 'term' => 20], $headers)
            ->assertOk()->assertJsonPath('messages.2.body', fn (string $body) => str_contains($body, 'Preferred Plus: $23.75/month')
                && str_contains($body, 'These are sample rates'));
        $this->assertDatabaseCount('nirvana_rate_requests', 1);
        $this->assertNotNull(DB::table('nirvana_rate_requests')->first()->emailed_at);
    }

    public function test_departure_recaps_partial_progress_after_grace_and_keeps_tabs_separate(): void
    {
        config(['nirvana.staff_emails' => ['staff@example.test'], 'nirvana.end_grace_seconds' => 30]);
        $first = $this->visitor();
        $second = $this->visitor();
        $firstHeaders = ['Authorization' => 'Bearer '.$first];
        $secondHeaders = ['Authorization' => 'Bearer '.$second];
        $this->postJson('/api/nirvana/message', ['message' => 'Please explain term life.'], $firstHeaders)->assertOk();
        $this->getJson('/api/nirvana/session', $secondHeaders)->assertJsonCount(1, 'messages');
        $this->postJson('/api/nirvana/depart', [], $firstHeaders)->assertNoContent();
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $this->assertDatabaseCount('conversations', 2);
        $this->assertSame(0, DB::table('conversations')->whereNotNull('nirvana_summary_sent_at')->count());
        $this->travel(31)->seconds();
        $this->artisan('nirvana:finalize-departed')->assertSuccessful();
        $this->assertSame(1, DB::table('conversations')->whereNotNull('nirvana_summary_sent_at')->count());
        $this->assertStringContainsString('Please explain term life.', DB::table('conversations')->whereNotNull('nirvana_summary_sent_at')->first()->nirvana_summary);
        $this->assertSame(0, DB::table('conversations')->whereNull('nirvana_end_requested_at')->whereNotNull('nirvana_summary_sent_at')->count());
    }
}
