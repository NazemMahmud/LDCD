<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;

class DeadCode extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:deadCode';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the dead code for classes and methods';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $fileLists = Request::create(route('findDeadCodes'), 'GET');
        $response = app()->handle($fileLists);
        $responseBody = json_decode($response->getContent(), true);
        $counter = 0;
    }
}
