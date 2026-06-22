<?php

declare(strict_types=1);

namespace App\Controllers;

use App\LlmsBuilder;

final class LlmsController
{
    public function __construct(private readonly LlmsBuilder $builder)
    {
    }

    public function index(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        echo $this->builder->readOrBuildIndex();
    }

    public function full(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Hour-long cache so a scraper hitting /llms-full.txt repeatedly
        // doesn't rebuild the (potentially multi-MB) concatenated body
        // on every request. Don't rely on Caddy — set it here too.
        header('Cache-Control: public, max-age=3600');
        echo $this->builder->readOrBuildFull();
    }
}
