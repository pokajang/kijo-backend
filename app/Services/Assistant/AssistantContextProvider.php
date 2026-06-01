<?php

namespace App\Services\Assistant;

use Illuminate\Http\Request;

interface AssistantContextProvider
{
    public function key(): string;

    public function supports(string $question, string $currentRoute, Request $request): bool;

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult;
}
