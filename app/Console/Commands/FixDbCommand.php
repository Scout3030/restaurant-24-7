<?php

namespace App\Console\Commands;

use App\Services\WhatsAppBusinessCloudService;
use Illuminate\Console\Command;

class FixDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-db-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        logger('fixing db');

        (new WhatsAppBusinessCloudService)->sendTemplate(
            phoneNumber: '+34686649345',
            templateName: 'hello_world',
            languageCode: 'en_US',
        );

        logger('db fixed');
    }
}
