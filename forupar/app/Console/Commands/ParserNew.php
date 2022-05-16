<?php

namespace App\Console\Commands;

use App\Services\Parsers\ParserService;
use Illuminate\Console\Command;

class ParserNew extends Command
{
    protected $signature = 'parser:new {--id=0}';

    protected $description = 'run parser';

    public function handle()
    {
        $id = $this->option('id');

        $parser = ParserService::get($id);
        $parser->loadNew();
    }
}
