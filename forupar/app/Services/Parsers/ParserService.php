<?php

namespace App\Services\Parsers;

use App\Models\Parser;
use App\Services\Parsers\Downloader;
use GuzzleHttp\Client;
use Symfony\Component\Console\Output\ConsoleOutput;

class ParserService
{
    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
        $this->client = new Client(['verify' => false]);
        $this->downloader = new Downloader();
        //$this->trade_site_param = TradeLotParam::where('code', 'trade_site')->first();
        $this->console = new ConsoleOutput();
    }

    public static function get($parser_id)
    {
        $parser = Parser::find($parser_id);
        return app()->makeWith("App\\Services\\Parsers\\$parser->slug\\{$parser->slug}Parser", ['parser' => $parser]);
    }

}
