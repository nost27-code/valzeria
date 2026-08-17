<?php

namespace App\Console\Commands;

use App\Services\ContactMailboxImportService;
use Illuminate\Console\Command;

class ImportContactMailbox extends Command
{
    protected $signature = 'contact-mail:import';

    protected $description = 'Import new messages from the administrator POP3 mailbox.';

    public function handle(ContactMailboxImportService $importer): int
    {
        if (! $importer->isConfigured()) {
            $this->line('skipped=mailbox_not_configured');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import();
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Contact mailbox import failed.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'checked=%d imported=%d skipped=%d',
            $result['checked'],
            $result['imported'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
