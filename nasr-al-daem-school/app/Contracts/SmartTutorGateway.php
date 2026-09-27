<?php

namespace App\Contracts;

use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorGatewayException;

interface SmartTutorGateway
{
    /**
     * @throws SmartTutorGatewayException
     */
    public function reply(SmartTutorPrompt $prompt): SmartTutorReply;
}
