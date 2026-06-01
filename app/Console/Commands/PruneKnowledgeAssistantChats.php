<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KnowledgeAssistantService;
use Illuminate\Console\Command;

class PruneKnowledgeAssistantChats extends Command
{
    protected $signature = 'knowledge:prune-assistant-chats';

    protected $description = 'Delete expired Learn Kijo assistant chat threads.';

    public function handle(KnowledgeAssistantService $assistant): int
    {
        $deleted = $assistant->pruneExpired();
        $this->info("Deleted {$deleted} expired Knowledge assistant thread(s).");

        return self::SUCCESS;
    }
}
