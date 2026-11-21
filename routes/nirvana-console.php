<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

Artisan::command('nirvana:import-rates {sqlite} {version}', function () {
    $path = realpath($this->argument('sqlite'));
    $version = $this->argument('version');
    if (!$path || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $version)) {
        $this->error('Supply a valid SQLite file and rate version.');
        return 1;
    }

    $source = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $source->exec('PRAGMA query_only = ON');
    $rows = $source->query('SELECT age, gender, face_amount, product AS term, preferred_plus, preferred_non_tobacco, standard_plus, standard_non_tobacco, preferred_tobacco, standard_tobacco FROM premiums');
    $count = DB::transaction(function () use ($rows, $version) {
        $batch = [];
        $count = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = array_intersect_key($row, array_flip(['age', 'gender', 'face_amount', 'term']));
            $batch[] = ['version' => $version, ...$key, 'premiums' => json_encode(array_diff_key($row, $key), JSON_THROW_ON_ERROR)];
            if (count($batch) === 500) {
                DB::table('nirvana_rates')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('nirvana_rates')->insert($batch);
        }
        return $count + count($batch);
    });
    $this->info("Imported {$count} rows for {$version}.");
    return 0;
});

Artisan::command('nirvana:publish-knowledge {pdf?}', function () {
    $path = realpath($this->argument('pdf') ?? config('nirvana.knowledge_pdf'));
    $key = config('nirvana.openai_key');
    if (!$path || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        $this->error('The approved PDF could not be found.');
        return 1;
    }
    if (!$key) {
        $this->error('The original PDF is available. Configure NIRVANA_OPENAI_KEY in backend/.env to publish it.');
        return 1;
    }

    $digest = hash_file('sha256', $path);
    $client = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
        ->withToken($key)->acceptJson()->connectTimeout(8)->timeout(90);
    $file = (clone $client)->attach('file', file_get_contents($path), basename($path))
        ->post('https://api.openai.com/v1/files', ['purpose' => 'assistants'])->throw()->json('id');
    $store = $client->post('https://api.openai.com/v1/vector_stores', [
        'name' => 'SFO Nirvana '.substr($digest, 0, 12),
        'metadata' => ['source_sha256' => $digest, 'source_filename' => basename($path)],
    ])->throw()->json('id');
    $client->post("https://api.openai.com/v1/vector_stores/{$store}/files", [
        'file_id' => $file, 'attributes' => ['source_sha256' => $digest],
    ])->throw();
    $this->info("Uploaded ".basename($path)." (SHA-256 {$digest}).");
    $this->line("Set NIRVANA_KNOWLEDGE_STORE={$store}, then run php artisan nirvana:knowledge-status before testing chat.");
    return 0;
});

Artisan::command('nirvana:attach-website-knowledge {pdf?}', function () {
    $path = realpath($this->argument('pdf') ?: config('nirvana.website_pdf'));
    $original = config('nirvana.knowledge_pdf');
    $key = config('nirvana.openai_key');
    $store = config('nirvana.knowledge_store');
    if (!$path || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        $this->error('The website reference PDF could not be found.');
        return 1;
    }
    if (!is_file($original) || !$key || !is_string($store) || !preg_match('/^vs_[a-zA-Z0-9]+$/D', $store)) {
        $this->error('Configure the original PDF, NIRVANA_OPENAI_KEY, and the existing NIRVANA_KNOWLEDGE_STORE first.');
        return 1;
    }
    try {
        $client = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
            ->withToken($key)->acceptJson()->connectTimeout(8)->timeout(90);
        $url = "https://api.openai.com/v1/vector_stores/{$store}";
        $manifest = $client->get($url)->throw()->json();
        $files = $client->get($url.'/files', ['limit' => 100])->throw()->json('data', []);
        $originalDigest = hash_file('sha256', $original);
        if (data_get($manifest, 'metadata.source_sha256') !== $originalDigest
            || !collect($files)->contains(fn ($file) => data_get($file, 'attributes.source_sha256') === $originalDigest
                && data_get($file, 'status') === 'completed')) {
            $this->error('The existing store does not contain the approved, indexed original PDF. No file was uploaded.');
            return 1;
        }
        $digest = hash_file('sha256', $path);
        $existing = collect($files)->first(fn ($file) => data_get($file, 'attributes.source_sha256') === $digest);
        if ($existing) {
            if (data_get($existing, 'status') === 'completed') {
                foreach (collect($files)->filter(fn ($file) => data_get($file, 'attributes.source_kind') === 'public_website_snapshot'
                    && data_get($file, 'attributes.source_sha256') !== $digest) as $stale) {
                    $client->delete($url.'/files/'.data_get($stale, 'id'))->throw();
                }
            }
            $this->info(data_get($existing, 'status') === 'completed'
                ? 'Website PDF is indexed in the existing store; older website snapshots removed.'
                : 'Website PDF is already attached and still indexing.');
            return data_get($existing, 'status') === 'completed' ? 0 : 1;
        }
        $file = (clone $client)->attach('file', file_get_contents($path), basename($path))
            ->post('https://api.openai.com/v1/files', ['purpose' => 'assistants'])->throw()->json('id');
        $client->post($url.'/files', ['file_id' => $file, 'attributes' => [
            'source_sha256' => $digest, 'source_kind' => 'public_website_snapshot',
        ]])->throw();
        $this->info('Attached '.basename($path).' to the existing Nirvana store; original PDF retained.');
        $this->line('After indexing, rerun this command to retire any older website snapshot, then run nirvana:knowledge-status.');
        return 0;
    } catch (\Throwable $exception) {
        $this->error('OpenAI could not attach the website PDF. Check network access, key permissions, and store ID.');
        return 1;
    }
});

Artisan::command('nirvana:knowledge-status', function () {
    $path = config('nirvana.knowledge_pdf');
    $website = config('nirvana.website_pdf');
    $key = config('nirvana.openai_key');
    $store = config('nirvana.knowledge_store');
    $this->line('Local reference PDF: '.(is_file($path) ? basename($path).' · SHA-256 '.hash_file('sha256', $path) : 'not present'));
    $this->line('Local website PDF: '.(is_file($website) ? basename($website).' · SHA-256 '.hash_file('sha256', $website) : 'not present'));
    if (!is_file($path) || !is_file($website)) {
        $this->error('Both approved PDFs must be present locally to verify the Nirvana knowledge store.');
        return 1;
    }
    if (!$key || !is_string($store) || !preg_match('/^vs_[a-zA-Z0-9]+$/D', $store)) {
        $this->error('Set NIRVANA_OPENAI_KEY and NIRVANA_KNOWLEDGE_STORE in backend/.env.');
        return 1;
    }

    try {
        $client = Http::withOptions(['verify' => config('nirvana.tls_ca_bundle') ?: true])
            ->withToken($key)->acceptJson()->connectTimeout(5)->timeout(15);
        $url = "https://api.openai.com/v1/vector_stores/{$store}";
        $manifest = $client->get($url)->throw()->json();
        $digest = data_get($manifest, 'metadata.source_sha256');
        $files = $client->get($url.'/files', ['limit' => 100])->throw()->json('data', []);
    } catch (\Throwable $exception) {
        $this->error('OpenAI could not verify this store. Check network access, the project key, and store ID.');
        return 1;
    }
    if (!$digest || (is_file($path) && hash_file('sha256', $path) !== $digest)) {
        $this->error('The store does not match the expected PDF. Publish the approved source into a new store.');
        return 1;
    }
    $expected = [$digest, hash_file('sha256', $website)];
    $actual = collect($files)->map(fn ($file) => data_get($file, 'attributes.source_sha256'))->all();
    if (data_get($manifest, 'file_counts.total') !== count($expected)
        || count($files) !== count($expected)
        || array_diff($expected, $actual) || array_diff($actual, $expected)
        || collect($files)->contains(fn ($file) => data_get($file, 'status') !== 'completed')
        || data_get($manifest, 'status') !== 'completed') {
        $this->error('The approved PDF set is missing, still indexing, or contains an unexpected file.');
        return 1;
    }
    $this->info('Ready: '.basename($path).' and '.basename($website).' in the existing store.');
    return 0;
});
