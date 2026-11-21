<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdf = config('nirvana.knowledge_pdf');
        if (is_string($pdf) && is_file($pdf)) {
            return;
        }

        $path = storage_path('framework/testing/radon-ai.pdf');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        if (!is_file($path)) {
            file_put_contents($path, "%PDF-1.1\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        }
        config(['nirvana.knowledge_pdf' => $path]);
    }
}
