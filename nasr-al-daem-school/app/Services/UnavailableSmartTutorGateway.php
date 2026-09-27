<?php

namespace App\Services;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorUnavailableException;

class UnavailableSmartTutorGateway implements SmartTutorGateway
{
    public function reply(SmartTutorPrompt $prompt): SmartTutorReply
    {
        throw new SmartTutorUnavailableException;
    }
}
